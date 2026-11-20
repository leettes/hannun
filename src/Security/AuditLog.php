<?php

declare(strict_types=1);

namespace App\Security;

use App\Database;
use Closure;
use PDO;

/**
 * 감사 로그. 기록(INSERT)과 조회만 있다. 수정·삭제는 1년 지난 기록 정리(purge)뿐이고 정리 자체도 기록한다.
 * detail 에는 변경 요약만 넣는다 (회의록 본문·비밀값은 넣지 않는다).
 */
final class AuditLog
{
    public const RETENTION_DAYS = 365;
    public const PAGE_SIZE = 50;

    /** 화면 표시용 이름. 여기 없는 행위는 코드 그대로 보인다 */
    public const ACTIONS = [
        'auth.totp_enable' => '2단계 인증 등록',
        'auth.totp_disable' => '2단계 인증 해제',
        'auth.totp_ok' => '2단계 인증 통과',
        'auth.totp_fail' => '2단계 인증 실패',
        'user.meeting_access' => '회의록 권한 변경',
        'user.totp_reset' => '2단계 인증 초기화',
        'meeting.view' => '회의록 열람',
        'meeting.create' => '회의록 작성',
        'meeting.update' => '회의록 수정',
        'meeting.delete' => '회의록 삭제',
        'meeting.visibility' => '공개 범위 변경',
        'audit.purge' => '감사 로그 정리',
    ];

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function record(
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $detail = [],
    ): void {
        ($this->connection)()
            ->prepare('INSERT INTO audit_logs (user_id, action, target_type, target_id, ip, user_agent, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $userId,
                $action,
                $targetType,
                $targetId,
                $ip,
                $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                Database::now(),
            ]);
    }

    /**
     * 요청한 사용자·IP·브라우저로 기록한다
     *
     * @param array<string, mixed> $detail
     */
    public function recordRequest(\App\Http\Request $request, string $action, ?string $targetType = null, ?int $targetId = null, array $detail = [], ?int $userId = null): void
    {
        $user = $request->attribute('user');
        $ip = $request->attribute('client_ip');
        $this->record(
            $userId ?? ($user instanceof \App\Auth\User ? $user->id : null),
            $action,
            $targetType,
            $targetId,
            is_string($ip) ? $ip : $request->remoteAddr(),
            $request->header('User-Agent'),
            $detail,
        );
    }

    /**
     * 최근 $windowSeconds 안에서, 마지막 통과 이후의 2단계 인증 실패 횟수
     */
    public function recentTotpFailures(int $userId, int $windowSeconds): int
    {
        $statement = ($this->connection)()->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE user_id = ? AND action = 'auth.totp_fail' AND created_at >= ?
               AND created_at > COALESCE((SELECT MAX(created_at) FROM audit_logs WHERE user_id = ? AND action = 'auth.totp_ok'), '1970-01-01')"
        );
        $statement->execute([$userId, date('Y-m-d H:i:s', time() - $windowSeconds), $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{user_id?: ?int, action?: ?string, from?: ?string, to?: ?string} $filter from/to 는 Y-m-d
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filter, int $page = 1): array
    {
        $where = [];
        $params = [];
        if (($filter['user_id'] ?? null) !== null) {
            $where[] = 'a.user_id = ?';
            $params[] = $filter['user_id'];
        }
        if (($filter['action'] ?? null) !== null) {
            $where[] = 'a.action = ?';
            $params[] = $filter['action'];
        }
        if (($filter['from'] ?? null) !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $filter['from'] . ' 00:00:00';
        }
        if (($filter['to'] ?? null) !== null) {
            $where[] = 'a.created_at <= ?';
            $params[] = $filter['to'] . ' 23:59:59';
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $pdo = ($this->connection)();

        $count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a {$sqlWhere}");
        $count->execute($params);

        $statement = $pdo->prepare(
            "SELECT a.*, u.name AS user_name, u.email AS user_email
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             {$sqlWhere} ORDER BY a.id DESC LIMIT ? OFFSET ?"
        );
        $statement->execute([...$params, self::PAGE_SIZE, (max(1, $page) - 1) * self::PAGE_SIZE]);

        return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * 보관 기간이 지난 기록을 지우고, 지웠으면 그 사실을 기록한다 (cron)
     *
     * @return int 지운 행 수
     */
    public function purge(int $now): int
    {
        $before = date('Y-m-d H:i:s', $now - self::RETENTION_DAYS * 86400);
        $statement = ($this->connection)()->prepare('DELETE FROM audit_logs WHERE created_at < ?');
        $statement->execute([$before]);
        $deleted = $statement->rowCount();
        if ($deleted > 0) {
            $this->record(null, 'audit.purge', detail: ['deleted' => $deleted, 'before' => $before]);
        }

        return $deleted;
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
