<?php

declare(strict_types=1);

namespace App\Meetings;

use App\Database;
use App\Security\Crypto;
use Closure;
use PDO;
use RuntimeException;

/**
 * 회의 할 일. 회의의 공개 범위를 그대로 따른다 (볼 수 있는 회의의 할 일만).
 * 항목·할 일·비고는 암호화, 서비스·상태·담당은 집계용 평문. 바꾼 기록은 meeting_task_events 에 남긴다.
 *
 * 담당 표시: 직원 계정 > 회의 참석자 라벨이 계정과 연결됐으면 그 이름 > 라벨('참석자 5') > 미지정
 */
final class MeetingTaskRepository
{
    public const STATUSES = [
        'todo' => '대기',
        'doing' => '진행',
        'done' => '완료',
        'hold' => '보류',
    ];

    /** 서비스 기본 분류 (시스템 이름과 함께 고를 수 있다) */
    public const DEFAULT_SERVICES = ['마케팅', '공통·신규'];

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?Crypto $crypto,
    ) {
    }

    /**
     * 볼 수 있는 회의의 할 일 (복호화). 검색은 복호화한 뒤 메모리에서 한다
     *
     * @param array{status?: ?string, service?: ?string, q?: ?string, meeting_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function visibleTo(int $userId, array $filter = []): array
    {
        $where = ['m.deleted_at IS NULL', self::VISIBLE];
        $params = [$userId, $userId];
        foreach (['status' => 't.status', 'service' => 't.service', 'meeting_id' => 't.meeting_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = "{$column} = ?";
                $params[] = $filter[$key];
            }
        }
        $rows = $this->rows('WHERE ' . implode(' AND ', $where) . ' ORDER BY m.held_at DESC, m.id DESC, t.sort, t.id', $params);

        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn (array $t): bool => mb_stripos($t['item'] . ' ' . $t['task'] . ' ' . ($t['note'] ?? ''), $q) !== false));
        }

        return $rows;
    }

    /**
     * 요약 숫자: 상태별, 담당 미지정, 서비스별 남은 할 일, 담당별
     *
     * @return array{status: array<string, int>, total: int, unassigned: int, services: array<string, int>, people: array<string, int>}
     */
    public function summary(int $userId): array
    {
        $rows = $this->rows('WHERE m.deleted_at IS NULL AND ' . self::VISIBLE, [$userId, $userId], decrypt: false);
        $status = array_fill_keys(array_keys(self::STATUSES), 0);
        $services = [];
        $people = [];
        $unassigned = 0;
        foreach ($rows as $t) {
            $status[$t['status']]++;
            if ($t['assignee_name'] === null) {
                $unassigned++;
            }
            if ($t['status'] !== 'done') {
                $services[$t['service']] = ($services[$t['service']] ?? 0) + 1;
            }
            $who = $t['assignee_name'] ?? '미지정';
            $people[$who] = ($people[$who] ?? 0) + 1;
        }
        arsort($services);
        uksort($people, static fn (string $a, string $b): int => (($a === '미지정') <=> ($b === '미지정')) ?: ($people[$b] <=> $people[$a]) ?: strcmp($a, $b));

        return ['status' => $status, 'total' => count($rows), 'unassigned' => $unassigned, 'services' => $services, 'people' => $people];
    }

    /**
     * 볼 수 있는 할 일에 쓰인 서비스 (서비스 탭). 서비스 => 0
     *
     * @return array<string, int>
     */
    public function usedServices(int $userId): array
    {
        $statement = ($this->connection)()->prepare(
            'SELECT DISTINCT t.service FROM meeting_tasks t JOIN meetings m ON m.id = t.meeting_id WHERE m.deleted_at IS NULL AND ' . self::VISIBLE . ' ORDER BY t.service'
        );
        $statement->execute([$userId, $userId]);

        return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), 0);
    }

    /**
     * @return array<string, mixed>|null 볼 수 있는 회의의 할 일
     */
    public function findFor(int $id, int $userId): ?array
    {
        return $this->rows('WHERE t.id = ? AND m.deleted_at IS NULL AND ' . self::VISIBLE, [$id, $userId, $userId])[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(int $taskId): array
    {
        $statement = ($this->connection)()->prepare(
            'SELECT e.*, u.name AS user_name FROM meeting_task_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.task_id = ? ORDER BY e.id DESC'
        );
        $statement->execute([$taskId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{service: string, item: string, task: string, note?: ?string, assignee_user_id?: ?int, assignee_label?: ?string, status?: string} $values
     */
    public function create(int $meetingId, array $values, ?int $userId): int
    {
        $pdo = ($this->connection)();
        $sort = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM meeting_tasks WHERE meeting_id = ?');
        $sort->execute([$meetingId]);
        $status = $values['status'] ?? 'todo';
        $now = Database::now();
        $pdo->prepare(
            'INSERT INTO meeting_tasks (meeting_id, service, item_enc, task_enc, note_enc, assignee_user_id, assignee_label, status, done_at, sort, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $meetingId, $values['service'], $this->crypto()->encrypt($values['item']), $this->crypto()->encrypt($values['task']),
            $this->encryptOrNull($values['note'] ?? null), $values['assignee_user_id'] ?? null, self::blank($values['assignee_label'] ?? null),
            $status, $status === 'done' ? $now : null, (int) $sort->fetchColumn(), $userId, $now, $now,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->event($id, $userId, 'created', null, $status, null);

        return $id;
    }

    public function changeStatus(int $id, string $from, string $to, int $userId): void
    {
        ($this->connection)()
            ->prepare('UPDATE meeting_tasks SET status = ?, done_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$to, $to === 'done' ? Database::now() : null, Database::now(), $id]);
        $this->event($id, $userId, 'status', $from, $to, null);
    }

    /**
     * 담당 지정. 직원을 고르면 회의에서 나온 라벨('참석자 5')은 지운다
     */
    public function assign(int $id, ?int $assigneeId, ?string $fromName, ?string $toName, int $userId): void
    {
        ($this->connection)()
            ->prepare('UPDATE meeting_tasks SET assignee_user_id = ?, assignee_label = CASE WHEN ? IS NULL THEN assignee_label ELSE NULL END, updated_at = ? WHERE id = ?')
            ->execute([$assigneeId, $assigneeId, Database::now(), $id]);
        $this->event($id, $userId, 'assignee', $fromName, $toName, null);
    }

    /**
     * @param array{service: string, item: string, task: string, note: ?string} $values
     * @param list<string> $changed
     */
    public function update(int $id, array $values, int $userId, array $changed): void
    {
        ($this->connection)()
            ->prepare('UPDATE meeting_tasks SET service = ?, item_enc = ?, task_enc = ?, note_enc = ?, updated_at = ? WHERE id = ?')
            ->execute([$values['service'], $this->crypto()->encrypt($values['item']), $this->crypto()->encrypt($values['task']), $this->encryptOrNull($values['note']), Database::now(), $id]);
        if ($changed !== []) {
            $this->event($id, $userId, 'edit', null, null, implode(', ', $changed));
        }
    }

    public function addNote(int $id, int $userId, string $note): void
    {
        $this->event($id, $userId, 'note', null, null, $this->crypto()->encrypt($note));
        ($this->connection)()->prepare('UPDATE meeting_tasks SET updated_at = ? WHERE id = ?')->execute([Database::now(), $id]);
    }

    /**
     * 타임라인 메모는 암호화돼 있다
     */
    public function eventNote(array $event): ?string
    {
        if ($event['note'] === null) {
            return null;
        }

        return $event['type'] === 'note' ? $this->crypto()->decrypt((string) $event['note']) : (string) $event['note'];
    }

    /**
     * 할 일에 고를 수 있는 서비스: 사용 중인 시스템 + 이미 쓴 분류 + 기본 분류
     *
     * @return list<string>
     */
    public function services(): array
    {
        $pdo = ($this->connection)();
        $names = $pdo->query('SELECT name FROM systems WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $used = $pdo->query('SELECT DISTINCT service FROM meeting_tasks ORDER BY service')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_unique([...$used, ...$names, ...self::DEFAULT_SERVICES]));
    }

    /**
     * 결정 사항 체크리스트를 할 일로 나눈다. '- [ ] 항목: 할 일 (참석자 5)' → 항목·할 일·담당 라벨
     *
     * @return list<array{item: string, task: string, assignee_label: ?string}>
     */
    public static function fromDecisions(string $decisions): array
    {
        $tasks = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $decisions)) as $line) {
            if (!preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?(.+?)\s*$/u', $line, $m)) {
                continue;
            }
            $text = $m[1];
            $label = null;
            if (preg_match('/\s*\((참석자 \d+)\)\s*$/u', $text, $who)) {
                $label = $who[1];
                $text = trim(substr($text, 0, -strlen($who[0])));
            }
            [$item, $task] = str_contains($text, ':') ? array_map('trim', explode(':', $text, 2)) : [mb_substr($text, 0, 30), $text];
            $tasks[] = ['item' => mb_substr($item, 0, 100), 'task' => $task === '' ? $item : $task, 'assignee_label' => $label];
        }

        return $tasks;
    }

    /** 볼 수 있는 회의 조건 (MeetingRepository 와 같은 규칙). 매개변수: 사용자 ID 두 번 */
    private const VISIBLE = "(m.visibility = 'all' OR m.author_user_id = ? OR (m.visibility = 'attendees' AND EXISTS (SELECT 1 FROM meeting_participants a WHERE a.meeting_id = m.id AND a.user_id = ?)))";

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $tail, array $params, bool $decrypt = true): array
    {
        $statement = ($this->connection)()->prepare(
            "SELECT t.id, t.meeting_id, t.service, t.item_enc, t.task_enc, t.note_enc, t.status, t.done_at, t.sort,
                    t.assignee_user_id, t.assignee_label, t.created_at, t.updated_at,
                    m.held_at AS meeting_held_at, m.title AS meeting_title, m.subtitle AS meeting_subtitle, m.author_user_id AS meeting_author_id,
                    COALESCE(au.name, pu.name) AS assignee_name, COALESCE(au.id, pu.id) AS assignee_id
             FROM meeting_tasks t
             JOIN meetings m ON m.id = t.meeting_id
             LEFT JOIN users au ON au.id = t.assignee_user_id
             LEFT JOIN meeting_participants p ON t.assignee_user_id IS NULL AND p.meeting_id = t.meeting_id AND p.label = t.assignee_label
             LEFT JOIN users pu ON pu.id = p.user_id
             {$tail}"
        );
        $statement->execute($params);

        return array_map(function (array $row) use ($decrypt): array {
            if ($decrypt) {
                $row['item'] = $this->crypto()->decrypt((string) $row['item_enc']);
                $row['task'] = $this->crypto()->decrypt((string) $row['task_enc']);
                $row['note'] = $row['note_enc'] === null ? null : $this->crypto()->decrypt((string) $row['note_enc']);
            }
            unset($row['item_enc'], $row['task_enc'], $row['note_enc']);
            // 계정과 연결되지 않은 라벨은 이름 대신 라벨로 보인다 (집계에서는 미지정 아님)
            if ($row['assignee_name'] === null && $row['assignee_label'] !== null) {
                $row['assignee_name'] = $row['assignee_label'];
            }

            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function event(int $taskId, ?int $userId, string $type, ?string $from, ?string $to, ?string $note): void
    {
        ($this->connection)()
            ->prepare('INSERT INTO meeting_task_events (task_id, user_id, type, from_value, to_value, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$taskId, $userId, $type, $from, $to, $note, Database::now()]);
    }

    private static function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function encryptOrNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $this->crypto()->encrypt($value);
    }

    private function crypto(): Crypto
    {
        return $this->crypto ?? throw new RuntimeException('APP_KEY 가 설정되지 않아 회의록을 읽거나 쓸 수 없습니다.');
    }
}
