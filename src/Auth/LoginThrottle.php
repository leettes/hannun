<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * 로그인 무차별 대입 방지
 */
interface LoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 20;

    public function tooManyAttempts(string $email, string $ip): bool;

    public function record(string $email, string $ip, bool $success, ?string $userAgent = null): void;
}
