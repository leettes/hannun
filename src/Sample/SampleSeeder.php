<?php

declare(strict_types=1);

namespace App\Sample;

use App\Monitoring\DatabaseMonitoringRepository as Repo;
use App\Monitoring\Thresholds;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SampleData 를 빈 DB 에 적재한다 (개발·테스트용).
 * 담당자 계정은 로그인할 수 없는 비활성 계정으로 만든다.
 */
final class SampleSeeder
{
    /** HTTP 점검 이력: 5분 간격 24시간 */
    private const HTTP_CHECKS = 288;
    private const INTERVAL = 300;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SampleData $data = new SampleData(),
    ) {
    }

    /**
     * @return array{systems: int, resources: int, incidents: int}
     */
    public function run(): array
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM systems')->fetchColumn() > 0) {
            throw new RuntimeException('systems 테이블이 비어 있지 않아 샘플 데이터를 넣지 않습니다.');
        }

        $this->pdo->beginTransaction();
        try {
            $counts = $this->seed();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $counts;
    }

    /**
     * @return array{systems: int, resources: int, incidents: int}
     */
    private function seed(): array
    {
        $users = [];
        $userId = function (?string $name) use (&$users): ?int {
            if ($name === null) {
                return null;
            }

            return $users[$name] ??= $this->insert('users', [
                'email' => 'sample-user-' . (count($users) + 1) . '@example.invalid',
                'name' => $name,
                'password_hash' => '!',
                'role' => 'operator',
                'is_active' => 0,
            ]);
        };

        $accounts = [];
        $resources = [];
        $resourceCount = 0;
        foreach ($this->data->systems() as $s) {
            $accounts[$s['aws_account']] ??= $this->insert('aws_accounts', [
                'account_id' => $s['aws_account'],
                'alias' => 'sample-' . $s['aws_account'],
                'default_region' => $s['region'],
            ]);
            $systemId = $this->insert('systems', [
                'code' => $s['code'],
                'name' => $s['name'],
                'environment' => $s['environment'],
                'aws_account_id' => $accounts[$s['aws_account']],
                'description' => $s['description'],
            ]);
            $this->insert('system_owners', ['system_id' => $systemId, 'user_id' => $userId($s['owner'])]);
            $resources[$s['code']] = [];

            foreach ($s['http'] as $m) {
                $id = $this->resource($systemId, 'http', $m['name'], $m['url'], null, $m);
                $this->httpChecks($id, $m);
                $resources[$s['code']][Repo::resourceLabel('http', $m['name'], $m['url'])] = $id;
            }
            foreach ($s['ec2'] as $i) {
                $id = $this->resource($systemId, 'ec2', $i['name'], $i['instance_id'], ['instance_type' => $i['instance_type']], $i);
                $this->series($id, Repo::METRIC_CPU, $i['series']['cpu'], $i);
                $this->series($id, Repo::METRIC_MEMORY, $i['series']['memory'], $i);
                $this->metric($id, Repo::METRIC_DISK, $i['disk'], $i);
                $this->metric($id, Repo::METRIC_STATUS_CHECK, $i['status_check_ok'] ? 0 : 1, $i);
                $resources[$s['code']][Repo::resourceLabel('ec2', $i['name'], $i['instance_id'])] = $id;
            }
            foreach ($s['rds'] as $d) {
                $id = $this->resource($systemId, 'rds', $d['name'], $d['identifier'], [
                    'engine' => $d['engine'],
                    'max_connections' => $d['max_connections'],
                ], $d);
                $this->series($id, Repo::METRIC_CPU, $d['series']['cpu'], $d);
                $this->series($id, Repo::METRIC_CONNECTIONS, $d['series']['connections'], $d);
                $this->metric($id, Repo::METRIC_FREE_MEMORY, $d['free_memory'], $d);
                $this->metric($id, Repo::METRIC_FREE_STORAGE, $d['free_storage'], $d);
                $resources[$s['code']][Repo::resourceLabel('rds', $d['name'], $d['identifier'])] = $id;
            }
            $resourceCount += count($resources[$s['code']]);
        }

        $systemIds = $this->pdo->query('SELECT code, id FROM systems')->fetchAll(PDO::FETCH_KEY_PAIR);
        $incidents = $this->data->incidents();
        // 오래된 장애부터 넣어 id 순서가 발생 순서와 같게 한다
        foreach (array_reverse($incidents) as $i) {
            $incidentId = $this->insert('incidents', [
                'id' => $i['id'],
                'system_id' => $systemIds[$i['system_code']],
                'resource_id' => $resources[$i['system_code']][$i['resource']] ?? null,
                'source' => $i['source'],
                'alert_key' => $i['source'] . ':sample-' . $i['id'],
                'severity' => $i['severity'],
                'status' => $i['status'],
                'title' => $i['title'],
                'assignee_user_id' => $userId($i['assignee']),
                'started_at' => self::fmt($i['started_at']),
                'acknowledged_at' => self::fmt($i['acknowledged_at']),
                'resolved_at' => self::fmt($i['resolved_at']),
                'root_cause' => $i['root_cause'],
                'action_taken' => $i['action_taken'],
            ]);
            foreach ($i['events'] as $ev) {
                $this->insert('incident_events', [
                    'incident_id' => $incidentId,
                    'type' => $ev['type'],
                    'user_id' => $userId($ev['user']),
                    'message' => $ev['message'],
                    'created_at' => self::fmt($ev['at']),
                ]);
            }
        }

        return ['systems' => count($systemIds), 'resources' => $resourceCount, 'incidents' => count($incidents)];
    }

    /**
     * @param array<string, mixed>|null $config
     * @param array{status: string, last_checked_at: DateTimeImmutable} $item
     */
    private function resource(int $systemId, string $type, string $name, string $identifier, ?array $config, array $item): int
    {
        return $this->insert('resources', [
            'system_id' => $systemId,
            'type' => $type,
            'name' => $name,
            'identifier' => $identifier,
            'config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
            'current_status' => $item['status'],
            'last_checked_at' => self::fmt($item['last_checked_at']),
        ]);
    }

    /**
     * 24시간 가용률이 샘플 값에 가깝도록 실패 횟수를 맞추고, 실패는 가장 최근 점검에 몰아 둔다.
     *
     * @param array<string, mixed> $m
     */
    private function httpChecks(int $resourceId, array $m): void
    {
        $failures = (int) round((100 - $m['uptime_24h']) / 100 * self::HTTP_CHECKS);
        if (!$m['up']) {
            $failures = max(1, $failures);
        }

        for ($n = self::HTTP_CHECKS - 1; $n >= 0; $n--) {
            // $n: 최근에서 몇 번째 전 점검인지 (0 = 마지막)
            $down = $m['up'] ? ($n >= 1 && $n <= $failures) : $n < $failures;
            $this->check($resourceId, Repo::METRIC_HTTP, $down ? 'down' : 'up', null, $down ? null : $m['response_ms'],
                $m['last_checked_at']->modify('-' . ($n * self::INTERVAL) . ' seconds'));
        }

        if ($m['ssl_days_left'] !== null) {
            $status = $m['ssl_days_left'] < Thresholds::SSL_WARN_DAYS ? 'warning' : 'up';
            $this->check($resourceId, Repo::METRIC_SSL_DAYS, $status, $m['ssl_days_left'], null, $m['last_checked_at']);
        }
    }

    /**
     * @param list<float> $values 오래된 순, 마지막 값이 현재 값
     * @param array{status: string, last_checked_at: DateTimeImmutable} $item
     */
    private function series(int $resourceId, string $metric, array $values, array $item): void
    {
        $last = count($values) - 1;
        foreach ($values as $n => $value) {
            $this->check($resourceId, $metric, $item['status'], $value, null,
                $item['last_checked_at']->modify('-' . (($last - $n) * self::INTERVAL) . ' seconds'));
        }
    }

    /**
     * @param array{status: string, last_checked_at: DateTimeImmutable} $item
     */
    private function metric(int $resourceId, string $metric, float|int $value, array $item): void
    {
        $this->check($resourceId, $metric, $item['status'], $value, null, $item['last_checked_at']);
    }

    private function check(int $resourceId, string $metric, string $status, float|int|null $value, ?int $responseMs, DateTimeImmutable $at): void
    {
        $this->insert('check_results', [
            'resource_id' => $resourceId,
            'source' => $metric === Repo::METRIC_HTTP || $metric === Repo::METRIC_SSL_DAYS ? 'uptime_kuma' : 'cloudwatch',
            'status' => $status,
            'metric_name' => $metric,
            'metric_value' => $value,
            'response_ms' => $responseMs,
            'checked_at' => self::fmt($at),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($row)),
            implode(', ', array_fill(0, count($row), '?')),
        );
        $this->pdo->prepare($sql)->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    private static function fmt(?DateTimeImmutable $time): ?string
    {
        return $time?->format('Y-m-d H:i:s');
    }
}
