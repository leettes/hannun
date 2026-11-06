<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Database;
use App\Notifications\Notifier;
use Closure;
use PDO;
use Throwable;

/**
 * 장애 센터에서 운영자가 하는 처리: 상태 변경(확인/복구/다시 열기), 담당자 지정, 원인·조치 내용, 메모.
 * 변경마다 incident_events 에 타임라인을 남긴다.
 */
final class IncidentActions
{
    /** 화면에서 바꿀 수 있는 incidents 컬럼 */
    public const FIELDS = ['status', 'assignee_user_id', 'root_cause', 'action_taken'];

    public const DISMISS_REASON_MAX = 500;

    private const STATUS_EVENTS =['acknowledged' => 'acknowledged', 'resolved' => 'resolved', 'open' => 'reopened'];

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * 사용자가 화면에서 본 값($seen)과 다르게 입력한 필드만 반영한다.
     * 그 필드의 현재 DB 값이 $seen 과 다르면 다른 사용자가 먼저 바꾼 것이므로 덮어쓰지 않고 거부한다.
     *
     * @param array<string, mixed> $seen FIELDS 별 화면 표시 값
     * @param array<string, mixed> $wanted FIELDS 별 입력 값
     * @throws IncidentUpdateRejected
     */
    public function update(int $id, int $userId, array $seen, array $wanted, ?string $note): void
    {
        $note = self::text($note);
        $pdo = ($this->connection)();
        // 읽은 뒤 쓰므로 처음부터 쓰기 잠금을 잡는다 (SQLite 의 잠금 승격 충돌 방지)
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->apply($pdo, $id, $userId, $seen, $wanted, $note);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $seen
     * @param array<string, mixed> $wanted
     */
    private function apply(PDO $pdo, int $id, int $userId, array $seen, array $wanted, ?string $note): void
    {
        $current = $this->fetch($pdo, 'SELECT ' . implode(', ', self::FIELDS) . ', alert_key FROM incidents WHERE id = ?', [$id])
            ?? throw new IncidentUpdateRejected('존재하지 않는 장애입니다.');

        $changes = [];
        foreach (self::FIELDS as $field) {
            $before = self::normalize($field, $seen[$field] ?? null);
            $after = self::normalize($field, $wanted[$field] ?? null);
            if ($after === $before) {
                continue;
            }
            if (self::normalize($field, $current[$field]) !== $before) {
                throw new IncidentUpdateRejected('다른 사용자가 먼저 이 장애를 수정했습니다. 새로고침한 뒤 다시 입력하세요.', conflict: true);
            }
            $changes[$field] = $after;
        }

        if ($changes === [] && $note === null) {
            throw new IncidentUpdateRejected('변경된 내용이 없습니다.');
        }

        $set = [];
        $params = [];
        foreach ($changes as $field => $value) {
            $set[] = "{$field} = ?";
            $params[] = $value;
        }

        $events = [];
        if (array_key_exists('assignee_user_id', $changes)) {
            $events[] = ['assigned', $changes['assignee_user_id'] === null
                ? '담당자 지정 해제'
                : '담당자: ' . $this->assigneeName($pdo, $changes['assignee_user_id'])];
        }

        if (isset($changes['status'])) {
            $from = (string) $current['status'];
            $to = $changes['status'];
            if (!in_array($to, Incident::TRANSITIONS[$from] ?? [], true)) {
                throw new IncidentUpdateRejected(sprintf(
                    "'%s' 상태에서 '%s'(으)로 바꿀 수 없습니다.",
                    Incident::STATUS_LABELS[$from] ?? $from,
                    Incident::STATUS_LABELS[$to] ?? $to,
                ));
            }
            if ($to === 'resolved' && ($changes['action_taken'] ?? self::text($current['action_taken'])) === null) {
                throw new IncidentUpdateRejected('복구 처리하려면 조치 내용을 입력하세요.');
            }
            if ($to === 'open') {
                $this->assertNoOtherActive($pdo, $id, (string) $current['alert_key']);
            }

            [$sql, $values] = match ($to) {
                'acknowledged' => ['acknowledged_at = COALESCE(acknowledged_at, ?)', [Database::now()]],
                'resolved' => ['resolved_at = ?', [Database::now()]],
                // 다시 열린 장애는 새 문제이므로 최근 장애 목록에 다시 나오게 한다
                'open' => ['resolved_at = NULL, dismissed_at = NULL, dismissed_by_user_id = NULL', []],
            };
            $set[] = $sql;
            array_push($params, ...$values);
            $events[] = [self::STATUS_EVENTS[$to], null];
        }

        if ($note !== null) {
            $events[] = ['note', $note];
        }

        if ($set !== []) {
            $params[] = $id;
            $pdo->prepare('UPDATE incidents SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        }

        $insert = $pdo->prepare('INSERT INTO incident_events (incident_id, type, user_id, message, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($events as [$type, $message]) {
            $insert->execute([$id, $type, $userId, $message, Database::now()]);
        }

        if (($changes['status'] ?? null) === 'resolved') {
            $this->notifier?->queue($pdo, $id, 'resolved');
        }
    }

    /**
     * 대시보드 '최근 장애'에서 확인 처리해 목록에서 뺀다. 장애 상태는 바꾸지 않는다.
     * 사유는 선택이고, 타임라인에 메모로 남긴다.
     *
     * @throws IncidentUpdateRejected 없는 장애, 이미 확인한 장애
     */
    public function dismiss(int $id, int $userId, ?string $reason): void
    {
        $reason = self::text($reason);
        if ($reason !== null && mb_strlen($reason) > self::DISMISS_REASON_MAX) {
            throw new IncidentUpdateRejected('확인 사유는 ' . self::DISMISS_REASON_MAX . '자까지 입력할 수 있습니다.');
        }

        $pdo = ($this->connection)();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->fetch($pdo, 'SELECT dismissed_at FROM incidents WHERE id = ?', [$id])
                ?? throw new IncidentUpdateRejected('존재하지 않는 장애입니다.');
            if ($current['dismissed_at'] !== null) {
                throw new IncidentUpdateRejected('이미 확인한 장애입니다.', conflict: true);
            }

            $now = Database::now();
            $pdo->prepare('UPDATE incidents SET dismissed_at = ?, dismissed_by_user_id = ? WHERE id = ?')->execute([$now, $userId, $id]);
            $pdo->prepare('INSERT INTO incident_events (incident_id, type, user_id, message, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, 'note', $userId, '최근 장애 목록에서 확인' . ($reason === null ? '' : ": {$reason}"), $now]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * 담당자는 운영자 이상인 활성 계정만 지정할 수 있다.
     */
    private function assigneeName(PDO $pdo, int $userId): string
    {
        $row = $this->fetch($pdo, "SELECT name FROM users WHERE id = ? AND is_active = 1 AND role IN ('operator', 'admin')", [$userId]);

        return $row === null
            ? throw new IncidentUpdateRejected('담당자는 운영자 이상의 사용 중인 계정만 지정할 수 있습니다.')
            : (string) $row['name'];
    }

    /**
     * 같은 alert_key 로 복구되지 않은 장애는 하나만 있을 수 있다 (uq_incidents_active_alert_key).
     */
    private function assertNoOtherActive(PDO $pdo, int $id, string $alertKey): void
    {
        $other = $this->fetch($pdo, "SELECT id FROM incidents WHERE alert_key = ? AND status <> 'resolved' AND id <> ?", [$alertKey, $id]);
        if ($other !== null) {
            throw new IncidentUpdateRejected("같은 알림으로 장애 #{$other['id']}이(가) 진행 중이라 다시 열 수 없습니다. 해당 장애에 기록하세요.");
        }
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetch(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function normalize(string $field, mixed $value): int|string|null
    {
        return match ($field) {
            'assignee_user_id' => $value === null || $value === '' ? null : (int) $value,
            'status' => (string) $value,
            default => self::text($value),
        };
    }

    /**
     * 앞뒤 공백을 지우고, 빈 값은 null 로 저장한다.
     */
    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
