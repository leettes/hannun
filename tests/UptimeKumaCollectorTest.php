<?php

declare(strict_types=1);

namespace Tests;

use App\Alerts\AlertIngestor;
use App\Alerts\UptimeKumaCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestClient;

final class UptimeKumaCollectorTest extends TestCase
{
    private TestClient $client;
    private PDO $pdo;
    private ?string $metrics = null;
    private UptimeKumaCollector $collector;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $this->pdo = $this->client->pdo();
        $this->collector = new UptimeKumaCollector(
            $this->client->pdo(...),
            new AlertIngestor($this->client->pdo(...), $this->client->notifier),
            fn (): ?string => $this->metrics,
        );
    }

    public function testParse(): void
    {
        $monitors = UptimeKumaCollector::parse(<<<'TXT'
            # HELP monitor_status Monitor Status (1 = UP, 0= DOWN, 2= PENDING, 3= MAINTENANCE)
            # TYPE monitor_status gauge
            monitor_status{monitor_name="미리 \"웹\"",monitor_type="http",monitor_url="https://miri.example.com",monitor_hostname="null",monitor_port="null"} 1
            monitor_response_time{monitor_name="미리 \"웹\"",monitor_type="http",monitor_url="https://miri.example.com",monitor_hostname="null",monitor_port="null"} 412.5
            monitor_cert_days_remaining{monitor_name="미리 \"웹\"",monitor_type="http",monitor_url="https://miri.example.com",monitor_hostname="null",monitor_port="null"} 182
            monitor_status{monitor_name="DB ping",monitor_type="ping",monitor_url="null",monitor_hostname="10.0.0.5",monitor_port="null"} 1
            monitor_status{monitor_id="7",monitor_name="코이 API",monitor_type="keyword",monitor_url="https://api.koi.example.com/health"} 0
            TXT);

        $this->assertSame([
            ['id' => null, 'name' => '미리 "웹"', 'url' => 'https://miri.example.com', 'status' => 1, 'response_ms' => 413, 'cert_days' => 182],
            ['id' => '7', 'name' => '코이 API', 'url' => 'https://api.koi.example.com/health', 'status' => 0, 'response_ms' => null, 'cert_days' => null],
        ], $monitors, 'URL 없는 모니터(ping 등)는 제외');
    }

    public function testRecordsChecksAndHandlesTransitions(): void
    {
        $this->metrics = $this->metric(1, 230, 182);
        $summary = $this->collector->run();
        $this->assertSame(['monitors' => 1, 'matched' => 1, 'opened' => 0, 'resolved' => 0, 'unmatched' => []], $summary);
        $this->assertSame('up', $this->resourceStatus());
        $before = $this->checks();

        $this->metrics = $this->metric(0, 0, 182);
        $this->assertSame(1, $this->collector->run()['opened']);
        $this->assertSame('down', $this->resourceStatus());
        $this->assertSame(['opened'], $this->notifications(), '장애 알림 대기열 (발송은 cron 이 이어서 함)');

        $this->assertSame(0, $this->collector->run()['opened'], '장애가 이어지는 동안 새 장애를 만들지 않는다');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM incidents WHERE alert_key LIKE 'uptime_kuma:http:%'")->fetchColumn());

        $this->metrics = $this->metric(1, 240, 182);
        $this->assertSame(1, $this->collector->run()['resolved']);
        $this->assertSame('up', $this->resourceStatus());
        $this->assertSame(['opened', 'resolved'], $this->notifications());

        $this->assertSame($before + 3, $this->checks(), '실행마다 HTTP 점검 1건');
    }

    public function testSlowResponseAndExpiringCertificateAreWarnings(): void
    {
        $this->metrics = $this->metric(1, 4200, 182);
        $this->collector->run();
        $this->assertSame('warning', $this->resourceStatus());

        $this->metrics = $this->metric(1, 200, 12);
        $this->collector->run();
        $this->assertSame('warning', $this->resourceStatus());

        $this->metrics = $this->metric(1, 200, 182);
        $this->collector->run();
        $this->assertSame('up', $this->resourceStatus());
    }

    public function testSslDaysRecordedOncePerDay(): void
    {
        $count = fn (): int => (int) $this->pdo->query("SELECT COUNT(*) FROM check_results WHERE metric_name = 'ssl_days_left' AND source = 'uptime_kuma' AND metric_value = 55")->fetchColumn();
        $this->pdo->exec("UPDATE check_results SET checked_at = datetime(checked_at, '-2 days') WHERE metric_name = 'ssl_days_left'");
        $this->metrics = $this->metric(1, 200, 55);

        $this->collector->run();
        $this->collector->run();

        $this->assertSame(1, $count());
    }

    public function testUnmatchedMonitorsAreReported(): void
    {
        $this->metrics = 'monitor_status{monitor_name="새 서비스",monitor_type="http",monitor_url="https://new.example.com"} 1';

        $this->assertSame(['새 서비스 (https://new.example.com)'], $this->collector->run()['unmatched']);
    }

    public function testFetchFailureThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->collector->run();
    }

    public function testUptimeIsComputedFromCollectedChecks(): void
    {
        $this->pdo->exec("DELETE FROM check_results WHERE metric_name = 'http'");
        foreach ([1, 1, 1, 0] as $status) {
            $this->metrics = $this->metric($status, 200, 182);
            $this->collector->run();
        }

        $client = $this->client;
        $client->loginAs('viewer');
        $this->assertStringContainsString('75.00%', $client->get('/systems/miri')->body);
    }

    private function metric(int $status, int $responseMs, int $certDays): string
    {
        $labels = 'monitor_name="미리 웹",monitor_type="http",monitor_url="https://miri.example.com",monitor_hostname="null",monitor_port="null"';

        return "monitor_status{{$labels}} {$status}\nmonitor_response_time{{$labels}} {$responseMs}\nmonitor_cert_days_remaining{{$labels}} {$certDays}\n";
    }

    private function resourceStatus(): string
    {
        return (string) $this->pdo->query("SELECT current_status FROM resources WHERE identifier = 'https://miri.example.com'")->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function notifications(): array
    {
        return $this->pdo->query('SELECT kind FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function checks(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM check_results c JOIN resources r ON r.id = c.resource_id WHERE r.identifier = 'https://miri.example.com' AND c.metric_name = 'http'")->fetchColumn();
    }
}
