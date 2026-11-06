<?php

declare(strict_types=1);

namespace App\Sample;

use App\Monitoring\MonitoringRepository;
use App\Monitoring\Status;
use App\Monitoring\Thresholds;
use App\Monitoring\TimeRange;
use DateTimeImmutable;

/**
 * 화면 구성용 샘플 데이터. 개발 DB 는 SampleSeeder 로 이 데이터를 적재해 쓴다.
 */
final class SampleData implements MonitoringRepository
{
    private readonly DateTimeImmutable $now;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $systems = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $incidents = null;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, array<string, mixed>> code => system
     */
    public function systems(): array
    {
        if ($this->systems !== null) {
            return $this->systems;
        }

        $systems = [];
        foreach ($this->rawSystems() as $raw) {
            $systems[$raw['code']] = $this->buildSystem($raw);
        }

        return $this->systems = $systems;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function system(string $code): ?array
    {
        return $this->systems()[$code] ?? null;
    }

    /**
     * @return list<array<string, mixed>> 최근 발생 순
     */
    public function incidents(?string $status = null, ?string $systemCode = null, ?int $limit = null, bool $hideDismissed = false): array
    {
        $this->incidents ??= $this->buildIncidents();

        return array_slice(array_values(array_filter(
            $this->incidents,
            static fn (array $i): bool => ($status === null || $i['status'] === $status)
                && ($systemCode === null || $i['system_code'] === $systemCode),
        )), 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function incident(int $id): ?array
    {
        $this->incidents ??= $this->buildIncidents();

        return $this->incidents[$id] ?? null;
    }

    /**
     * 복구되지 않은(진행 중 + 확인됨) 장애 수
     */
    public function activeIncidentCount(?string $systemCode = null): int
    {
        return count(array_filter(
            $this->incidents(systemCode: $systemCode),
            static fn (array $i): bool => $i['status'] !== 'resolved',
        ));
    }

    public function responseTimes(string $systemCode, TimeRange $range): array
    {
        return [];
    }

    public function lastCheckedAt(): ?DateTimeImmutable
    {
        $times = array_column($this->systems(), 'last_checked_at');

        return $times === [] ? null : max($times);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function buildSystem(array $raw): array
    {
        $http = array_map(function (array $m): array {
            $m['ssl_days_left'] = $m['ssl_expires_at'] === null
                ? null
                : (int) $this->now->diff($m['ssl_expires_at'])->format('%r%a');
            $m['status'] = Thresholds::http($m);

            return $m;
        }, $raw['http']);

        $ec2 = array_map(function (array $i): array {
            $i['status'] = Thresholds::ec2($i);
            $i['series'] = [
                'cpu' => $this->series($i['cpu'], 8, $i['seed']),
                'memory' => $this->series($i['memory'], 3, $i['seed'] + 1),
            ];

            return $i;
        }, $raw['ec2']);

        $rds = array_map(function (array $d): array {
            $d['status'] = Thresholds::rds($d);
            $d['connection_ratio'] = Thresholds::connectionRatio($d);
            $d['series'] = [
                'cpu' => $this->series($d['cpu'], 10, $d['seed']),
                'connections' => $this->series($d['connections'], $d['connections'] * 0.2, $d['seed'] + 2),
            ];

            return $d;
        }, $raw['rds']);

        $groupStatus = static fn (array $items): string => Status::worst(array_column($items, 'status'));

        $checkedAt = [];
        foreach ([$http, $ec2, $rds] as $items) {
            foreach ($items as $item) {
                $checkedAt[] = $item['last_checked_at'];
            }
        }

        return [
            'code' => $raw['code'],
            'name' => $raw['name'],
            'environment' => $raw['environment'],
            'owner' => $raw['owner'],
            'description' => $raw['description'],
            'aws_account' => $raw['aws_account'],
            'region' => $raw['region'],
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
     * 최근 2시간(5분 간격 24개) 값. 마지막 값이 현재 값과 같도록 만든다.
     *
     * @return list<float>
     */
    private function series(float $current, float $amplitude, int $seed): array
    {
        $wave = static fn (int $i): float => sin(($i + $seed) / 2.3) + 0.4 * sin(($i + $seed) * 1.7);
        $values = [];
        for ($i = 0; $i < 24; $i++) {
            $values[] = round(max(0, $current + $amplitude * ($wave($i) - $wave(23))), 1);
        }

        return $values;
    }

    private function ago(int $seconds): DateTimeImmutable
    {
        return $this->now->modify("-{$seconds} seconds");
    }

    private function in(int $days): DateTimeImmutable
    {
        return $this->now->modify("+{$days} days");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawSystems(): array
    {
        return [
            [
                'code' => 'miri',
                'name' => '미리',
                'environment' => 'prod',
                'owner' => '담당자 1',
                'description' => '미리 서비스 운영 환경',
                'aws_account' => '111111111111',
                'region' => 'ap-northeast-2',
                'http' => [
                    $this->http('웹', 'https://miri.example.com', 412, 100.0, 182, 14),
                    $this->http('API', 'https://api.miri.example.com/health', 138, 99.98, 182, 14),
                ],
                'ec2' => [
                    $this->ec2('miri-web-01', 'i-0a1b2c3d4e5f60001', 't3.medium', 34.2, 61.5, 48.0, 1),
                    $this->ec2('miri-web-02', 'i-0a1b2c3d4e5f60002', 't3.medium', 29.8, 58.1, 47.3, 4),
                ],
                'rds' => [
                    $this->rds('miri-db', 'miri-prod-mysql', 'MySQL 8.0', 22.4, 41, 300, 38.0, 64.0, 2),
                ],
            ],
            [
                'code' => 'koi',
                'name' => '코이',
                'environment' => 'prod',
                'owner' => '담당자 2',
                'description' => '코이 서비스 운영 환경',
                'aws_account' => '222222222222',
                'region' => 'ap-northeast-2',
                'http' => [
                    $this->http('웹', 'https://koi.example.com', 655, 100.0, 96, 21),
                    $this->http('API', 'https://api.koi.example.com/health', 201, 100.0, 96, 21),
                ],
                'ec2' => [
                    $this->ec2('koi-app-01', 'i-0b1c2d3e4f5a60011', 't3.large', 46.7, 72.3, 84.2, 7),
                ],
                'rds' => [
                    $this->rds('koi-db', 'koi-prod-mysql', 'MySQL 8.0', 31.0, 88, 400, 27.5, 41.0, 5),
                ],
            ],
            [
                'code' => 'sys-c',
                'name' => '시스템 C',
                'environment' => 'prod',
                'owner' => '담당자 3',
                'description' => '샘플 시스템 (실제 시스템명으로 교체 예정)',
                'aws_account' => '111111111111',
                'region' => 'ap-northeast-2',
                'http' => [
                    $this->http('웹', 'https://c.example.com', 380, 99.91, 240, 9),
                    $this->http('API', 'https://api.c.example.com/health', null, 97.42, 240, 9, false),
                ],
                'ec2' => [
                    $this->ec2('c-api-01', 'i-0c1d2e3f4a5b60021', 't3.medium', 71.5, 79.2, 55.4, 3),
                ],
                'rds' => [
                    $this->rds('c-db', 'c-prod-postgres', 'PostgreSQL 16', 48.3, 120, 200, 22.0, 58.0, 8),
                ],
            ],
            [
                'code' => 'sys-d',
                'name' => '시스템 D',
                'environment' => 'prod',
                'owner' => '담당자 1',
                'description' => '샘플 시스템 (실제 시스템명으로 교체 예정)',
                'aws_account' => '333333333333',
                'region' => 'ap-northeast-2',
                'http' => [
                    $this->http('웹', 'https://d.example.com', 290, 100.0, 300, 33),
                ],
                'ec2' => [
                    $this->ec2('d-web-01', 'i-0d1e2f3a4b5c60031', 't3.small', 18.9, 52.0, 37.6, 6),
                ],
                'rds' => [
                    $this->rds('d-db', 'd-prod-mysql', 'MySQL 8.0', 26.1, 23, 150, 45.0, 72.0, 9),
                ],
            ],
            [
                'code' => 'sys-e',
                'name' => '시스템 E',
                'environment' => 'prod',
                'owner' => '담당자 2',
                'description' => '샘플 시스템 (실제 시스템명으로 교체 예정)',
                'aws_account' => '333333333333',
                'region' => 'ap-northeast-2',
                'http' => [
                    $this->http('웹', 'https://e.example.com', 520, 100.0, 13, 27),
                    $this->http('API', 'https://api.e.example.com/health', 176, 100.0, 13, 27),
                ],
                'ec2' => [
                    $this->ec2('e-app-01', 'i-0e1f2a3b4c5d60041', 't3.medium', 24.4, 66.8, 51.2, 2),
                ],
                'rds' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function http(
        string $name,
        string $url,
        ?int $responseMs,
        float $uptime24h,
        int $sslDays,
        int $checkedSecondsAgo,
        bool $up = true,
    ): array {
        return [
            'name' => $name,
            'url' => $url,
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'resolved_ips' => null,
            'resolved_at' => null,
            'domain_info' => null,
            'up' => $up,
            'response_ms' => $responseMs,
            'uptime_24h' => $uptime24h,
            'ssl_expires_at' => $this->in($sslDays),
            'last_checked_at' => $this->ago($checkedSecondsAgo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ec2(
        string $name,
        string $instanceId,
        string $type,
        float $cpu,
        float $memory,
        float $disk,
        int $seed,
    ): array {
        return [
            'name' => $name,
            'instance_id' => $instanceId,
            'instance_type' => $type,
            'status_check_ok' => true,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'seed' => $seed,
            'last_checked_at' => $this->ago(60 + $seed * 7),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rds(
        string $name,
        string $identifier,
        string $engine,
        float $cpu,
        int $connections,
        int $maxConnections,
        float $freeMemory,
        float $freeStorage,
        int $seed,
    ): array {
        return [
            'name' => $name,
            'identifier' => $identifier,
            'engine' => $engine,
            'available' => true,
            'cpu' => $cpu,
            'connections' => $connections,
            'max_connections' => $maxConnections,
            'free_memory' => $freeMemory,
            'free_storage' => $freeStorage,
            'seed' => $seed,
            'last_checked_at' => $this->ago(60 + $seed * 5),
        ];
    }

    /**
     * @return array<int, array<string, mixed>> id => incident, 최근 발생 순
     */
    private function buildIncidents(): array
    {
        $systems = $this->systems();
        $hour = 3600;
        $day = 86400;

        $rows = [
            [
                'id' => 6, 'system_code' => 'sys-c', 'resource' => 'API (https://api.c.example.com/health)',
                'source' => 'uptime_kuma', 'severity' => 'critical', 'status' => 'open',
                'title' => 'API 헬스체크 실패 (HTTP 502)', 'assignee' => null,
                'started_at' => $this->ago(18 * 60), 'acknowledged_at' => null, 'resolved_at' => null,
                'root_cause' => null, 'action_taken' => null,
                'events' => [
                    ['opened', 18 * 60, null, '연속 3회 실패로 장애 판정 (HTTP 502 Bad Gateway)'],
                    ['alert', 13 * 60, null, '장애 지속 알림 발송 (이메일)'],
                    ['alert', 3 * 60, null, '장애 지속 알림 발송 (이메일)'],
                ],
            ],
            [
                'id' => 5, 'system_code' => 'koi', 'resource' => 'EC2 koi-app-01 (i-0b1c2d3e4f5a60011)',
                'source' => 'cloudwatch', 'severity' => 'minor', 'status' => 'acknowledged',
                'title' => '디스크 사용률 80% 초과 (84.2%)', 'assignee' => '담당자 2',
                'started_at' => $this->ago(2 * $hour + 600), 'acknowledged_at' => $this->ago(2 * $hour),
                'resolved_at' => null, 'root_cause' => null, 'action_taken' => null,
                'events' => [
                    ['opened', 2 * $hour + 600, null, 'CloudWatch 알람 ALARM: koi-app-01-disk-used'],
                    ['acknowledged', 2 * $hour, '담당자 2', '확인했습니다. 로그 정리 예정'],
                    ['note', $hour, '담당자 2', '/var/log 내 오래된 애플리케이션 로그 확인 중'],
                ],
            ],
            [
                'id' => 4, 'system_code' => 'sys-e', 'resource' => '웹 (https://e.example.com)',
                'source' => 'uptime_kuma', 'severity' => 'info', 'status' => 'open',
                'title' => 'SSL 인증서 만료 30일 이내 (13일 남음)', 'assignee' => null,
                'started_at' => $this->ago($day + 3 * $hour), 'acknowledged_at' => null, 'resolved_at' => null,
                'root_cause' => null, 'action_taken' => null,
                'events' => [
                    ['opened', $day + 3 * $hour, null, '인증서 만료 예정 알림'],
                ],
            ],
            [
                'id' => 3, 'system_code' => 'miri', 'resource' => '웹 (https://miri.example.com)',
                'source' => 'uptime_kuma', 'severity' => 'major', 'status' => 'resolved',
                'title' => '웹 응답 지연 (2초 이상)', 'assignee' => '담당자 1',
                'started_at' => $this->ago($day + 6 * $hour), 'acknowledged_at' => $this->ago($day + 6 * $hour - 300),
                'resolved_at' => $this->ago($day + 6 * $hour - 23 * 60),
                'root_cause' => '정적 파일 캐시 설정 누락으로 원본 서버 부하 증가',
                'action_taken' => '캐시 설정 복구 후 응답 시간 정상화 확인',
                'events' => [
                    ['opened', $day + 6 * $hour, null, '응답 시간 4,820ms (기준 3,000ms)'],
                    ['acknowledged', $day + 6 * $hour - 300, '담당자 1', null],
                    ['resolved', $day + 6 * $hour - 23 * 60, null, '응답 시간 410ms, 복구 알림 발송'],
                ],
            ],
            [
                'id' => 2, 'system_code' => 'sys-d', 'resource' => 'RDS d-db (d-prod-mysql)',
                'source' => 'cloudwatch', 'severity' => 'major', 'status' => 'resolved',
                'title' => 'RDS CPU 80% 초과 (92%)', 'assignee' => '담당자 1',
                'started_at' => $this->ago(3 * $day + 2 * $hour), 'acknowledged_at' => $this->ago(3 * $day + 2 * $hour - 600),
                'resolved_at' => $this->ago(3 * $day + 2 * $hour - 41 * 60),
                'root_cause' => '신규 배치 쿼리의 인덱스 미사용',
                'action_taken' => '인덱스 추가 및 배치 실행 시간 조정',
                'events' => [
                    ['opened', 3 * $day + 2 * $hour, null, 'CloudWatch 알람 ALARM: d-db-cpu-high'],
                    ['acknowledged', 3 * $day + 2 * $hour - 600, '담당자 1', null],
                    ['note', 3 * $day + 2 * $hour - 25 * 60, '담당자 1', '슬로우 쿼리 로그에서 원인 쿼리 확인'],
                    ['resolved', 3 * $day + 2 * $hour - 41 * 60, null, 'CloudWatch 알람 OK'],
                ],
            ],
            [
                'id' => 1, 'system_code' => 'koi', 'resource' => 'EC2 koi-app-01 (i-0b1c2d3e4f5a60011)',
                'source' => 'cloudwatch', 'severity' => 'critical', 'status' => 'resolved',
                'title' => 'EC2 상태 검사 실패', 'assignee' => '담당자 2',
                'started_at' => $this->ago(6 * $day), 'acknowledged_at' => $this->ago(6 * $day - 240),
                'resolved_at' => $this->ago(6 * $day - 12 * 60),
                'root_cause' => '호스트 하드웨어 문제 (AWS 예약 이벤트)',
                'action_taken' => '인스턴스 중지 후 시작하여 호스트 이전',
                'events' => [
                    ['opened', 6 * $day, null, 'StatusCheckFailed_System = 1'],
                    ['acknowledged', 6 * $day - 240, '담당자 2', null],
                    ['resolved', 6 * $day - 12 * 60, null, '상태 검사 2/2 통과'],
                ],
            ],
        ];

        $incidents = [];
        foreach ($rows as $row) {
            $row['system_name'] = $systems[$row['system_code']]['name'] ?? $row['system_code'];
            $row['events'] = array_map(fn (array $ev): array => [
                'type' => $ev[0],
                'at' => $this->ago($ev[1]),
                'user' => $ev[2],
                'message' => $ev[3],
            ], $row['events']);
            $incidents[$row['id']] = $row;
        }

        return $incidents;
    }
}
