<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use App\Migrator;
use App\Monitoring\DatabaseMonitoringRepository;
use App\Monitoring\TimeRange;
use App\Sample\SampleData;
use App\Sample\SampleSeeder;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestClient;

/**
 * 샘플 데이터를 DB 에 적재한 뒤 DB 저장소가 같은 화면 데이터를 돌려주는지 확인한다.
 */
final class MonitoringRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SampleData $sample;
    private DatabaseMonitoringRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Database::open(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->run();
        // DB 는 초 단위로 저장하므로 기준 시각도 초 단위로 맞춘다
        $this->sample = new SampleData(new DateTimeImmutable(date('Y-m-d H:i:s')));
        (new SampleSeeder($this->pdo, $this->sample))->run();
        $this->repo = new DatabaseMonitoringRepository(fn (): PDO => $this->pdo);
    }

    public function testSystemsMatchSampleData(): void
    {
        $expected = $this->sample->systems();
        $actual = $this->repo->systems();

        $this->assertSame(array_keys($expected), array_keys($actual), '등록 순서 유지');
        foreach ($expected as $code => $s) {
            $a = $actual[$code];
            foreach (['name', 'environment', 'owner', 'description', 'aws_account', 'region', 'status', 'http_status', 'ec2_status', 'rds_status'] as $key) {
                $this->assertSame($s[$key], $a[$key], "{$code}.{$key}");
            }
            $this->assertEquals($s['last_checked_at'], $a['last_checked_at'], "{$code}.last_checked_at");

            foreach ($s['http'] as $n => $m) {
                $h = $a['http'][$n];
                $this->assertSame([$m['name'], $m['url'], $m['status'], $m['response_ms'], $m['ssl_days_left']],
                    [$h['name'], $h['url'], $h['status'], $h['response_ms'], $h['ssl_days_left']], "{$code}.http.{$n}");
                $this->assertEqualsWithDelta($m['uptime_24h'], $h['uptime_24h'], 0.5, '가용률은 점검 횟수 단위로 근사');
            }
            foreach ($s['ec2'] as $n => $i) {
                $e = $a['ec2'][$n];
                $this->assertSame([$i['instance_id'], $i['instance_type'], $i['status_check_ok'], $i['cpu'], $i['memory'], $i['disk'], $i['series']],
                    [$e['instance_id'], $e['instance_type'], $e['status_check_ok'], $e['cpu'], $e['memory'], $e['disk'], $e['series']], "{$code}.ec2.{$n}");
            }
            foreach ($s['rds'] as $n => $d) {
                $r = $a['rds'][$n];
                $this->assertSame([$d['identifier'], $d['engine'], $d['available'], $d['cpu'], $d['connections'], $d['max_connections'], $d['free_memory'], $d['free_storage']],
                    [$r['identifier'], $r['engine'], $r['available'], $r['cpu'], $r['connections'], $r['max_connections'], $r['free_memory'], $r['free_storage']], "{$code}.rds.{$n}");
                $this->assertEqualsWithDelta($d['connection_ratio'], $r['connection_ratio'], 0.001);
                $this->assertSame($d['series']['cpu'], $r['series']['cpu']);
            }
        }
        $this->assertEquals($this->sample->lastCheckedAt(), $this->repo->lastCheckedAt());
    }

    public function testIncidentsMatchSampleData(): void
    {
        $keys = ['id', 'system_code', 'system_name', 'resource', 'source', 'severity', 'status', 'title', 'assignee', 'root_cause', 'action_taken'];
        $pick = static fn (array $i): array => array_map(static fn (string $k): mixed => $i[$k], $keys);

        $this->assertSame(array_map($pick, $this->sample->incidents()), array_map($pick, $this->repo->incidents()));
        $this->assertSame(array_map($pick, $this->sample->incidents('open')), array_map($pick, $this->repo->incidents('open')));
        $this->assertSame(array_map($pick, $this->sample->incidents(systemCode: 'koi')), array_map($pick, $this->repo->incidents(systemCode: 'koi')));
        $this->assertSame([6, 5], array_column($this->repo->incidents(limit: 2), 'id'));

        $this->assertSame($this->sample->activeIncidentCount(), $this->repo->activeIncidentCount());
        $this->assertSame($this->sample->activeIncidentCount('koi'), $this->repo->activeIncidentCount('koi'));

        $expected = $this->sample->incident(5);
        $actual = $this->repo->incident(5);
        $this->assertEquals($expected['started_at'], $actual['started_at']);
        $this->assertEquals($expected['events'], $actual['events'], '타임라인: 유형, 시각, 작성자, 메시지');
        $this->assertNull($this->repo->incident(999));
    }

    public function testResourceWithoutMetricsShowsUnknown(): void
    {
        $client = new TestClient(['aws_resources' => true]);  // EC2·RDS 화면 표시
        $client->loginAs();
        $client->pdo()->exec(
            "INSERT INTO resources (system_id, type, name, identifier) SELECT id, 'ec2', 'miri-new-01', 'i-000000000000000ff' FROM systems WHERE code = 'miri';
             INSERT INTO resources (system_id, type, name, identifier) SELECT id, 'rds', 'miri-db-2', 'miri-new-db' FROM systems WHERE code = 'miri';
             INSERT INTO resources (system_id, type, name, identifier) SELECT id, 'http', '관리자', 'https://admin.miri.example.com' FROM systems WHERE code = 'miri';"
        );

        $system = (new DatabaseMonitoringRepository($client->pdo(...)))->system('miri');
        $this->assertSame('unknown', $system['ec2'][2]['status']);
        $this->assertNull($system['ec2'][2]['cpu']);
        $this->assertSame([], $system['ec2'][2]['series']['cpu']);
        $this->assertNull($system['rds'][1]['connection_ratio']);
        $this->assertNull($system['http'][2]['uptime_24h']);

        $response = $client->get('/systems/miri');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('miri-new-01', $response->body);
    }

    public function testResponseTimesAreBucketedWithinRange(): void
    {
        // 미리: 웹, API 두 개. 샘플 점검은 지우고 웹에만 넣는다
        $id = (int) $this->pdo->query("SELECT id FROM resources WHERE identifier = 'https://miri.example.com'")->fetchColumn();
        $this->pdo->exec("DELETE FROM check_results WHERE metric_name = 'http'");
        $insert = $this->pdo->prepare(
            "INSERT INTO check_results (resource_id, source, status, metric_name, response_ms, checked_at) VALUES (?, 'uptime_kuma', ?, 'http', ?, ?)"
        );
        foreach ([
            ['up', 100, '2026-11-04 09:58:00'],   // 범위 밖
            ['up', 200, '2026-11-04 10:01:00'],
            ['up', 400, '2026-11-04 10:09:59'],
            ['down', null, '2026-11-04 10:15:00'],
            ['warning', 1500, '2026-11-04 10:20:00'],
        ] as [$status, $ms, $at]) {
            $insert->execute([$id, $status, $ms, $at]);
        }

        $range = TimeRange::fromQuery('custom', '2026-11-04T10:00', '2026-11-05T13:00');
        $series = $this->repo->responseTimes('miri', $range);

        $this->assertSame(600, $range->bucketSeconds);
        $this->assertSame(['웹', 'API'], array_column($series, 'name'), '등록 순');
        $this->assertSame([], $series[1]['points'], '점검 없는 리소스도 계열은 남는다');
        $this->assertSame([
            ['t' => strtotime('2026-11-04 10:00:00'), 'avg' => 300, 'max' => 400, 'down' => 0, 'n' => 2],
            ['t' => strtotime('2026-11-04 10:10:00'), 'avg' => null, 'max' => null, 'down' => 1, 'n' => 1],
            ['t' => strtotime('2026-11-04 10:20:00'), 'avg' => 1500, 'max' => 1500, 'down' => 0, 'n' => 1],
        ], $series[0]['points'], '27시간 → 10분 묶음, down 은 응답 시간에서 빠짐');

        $this->assertSame([], $this->repo->responseTimes('nope', $range));
    }

    public function testInactiveSystemIsHidden(): void
    {
        $this->pdo->exec("UPDATE systems SET is_active = 0 WHERE code = 'koi'");

        $this->assertArrayNotHasKey('koi', $this->repo->systems());
        $this->assertNull($this->repo->system('koi'));
    }

    public function testSeederRefusesNonEmptyDatabase(): void
    {
        $this->expectException(RuntimeException::class);
        (new SampleSeeder($this->pdo))->run();
    }
}
