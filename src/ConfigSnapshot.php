<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * 기본 설정 데이터(서비스, 리소스, 사용자, AWS 계정, 관리회사, 앱 등)를 SQL 파일로 내보내고 불러온다.
 * database/config.sql 을 git 으로 관리한다. cron 이 설정이 바뀔 때마다 이 파일을 최신으로 다시 쓴다.
 *
 * - 점검 결과·장애·알림·로그인 기록 같은 실행 데이터는 넣지 않는다
 * - cron 이 계속 바꾸는 컬럼(현재 상태, 마지막 점검, 연결 IP, 자동 도메인 정보, 자동 조회 시각 등)도 넣지 않는다.
 *   그래서 파일은 설정을 바꿀 때만 바뀐다. 불러온 뒤 cron 이 다시 채운다
 * - 불러오기는 기본 키 기준으로 덮어쓴다 (실행 데이터 컬럼은 그대로 둔다)
 * - 파일에 없는 행은 연결 테이블(SYNC_DELETE)에서만 지운다. 서비스·리소스 등은 지우지 않는다:
 *   앱에서도 삭제하지 않고 비활성화하며, 지우면 ON DELETE CASCADE 로 장애 이력·점검 결과까지 사라진다
 */
final class ConfigSnapshot
{
    public const PATH = 'database/config.sql';

    /** 테이블 => 넣지 않는 컬럼. 순서는 외래 키 순서 (먼저 참조되는 것부터) */
    public const TABLES = [
        'companies' => [],
        'aws_accounts' => [],
        'users' => ['last_login_at'],
        'systems' => [],
        'system_owners' => [],
        'resources' => ['current_status', 'last_checked_at', 'resolved_ips', 'resolved_at', 'domain_info'],
        'app_store_accounts' => [],
        'apps' => ['store_info', 'checked_at', 'store_error', 'icon_checked_at', 'icon_error'],
        'domain_overrides' => [],
    ];

    /** 파일에 없는 행을 지우는 테이블. 앱에서 행을 지우는 곳이고, 다른 데이터가 참조하지 않는다 */
    public const SYNC_DELETE = ['system_owners', 'domain_overrides'];

    public static function export(PDO $pdo): string
    {
        $lines = [
            '-- 한눈 기본 설정 데이터. bin/config.php export 가 만든다 (cron 이 설정 변경 시 자동 갱신). 직접 고치지 말 것.',
            '-- 불러오기: composer migrate && composer config:import',
            '-- 스키마: ' . self::schemaVersion($pdo),
            '',
            'BEGIN;',
        ];
        $deletes = [];
        foreach (self::TABLES as $table => $excluded) {
            [$columns, $keys] = self::columns($pdo, $table, $excluded);
            $rows = $pdo->query(sprintf('SELECT %s FROM %s ORDER BY %s', self::list($columns), $table, self::list($keys)))->fetchAll(PDO::FETCH_ASSOC);

            $lines[] = '';
            $lines[] = "-- {$table} (" . count($rows) . ')';
            $updates = array_values(array_diff($columns, $keys));
            foreach ($rows as $row) {
                $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, self::list($columns), implode(', ', array_map(static fn ($v): string => self::literal($pdo, $v), $row)));
                $sql .= $updates === []
                    ? ' ON CONFLICT DO NOTHING;'
                    : sprintf(' ON CONFLICT (%s) DO UPDATE SET %s;', self::list($keys), implode(', ', array_map(static fn (string $c): string => "{$c} = excluded.{$c}", $updates)));
                $lines[] = $sql;
            }
            if (in_array($table, self::SYNC_DELETE, true)) {
                $deletes[$table] = self::deleteMissing($pdo, $table, $keys, $rows);
            }
        }

        // 파일에 없는 행은 지운다 (참조하는 쪽부터)
        $lines[] = '';
        $lines[] = '-- 파일에 없는 연결 행 삭제 (서비스·리소스 등은 지우지 않는다)';
        foreach (array_reverse($deletes) as $sql) {
            $lines[] = $sql;
        }
        $lines[] = '';
        $lines[] = 'COMMIT;';

        return implode("\n", $lines) . "\n";
    }

    /**
     * 내용이 바뀌었을 때만 쓴다.
     *
     * @return bool 썼으면 true
     */
    public static function write(PDO $pdo, string $path): bool
    {
        $sql = self::export($pdo);
        if (is_file($path) && file_get_contents($path) === $sql) {
            return false;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $sql) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("{$path} 을(를) 쓸 수 없습니다 (쓰기 권한 확인).");
        }

        return true;
    }

    /**
     * 설정 SQL 을 불러온다. DB 스키마가 파일과 같아야 한다 (먼저 migrate).
     */
    public static function import(PDO $pdo, string $sql): void
    {
        if (preg_match('/^-- 스키마: (\S+)/m', $sql, $m) && $m[1] !== self::schemaVersion($pdo)) {
            throw new RuntimeException("설정 파일 스키마({$m[1]})와 DB 스키마(" . self::schemaVersion($pdo) . ')가 다릅니다. composer migrate 후 다시 시도하세요.');
        }
        // 트랜잭션은 파일 안의 BEGIN/COMMIT 이 연다. 실패하면 되돌린다
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('설정 불러오기 실패 (변경 없음): ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 마지막으로 적용한 마이그레이션
     */
    public static function schemaVersion(PDO $pdo): string
    {
        return (string) ($pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() ?: '-');
    }

    /**
     * @param list<string> $excluded
     * @return array{list<string>, list<string>} [내보낼 컬럼, 기본 키]
     */
    private static function columns(PDO $pdo, string $table, array $excluded): array
    {
        $info = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        if ($info === []) {
            throw new RuntimeException("테이블 {$table} 이 없습니다.");
        }
        $columns = [];
        $keys = [];
        foreach ($info as $col) {
            if ((int) $col['pk'] > 0) {
                $keys[(int) $col['pk']] = (string) $col['name'];
            }
            if (!in_array($col['name'], $excluded, true)) {
                $columns[] = (string) $col['name'];
            }
        }
        ksort($keys);

        return [$columns, array_values($keys)];
    }

    /**
     * @param list<string> $keys
     * @param list<array<string, mixed>> $rows
     */
    private static function deleteMissing(PDO $pdo, string $table, array $keys, array $rows): string
    {
        if ($rows === []) {
            return "DELETE FROM {$table};";
        }
        $tuples = array_map(
            static fn (array $row): string => count($keys) === 1
                ? self::literal($pdo, $row[$keys[0]])
                : '(' . implode(', ', array_map(static fn (string $k): string => self::literal($pdo, $row[$k]), $keys)) . ')',
            $rows,
        );
        $target = count($keys) === 1 ? $keys[0] : '(' . self::list($keys) . ')';

        return count($keys) === 1
            ? sprintf('DELETE FROM %s WHERE %s NOT IN (%s);', $table, $target, implode(', ', $tuples))
            : sprintf('DELETE FROM %s WHERE %s NOT IN (VALUES %s);', $table, $target, implode(', ', $tuples));
    }

    private static function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            default => (string) $pdo->quote((string) $value),
        };
    }

    /**
     * @param list<string> $names
     */
    private static function list(array $names): string
    {
        return implode(', ', $names);
    }
}
