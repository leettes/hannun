<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Database;
use App\Monitoring\Status;
use App\Notifications\Notifier;
use Closure;
use PDO;
use Throwable;

/**
 * 외부 알림(Uptime Kuma, CloudWatch)을 DB 에 반영한다.
 *
 * - 원본은 alert_events 에 저장한다. 처리 완료된 (source, external_id) 를 다시 받으면 무시한다.
 * - 같은 alert_key 로 복구되지 않은 장애가 있으면 새 장애를 만들지 않고 'alert' 이벤트만 추가한다.
 * - 복구 알림이 오면 장애를 복구 처리하고, 그 리소스에 남은 장애가 없으면 상태를 정상으로 돌린다.
 * - 새 장애와 복구는 문자 알림 대기열에 넣는다 (반복 알림은 보내지 않음).
 */
final class AlertIngestor
{
    /** 사용 중인 서비스의 사용 중인 리소스만 알림·수집 대상 */
    private const RESOURCE_SELECT = 'SELECT r.id, r.system_id, r.name FROM resources r
        JOIN systems s ON s.id = r.system_id AND s.is_active = 1
        WHERE r.type = ? AND r.is_active = 1';

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * 원본 알림을 저장한다.
     *
     * @return int|null alert_events id. 이미 처리를 끝낸 알림이면 null (실패했던 알림은 같은 id 로 다시 처리)
     */
    public function receive(string $source, ?string $externalId, string $payload): ?int
    {
        $pdo = ($this->connection)();
        $insert = $pdo->prepare(
            'INSERT INTO alert_events (source, external_id, payload, received_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (source, external_id) DO NOTHING'
        );
        $insert->execute([$source, $externalId, json_decode($payload) !== null ? $payload : json_encode(['raw' => $payload]), Database::now()]);
        if ($insert->rowCount() === 1) {
            return (int) $pdo->lastInsertId();
        }

        $row = $this->fetch('SELECT id, processed_at FROM alert_events WHERE source = ? AND external_id = ?', [$source, $externalId]);

        return $row === null || $row['processed_at'] !== null ? null : (int) $row['id'];
    }

    /**
     * 처리 결과 기록. $processed 가 false 면 오류만 남기고 재처리 대상으로 둔다.
     */
    public function finish(int $alertEventId, ?int $incidentId, ?string $error = null, bool $processed = true): void
    {
        ($this->connection)()
            ->prepare('UPDATE alert_events SET incident_id = COALESCE(?, incident_id), error = ?, processed_at = ? WHERE id = ?')
            ->execute([$incidentId, $error, $processed ? Database::now() : null, $alertEventId]);
    }

    /**
     * external_ref(외부 시스템 키)가 맞는 리소스를 먼저 찾고, 없으면 identifier(URL, 인스턴스 ID 등)로 찾는다.
     *
     * @return array{id: int, system_id: int, name: string}|null
     */
    public function findResource(string $type, ?string $externalRef, ?string $identifier): ?array
    {
        $row = null;
        if ($externalRef !== null && $externalRef !== '') {
            $row = $this->fetch(self::RESOURCE_SELECT . ' AND r.external_ref = ?', [$type, $externalRef]);
        }
        if ($row === null && $identifier !== null && $identifier !== '') {
            $row = $this->fetch(self::RESOURCE_SELECT . ' AND r.identifier = ?', [$type, $identifier]);
        }

        return $row === null ? null : ['id' => (int) $row['id'], 'system_id' => (int) $row['system_id'], 'name' => (string) $row['name']];
    }

