<?php

declare(strict_types=1);

namespace App\Meetings;

use App\Database;
use App\Security\Crypto;
use Closure;
use PDO;
use RuntimeException;

/**
 * 회의록 저장소. 본문·결정 사항은 여기서만 암호화·복호화한다.
 *
 * 공개 범위 (작성자는 항상 본다):
 * - all: 회의록 권한이 있는 직원 전체
 * - attendees: 참석자로 지정된 직원
 * - private: 작성자만
 * 볼 수 없는 회의는 목록에도 나오지 않는다 (제목 노출 방지)
 */
final class MeetingRepository
{
    public const VISIBILITY = [
        'attendees' => '참석자만',
        'all' => '회의록 권한자 전체',
        'private' => '작성자만',
    ];

    public const PAGE_SIZE = 30;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?Crypto $crypto,
    ) {
    }

    /**
     * @param array{from?: ?string, to?: ?string, system_id?: ?int, attendee_id?: ?int, author_id?: ?int, q?: ?string} $filter
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function visibleTo(int $userId, array $filter = [], int $page = 1): array
    {
        [$where, $params] = $this->visibleWhere($userId);
        $where[] = 'm.deleted_at IS NULL';
        if (($filter['from'] ?? null) !== null) {
            $where[] = 'm.held_at >= ?';
            $params[] = $filter['from'] . ' 00:00';
        }
        if (($filter['to'] ?? null) !== null) {
            $where[] = 'm.held_at <= ?';
            $params[] = $filter['to'] . ' 23:59';
        }
        if (($filter['system_id'] ?? null) !== null) {
            $where[] = 'm.system_id = ?';
            $params[] = $filter['system_id'];
        }
        if (($filter['attendee_id'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM meeting_attendees x WHERE x.meeting_id = m.id AND x.user_id = ?)';
            $params[] = $filter['attendee_id'];
        }
        if (($filter['author_id'] ?? null) !== null) {
            $where[] = 'm.author_user_id = ?';
            $params[] = $filter['author_id'];
        }
        if (($filter['q'] ?? null) !== null) {
            $where[] = "m.title LIKE ? ESCAPE '\\'";
            $params[] = '%' . addcslashes($filter['q'], '%_\\') . '%';
        }

        return $this->list('WHERE ' . implode(' AND ', $where), $params, 'm.held_at DESC, m.id DESC', $page);
    }

    /**
     * 휴지통: 내가 작성하고 지운 회의록
     *
     * @return list<array<string, mixed>>
     */
    public function trashOf(int $userId): array
    {
        return $this->list('WHERE m.author_user_id = ? AND m.deleted_at IS NOT NULL', [$userId], 'm.deleted_at DESC', 1, 200)['rows'];
    }

    /**
     * 사용자가 볼 수 있으면 복호화한 회의록, 없거나 볼 수 없으면 null.
     * 지운 회의록은 작성자만 (휴지통에서) 본다.
     *
     * @return array<string, mixed>|null
     */
    public function findFor(int $id, int $userId): ?array
    {
        [$where, $params] = $this->visibleWhere($userId);
        $statement = ($this->connection)()->prepare(
            'SELECT m.*, s.name AS system_name, s.code AS system_code, u.name AS author_name
             FROM meetings m
             LEFT JOIN systems s ON s.id = m.system_id
             JOIN users u ON u.id = m.author_user_id
             WHERE m.id = ? AND ' . implode(' AND ', $where) . ' AND (m.deleted_at IS NULL OR m.author_user_id = ?)'
        );
        $statement->execute([$id, ...$params, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['body'] = $this->crypto()->decrypt((string) $row['body_enc']);
        $row['decisions'] = $this->crypto()->decrypt((string) $row['decisions_enc']);
        unset($row['body_enc'], $row['decisions_enc']);
        $row['attendees'] = $this->attendees($id);

        return $row;
    }

    /**
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
     */
    public function create(array $values, int $authorId): int
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO meetings (title, held_at, system_id, visibility, body_enc, decisions_enc, author_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $values['title'], $values['held_at'], $values['system_id'], $values['visibility'],
                $this->crypto()->encrypt($values['body']), $this->crypto()->encrypt($values['decisions']),
                $authorId, Database::now(), Database::now(),
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->saveAttendees($pdo, $id, $values['attendees']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
     */
    public function update(int $id, array $values): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE meetings SET title = ?, held_at = ?, system_id = ?, visibility = ?, body_enc = ?, decisions_enc = ?, updated_at = ? WHERE id = ?'
            )->execute([
                $values['title'], $values['held_at'], $values['system_id'], $values['visibility'],
                $this->crypto()->encrypt($values['body']), $this->crypto()->encrypt($values['decisions']),
                Database::now(), $id,
            ]);
            $pdo->prepare('DELETE FROM meeting_attendees WHERE meeting_id = ?')->execute([$id]);
            $this->saveAttendees($pdo, $id, $values['attendees']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function trash(int $id): void
    {
        ($this->connection)()->prepare('UPDATE meetings SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL')->execute([Database::now(), $id]);
    }

    public function restore(int $id): void
    {
        ($this->connection)()->prepare('UPDATE meetings SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    }

    /**
     * 참석자로 고를 수 있는 직원 (사용 중인 계정)
     *
     * @return list<array{id: int, name: string, meeting_access: string}>
     */
    public function staff(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'meeting_access' => (string) $r['meeting_access']],
            ($this->connection)()->query('SELECT id, name, meeting_access FROM users WHERE is_active = 1 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function systems(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            ($this->connection)()->query('SELECT id, name FROM systems WHERE is_active = 1 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return array{list<string>, list<mixed>}
     */
    private function visibleWhere(int $userId): array
    {
        return [
            ["(m.visibility = 'all' OR m.author_user_id = ? OR (m.visibility = 'attendees' AND EXISTS (SELECT 1 FROM meeting_attendees a WHERE a.meeting_id = m.id AND a.user_id = ?)))"],
            [$userId, $userId],
        ];
    }

    /**
     * @param list<mixed> $params
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function list(string $where, array $params, string $order, int $page, int $size = self::PAGE_SIZE): array
    {
        $pdo = ($this->connection)();
        $count = $pdo->prepare("SELECT COUNT(*) FROM meetings m {$where}");
        $count->execute($params);
        $statement = $pdo->prepare(
            "SELECT m.id, m.title, m.held_at, m.visibility, m.author_user_id, m.deleted_at, m.system_id,
                    s.name AS system_name, u.name AS author_name,
                    (SELECT COUNT(*) FROM meeting_attendees a WHERE a.meeting_id = m.id) AS attendee_count
             FROM meetings m
             LEFT JOIN systems s ON s.id = m.system_id
             JOIN users u ON u.id = m.author_user_id
             {$where} ORDER BY {$order} LIMIT ? OFFSET ?"
        );
        $statement->execute([...$params, $size, (max(1, $page) - 1) * $size]);

        return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function attendees(int $meetingId): array
    {
        $statement = ($this->connection)()->prepare(
            'SELECT u.id, u.name FROM meeting_attendees a JOIN users u ON u.id = a.user_id WHERE a.meeting_id = ? ORDER BY u.name, u.id'
        );
        $statement->execute([$meetingId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int> $userIds
     */
    private function saveAttendees(PDO $pdo, int $meetingId, array $userIds): void
    {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES (?, ?)');
        foreach (array_unique($userIds) as $userId) {
            $insert->execute([$meetingId, $userId]);
        }
    }

    private function crypto(): Crypto
    {
        return $this->crypto ?? throw new RuntimeException('APP_KEY 가 설정되지 않아 회의록을 읽거나 쓸 수 없습니다.');
    }
}
