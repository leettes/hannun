<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\LoginThrottle;

final class InMemoryLoginThrottle implements LoginThrottle
{
    /** @var list<array{email: string, ip: string, success: bool}> */
    public array $attempts = [];

    public function tooManyAttempts(string $email, string $ip): bool
    {
        $byEmail = 0;
        $byIp = 0;
        foreach ($this->attempts as $a) {
            if ($a['email'] === $email) {
                $byEmail = $a['success'] ? 0 : $byEmail + 1;
            }
            if ($a['ip'] === $ip && !$a['success']) {
                $byIp++;
            }
        }

        return $byEmail >= self::MAX_FAILURES_PER_EMAIL || $byIp >= self::MAX_FAILURES_PER_IP;
    }

    public function record(string $email, string $ip, bool $success, ?string $userAgent = null): void
    {
        $this->attempts[] = ['email' => $email, 'ip' => $ip, 'success' => $success];
    }
}
