<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * database/migrations/*.sql 을 파일명 순서대로 한 번씩 실행한다.
 * SQLite 는 DDL 도 트랜잭션으로 묶이므로 실패한 파일은 통째로 되돌려진다.
 *
 * 첫 줄이 '-- migrator: foreign_keys=off' 인 파일은 외래 키를 끄고 실행한다(테이블 재생성용).
 * 이 PRAGMA 는 트랜잭션 안에서 바꿀 수 없어 트랜잭션 밖에서 끄고, 커밋 전에 외래 키 위반을 검사한다.
 */
final class Migrator
{
    private const FOREIGN_KEYS_OFF = '-- migrator: foreign_keys=off';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> 이번에 적용한 버전
     */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     TEXT PRIMARY KEY,
                applied_at  TEXT NOT NULL
            )'
        );

        $applied = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

        $done = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            $foreignKeysOff = str_starts_with($sql, self::FOREIGN_KEYS_OFF);
            if ($foreignKeysOff) {
                $this->pdo->exec('PRAGMA foreign_keys = OFF');
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                if ($foreignKeysOff && $this->pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                    throw new RuntimeException("{$version}: 외래 키 위반이 있어 되돌립니다.");
                }
                $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                    ->execute([$version, Database::now()]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            } finally {
                if ($foreignKeysOff) {
                    $this->pdo->exec('PRAGMA foreign_keys = ON');
                }
            }

            $done[] = $version;
        }

        return $done;
    }
}
