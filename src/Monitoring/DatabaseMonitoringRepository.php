<?php

declare(strict_types=1);

namespace App\Monitoring;

use Closure;
use DateTimeImmutable;
use PDO;

/**
 * systems / resources / check_results / incidents 테이블에서 화면 데이터를 만든다.
 *
 * 리소스 상태는 수집기가 갱신한 resources.current_status 를 그대로 쓰고,
 * 지표는 check_results 의 metric_name 별 최신 값(추이는 최근 SERIES_POINTS 개)을 쓴다.
 * 인스턴스 타입, 엔진, max_connections 같은 고정 정보는 resources.config(JSON)에 둔다.
 */
final class DatabaseMonitoringRepository implements MonitoringRepository
{
    public const METRIC_HTTP = 'http';                  // status, response_ms
    public const METRIC_SSL_DAYS = 'ssl_days_left';     // 점검 시점 기준 남은 일수
    public const METRIC_CPU = 'CPUUtilization';
    public const METRIC_MEMORY = 'mem_used_percent';    // CloudWatch Agent
    public const METRIC_DISK = 'disk_used_percent';     // CloudWatch Agent
    public const METRIC_STATUS_CHECK = 'StatusCheckFailed';
    public const METRIC_CONNECTIONS = 'DatabaseConnections';
    public const METRIC_FREE_MEMORY = 'freeable_memory_percent';  // FreeableMemory / 인스턴스 메모리
    public const METRIC_FREE_STORAGE = 'free_storage_percent';    // FreeStorageSpace / 할당 스토리지

    /** 추이 그래프 점 개수 (5분 간격 = 2시간) */
    public const SERIES_POINTS = 24;

    private const INCIDENT_SELECT = 'SELECT i.id, i.source, i.severity, i.status, i.title, i.started_at, i.acknowledged_at,
            i.resolved_at, i.root_cause, i.action_taken, s.code AS system_code, s.name AS system_name,
            r.type AS resource_type, r.name AS resource_name, r.identifier AS resource_identifier,
            i.assignee_user_id, u.name AS assignee
        FROM incidents i
        JOIN systems s ON s.id = i.system_id
        LEFT JOIN resources r ON r.id = i.resource_id
        LEFT JOIN users u ON u.id = i.assignee_user_id';

    /**
     * @param Closure(): PDO $connection 처음 사용할 때 연결한다
     */
    /** @var array<string, array<string, mixed>> systems() 를 읽을 때 채운다 */
    private array $domainOverrides = [];

    public function __construct(private readonly Closure $connection)
    {
    }

    public function systems(): array
    {
        $this->domainOverrides = DomainInfo::overrides(($this->connection)());
        $resources = [];
        foreach ($this->query(
            'SELECT r.id, r.system_id, r.type, r.name, r.identifier, r.config, r.current_status, r.last_checked_at,
                    r.resolved_ips, r.resolved_at, r.domain_info
             FROM resources r
             WHERE r.is_active = 1
             ORDER BY r.system_id, r.id',
        ) as $row) {
            $resources[$row['system_id']][$row['type']][] = $this->buildResource($row);
        }

        $systems = [];
        foreach ($this->query(
            "SELECT s.id, s.code, s.name, s.short_name, s.environment, s.description, s.cloud_provider,
                    (SELECT GROUP_CONCAT(name, ', ') FROM (
                        SELECT u.name FROM system_owners so JOIN users u ON u.id = so.user_id
                        WHERE so.system_id = s.id ORDER BY u.name)) AS owner,
                    a.account_id AS aws_account, a.alias AS aws_alias, a.default_region AS region, c.id AS company_id, c.name AS company_name
             FROM systems s
             LEFT JOIN aws_accounts a ON a.id = s.aws_account_id
             -- 서비스에 직접 지정한 관리회사, 없으면 AWS 계정의 관리회사
             LEFT JOIN companies c ON c.id = COALESCE(s.company_id, CASE WHEN s.cloud_provider = 'aws' THEN a.company_id END)
             WHERE s.is_active = 1
             ORDER BY s.id",
        ) as $row) {
            $systems[$row['code']] = $this->buildSystem($row, $resources[$row['id']] ?? []);
        }

        return $systems;
    }

