<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Monitoring\DatabaseMonitoringRepository as Repo;
use App\Monitoring\Status;
use Throwable;

/**
 * Uptime Kuma 'Webhook' 알림 (application/json) 처리.
 *
 * 상태가 바뀔 때만 오므로(DOWN/UP 등) 점검 이력과 24시간 가용률의 정확한 원천은 아니다.
 * 리소스는 resources.external_ref = 모니터 ID, 없으면 identifier = 모니터 URL 로 찾는다.
 */
final class UptimeKumaWebhook
{
    public const SOURCE = 'uptime_kuma';

    private const DOWN = 0;
    private const UP = 1;
    private const PENDING = 2;
    private const MAINTENANCE = 3;

    public function __construct(private readonly AlertIngestor $ingestor)
    {
    }

    /**
     * @return array{result: string, incident_id?: int|null}
     * @throws InvalidAlert
     */
    public function handle(string $content): array
    {
        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            throw new InvalidAlert('JSON 본문이 아닙니다.');
        }

        $monitor = is_array($payload['monitor'] ?? null) ? $payload['monitor'] : null;
        $heartbeat = is_array($payload['heartbeat'] ?? null) ? $payload['heartbeat'] : null;
        $msg = is_string($payload['msg'] ?? null) ? $payload['msg'] : '';

        $externalId = $heartbeat !== null
            ? sprintf('hb:%s:%s:%s', $heartbeat['monitorID'] ?? $monitor['id'] ?? '', $heartbeat['time'] ?? '', $heartbeat['status'] ?? '')
            : 'msg:' . sha1($content);
        $eventId = $this->ingestor->receive(self::SOURCE, $externalId, $content);
        if ($eventId === null) {
            return ['result' => 'duplicate'];
        }

        // 설정 화면의 '테스트' 알림은 모니터 정보가 없다
        if ($monitor === null) {
            $this->ingestor->finish($eventId, null, '모니터 정보 없음 (테스트 알림)');

            return ['result' => 'ignored'];
        }

        $resource = $this->ingestor->findResource('http', isset($monitor['id']) ? (string) $monitor['id'] : null, $monitor['url'] ?? null);
        if ($resource === null) {
            $error = sprintf('등록되지 않은 모니터 #%s (%s) — resources.external_ref 에 모니터 ID 를 넣으세요.', $monitor['id'] ?? '?', $monitor['name'] ?? '');
            $this->ingestor->finish($eventId, null, $error);

            return ['result' => 'unmatched'];
        }

        try {
            $incidentId = $heartbeat === null
                ? $this->notice($resource, $msg)
                : $this->heartbeat($resource, $heartbeat);
        } catch (Throwable $e) {
            $this->ingestor->finish($eventId, null, $e->getMessage(), processed: false);
            throw $e;
        }
        $this->ingestor->finish($eventId, $incidentId);

        return ['result' => 'processed', 'incident_id' => $incidentId];
    }

    /**
     * @param array{id: int, system_id: int, name: string} $resource
     * @param array<string, mixed> $heartbeat
     */
    private function heartbeat(array $resource, array $heartbeat): ?int
    {
        $message = is_string($heartbeat['msg'] ?? null) && $heartbeat['msg'] !== '' ? $heartbeat['msg'] : null;
        $ping = is_numeric($heartbeat['ping'] ?? null) ? (int) $heartbeat['ping'] : null;
        $alertKey = HttpStatusTracker::alertKey(self::SOURCE, $resource['id']);

        switch ((int) ($heartbeat['status'] ?? -1)) {
            case self::DOWN:
                $this->ingestor->recordCheck($resource['id'], self::SOURCE, Status::DOWN, Repo::METRIC_HTTP, message: $message);

                return $this->ingestor->raise($resource, self::SOURCE, $alertKey, 'critical',
                    $resource['name'] . ' 응답 실패' . ($message === null ? '' : ' (' . mb_strimwidth($message, 0, 80, '…') . ')'),
                    $message, Status::DOWN);

            case self::UP:
                $this->ingestor->recordCheck($resource['id'], self::SOURCE, Status::UP, Repo::METRIC_HTTP, responseMs: $ping, message: $message);

                return $this->ingestor->clear($resource, $alertKey, '정상 응답' . ($ping === null ? '' : " ({$ping}ms)"));

            case self::PENDING:
                $this->ingestor->recordCheck($resource['id'], self::SOURCE, Status::WARNING, Repo::METRIC_HTTP, message: $message);

                return null;

            case self::MAINTENANCE:
                $this->ingestor->recordCheck($resource['id'], self::SOURCE, Status::UNKNOWN, Repo::METRIC_HTTP, message: '점검 중' . ($message === null ? '' : ": {$message}"));

                return null;
        }

        throw new InvalidAlert('알 수 없는 heartbeat.status');
    }

    /**
     * 하트비트 없는 알림. 인증서 만료 예정 알림만 처리한다.
     *
     * @param array{id: int, system_id: int, name: string} $resource
     */
    private function notice(array $resource, string $msg): ?int
    {
        if (!preg_match('/expire[sd]? in (\d+) days?/i', $msg, $m)) {
            return null;
        }

        $days = (int) $m[1];
        $this->ingestor->recordCheck($resource['id'], self::SOURCE, Status::WARNING, Repo::METRIC_SSL_DAYS, (float) $days, message: $msg);

        return $this->ingestor->raise($resource, self::SOURCE, self::SOURCE . ':ssl:' . $resource['id'], 'info',
            "{$resource['name']} SSL 인증서 만료 {$days}일 전", $msg, Status::WARNING);
    }
}
