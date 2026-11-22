<?php

declare(strict_types=1);

namespace App\Features;

use App\Database;
use Closure;
use PDO;

/**
 * 빌드업(코드 이름 feature): 서비스·앱 단위 개발 건을 요청 → 확정 → 개발 → 배포까지.
 * 끝내지 않을 건은 '취소'로 두고, 잘못 만든 건은 삭제한다 (타임라인도 같이 지우고 감사 로그에 남긴다).
 * 모든 변경은 feature_events 타임라인에 남긴다.
 */
final class FeatureRepository
{
    public const STATUSES = [
        'requested' => '요청',
        'planned' => '확정',
        'in_progress' => '개발 중',
        'released' => '배포됨',
        'on_hold' => '보류',
        'dropped' => '취소',
    ];

    /** 아직 끝나지 않은 상태 (서비스·앱 상세의 '진행 중 기능') */
    public const OPEN = ['requested', 'planned', 'in_progress', 'on_hold'];

    /** 상태별로 바꿀 수 있는 다음 상태 */
    public const TRANSITIONS = [
        'requested' => ['planned', 'in_progress', 'on_hold', 'dropped'],
        'planned' => ['in_progress', 'requested', 'on_hold', 'dropped'],
        'in_progress' => ['released', 'planned', 'on_hold', 'dropped'],
        'on_hold' => ['planned', 'in_progress', 'dropped'],
        'released' => ['in_progress'],      // 배포 후 재작업
        'dropped' => ['requested'],         // 다시 요청
    ];

    /** 회의 할 일에서 올린 항목: 빌드업 상태 → 할 일 상태 (MeetingTaskRepository::STATUSES) */
    public const TASK_STATUS = [
        'requested' => 'todo',
        'planned' => 'todo',
        'in_progress' => 'doing',
        'released' => 'done',
        'on_hold' => 'hold',
        'dropped' => 'hold',
    ];

    /** 새로 등록할 때 고를 수 있는 상태 */
    public const INITIAL = ['requested', 'planned', 'in_progress'];