    public function system(string $code): ?array
    {
        return $this->systems()[$code] ?? null;
    }

    public function incidents(?string $status = null, ?string $systemCode = null, ?int $limit = null, bool $hideDismissed = false): array
    {
        $where = $hideDismissed ? ['i.dismissed_at IS NULL'] : [];
        $params = [];
        if ($status !== null) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($systemCode !== null) {
            $where[] = 's.code = ?';
            $params[] = $systemCode;
        }

        $sql = self::INCIDENT_SELECT
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY i.started_at DESC, i.id DESC'
            . ($limit === null ? '' : ' LIMIT ' . max(0, $limit));

        return array_map($this->buildIncident(...), $this->query($sql, $params));
    }

    public function incident(int $id): ?array
    {
        $row = $this->query(self::INCIDENT_SELECT . ' WHERE i.id = ?', [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }

        $incident = $this->buildIncident($row);
        $incident['events'] = array_map(static fn (array $ev): array => [
            'type' => $ev['type'],
            'at' => self::time($ev['created_at']),
            'user' => $ev['user'],
            'message' => $ev['message'],
        ], $this->query(
            'SELECT e.type, e.message, e.created_at, u.name AS user
             FROM incident_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.incident_id = ?
             ORDER BY e.created_at, e.id',
            [$id],
        ));

        return $incident;
    }

    public function activeIncidentCount(?string $systemCode = null): int
    {
        $sql = "SELECT COUNT(*) AS n FROM incidents i JOIN systems s ON s.id = i.system_id WHERE i.status <> 'resolved'";

        return $systemCode === null
            ? (int) $this->query($sql)[0]['n']
            : (int) $this->query($sql . ' AND s.code = ?', [$systemCode])[0]['n'];
    }

    public function lastCheckedAt(): ?DateTimeImmutable
    {
        return self::time($this->query(
            'SELECT MAX(r.last_checked_at) AS t FROM resources r JOIN systems s ON s.id = r.system_id
             WHERE r.is_active = 1 AND s.is_active = 1',
        )[0]['t']);
    }

    /**
     * 장애 화면에 표시하는 리소스 이름. SampleSeeder 도 이 형식으로 리소스를 찾는다.
     */
    public static function resourceLabel(string $type, string $name, string $identifier): string
    {
        return match ($type) {
            'ec2' => "EC2 {$name} ({$identifier})",
            'rds' => "RDS {$name} ({$identifier})",
            default => "{$name} ({$identifier})",
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<array<string, mixed>>> $resources type => items
     * @return array<string, mixed>
     */
    private function buildSystem(array $row, array $resources): array
    {
        $http = $resources['http'] ?? [];
        $ec2 = $resources['ec2'] ?? [];
        $rds = $resources['rds'] ?? [];
        $groupStatus = static fn (array $items): string => Status::worst(array_column($items, 'status'));
        $checkedAt = array_filter(array_column([...$http, ...$ec2, ...$rds], 'last_checked_at'));

        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'short_name' => $row['short_name'],
            'environment' => $row['environment'],
            'owner' => $row['owner'] ?? '미지정',
            'description' => $row['description'] ?? '',
            'cloud' => $row['cloud_provider'],
            'aws_account' => $row['aws_account'] ?? '-',
            'aws_alias' => $row['aws_alias'],
            'company_id' => $row['company_id'] === null ? null : (int) $row['company_id'],
            'company_name' => $row['company_name'],
            'region' => $row['region'] ?? '-',
            'http' => $http,
            'ec2' => $ec2,
            'rds' => $rds,
            'http_status' => $groupStatus($http),
            'ec2_status' => $groupStatus($ec2),
            'rds_status' => $groupStatus($rds),
            'status' => Status::worst([$groupStatus($http), $groupStatus($ec2), $groupStatus($rds)]),
            'last_checked_at' => $checkedAt === [] ? null : max($checkedAt),
        ];
    }

    /**
     * @param array<string, mixed> $row resources 행
     * @return array<string, mixed>
     */
    private function buildResource(array $row): array
    {
        $id = (int) $row['id'];
        $config = $row['config'] === null ? [] : (array) json_decode($row['config'], true);
        $base = [
            'name' => $row['name'],
            'status' => $row['current_status'],
            'last_checked_at' => self::time($row['last_checked_at']),
        ];

        $host = (string) parse_url((string) $row['identifier'], PHP_URL_HOST);
        $domainInfo = $row['domain_info'] === null ? null : (array) json_decode((string) $row['domain_info'], true);
        $domain = DomainInfo::domainOf($host, $domainInfo);

        return match ($row['type']) {
            'http' => $base + $this->httpMetrics($id) + [
                'url' => $row['identifier'],
                'kind' => SystemRegistry::httpKind($config, (string) $row['identifier']),
                // DomainInspector 가 cron 에서 갱신
                'host' => $host,
                'resolved_ips' => $row['resolved_ips'],
                'resolved_at' => $row['resolved_at'],
                // 관리자가 직접 입력한 값(domain_overrides)이 있으면 그 값을 쓴다
                'domain_info' => DomainInfo::merge($domainInfo, $domain === null ? null : $this->domainOverrides[$domain] ?? null, $domain),
            ],
            'ec2' => $base + [
                'instance_id' => $row['identifier'],
                'instance_type' => $config['instance_type'] ?? '-',
                'status_check_ok' => ($failed = $this->latest($id, self::METRIC_STATUS_CHECK)) === null
                    ? null
                    : (float) $failed['metric_value'] === 0.0,
                'cpu' => $this->latestValue($id, self::METRIC_CPU),
                'memory' => $this->latestValue($id, self::METRIC_MEMORY),
                'disk' => $this->latestValue($id, self::METRIC_DISK),
                'series' => [
                    'cpu' => $this->series($id, self::METRIC_CPU),
                    'memory' => $this->series($id, self::METRIC_MEMORY),
                ],
            ],
            'rds' => $base + $this->rdsMetrics($id, $row['identifier'], $config, $row['current_status']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function httpMetrics(int $id): array
    {
        $check = $this->latest($id, self::METRIC_HTTP);
        $ssl = $this->latest($id, self::METRIC_SSL_DAYS);
        $sslCheckedAt = self::time($ssl['checked_at'] ?? null);
        $sslDays = $ssl === null ? 0 : (int) $ssl['metric_value'];
        $uptime = $this->query(
            "SELECT AVG(status = 'up') * 100 AS uptime FROM check_results
             WHERE resource_id = ? AND metric_name = ? AND checked_at >= ?",
            [$id, self::METRIC_HTTP, date('Y-m-d H:i:s', time() - 86400)],
        )[0]['uptime'];

        return [
            'up' => $check === null ? null : $check['status'] !== Status::DOWN,
            'response_ms' => $check['response_ms'] ?? null,
            'uptime_24h' => $uptime === null ? null : (float) $uptime,
            'ssl_expires_at' => $sslCheckedAt?->modify("+{$sslDays} days"),
            // 점검 시점의 남은 일수에서 그 뒤로 지난 날수를 뺀다
            'ssl_days_left' => $sslCheckedAt === null ? null : $sslDays - $sslCheckedAt->diff(new DateTimeImmutable())->days,
        ];
    }

    public function responseTimes(string $systemCode, TimeRange $range): array
    {
        $series = [];
        foreach ($this->query(
            "SELECT r.id, r.name, r.identifier FROM resources r JOIN systems s ON s.id = r.system_id
             WHERE s.code = ? AND r.type = 'http' AND r.is_active = 1
             ORDER BY r.id",
            [$systemCode],
        ) as $row) {
            $series[(int) $row['id']] = ['name' => $row['name'], 'url' => $row['identifier'], 'points' => []];
        }
        if ($series === []) {
            return [];
        }

        // checked_at 은 현지 시각 텍스트라 strftime('%s') 가 UTC 로 읽는다. 묶음도 현지 시각 기준(정시·자정)으로
        // 나뉘고, datetime(..., 'unixepoch') 로 다시 현지 시각 텍스트가 된다.
        $bucket = $range->bucketSeconds;
        $rows = $this->query(
            "SELECT resource_id,
                    datetime(CAST(strftime('%s', checked_at) AS INTEGER) / {$bucket} * {$bucket}, 'unixepoch') AS bucket_at,
                    AVG(CASE WHEN status <> 'down' THEN response_ms END) AS avg_ms,
                    MAX(CASE WHEN status <> 'down' THEN response_ms END) AS max_ms,
                    SUM(status = 'down') AS downs,
                    COUNT(*) AS checks
             FROM check_results
             WHERE resource_id IN (" . implode(',', array_keys($series)) . ") AND metric_name = ?
               AND checked_at >= ? AND checked_at <= ?
             GROUP BY resource_id, bucket_at
             ORDER BY resource_id, bucket_at",
            [self::METRIC_HTTP, $range->from->format('Y-m-d H:i:s'), $range->to->format('Y-m-d H:i:s')],
        );
        foreach ($rows as $row) {
            $series[(int) $row['resource_id']]['points'][] = [
                't' => (int) strtotime((string) $row['bucket_at']),
                'avg' => $row['avg_ms'] === null ? null : (int) round((float) $row['avg_ms']),
                'max' => $row['max_ms'] === null ? null : (int) $row['max_ms'],
                'down' => (int) $row['downs'],
                'n' => (int) $row['checks'],
            ];
        }

        return array_values($series);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function rdsMetrics(int $id, string $identifier, array $config, string $status): array
    {
        $connections = $this->latestValue($id, self::METRIC_CONNECTIONS);
        $maxConnections = (int) ($config['max_connections'] ?? 0);

        return [
            'identifier' => $identifier,
            'engine' => $config['engine'] ?? '-',
            'available' => $status !== Status::DOWN,
            'cpu' => $this->latestValue($id, self::METRIC_CPU),
            'connections' => $connections === null ? null : (int) $connections,
            'max_connections' => $maxConnections,
            'connection_ratio' => $connections === null
                ? null
                : Thresholds::connectionRatio(['connections' => (int) $connections, 'max_connections' => $maxConnections]),
            'free_memory' => $this->latestValue($id, self::METRIC_FREE_MEMORY),
            'free_storage' => $this->latestValue($id, self::METRIC_FREE_STORAGE),
            'series' => [
                'cpu' => $this->series($id, self::METRIC_CPU),
                'connections' => $this->series($id, self::METRIC_CONNECTIONS),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latest(int $resourceId, string $metric): ?array
    {
        return $this->recent($resourceId, $metric, 1)[0] ?? null;
    }

    private function latestValue(int $resourceId, string $metric): ?float
    {
        $value = $this->latest($resourceId, $metric)['metric_value'] ?? null;

        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * @return list<float> 오래된 순
     */
    private function series(int $resourceId, string $metric): array
    {
        return array_reverse(array_map(
            static fn (array $r): float => round((float) $r['metric_value'], 1),
            array_filter($this->recent($resourceId, $metric, self::SERIES_POINTS), static fn (array $r): bool => $r['metric_value'] !== null),
        ));
    }

    /**
     * @return list<array<string, mixed>> 최근 순
     */
    private function recent(int $resourceId, string $metric, int $limit): array
    {
        return $this->query(
            'SELECT status, metric_value, response_ms, checked_at FROM check_results
             WHERE resource_id = ? AND metric_name = ?
             ORDER BY checked_at DESC, id DESC
             LIMIT ' . $limit,
            [$resourceId, $metric],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildIncident(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'system_code' => $row['system_code'],
            'system_name' => $row['system_name'],
            'resource' => $row['resource_type'] === null
                ? '-'
                : self::resourceLabel($row['resource_type'], $row['resource_name'], $row['resource_identifier']),
            'source' => $row['source'],
            'severity' => $row['severity'],
            'status' => $row['status'],
            'title' => $row['title'],
            'assignee_id' => $row['assignee_user_id'] === null ? null : (int) $row['assignee_user_id'],
            'assignee' => $row['assignee'],
            'started_at' => self::time($row['started_at']),
            'acknowledged_at' => self::time($row['acknowledged_at']),
            'resolved_at' => self::time($row['resolved_at']),
            'root_cause' => $row['root_cause'],
            'action_taken' => $row['action_taken'],
        ];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        $statement = ($this->connection)()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
