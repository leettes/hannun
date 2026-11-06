<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public const DEFAULT_PATH = 'storage/database.sqlite';

    private static ?PDO $pdo = null;

    /**
     * .env 의 DB_DATABASE (프로젝트 루트 기준 상대 경로 또는 절대 경로)
     */
    public static function connection(): PDO
    {
        return self::$pdo ??= self::open(self::path());
    }

    public static function path(): string
    {
        $path = Env::get('DB_DATABASE', self::DEFAULT_PATH) ?: self::DEFAULT_PATH;

        return str_starts_with($path, '/') || $path === ':memory:' ? $path : dirname(__DIR__) . '/' . $path;
    }

    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // WAL: 읽기와 쓰기가 서로 막지 않게 한다. foreign_keys 는 연결마다 켜야 한다.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /**
     * DB 저장 시각 형식 (KST, 서머타임 없음)
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