    public const PAGE_SIZE = 50;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param array{status?: ?string, system_id?: ?int, app_id?: ?int, assignee_id?: ?int} $filter
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array<string, int>}
     */
    public function search(array $filter, int $page = 1): array
    {
        $where = [];
        $params = [];
        foreach (['system_id' => 'f.system_id', 'app_id' => 'f.app_id', 'assignee_id' => 'f.assignee_user_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = "{$column} = ?";
                $params[] = $filter[$key];
            }
        }
        $pdo = ($this->connection)();

        // 상태 탭 숫자는 다른 필터만 적용한 값
        $countSql = 'SELECT f.status, COUNT(*) FROM features f' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' GROUP BY f.status';
        $statement = $pdo->prepare($countSql);
        $statement->execute($params);
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($statement->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $n) {
            $counts[$status] = (int) $n;
        }

        $status = $filter['status'] ?? null;
        if ($status === 'open') {
            $where[] = "f.status IN ('" . implode("', '", self::OPEN) . "')";
        } elseif ($status !== null) {
            $where[] = 'f.status = ?';
            $params[] = $status;
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = $pdo->prepare("SELECT COUNT(*) FROM features f {$sqlWhere}");
        $total->execute($params);

        return [
            'rows' => $this->rows("{$sqlWhere} ORDER BY " . self::ORDER . ' LIMIT ? OFFSET ?', [...$params, self::PAGE_SIZE, (max(1, $page) - 1) * self::PAGE_SIZE]),
            'total' => (int) $total->fetchColumn(),
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->rows('WHERE f.id = ?', [$id])[0] ?? null;
    }

    /**
     * 서비스의 진행 중 기능 (서비스 상세)
     *
     * @return list<array<string, mixed>>
     */
    public function openForSystem(int $systemId): array
    {
        return $this->rows("WHERE f.system_id = ? AND f.status IN ('" . implode("', '", self::OPEN) . "') ORDER BY " . self::ORDER, [$systemId]);
    }

    /**
     * 앱(플랫폼별 형제 앱 포함)의 진행 중 기능 (앱 상세)
     *
     * @param list<int> $appIds
     * @return list<array<string, mixed>>
     */
    public function openForApps(array $appIds): array
    {
        if ($appIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($appIds), '?'));

        return $this->rows("WHERE f.app_id IN ({$marks}) AND f.status IN ('" . implode("', '", self::OPEN) . "') ORDER BY " . self::ORDER, $appIds);
    }

    /**
     * 회의에서 등록한 기능 (회의록 상세)
     *
     * @return list<array<string, mixed>>
     */
    public function forMeeting(int $meetingId): array
    {
        return $this->rows('WHERE f.meeting_id = ? ORDER BY f.id', [$meetingId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(int $featureId): array
    {
        $statement = ($this->connection)()->prepare(
            'SELECT e.*, u.name AS user_name FROM feature_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.feature_id = ? ORDER BY e.id DESC'
        );
        $statement->execute([$featureId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{system_id: int, app_id: ?int, title: string, description: ?string, target_version: ?string, assignee_user_id: ?int, status: string} $values
     */
    public function create(array $values, int $userId, ?int $meetingId = null, ?int $incidentId = null, ?int $meetingTaskId = null): int
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $now = Database::now();
            $pdo->prepare(
                'INSERT INTO features (system_id, app_id, title, description, status, target_version, assignee_user_id, meeting_id, incident_id, meeting_task_id, created_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $values['system_id'], $values['app_id'], $values['title'], $values['description'], $values['status'],
                $values['target_version'], $values['assignee_user_id'], $meetingId, $incidentId, $meetingTaskId, $userId, $now, $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            $from = null;
            if ($meetingId !== null) {
                // 타임라인에 언제 회의에서 나온 건지 남긴다 (회의 제목은 넣지 않음)
                $heldAt = $pdo->prepare('SELECT held_at FROM meetings WHERE id = ?');
                $heldAt->execute([$meetingId]);
                $when = $heldAt->fetchColumn();
                $from = ($when === false ? '' : meeting_day((string) $when) . ' ') . ($meetingTaskId !== null ? '회의 할 일에서 올림' : '회의에서 등록');
            }
            $this->event($pdo, $id, $userId, 'created', null, $values['status'], $from);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * 내용 수정. 바뀐 항목이 있으면 타임라인에 남긴다
     *
     * @param array{system_id: int, app_id: ?int, title: string, description: ?string, target_version: ?string, assignee_user_id: ?int} $values
     * @param list<string> $changed 화면에 보일 바뀐 항목 이름
     */
    public function update(int $id, array $values, int $userId, array $changed): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE features SET system_id = ?, app_id = ?, title = ?, description = ?, target_version = ?, assignee_user_id = ?, updated_at = ? WHERE id = ?'
            )->execute([
                $values['system_id'], $values['app_id'], $values['title'], $values['description'],
                $values['target_version'], $values['assignee_user_id'], Database::now(), $id,
            ]);
            if ($changed !== []) {
                $this->event($pdo, $id, $userId, 'edit', null, null, implode(', ', $changed));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 상태 변경. 배포됨이면 배포 버전·시각을 기록하고, 배포됨에서 벗어나면 지운다
     */
    public function changeStatus(int $id, string $from, string $to, int $userId, ?string $releasedVersion, ?string $note): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $released = $to === 'released';
            $pdo->prepare('UPDATE features SET status = ?, released_version = ?, released_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$to, $released ? $releasedVersion : null, $released ? Database::now() : null, Database::now(), $id]);
            $this->event($pdo, $id, $userId, 'status', $from, $to, trim(($released ? "v{$releasedVersion} " : '') . ($note ?? '')) ?: null);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 삭제: 타임라인까지 지운다 (되돌릴 수 없다)
     */
    public function delete(int $id): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM feature_events WHERE feature_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM features WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function addNote(int $id, int $userId, string $note): void
    {
        $pdo = ($this->connection)();
        $this->event($pdo, $id, $userId, 'note', null, null, $note);
        $pdo->prepare('UPDATE features SET updated_at = ? WHERE id = ?')->execute([Database::now(), $id]);
    }

    /**
     * 기능을 걸 수 있는 앱 (서비스별)
     *
     * @return list<array{id: int, system_id: int, name: string, platform: string}>
     */
    public function apps(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'system_id' => (int) $r['system_id'], 'name' => (string) $r['name'], 'platform' => (string) $r['platform']],
            ($this->connection)()->query("SELECT id, system_id, name, platform FROM apps WHERE is_active = 1 ORDER BY name, CASE platform WHEN 'ios' THEN 0 WHEN 'android' THEN 1 ELSE 2 END, id")->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return list<array{id: int, name: string, code: string}>
     */
    public function systems(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code']],
            ($this->connection)()->query('SELECT id, name, code FROM systems WHERE is_active = 1 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * 담당자로 고를 수 있는 직원
     *
     * @return list<array{id: int, name: string}>
     */
    public function staff(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            ($this->connection)()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** 진행 중인 것 먼저, 그다음 최근 수정 순 */
    private const ORDER = "CASE f.status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 WHEN 'requested' THEN 2 WHEN 'on_hold' THEN 3 WHEN 'released' THEN 4 ELSE 5 END, f.updated_at DESC, f.id DESC";

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $tail, array $params): array
    {
        $statement = ($this->connection)()->prepare(
            "SELECT f.*, s.name AS system_name, s.code AS system_code, a.name AS app_name, a.platform AS app_platform,
                    au.name AS assignee_name, cu.name AS creator_name, mt.held_at AS meeting_held_at
             FROM features f
             JOIN systems s ON s.id = f.system_id
             LEFT JOIN apps a ON a.id = f.app_id
             LEFT JOIN users au ON au.id = f.assignee_user_id
             LEFT JOIN users cu ON cu.id = f.created_by_user_id
             LEFT JOIN meetings mt ON mt.id = f.meeting_id
             {$tail}"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function event(PDO $pdo, int $featureId, int $userId, string $type, ?string $from, ?string $to, ?string $note): void
    {
        $pdo->prepare('INSERT INTO feature_events (feature_id, user_id, type, from_value, to_value, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$featureId, $userId, $type, $from, $to, $note, Database::now()]);
    }
}
