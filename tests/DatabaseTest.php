<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\DatabaseLoginThrottle;
use App\Auth\DatabaseUserProvider;
use App\Auth\LoginThrottle;
use App\Database;
use App\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * 임시 SQLite 파일에 실제 마이그레이션을 적용해 SQL 을 검증한다.
 */
final class DatabaseTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'monitoring-test-') . '.sqlite';
        $this->pdo = Database::open($this->path);
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->run();
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testMigrationsCreateTablesAndAreIdempotent(): void
    {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([
            'alert_events', 'app_store_accounts', 'app_store_daily_stats', 'app_store_reviews', 'apps', 'aws_accounts', 'check_results', 'companies', 'domain_overrides', 'incident_events', 'incidents',
            'login_attempts', 'notifications', 'resources', 'schema_migrations', 'system_owners', 'systems', 'users',
        ], $tables);
        $this->assertSame([], (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->run());
    }

    public function testWalAndForeignKeysAreEnabled(): void
    {
        $this->assertSame('wal', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testUserProviderCreatesFindsAndRecordsLogin(): void
    {
        $users = new DatabaseUserProvider(fn (): PDO => $this->pdo);
        $id = $users->create('admin@example.com', '관리자', password_hash('pw', PASSWORD_BCRYPT, ['cost' => 4]), 'admin');

        $user = $users->findByEmail('ADMIN@example.com');
        $this->assertNotNull($user, '이메일은 대소문자 구분 없이 찾는다');
        $this->assertSame($id, $user->id);
        $this->assertTrue($user->active);
        $this->assertTrue($user->can('operator'));

        $users->recordLogin($id, 'new-hash');
        $row = $this->pdo->query("SELECT last_login_at, password_hash FROM users WHERE id = {$id}")->fetch();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['last_login_at']);
        $this->assertSame('new-hash', $row['password_hash']);

        $users->recordLogin($id);
        $this->assertSame('new-hash', $users->findById($id)?->passwordHash, 'null 이면 해시 유지');
    }

    public function testUserProviderListsAndUpdates(): void
    {
        $users = new DatabaseUserProvider(fn (): PDO => $this->pdo);
        $b = $users->create('b@example.com', '나', 'h1', 'viewer');
        $a = $users->create('a@example.com', '가', 'h1', 'admin');

        $users->update($b, '다', 'operator', false);
        $this->assertSame([$a, $b], array_map(static fn ($u): int => $u->id, $users->all()), '활성 계정 먼저');
        $user = $users->findById($b);
        $this->assertSame(['다', 'operator', false, 'h1'], [$user?->name, $user?->role, $user?->active, $user?->passwordHash]);

        $users->update($b, '다', 'operator', true, 'h2');
        $this->assertSame('h2', $users->findById($b)?->passwordHash);
        $this->assertNull($users->findById($b)?->lastLoginAt);
        $users->recordLogin($b);
        $this->assertNotNull($users->findById($b)?->lastLoginAt);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $users = new DatabaseUserProvider(fn (): PDO => $this->pdo);
        $users->create('admin@example.com', 'A', 'h', 'admin');

        $this->expectException(PDOException::class);
        $users->create('Admin@Example.com', 'B', 'h', 'admin');
    }

    public function testCheckConstraintRejectsUnknownRole(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO users (email, name, password_hash, role) VALUES ('x@example.com', 'x', 'h', 'root')");
    }

    public function testUpdatedAtTriggerRuns(): void
    {
        $this->pdo->exec("INSERT INTO users (email, name, password_hash, updated_at) VALUES ('x@example.com', 'x', 'h', '2000-01-01 00:00:00')");
        $this->pdo->exec("UPDATE users SET name = 'y' WHERE email = 'x@example.com'");

        $this->assertNotSame('2000-01-01 00:00:00', $this->pdo->query('SELECT updated_at FROM users')->fetchColumn());
    }

    public function testOnlyOneActiveIncidentPerAlertKey(): void
    {
        $this->pdo->exec("INSERT INTO systems (code, name) VALUES ('miri', '미리')");
        $insert = $this->pdo->prepare(
            "INSERT INTO incidents (system_id, source, alert_key, title, started_at, status) VALUES (1, 'cloudwatch', 'cw:cpu', 't', '2026-01-01 00:00:00', ?)"
        );
        $insert->execute(['resolved']);
        $insert->execute(['open']);

        $this->expectException(PDOException::class);
        $insert->execute(['acknowledged']);
    }

    public function testForeignKeyCascade(): void
    {
        $this->pdo->exec("INSERT INTO systems (code, name) VALUES ('miri', '미리')");
        $this->pdo->exec("INSERT INTO resources (system_id, type, name, identifier) VALUES (1, 'http', '웹', 'https://miri.example.com')");
        $this->pdo->exec('DELETE FROM systems WHERE id = 1');

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM resources')->fetchColumn());
    }

    public function testThrottleLocksEmailAfterFailuresAndResetsOnSuccess(): void
    {
        $throttle = new DatabaseLoginThrottle(fn (): PDO => $this->pdo);

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_EMAIL - 1; $i++) {
            $throttle->record('a@example.com', '10.0.0.1', false);
        }
        $this->assertFalse($throttle->tooManyAttempts('a@example.com', '10.0.0.1'));

        $throttle->record('a@example.com', '10.0.0.1', false, str_repeat('x', 300));
        $this->assertTrue($throttle->tooManyAttempts('a@example.com', '10.0.0.2'));
        $this->assertTrue($throttle->tooManyAttempts('A@EXAMPLE.COM', '10.0.0.2'), '이메일 대소문자 무시');
        $this->assertFalse($throttle->tooManyAttempts('b@example.com', '10.0.0.2'));

        // 마지막 성공 이후 실패만 센다. 같은 초에 기록된 실패와 섞이지 않게 실패 시각을 과거로 옮긴다.
        $this->pdo->exec("UPDATE login_attempts SET created_at = datetime(created_at, '-1 minute')");
        $throttle->record('a@example.com', '10.0.0.9', true);
        $this->assertFalse($throttle->tooManyAttempts('a@example.com', '10.0.0.2'));
    }

    public function testThrottleLocksIp(): void
    {
        $throttle = new DatabaseLoginThrottle(fn (): PDO => $this->pdo);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_IP; $i++) {
            $throttle->record("user{$i}@example.com", '10.0.0.1', false);
        }

        $this->assertTrue($throttle->tooManyAttempts('new@example.com', '10.0.0.1'));
        $this->assertFalse($throttle->tooManyAttempts('new@example.com', '10.0.0.2'));
    }

    public function testOldFailuresOutsideWindowAreIgnored(): void
    {
        $throttle = new DatabaseLoginThrottle(fn (): PDO => $this->pdo);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_EMAIL; $i++) {
            $throttle->record('a@example.com', '10.0.0.1', false);
        }
        $this->pdo->exec("UPDATE login_attempts SET created_at = datetime(created_at, '-16 minutes')");

        $this->assertFalse($throttle->tooManyAttempts('a@example.com', '10.0.0.1'));
    }
}
