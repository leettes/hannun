<?php

declare(strict_types=1);

namespace App\Meetings;

use App\Database;
use App\Security\Crypto;
use Closure;
use PDO;
use RuntimeException;

/**
 * 회의록 저장소. 본문·결정 사항·요약·녹취 원문은 여기서만 암호화·복호화한다.
 *
 * 공개 범위 (작성자는 항상 본다):
 * - all: 회의록 권한이 있는 직원 전체
 * - attendees: 직원 계정과 연결된 참석자
 * - private: 작성자만
 * 볼 수 없는 회의는 목록에도 나오지 않는다 (제목 노출 방지)
 *
 * 참석자는 라벨('참석자 3', 직원 이름)로 두고, 직원 계정과 연결되면 user_id 를 채운다.
 */
final class MeetingRepository
{
    public const VISIBILITY = [
        'attendees' => '참석자만',
        'all' => '회의록 권한자 전체',
        'private' => '작성자만',
    ];

    /** 카드·배지에 쓰는 짧은 이름 */
    public const VISIBILITY_SHORT = [
        'attendees' => '참석자만',
        'all' => '전 직원',
        'private' => '작성자만',
    ];

    public const PAGE_SIZE = 30;

    /** 녹취 발언 줄: '참석자 3 12:34' 또는 '홍길동 1:02:03' */
    public const SPEAKER_LINE = '/^(.{1,30}?)\s+((?:\d{1,2}:)?\d{1,2}:\d{2})\s*$/u';

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
            $where[] = 'EXISTS (SELECT 1 FROM meeting_participants x WHERE x.meeting_id = m.id AND x.user_id = ?)';
            $params[] = $filter['attendee_id'];
        }
        if (($filter['author_id'] ?? null) !== null) {
            $where[] = 'm.author_user_id = ?';
            $params[] = $filter['author_id'];
        }
        if (($filter['q'] ?? null) !== null) {
            $where[] = "(m.title LIKE ? ESCAPE '\\' OR m.subtitle LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($filter['q'], '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }

        return $this->list('WHERE ' . implode(' AND ', $where), $params, 'm.held_at DESC, m.id DESC', $page);
    }

    /**
     * 최근 회의 카드: 요약·태그·참석 인원·할 일 진행
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $userId, int $limit = 3): array
    {
        [$where, $params] = $this->visibleWhere($userId);
        $statement = ($this->connection)()->prepare(
            'SELECT m.id, m.title, m.subtitle, m.held_at, m.visibility, m.summary_enc, m.topics, m.duration_minutes,
                    (SELECT COUNT(*) FROM meeting_participants p WHERE p.meeting_id = m.id) AS participant_count,
                    (SELECT COUNT(*) FROM meeting_tasks t WHERE t.meeting_id = m.id) AS task_count,
                    (SELECT COUNT(*) FROM meeting_tasks t WHERE t.meeting_id = m.id AND t.status = \'done\') AS task_done
             FROM meetings m WHERE m.deleted_at IS NULL AND ' . implode(' AND ', $where) . '
             ORDER BY m.held_at DESC, m.id DESC LIMIT ?'
        );
        $statement->execute([...$params, $limit]);

        return array_map(function (array $row): array {
            $row['summary'] = $row['summary_enc'] === null ? '' : $this->crypto()->decrypt((string) $row['summary_enc']);
            unset($row['summary_enc']);
            $row['topics'] = self::decodeTopics($row['topics']);

            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 요약 카드용: 볼 수 있는 회의 수 (이번 달)와 최근 회의 일시
     *
     * @return array{month: int, total: int, latest: ?string}
     */
    public function stats(int $userId, string $month): array
    {
        [$where, $params] = $this->visibleWhere($userId);
        $statement = ($this->connection)()->prepare(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN substr(m.held_at, 1, 7) = ? THEN 1 ELSE 0 END) AS month, MAX(m.held_at) AS latest
             FROM meetings m WHERE m.deleted_at IS NULL AND ' . implode(' AND ', $where)
        );
        $statement->execute([$month, ...$params]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['month' => (int) ($row['month'] ?? 0), 'total' => (int) ($row['total'] ?? 0), 'latest' => $row['latest'] ?? null];
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
     * 사용자가 볼 수 있으면 복호화한 회의록(녹취 원문 제외), 없거나 볼 수 없으면 null.
     * 지운 회의록은 작성자만 (휴지통에서) 본다.
     *
     * @return array<string, mixed>|null
     */
    public function findFor(int $id, int $userId): ?array
    {
        [$where, $params] = $this->visibleWhere($userId);
        $statement = ($this->connection)()->prepare(
            'SELECT m.id, m.title, m.subtitle, m.held_at, m.system_id, m.visibility, m.body_enc, m.decisions_enc, m.summary_enc,
                    m.topics, m.duration_minutes, m.transcript_name, m.transcript_bytes, m.version,
                    m.author_user_id, m.deleted_at, m.created_at, m.updated_at,
                    s.name AS system_name, s.code AS system_code, u.name AS author_name
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
        $row['summary'] = $row['summary_enc'] === null ? '' : $this->crypto()->decrypt((string) $row['summary_enc']);
        unset($row['body_enc'], $row['decisions_enc'], $row['summary_enc']);
        $row['topics'] = self::decodeTopics($row['topics']);
        $row['participants'] = $this->participants($id);
        $row['attendees'] = array_values(array_map(
            static fn (array $p): array => ['id' => (int) $p['user_id'], 'name' => (string) $p['user_name']],
            array_filter($row['participants'], static fn (array $p): bool => $p['user_id'] !== null),
        ));

        return $row;
    }

    /**
     * 녹취 원문 (findFor 로 볼 수 있는지 먼저 확인한 뒤 부른다)
     */
    public function transcript(int $id): ?string
    {
        $statement = ($this->connection)()->prepare('SELECT transcript_enc FROM meetings WHERE id = ?');
        $statement->execute([$id]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : $this->crypto()->decrypt((string) $value);
    }

    /**
     * 볼 수 있는 회의인지만 확인 (복호화하지 않음). 기능 화면의 출처 링크 표시용
     */
    public function isVisibleTo(int $id, int $userId): bool
    {
        [$where, $params] = $this->visibleWhere($userId);
        $statement = ($this->connection)()->prepare('SELECT 1 FROM meetings m WHERE m.id = ? AND m.deleted_at IS NULL AND ' . implode(' AND ', $where));
        $statement->execute([$id, ...$params]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>, subtitle?: ?string, summary?: ?string, topics?: list<string>, duration_minutes?: ?int, labels?: list<string>, transcript?: ?string, transcript_name?: ?string} $values
     */
    public function create(array $values, int $authorId): int
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $transcript = $values['transcript'] ?? null;
            $pdo->prepare(
                'INSERT INTO meetings (title, subtitle, held_at, system_id, visibility, body_enc, decisions_enc, summary_enc, topics, duration_minutes,
                                       transcript_enc, transcript_name, transcript_bytes, author_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $values['title'], self::blank($values['subtitle'] ?? null), $values['held_at'], $values['system_id'], $values['visibility'],
                $this->crypto()->encrypt($values['body']), $this->crypto()->encrypt($values['decisions']),
                $this->encryptOrNull($values['summary'] ?? null), self::encodeTopics($values['topics'] ?? []), $values['duration_minutes'] ?? null,
                $this->encryptOrNull($transcript), $transcript === null ? null : ($values['transcript_name'] ?? '녹취.txt'), $transcript === null ? null : strlen($transcript),
                $authorId, Database::now(), Database::now(),
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->saveParticipants($pdo, $id, $values['labels'] ?? [], $values['attendees']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * 본문·결정 사항이 바뀌면 버전을 올린다
     *
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>, subtitle?: ?string, summary?: ?string, topics?: list<string>, duration_minutes?: ?int} $values
     */
    public function update(int $id, array $values, bool $contentChanged = true): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE meetings SET title = ?, subtitle = ?, held_at = ?, system_id = ?, visibility = ?, body_enc = ?, decisions_enc = ?,
                        summary_enc = ?, topics = ?, duration_minutes = ?, version = version + ?, updated_at = ? WHERE id = ?'
            )->execute([
                $values['title'], self::blank($values['subtitle'] ?? null), $values['held_at'], $values['system_id'], $values['visibility'],
                $this->crypto()->encrypt($values['body']), $this->crypto()->encrypt($values['decisions']),
                $this->encryptOrNull($values['summary'] ?? null), self::encodeTopics($values['topics'] ?? []), $values['duration_minutes'] ?? null,
                $contentChanged ? 1 : 0, Database::now(), $id,
            ]);
            $this->saveParticipants($pdo, $id, [], $values['attendees']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 가져오기에서 비어 있는 칸만 채운다 (요약·시간·참석자·녹취)
     *
     * @param array{summary?: ?string, duration_minutes?: ?int, labels?: list<string>, transcript?: ?string, transcript_name?: ?string, subtitle?: ?string, topics?: list<string>} $values
     */
    public function fillMissing(int $id, array $values): void
    {
        $pdo = ($this->connection)();
        $transcript = $values['transcript'] ?? null;
        $pdo->prepare(
            'UPDATE meetings SET
                summary_enc = COALESCE(summary_enc, ?), duration_minutes = COALESCE(duration_minutes, ?),
                subtitle = COALESCE(subtitle, ?), topics = COALESCE(topics, ?),
                transcript_enc = COALESCE(transcript_enc, ?), transcript_name = COALESCE(transcript_name, ?), transcript_bytes = COALESCE(transcript_bytes, ?)
             WHERE id = ?'
        )->execute([
            $this->encryptOrNull($values['summary'] ?? null), $values['duration_minutes'] ?? null,
            self::blank($values['subtitle'] ?? null), ($values['topics'] ?? []) === [] ? null : self::encodeTopics($values['topics']),
            $this->encryptOrNull($transcript), $transcript === null ? null : ($values['transcript_name'] ?? null), $transcript === null ? null : strlen($transcript),
            $id,
        ]);
        $insert = $pdo->prepare('INSERT OR IGNORE INTO meeting_participants (meeting_id, label, sort) VALUES (?, ?, ?)');
        foreach ($values['labels'] ?? [] as $i => $label) {
            $insert->execute([$id, $label, $i + 1]);
        }
    }

    /**
     * 참석자 라벨을 직원 계정과 연결 (null 이면 연결 해제)
     */
    public function linkParticipant(int $meetingId, int $participantId, ?int $userId): bool
    {
        $statement = ($this->connection)()->prepare('UPDATE meeting_participants SET user_id = ? WHERE id = ? AND meeting_id = ?');
        $statement->execute([$userId, $participantId, $meetingId]);

        return $statement->rowCount() === 1;
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
     * 녹취 원문에서 발언자 목록과 길이(분)를 읽는다. '참석자 3 12:34' 같은 줄이 발언 시작
     *
     * @return array{speakers: list<string>, minutes: ?int, lines: list<array{speaker: string, time: string, text: string}>}
     */
    public static function parseTranscript(string $text): array
    {
        $lines = [];
        $current = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match(self::SPEAKER_LINE, trim($line), $m)) {
                if ($current !== null) {
                    $lines[] = $current;
                }
                $current = ['speaker' => trim($m[1]), 'time' => $m[2], 'text' => ''];
                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $current['text'] = trim($current['text'] . "\n" . trim($line));
            }
        }
        if ($current !== null) {
            $lines[] = $current;
        }
        $speakers = array_values(array_unique(array_column($lines, 'speaker')));
        usort($speakers, static fn (string $a, string $b): int => strnatcmp($a, $b));
        $last = $lines === [] ? null : end($lines)['time'];
        $seconds = 0;
        foreach ($last === null ? [] : array_reverse(explode(':', $last)) as $i => $part) {
            $seconds += (int) $part * 60 ** $i;
        }

        return ['speakers' => $speakers, 'minutes' => $last === null ? null : max(1, (int) round($seconds / 60)), 'lines' => $lines];
    }

    /**
     * @return list<string>
     */
    public static function decodeTopics(mixed $json): array
    {
        $topics = is_string($json) ? json_decode($json, true) : null;

        return is_array($topics) ? array_values(array_filter($topics, 'is_string')) : [];
    }

    /**
     * @return array{list<string>, list<mixed>}
     */
    private function visibleWhere(int $userId): array
    {
        return [
            ["(m.visibility = 'all' OR m.author_user_id = ? OR (m.visibility = 'attendees' AND EXISTS (SELECT 1 FROM meeting_participants a WHERE a.meeting_id = m.id AND a.user_id = ?)))"],
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
            "SELECT m.id, m.title, m.subtitle, m.held_at, m.visibility, m.author_user_id, m.deleted_at, m.system_id,
                    s.name AS system_name, u.name AS author_name,
                    (SELECT COUNT(*) FROM meeting_participants a WHERE a.meeting_id = m.id) AS attendee_count,
                    (SELECT COUNT(*) FROM meeting_tasks t WHERE t.meeting_id = m.id) AS task_count
             FROM meetings m
             LEFT JOIN systems s ON s.id = m.system_id
             JOIN users u ON u.id = m.author_user_id
             {$where} ORDER BY {$order} LIMIT ? OFFSET ?"
        );
        $statement->execute([...$params, $size, (max(1, $page) - 1) * $size]);

        return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * @return list<array{id: int, label: string, user_id: ?int, user_name: ?string}>
     */
    private function participants(int $meetingId): array
    {
        $statement = ($this->connection)()->prepare(
            'SELECT p.id, p.label, p.user_id, u.name AS user_name FROM meeting_participants p LEFT JOIN users u ON u.id = p.user_id
             WHERE p.meeting_id = ? ORDER BY p.sort, p.id'
        );
        $statement->execute([$meetingId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'label' => (string) $r['label'],
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'user_name' => $r['user_name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 직원 참석자(체크박스)를 맞춘다. 녹취 라벨('참석자 N')은 지우지 않고 연결만 푼다
     *
     * @param list<string> $labels 새로 넣을 라벨 (녹취 발언자)
     * @param list<int> $userIds 참석자로 고른 직원
     */
    private function saveParticipants(PDO $pdo, int $meetingId, array $labels, array $userIds): void
    {
        $userIds = array_values(array_unique($userIds));
        $insertLabel = $pdo->prepare('INSERT OR IGNORE INTO meeting_participants (meeting_id, label, sort) VALUES (?, ?, ?)');
        foreach ($labels as $i => $label) {
            $insertLabel->execute([$meetingId, $label, $i + 1]);
        }

        $existing = $pdo->prepare('SELECT id, label, user_id FROM meeting_participants WHERE meeting_id = ?');
        $existing->execute([$meetingId]);
        $linked = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if ($p['user_id'] === null) {
                continue;
            }
            if (in_array((int) $p['user_id'], $userIds, true)) {
                $linked[] = (int) $p['user_id'];
            } elseif (preg_match('/^참석자 \d+$/u', (string) $p['label'])) {
                $pdo->prepare('UPDATE meeting_participants SET user_id = NULL WHERE id = ?')->execute([$p['id']]);
            } else {
                $pdo->prepare('DELETE FROM meeting_participants WHERE id = ?')->execute([$p['id']]);
            }
        }

        $name = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $insertUser = $pdo->prepare('INSERT INTO meeting_participants (meeting_id, label, user_id, sort) VALUES (?, ?, ?, 0)');
        foreach (array_diff($userIds, $linked) as $userId) {
            $name->execute([$userId]);
            $label = (string) $name->fetchColumn();
            // 같은 이름 라벨이 이미 있으면 (동명이인 등) 번호를 붙인다
            $taken = $pdo->prepare('SELECT 1 FROM meeting_participants WHERE meeting_id = ? AND label = ?');
            $taken->execute([$meetingId, $label]);
            $insertUser->execute([$meetingId, $taken->fetchColumn() === false ? $label : "{$label} #{$userId}", $userId]);
        }
    }

    /**
     * @param list<string> $topics
     */
    private static function encodeTopics(array $topics): ?string
    {
        $topics = array_values(array_unique(array_filter(array_map('trim', $topics), static fn (string $t): bool => $t !== '')));

        return $topics === [] ? null : json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
