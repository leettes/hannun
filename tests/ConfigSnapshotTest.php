<?php

declare(strict_types=1);

namespace Tests;

use App\ConfigSnapshot;
use App\Database;
use App\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestClient;

final class ConfigSnapshotTest extends TestCase
{
    private static function freshDb(): PDO
    {
        $pdo = Database::open(':memory:');
        (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->run();

        return $pdo;
    }

    public function testRoundTripIntoFreshDatabase(): void
    {
        $source = (new TestClient())->pdo();
        $source->exec("INSERT INTO companies (name, business_no) VALUES ('가회사 ''따옴표''', '1248100998')");
        $source->exec("INSERT INTO apps (system_id, platform, name, bundle_id, notes) VALUES (1, 'ios', '앱', 'kr.a.app', '줄바꿈\n메모')");
        $sql = ConfigSnapshot::export($source);

        $target = self::freshDb();
        ConfigSnapshot::import($target, $sql);

        $this->assertSame($sql, ConfigSnapshot::export($target), '불러온 뒤 다시 내보내면 같다');
        $this->assertSame("가회사 '따옴표'", $target->query("SELECT name FROM companies WHERE business_no = '1248100998'")->fetchColumn());
        $this->assertSame("줄바꿈\n메모", $target->query("SELECT notes FROM apps WHERE bundle_id = 'kr.a.app'")->fetchColumn());
        $this->assertSame(
            (int) $source->query('SELECT COUNT(*) FROM resources')->fetchColumn(),
            (int) $target->query('SELECT COUNT(*) FROM resources')->fetchColumn(),
        );
        $this->assertSame([], $target->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM check_results')->fetchColumn(), '실행 데이터는 넣지 않는다');
    }

    public function testRuntimeChangesDoNotChangeTheFile(): void
    {
        $pdo = (new TestClient())->pdo();
        $before = ConfigSnapshot::export($pdo);

        $pdo->exec("UPDATE resources SET current_status = 'down', last_checked_at = '2030-01-01 00:00:00', resolved_ips = '1.2.3.4', domain_info = '{}'");
        $pdo->exec("UPDATE users SET last_login_at = '2030-01-01 00:00:00'");
        $pdo->exec("INSERT INTO check_results (resource_id, source, status, checked_at) SELECT id, 'dashboard', 'down', '2030-01-01 00:00:00' FROM resources");
        $this->assertSame($before, ConfigSnapshot::export($pdo), '점검·로그인으로는 바뀌지 않는다');

        $pdo->exec("UPDATE systems SET name = '바뀐 이름' WHERE id = 1");
        $this->assertStringContainsString("'바뀐 이름'", ConfigSnapshot::export($pdo), '설정이 바뀌면 바뀐다');
    }

    public function testImportKeepsRuntimeColumnsAndNeverDeletesHistory(): void
    {
        $pdo = (new TestClient())->pdo();
        $sql = ConfigSnapshot::export($pdo);
        $owners = (int) $pdo->query('SELECT COUNT(*) FROM system_owners')->fetchColumn();

        $pdo->exec("UPDATE resources SET current_status = 'down', name = '로컬에서 바꾼 이름' WHERE id = 1");
        $pdo->exec("INSERT INTO companies (name) VALUES ('파일에 없는 회사')");
        // 파일에 없는 서비스와 그 장애 이력: 지우면 CASCADE 로 이력이 사라지므로 남겨야 한다
        $pdo->exec("INSERT INTO systems (code, name, environment, is_active) VALUES ('extra', '추가', 'prod', 1)");
        $extra = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO incidents (system_id, source, alert_key, severity, status, title, started_at) SELECT {$extra}, source, 'k', severity, status, 't', started_at FROM incidents LIMIT 1");
        $incidents = (int) $pdo->query('SELECT COUNT(*) FROM incidents')->fetchColumn();
        $pdo->exec("INSERT INTO system_owners (system_id, user_id) SELECT 1, id FROM users WHERE id NOT IN (SELECT user_id FROM system_owners WHERE system_id = 1) LIMIT 1");
        $pdo->exec("INSERT INTO domain_overrides (domain, registrar, updated_at) VALUES ('x.com', 'X', '2026-01-01 00:00:00')");

        ConfigSnapshot::import($pdo, $sql);

        $row = $pdo->query('SELECT name, current_status FROM resources WHERE id = 1')->fetch();
        $this->assertNotSame('로컬에서 바꾼 이름', $row['name'], '설정 컬럼은 파일 값');
        $this->assertSame('down', $row['current_status'], '실행 컬럼은 그대로');
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = '파일에 없는 회사'")->fetchColumn(), '주요 테이블은 지우지 않는다');
        $this->assertSame($incidents, (int) $pdo->query('SELECT COUNT(*) FROM incidents')->fetchColumn(), '장애 이력 유지');
        $this->assertSame($owners, (int) $pdo->query('SELECT COUNT(*) FROM system_owners')->fetchColumn(), '담당자 연결은 파일과 같게');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM domain_overrides')->fetchColumn());
    }

    public function testRejectsSchemaMismatchAndRollsBackOnError(): void
    {
        $pdo = (new TestClient())->pdo();
        $sql = ConfigSnapshot::export($pdo);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM systems')->fetchColumn();

        try {
            ConfigSnapshot::import($pdo, str_replace('-- 스키마: ', '-- 스키마: 999_', $sql));
            $this->fail('스키마가 다르면 거부');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('composer migrate', $e->getMessage());
        }

        // 중간에 실패하면 통째로 되돌린다 (앞에서 바뀐 서비스 이름도 원래대로)
        $pdo->exec("UPDATE systems SET name = '되돌려져야 함' WHERE id = 1");
        try {
            ConfigSnapshot::import($pdo, str_replace("\n\nCOMMIT;", "\nINSERT INTO no_such_table VALUES (1);\nCOMMIT;", $sql));
            $this->fail('없는 테이블');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('변경 없음', $e->getMessage());
        }
        $this->assertSame('되돌려져야 함', $pdo->query('SELECT name FROM systems WHERE id = 1')->fetchColumn());
        $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM systems')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
    }

    public function testWriteOnlyWhenChanged(): void
    {
        $pdo = (new TestClient())->pdo();
        $path = sys_get_temp_dir() . '/config-' . bin2hex(random_bytes(4)) . '.sql';
        try {
            $this->assertTrue(ConfigSnapshot::write($pdo, $path));
            $this->assertFalse(ConfigSnapshot::write($pdo, $path));
            $pdo->exec("UPDATE systems SET description = '새 설명' WHERE id = 1");
            $this->assertTrue(ConfigSnapshot::write($pdo, $path));
        } finally {
            @unlink($path);
        }
    }
}
