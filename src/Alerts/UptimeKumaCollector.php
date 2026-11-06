<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Monitoring\Status;
use Closure;
use PDO;
use RuntimeException;

/**
 * Uptime Kuma 의 Prometheus 지표(/metrics)를 주기적으로(cron 1분) 가져와 반영한다.
 *
 * 반영 규칙은 HttpStatusTracker (Webhook 과 같은 alert_key 라 장애가 중복되지 않음).
 * 연속 실패 판정(재시도 횟수)은 Uptime Kuma 모니터 설정을 따른다.
 */
final class UptimeKumaCollector
{
    /** Uptime Kuma monitor_status 값 */
    private const STATUS = [0 => Status::DOWN, 1 => Status::UP, 2 => Status::WARNING, 3 => Status::UNKNOWN];

    private readonly HttpStatusTracker $tracker;

    /**
     * @param Closure(): PDO $connection
     * @param Closure(): ?string $fetchMetrics /metrics 본문, 실패하면 null
     */
    public function __construct(
        Closure $connection,
        private readonly AlertIngestor $ingestor,
        private readonly Closure $fetchMetrics,
    ) {
        $this->tracker = new HttpStatusTracker($connection, $ingestor);
    }

    /**
     * @return array{monitors: int, matched: int, opened: int, resolved: int, unmatched: list<string>}
     * @throws RuntimeException 지표를 가져오지 못함
     */
    public function run(): array
    {
        $body = ($this->fetchMetrics)();
        if ($body === null) {
            throw new RuntimeException('Uptime Kuma /metrics 를 가져오지 못했습니다 (UPTIME_KUMA_URL, UPTIME_KUMA_API_KEY 확인).');
        }

        $summary = ['monitors' => 0, 'matched' => 0, 'opened' => 0, 'resolved' => 0, 'unmatched' => []];
        foreach (self::parse($body) as $monitor) {
            $summary['monitors']++;
            $resource = $this->ingestor->findResource('http', $monitor['id'], $monitor['url']);
            if ($resource === null) {
                $summary['unmatched'][] = "{$monitor['name']} ({$monitor['url']})";
                continue;
            }
            $summary['matched']++;

            $change = $this->apply($resource, $monitor);
            if ($change !== null) {
                $summary[$change]++;
            }
        }

        return $summary;
    }

    /**
     * @param array{id: int, system_id: int, name: string} $resource
     * @param array{status: ?int, response_ms: ?int, cert_days: ?int} $monitor
     * @return 'opened'|'resolved'|null
     */
    private function apply(array $resource, array $monitor): ?string
    {
        $status = self::STATUS[$monitor['status'] ?? -1] ?? Status::UNKNOWN;
        $responseMs = $status === Status::UP && $monitor['response_ms'] !== null && $monitor['response_ms'] >= 0 ? $monitor['response_ms'] : null;

        return $this->tracker->apply(
            $resource,
            UptimeKumaWebhook::SOURCE,
            $status,
            $responseMs,
            $monitor['cert_days'],
            'Uptime Kuma 상태 ' . strtoupper($status) . ($responseMs === null ? '' : " ({$responseMs}ms)"),
        );
    }

    /**
     * Prometheus 텍스트에서 모니터별 값을 모은다. HTTP 계열 모니터만 (URL 이 있는 것).
     *
     * @return list<array{id: ?string, name: string, url: string, status: ?int, response_ms: ?int, cert_days: ?int}>
     */
    public static function parse(string $body): array
    {
        $metrics = [
            'monitor_status' => 'status',
            'monitor_response_time' => 'response_ms',
            'monitor_cert_days_remaining' => 'cert_days',
        ];

        $monitors = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!preg_match('/^(\w+)\{(.*)\}\s+(\S+)/', $line, $m) || !isset($metrics[$m[1]])) {
                continue;
            }
            preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $m[2], $pairs, PREG_SET_ORDER);
            $labels = [];
            foreach ($pairs as [, $key, $value]) {
                $labels[$key] = stripcslashes($value);
            }

            $url = $labels['monitor_url'] ?? '';
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            // Uptime Kuma 2.x 부터 monitor_id 라벨이 있다. 1.x 는 이름 + URL 로 구분
            $key = $labels['monitor_id'] ?? ($labels['monitor_name'] ?? '') . "\0" . $url;
            $monitors[$key] ??= [
                'id' => $labels['monitor_id'] ?? null,
                'name' => $labels['monitor_name'] ?? '',
                'url' => $url,
                'status' => null,
                'response_ms' => null,
                'cert_days' => null,
            ];
            $monitors[$key][$metrics[$m[1]]] = is_numeric($m[3]) ? (int) round((float) $m[3]) : null;
        }

        return array_values($monitors);
    }
}
