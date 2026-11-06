<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Response;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AlertsTest extends TestCase
{
    private const CERT_URL = 'https://sns.ap-northeast-2.amazonaws.com/SimpleNotificationService-test.pem';

    private static OpenSSLAsymmetricKey $key;
    private static string $certificate;

    private TestClient $client;

    public static function setUpBeforeClass(): void
    {
        // SNS 서명 인증서 대용 자체 서명 인증서
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $pem);
        self::$key = $key;
        self::$certificate = $pem;
    }

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $this->client->httpResponses[self::CERT_URL] = self::$certificate;
    }

    // ---------- Uptime Kuma ----------

    public function testUptimeKumaRequiresConfiguredToken(): void
    {
        $this->assertSame(503, (new TestClient(['alert_webhook_token' => '']))->postRaw('/api/alerts/uptime-kuma', '{}', ['HTTP_AUTHORIZATION' => 'Bearer '])->status);
        $this->assertSame(401, $this->client->postRaw('/api/alerts/uptime-kuma', '{}')->status);
        $this->assertSame(401, $this->client->postRaw('/api/alerts/uptime-kuma', '{}', ['HTTP_AUTHORIZATION' => 'Bearer wrong'])->status);
        $this->assertSame(400, $this->kuma('not json')->status);
    }

    public function testUptimeKumaBypassesLoginSessionAndIpRestriction(): void
    {
        $client = new TestClient(['env' => 'production', 'allowed_ips' => '10.0.0.1']);
        $this->assertSame(302, $client->get('/', ['REMOTE_ADDR' => '10.0.0.1'])->status, '화면은 여전히 로그인 필요');

        $response = $client->postRaw('/api/alerts/uptime-kuma', $this->heartbeat(0), ['HTTP_AUTHORIZATION' => 'Bearer ' . TestClient::ALERT_TOKEN]);

        $this->assertSame(200, $response->status);
        $this->assertArrayNotHasKey('Set-Cookie', $response->headers);
    }

    public function testDownOpensIncidentRepeatsAlertAndUpResolves(): void
    {
        $down = $this->json($this->kuma($this->heartbeat(0, time: '2026-11-03 01:00:00', msg: 'timeout of 48000ms exceeded')));
        $this->assertSame('processed', $down['result']);
        $id = $down['incident_id'];

        $incident = $this->row("SELECT * FROM incidents WHERE id = {$id}");
        $this->assertSame(['critical', 'open', 'uptime_kuma'], [$incident['severity'], $incident['status'], $incident['source']]);
        $this->assertStringContainsString('웹 응답 실패', $incident['title']);
        $this->assertSame('down', $this->resourceStatus('https://miri.example.com'));

        $this->assertSame(['result' => 'duplicate'], $this->json($this->kuma($this->heartbeat(0, time: '2026-11-03 01:00:00'))), '같은 하트비트 재전송');
        $again = $this->json($this->kuma($this->heartbeat(0, time: '2026-11-03 01:05:00')));
        $this->assertSame($id, $again['incident_id'], '진행 중인 장애에 이어 붙인다');

        $up = $this->json($this->kuma($this->heartbeat(1, time: '2026-11-03 01:10:00', ping: 321)));
        $this->assertSame($id, $up['incident_id']);
        $incident = $this->row("SELECT * FROM incidents WHERE id = {$id}");
        $this->assertSame('resolved', $incident['status']);
        $this->assertNotNull($incident['resolved_at']);
        $this->assertSame('up', $this->resourceStatus('https://miri.example.com'));
        $this->assertSame(['opened', 'alert', 'resolved'], array_column($this->rows("SELECT type FROM incident_events WHERE incident_id = {$id} ORDER BY id"), 'type'));
        $this->assertSame(321, (int) $this->row("SELECT response_ms FROM check_results WHERE metric_name = 'http' ORDER BY id DESC LIMIT 1")['response_ms']);

        $page = $this->loggedIn()->get("/incidents/{$id}")->body;
        $this->assertStringContainsString('timeout of 48000ms exceeded', $page);
    }

    public function testMonitorMatchedByExternalRef(): void
    {
        $this->client->pdo()->exec("UPDATE resources SET external_ref = '42' WHERE identifier = 'https://koi.example.com'");

        $result = $this->json($this->kuma($this->heartbeat(0, monitorId: 42, url: 'https://changed.example.com')));

        $this->assertSame('down', $this->resourceStatus('https://koi.example.com'));
        $this->assertSame('koi', $this->row("SELECT s.code FROM incidents i JOIN systems s ON s.id = i.system_id WHERE i.id = {$result['incident_id']}")['code']);
    }

    public function testUnknownMonitorAndTestMessageAreStoredNotProcessed(): void
    {
        $this->assertSame('unmatched', $this->json($this->kuma($this->heartbeat(0, monitorId: 99, url: 'https://nope.example.com')))['result']);
        $this->assertSame('ignored', $this->json($this->kuma('{"heartbeat":null,"monitor":null,"msg":"Uptime Kuma Testing"}'))['result']);

        $events = $this->rows('SELECT error, processed_at FROM alert_events ORDER BY id');
        $this->assertStringContainsString('등록되지 않은 모니터 #99', $events[0]['error']);
        $this->assertNotNull($events[1]['processed_at']);
        $this->assertSame(6, (int) $this->row('SELECT COUNT(*) AS n FROM incidents')['n'], '샘플 6건 그대로');
    }

    public function testCertificateExpiryNotice(): void
    {
        $content = json_encode([
            'heartbeat' => null,
            'monitor' => ['id' => 7, 'name' => '미리 웹', 'url' => 'https://miri.example.com'],
            'msg' => '[미리 웹][https://miri.example.com] Certificate will be expired in 13 days',
        ]);

        $result = $this->json($this->kuma($content));

        $incident = $this->row("SELECT severity, title FROM incidents WHERE id = {$result['incident_id']}");
        $this->assertSame('info', $incident['severity']);
        $this->assertStringContainsString('만료 13일 전', $incident['title']);
        $this->assertSame(13.0, (float) $this->row("SELECT metric_value FROM check_results WHERE metric_name = 'ssl_days_left' ORDER BY id DESC LIMIT 1")['metric_value']);
    }

    public function testFailedProcessingIsRetried(): void
    {
        $pdo = $this->client->pdo();
        $pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON incidents BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $this->assertSame(500, $this->kuma($this->heartbeat(0))->status);
        $event = $this->row('SELECT error, processed_at FROM alert_events');
        $this->assertStringContainsString('boom', $event['error']);
        $this->assertNull($event['processed_at']);

        $pdo->exec('DROP TRIGGER boom');
        $this->assertSame('processed', $this->json($this->kuma($this->heartbeat(0)))['result'], '같은 알림 재전송 시 다시 처리');
        $this->assertSame(1, (int) $this->row('SELECT COUNT(*) AS n FROM alert_events')['n']);
    }

    // ---------- SNS / CloudWatch ----------

    public function testSnsRequiresConfiguredTopic(): void
    {
        $this->assertSame(503, (new TestClient(['sns_topic_arns' => '']))->postRaw('/api/alerts/sns', $this->sns($this->alarm('ALARM')))->status);
    }

    public function testSnsRejectsForgedMessages(): void
    {
        $tampered = json_decode($this->sns($this->alarm('ALARM')), true);
        $tampered['Message'] = $this->alarm('OK');
        $otherTopic = $this->sns($this->alarm('ALARM'), topic: 'arn:aws:sns:ap-northeast-2:999999999999:evil');
        $foreignCert = json_decode($this->sns($this->alarm('ALARM')), true);
        $foreignCert['SigningCertURL'] = 'https://evil.example.com/cert.pem';

        foreach ([json_encode($tampered), $otherTopic, json_encode($foreignCert), '{"Type":"Notification"}', 'nope'] as $content) {
            $this->assertSame(400, $this->client->postRaw('/api/alerts/sns', $content)->status);
        }
        $this->assertNotContains('https://evil.example.com/cert.pem', $this->client->httpRequests, 'SNS 도메인 밖의 인증서는 받지 않는다');
        $this->assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM alert_events')['n']);
    }

    public function testSubscriptionConfirmation(): void
    {
        $subscribeUrl = 'https://sns.ap-northeast-2.amazonaws.com/?Action=ConfirmSubscription&Token=abc';
        $this->client->httpResponses[$subscribeUrl] = '<ConfirmSubscriptionResponse/>';

        $response = $this->client->postRaw('/api/alerts/sns', $this->sns('You have chosen to subscribe', type: 'SubscriptionConfirmation', extra: [
            'SubscribeURL' => $subscribeUrl,
            'Token' => 'abc',
        ]));

        $this->assertSame(['result' => 'subscribed'], $this->json($response));
        $this->assertContains($subscribeUrl, $this->client->httpRequests);
    }

    public function testCloudWatchAlarmOpensAndOkResolves(): void
    {
        // koi-app-01 에는 샘플 장애 #5(디스크, 확인됨)가 이미 진행 중
        $alarm = $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('ALARM'), messageId: 'm-1')));
        $id = $alarm['incident_id'];
        $incident = $this->row("SELECT * FROM incidents WHERE id = {$id}");
        $this->assertSame(['major', 'open', 'cloudwatch:222222222222:koi-app-01-cpu-high'], [$incident['severity'], $incident['status'], $incident['alert_key']]);
        $this->assertSame('warning', $this->resourceStatus('i-0b1c2d3e4f5a60011'));

        $this->assertSame(['result' => 'duplicate'], $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('ALARM'), messageId: 'm-1'))), 'SNS 재전송');

        $ok = $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('OK'), messageId: 'm-2', version: '2')));
        $this->assertSame($id, $ok['incident_id']);
        $this->assertSame('resolved', $this->row("SELECT status FROM incidents WHERE id = {$id}")['status']);
        $this->assertSame('warning', $this->resourceStatus('i-0b1c2d3e4f5a60011'), '장애 #5 가 남아 있어 정상으로 돌리지 않는다');
    }

    public function testStatusCheckAlarmMarksResourceDown(): void
    {
        $result = $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('ALARM', instanceId: 'i-0a1b2c3d4e5f60001', metric: 'StatusCheckFailed_System'))));

        $this->assertSame('critical', $this->row("SELECT severity FROM incidents WHERE id = {$result['incident_id']}")['severity']);
        $this->assertSame('down', $this->resourceStatus('i-0a1b2c3d4e5f60001'));
        $this->assertSame('down', $this->row("SELECT current_status FROM resources WHERE identifier = 'i-0a1b2c3d4e5f60001'")['current_status']);
    }

    public function testUnknownInstanceAndInsufficientData(): void
    {
        $this->assertSame('unmatched', $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('ALARM', instanceId: 'i-unknown'), messageId: 'a')))['result']);
        $this->assertSame('ignored', $this->json($this->client->postRaw('/api/alerts/sns', $this->sns($this->alarm('INSUFFICIENT_DATA'), messageId: 'b')))['result']);
        $this->assertSame(6, (int) $this->row('SELECT COUNT(*) AS n FROM incidents')['n']);
    }

    // ---------- helpers ----------

    private function kuma(string $content): Response
    {
        return $this->client->postRaw('/api/alerts/uptime-kuma', $content, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestClient::ALERT_TOKEN]);
    }

    private function heartbeat(int $status, string $time = '2026-11-03 01:00:00', ?string $msg = null, ?int $ping = null, int $monitorId = 1, string $url = 'https://miri.example.com'): string
    {
        return json_encode([
            'heartbeat' => ['monitorID' => $monitorId, 'status' => $status, 'time' => $time, 'msg' => $msg ?? '', 'ping' => $ping, 'important' => true],
            'monitor' => ['id' => $monitorId, 'name' => '미리 웹', 'url' => $url, 'type' => 'http'],
            'msg' => '[미리 웹] ' . ($status === 0 ? '🔴 Down' : '✅ Up'),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function alarm(string $state, string $instanceId = 'i-0b1c2d3e4f5a60011', string $metric = 'CPUUtilization'): string
    {
        return json_encode([
            'AlarmName' => 'koi-app-01-cpu-high',
            'AWSAccountId' => '222222222222',
            'NewStateValue' => $state,
            'NewStateReason' => "Threshold Crossed: 1 datapoint [91.2] was greater than the threshold (80.0).",
            'StateChangeTime' => '2026-11-03T01:00:00.000+0000',
            'Region' => 'Asia Pacific (Seoul)',
            'Trigger' => ['MetricName' => $metric, 'Namespace' => 'AWS/EC2', 'Dimensions' => [['name' => 'InstanceId', 'value' => $instanceId]]],
        ]);
    }

    /**
     * 서명한 SNS 메시지
     *
     * @param array<string, string> $extra
     */
    private function sns(string $message, string $type = 'Notification', ?string $messageId = null, string $topic = TestClient::SNS_TOPIC, string $version = '1', array $extra = []): string
    {
        $fields = [
            'Type' => $type,
            'MessageId' => $messageId ?? bin2hex(random_bytes(8)),
            'TopicArn' => $topic,
            'Message' => $message,
            'Timestamp' => '2026-11-03T01:00:00.000Z',
        ] + $extra;
        if ($type === 'Notification') {
            $fields['Subject'] = 'ALARM: "koi-app-01-cpu-high" in Asia Pacific (Seoul)';
        }

        $signed = $type === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $data = '';
        foreach ($signed as $key) {
            if (isset($fields[$key])) {
                $data .= $key . "\n" . $fields[$key] . "\n";
            }
        }
        openssl_sign($data, $signature, self::$key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);

        return json_encode($fields + [
            'SignatureVersion' => $version,
            'Signature' => base64_encode($signature),
            'SigningCertURL' => self::CERT_URL,
        ]);
    }

    private function loggedIn(): TestClient
    {
        $this->client->loginAs('viewer');

        return $this->client;
    }

    private function resourceStatus(string $identifier): string
    {
        return $this->row("SELECT current_status FROM resources WHERE identifier = '{$identifier}'")['current_status'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $this->assertSame(200, $response->status, $response->body);

        return json_decode($response->body, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sql): array
    {
        return $this->client->pdo()->query($sql)->fetch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->client->pdo()->query($sql)->fetchAll();
    }
}
