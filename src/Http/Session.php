<?php

declare(strict_types=1);

namespace App\Http;

/**
 * $_SESSION 래퍼. 테스트에서는 배열 저장소로 생성한다.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $data;

    private bool $native = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public static function start(bool $secureCookie, string $name = 'monitoring_sid'): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            session_name($name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $session = new self();
        $session->native = true;
        $session->data = &$_SESSION;

        return $session;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * 세션 고정 공격 방지: 권한이 바뀔 때(로그인/로그아웃) 세션 ID를 새로 발급한다.
     */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        $this->data = [];
    }
}
