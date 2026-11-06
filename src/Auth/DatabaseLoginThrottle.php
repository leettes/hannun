<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use Closure;
use PDO;

final class DatabaseLoginThrottle implements LoginThrottle
{
    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    public function tooManyAttempts(string $email, string $ip): bool
    {
        $pdo = ($this->connection)();
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        // 이메일 기준 실패는 마지막 로그인 성공 이후만 센다.
        $byEmail = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND success = 0 AND created_at >= ?
               AND created_at > COALESCE(
                   (SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND success = 1), \'1970-01-01\')'
        );
        $byEmail->execute([$email, $since, $email]);
        if ((int) $byEmail->fetchColumn() >= self::MAX_FAILURES_PER_EMAIL) {
            return true;
        }

        $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?');
        $byIp->execute([$ip, $since]);

        return (int) $byIp->fetchColumn() >= self::MAX_FAILURES_PER_IP;
    }

    public function record(string $email, string $ip, bool $success, ?string $userAgent = null): void
    {
        ($this->connection)()
            ->prepare('INSERT INTO login_attempts (email, ip, success, user_agent, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, $ip, (int) $success, $userAgent === null ? null : mb_substr($userAgent, 0, 255), Database::now()]);
    }
}
