<?php

declare(strict_types=1);

namespace Tests;

use App\Notifications\AligoSms;
use App\Notifications\Notifier;
use App\Notifications\SmsFailed;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class NotificationsTest extends TestCase
{
    private const OWNER = '01011112222';     // 미리 담당자
    private const OPERATOR = '01033334444';  // 알림 받는 운영자

    private TestClient $client;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $pdo = $this->client->pdo();
        // 미리 담당자(샘플 비활성 계정)를 활성화하고 번호를 준다
        $pdo->exec("UPDATE users SET is_active = 1, phone = '" . self::OWNER . "' WHERE id IN (SELECT user_id FROM system_owners WHERE system_id = (SELECT id FROM systems WHERE code = 'miri'))");

        $operator = $this->client->addUser('op@example.com', 'correct horse battery', 'operator');
        $this->client->users->updateContact($operator->id, self::OPERATOR, 'all');
        $viewer = $this->client->addUser('viewer@example.com', 'correct horse battery', 'viewer');
        $this->client->users->updateContact($viewer->id, '01055556666', 'all');
        $gone = $this->client->addUser('gone@example.com', 'correct horse battery', 'operator', active: false);
        $this->client->users->updateContact($gone->id, '01077778888', 'all');
    }

    public function testEveryOwnerOfTheSystemGetsSms(): void
    {
        // 미리에 담당자를 한 명 더 둔다 (문자 범위 '담당 서비스')
        $second = $this->client->addUser('second@example.com', 'correct horse battery', 'viewer');
        $this->client->users->updateContact($second->id, '01099990000', 'owned');
        $this->client->pdo()->exec("INSERT INTO system_owners (system_id, user_id) SELECT id, {$second->id} FROM systems WHERE code = 'miri'");

        $this->kuma(0, '2026-11-03 01:00:00');

        $this->assertSame([self::OWNER, self::OPERATOR, '01099990000'], $this->client->sms->sent[0]['to'], '번호 순');
    }

    public function testSmsScopeDecidesRecipients(): void
    {
        // 다른 서비스 담당자(담당 서비스)는 받지 않고, 안함으로 둔 담당자도 받지 않는다
        $other = $this->client->addUser('other@example.com', 'correct horse battery', 'operator');
        $this->client->users->updateContact($other->id, '01012120000', 'owned');
        $this->client->pdo()->exec("INSERT INTO system_owners (system_id, user_id) SELECT id, {$other->id} FROM systems WHERE code <> 'miri' LIMIT 1");
        $muted = $this->client->addUser('muted@example.com', 'correct horse battery', 'operator');
        $this->client->users->updateContact($muted->id, '01034340000', 'none');
        $this->client->pdo()->exec("INSERT INTO system_owners (system_id, user_id) SELECT id, {$muted->id} FROM systems WHERE code = 'miri'");

        $this->kuma(0, '2026-11-03 01:00:00');

        $this->assertSame([self::OWNER, self::OPERATOR], $this->client->sms->sent[0]['to']);
    }

    public function testNewAdminGetsAllAndOthersGetOwned(): void
    {
        $this->assertSame('all', $this->client->addUser('a2@example.com', 'correct horse battery', 'admin')->smsScope);
        $this->assertSame('owned', $this->client->addUser('o2@example.com', 'correct horse battery', 'operator')->smsScope);
        $this->assertSame('owned', $this->client->addUser('v2@example.com', 'correct horse battery', 'viewer')->smsScope);
    }

    public function testDownAndUpSendSmsToOwnerAndSubscribedOperators(): void
    {
        $down = $this->kuma(0, '2026-11-03 01:00:00');
        $this->assertCount(1, $this->client->sms->sent);
        $sms = $this->client->sms->sent[0];
        $this->assertSame([self::OWNER, self::OPERATOR], $sms['to'], '전체 서비스라도 조회 계정·비활성 계정은 제외');
        $this->assertStringStartsWith('[장애] 미리 웹 응답 실패', $sms['message']);
        $this->assertStringContainsString('(심각)', $sms['message']);
        $this->assertStringContainsString("https://monitor.example.com/incidents/{$down['incident_id']}", $sms['message']);

        $this->kuma(0, '2026-11-03 01:05:00');
        $this->assertCount(1, $this->client->sms->sent, '반복 알림은 문자를 보내지 않는다');

        $this->kuma(1, '2026-11-03 01:10:00');
        $this->assertCount(2, $this->client->sms->sent);
        $this->assertStringStartsWith('[복구] 미리 웹 응답 실패', $this->client->sms->sent[1]['message']);

        $this->assertSame(['sent', 'sent'], array_column($this->rows('SELECT status FROM notifications ORDER BY id'), 'status'));
    }

    public function testLowSeverityIsNotSent(): void
    {
        $this->client->postRaw('/api/alerts/uptime-kuma', json_encode([
            'heartbeat' => null,
            'monitor' => ['id' => 1, 'name' => '미리 웹', 'url' => 'https://miri.example.com'],
            'msg' => 'Certificate will be expired in 13 days',
        ]), $this->auth());

        $this->assertSame([], $this->client->sms->sent);
        $this->assertSame(0, (int) $this->client->pdo()->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testManualResolveSendsRecovery(): void
    {
        $this->client->loginAs('operator');
        $form = ['status' => 'resolved', 'assignee' => '', 'root_cause' => '', 'action_taken' => 'API 서버 재시작', 'note' => '',
            'seen_status' => 'open', 'seen_assignee' => '', 'seen_root_cause' => '', 'seen_action_taken' => ''];

        $this->assertSame(302, $this->client->post('/incidents/6', $form)->status);

        // #6 은 시스템 C(담당자 번호 없음) → 알림 받는 운영자만
        $this->assertCount(1, $this->client->sms->sent);
        $this->assertSame([self::OPERATOR], $this->client->sms->sent[0]['to']);
        $this->assertStringStartsWith('[복구] 시스템 C API 헬스체크 실패', $this->client->sms->sent[0]['message']);
    }

    public function testNoRecipientsIsSkipped(): void
    {
        $this->client->pdo()->exec('UPDATE users SET phone = NULL');

        $this->kuma(0, '2026-11-03 01:00:00');

        $this->assertSame([], $this->client->sms->sent);
        $row = $this->rows('SELECT status, error FROM notifications')[0];
        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('수신자 없음', $row['error']);
    }

    public function testFailedSmsIsRetriedThenGivesUp(): void
    {
        $this->client->sms->failNext = 1;
        $this->kuma(0, '2026-11-03 01:00:00');
        $row = $this->rows('SELECT status, attempts, error FROM notifications')[0];
        $this->assertSame(['pending', 1, 'fake failure'], [$row['status'], (int) $row['attempts'], $row['error']]);

        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], $this->client->notifier->dispatch(), 'cron 재발송');
        $this->assertCount(1, $this->client->sms->sent);
        $this->assertSame(0, $this->client->notifier->dispatch()['sent'], '보낸 건은 다시 보내지 않는다');

        $this->client->sms->failNext = Notifier::MAX_ATTEMPTS;
        $this->kuma(1, '2026-11-03 01:10:00');
        $this->client->notifier->dispatch();
        $this->client->notifier->dispatch();
        $this->assertSame('failed', $this->rows('SELECT status FROM notifications ORDER BY id DESC LIMIT 1')[0]['status']);
        $this->assertSame(0, $this->client->notifier->dispatch()['failed'], '최대 횟수 후에는 포기');
    }

    public function testWithoutSmsSettingsNotificationsAreSkipped(): void
    {
        $notifier = new Notifier($this->client->pdo(...), null);
        $pdo = $this->client->pdo();
        $notifier->queue($pdo, 6, 'opened');

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], $notifier->dispatch());
        $this->assertStringContainsString('ALIGO_API_KEY', $this->rows('SELECT error FROM notifications')[0]['error']);
    }

    public function testAligoRequest(): void
    {
        $requests = [];
        $post = function (string $url, array $fields) use (&$requests): ?string {
            $requests[] = [$url, $fields];

            return '{"result_code":"1","message":"success","msg_id":"123","success_cnt":2,"error_cnt":0}';
        };
        $aligo = new AligoSms('key', 'user', '0212345678', true, $post);

        $aligo->send(['01011112222', '01033334444'], '[장애] 짧은 문자');
        $aligo->send(['01011112222'], str_repeat('가', 46));

        $this->assertSame('https://apis.aligo.in/send/', $requests[0][0]);
        $this->assertSame(['key' => 'key', 'user_id' => 'user', 'sender' => '0212345678', 'receiver' => '01011112222,01033334444',
            'msg' => '[장애] 짧은 문자', 'msg_type' => 'SMS', 'testmode_yn' => 'Y'], $requests[0][1]);
        $this->assertSame('LMS', $requests[1][1]['msg_type'], '한글 46자 = 92바이트 > 90');
        $this->assertArrayHasKey('title', $requests[1][1]);
    }

    public function testAligoErrorsThrow(): void
    {
        foreach (['{"result_code":-101,"message":"인증오류"}', null, 'not json'] as $body) {
            try {
                (new AligoSms('k', 'u', '0212345678', false, fn (): ?string => $body))->send(['01011112222'], 'x');
                $this->fail('SmsFailed 가 나야 한다');
            } catch (SmsFailed $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAdminSetsPhoneAndSubscription(): void
    {
        $this->client->loginAs('admin');
        $op = $this->client->users->findByEmail('op@example.com');

        $invalid = $this->client->post("/users/{$op->id}", ['name' => $op->name, 'role' => 'operator', 'active' => '1', 'phone' => '02-123-4567', 'sms_scope' => 'all']);
        $this->assertSame(422, $invalid->status);
        $this->assertStringContainsString('휴대폰 번호를 확인하세요', $invalid->body);

        $viewerAll = $this->client->post("/users/{$op->id}", ['name' => $op->name, 'role' => 'viewer', 'active' => '1', 'phone' => '', 'sms_scope' => 'all']);
        $this->assertSame(422, $viewerAll->status);
        $this->assertStringContainsString('운영자 이상만', $viewerAll->body);

        $this->client->post("/users/{$op->id}", ['name' => $op->name, 'role' => 'operator', 'active' => '1', 'phone' => '010-9999-0000', 'sms_scope' => 'all']);
        $saved = $this->client->users->findById($op->id);
        $this->assertSame(['01099990000', 'all'], [$saved?->phone, $saved?->smsScope]);
        $this->assertStringContainsString('010-9999-0000', $this->client->get('/users')->body);
        $this->assertStringContainsString('value="010-9999-0000"', $this->client->get("/users/{$op->id}")->body);
    }

    /**
     * @return array<string, mixed>
     */
    private function kuma(int $status, string $time): array
    {
        $response = $this->client->postRaw('/api/alerts/uptime-kuma', json_encode([
            'heartbeat' => ['monitorID' => 1, 'status' => $status, 'time' => $time, 'msg' => '', 'ping' => 100],
            'monitor' => ['id' => 1, 'name' => '미리 웹', 'url' => 'https://miri.example.com'],
            'msg' => '',
        ]), $this->auth());
        $this->assertSame(200, $response->status, $response->body);

        return json_decode($response->body, true);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestClient::ALERT_TOKEN];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->client->pdo()->query($sql)->fetchAll();
    }
}