    /**
     * 장애 발생(또는 지속).
     *
     * @param array{id: int, system_id: int} $resource
     * @param string $resourceStatus 리소스에 반영할 상태 (down / warning)
     * @return int 장애 id
     */
    public function raise(array $resource, string $source, string $alertKey, string $severity, string $title, ?string $message, string $resourceStatus): int
    {
        return $this->transaction(function (PDO $pdo) use ($resource, $source, $alertKey, $severity, $title, $message, $resourceStatus): int {
            $now = Database::now();
            $this->setStatus($pdo, $resource['id'], $resourceStatus, worstOnly: true);

            $active = $this->activeIncident($alertKey);
            if ($active !== null) {
                $this->event($pdo, $active, 'alert', $message);

                return $active;
            }

            $pdo->prepare(
                'INSERT INTO incidents (system_id, resource_id, source, alert_key, severity, status, title, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$resource['system_id'], $resource['id'], $source, $alertKey, $severity, 'open', mb_substr($title, 0, 200), $now]);
            $id = (int) $pdo->lastInsertId();
            $this->event($pdo, $id, 'opened', $message);
            $this->notifier?->queue($pdo, $id, 'opened');

            return $id;
        });
    }

    /**
     * 복구. 진행 중인 장애가 없으면 리소스 상태만 갱신한다.
     *
     * @param array{id: int} $resource
     * @return int|null 복구 처리한 장애 id
     */
    public function clear(array $resource, string $alertKey, ?string $message): ?int
    {
        return $this->transaction(function (PDO $pdo) use ($resource, $alertKey, $message): ?int {
            $active = $this->activeIncident($alertKey);
            if ($active !== null) {
                $pdo->prepare("UPDATE incidents SET status = 'resolved', resolved_at = ? WHERE id = ?")->execute([Database::now(), $active]);
                $this->event($pdo, $active, 'resolved', $message);
                $this->notifier?->queue($pdo, $active, 'resolved');
            }

            $remaining = $this->fetch(
                "SELECT COUNT(*) AS n FROM incidents WHERE resource_id = ? AND status <> 'resolved'",
                [$resource['id']],
            );
            if ((int) $remaining['n'] === 0) {
                $this->setStatus($pdo, $resource['id'], Status::UP);
            } else {
                $this->touch($pdo, $resource['id']);
            }

            return $active;
        });
    }

    /**
     * 리소스 상태를 그대로 바꾼다 (Uptime Kuma 수집기처럼 현재 상태를 통째로 아는 경우).
     */
    public function updateStatus(int $resourceId, string $status): void
    {
        $this->setStatus(($this->connection)(), $resourceId, $status);
    }

    /**
     * 점검 결과 한 건을 남기고 마지막 점검 시각을 갱신한다.
     */
    public function recordCheck(int $resourceId, string $source, string $status, string $metric, ?float $value = null, ?int $responseMs = null, ?string $message = null): void
    {
        $pdo = ($this->connection)();
        $pdo->prepare(
            'INSERT INTO check_results (resource_id, source, status, metric_name, metric_value, response_ms, message, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$resourceId, $source, $status, $metric, $value, $responseMs, $message === null ? null : mb_substr($message, 0, 500), Database::now()]);
        $this->touch($pdo, $resourceId);
    }

    private function activeIncident(string $alertKey): ?int
    {
        $row = $this->fetch("SELECT id FROM incidents WHERE alert_key = ? AND status <> 'resolved'", [$alertKey]);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * @param bool $worstOnly true 면 지금보다 나빠질 때만 바꾼다 (장애 중 경고 알림이 와도 '장애' 유지)
     */
    private function setStatus(PDO $pdo, int $resourceId, string $status, bool $worstOnly = false): void
    {
        if ($worstOnly) {
            $current = (string) $this->fetch('SELECT current_status FROM resources WHERE id = ?', [$resourceId])['current_status'];
            $status = Status::worst([$current === Status::UNKNOWN ? Status::UP : $current, $status]);
        }
        $pdo->prepare('UPDATE resources SET current_status = ?, last_checked_at = ? WHERE id = ?')->execute([$status, Database::now(), $resourceId]);
    }

    private function touch(PDO $pdo, int $resourceId): void
    {
        $pdo->prepare('UPDATE resources SET last_checked_at = ? WHERE id = ?')->execute([Database::now(), $resourceId]);
    }

    private function event(PDO $pdo, int $incidentId, string $type, ?string $message): void
    {
        $pdo->prepare('INSERT INTO incident_events (incident_id, type, message, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$incidentId, $type, $message === null ? null : mb_substr($message, 0, 1000), Database::now()]);
    }

    /**
     * @template T
     * @param Closure(PDO): T $work
     * @return T
     */
    private function transaction(Closure $work): mixed
    {
        $pdo = ($this->connection)();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work($pdo);
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetch(string $sql, array $params): ?array
    {
        $statement = ($this->connection)()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
