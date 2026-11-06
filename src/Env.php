<?php

declare(strict_types=1);

namespace App;

final class Env
{
    /**
     * .env 값($_ENV)을 우선하고, 없으면 프로세스 환경변수(docker compose 등)를 읽는다.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        $value = getenv($key);

        return $value === false ? $default : $value;
    }

}
