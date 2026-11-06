<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Monitoring\DatabaseMonitoringRepository as Repo;
use App\Monitoring\Status;
use App\Monitoring\Thresholds;
use Closure;
use PDO;

/**
 * 웹/API 점검 결과 한 건을 반영한다 (Uptime Kuma 수집, 대시보드 자체 점검 공통).
 *
 * - HTTP 점검 결과(상태, 응답 시간)를 check_results 에 남긴다 → 24시간 가용률·추이
 * - SSL 남은 일수는 하루 한 번 남긴다
 * - 상태가 장애로 바뀌면 장애를 열고, 장애에서 벗어나면 복구한다
 * - 장애가 아니면 응답 지연·인증서 만료 임박을 경고로 반영한다 (Thresholds::http)
 */
final class HttpStatusTracker
{
    private const SSL_RECORD_INTERVAL = 86400 - 3600;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly AlertIngestor $ingestor,
    ) {
    }

    public static function alertKey(string $source, int $resourceId): string
    {
        return "{$source}:http:{$resourceId}";
    }

    /**
     * @param array{id: int, system_id: int, name: string} $resource
     * @param string $status Status::UP / DOWN / WARNING(재시도 중 등) / UNKNOWN(점검 중지)
     * @param string $detail 장애 타임라인·점검 기록에 남길 설명 (예: 'HTTP 503')
     * @return 'opened'|'resolved'|null
     */
    public function apply(array $resource, string $source, string $status, ?int $responseMs, ?int $certDays, string $detail): ?string
    {
        $alertKey = self::alertKey($source, $resource['id']);
        $previous = $this->currentStatus($resource['id']);

        $this->ingestor->recordCheck($resource['id'], $source, $status, Repo::METRIC_HTTP, responseMs: $responseMs, message: $status === Status::UP ? null : $detail);
        if ($certDays !== null && $this->sslRecordDue($resource['id'])) {
            $sslStatus = $certDays < Thresholds::SSL_WARN_DAYS ? Status::WARNING : Status::UP;
            $this->ingestor->recordCheck($resource['id'], $source, $sslStatus, Repo::METRIC_SSL_DAYS, (float) $certDays);
        }

        $change = null;
        if ($status === Status::DOWN && $previous !== Status::DOWN) {
            $this->ingestor->raise($resource, $source, $alertKey, 'critical', $resource['name'] . ' 응답 실패', $detail, Status::DOWN);
            $change = 'opened';
        } elseif ($status !== Status::DOWN && $previous === Status::DOWN) {
            $change = $this->ingestor->clear($resource, $alertKey, $detail) === null ? null : 'resolved';
        }

        if ($status !== Status::DOWN) {
            $effective = $status === Status::UP
                ? Thresholds::http(['up' => true, 'response_ms' => $responseMs, 'ssl_days_left' => $certDays])
                : $status;
            $this->ingestor->updateStatus($resource['id'], $effective);
        }

        return $change;
    }

    private function currentStatus(int $resourceId): string
    {
        $statement = ($this->connection)()->prepare('SELECT current_status FROM resources WHERE id = ?');
        $statement->execute([$resourceId]);

        return (string) $statement->fetchColumn();
    }

    private function sslRecordDue(int $resourceId): bool
    {
        $statement = ($this->connection)()->prepare('SELECT MAX(checked_at) FROM check_results WHERE resource_id = ? AND metric_name = ?');
        $statement->execute([$resourceId, Repo::METRIC_SSL_DAYS]);
        $last = $statement->fetchColumn();

        return !is_string($last) || strtotime($last) < time() - self::SSL_RECORD_INTERVAL;
    }
}
