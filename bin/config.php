<?php

declare(strict_types=1);

// 기본 설정 데이터 SQL (database/config.sql)
//   php bin/config.php export   현재 DB 의 설정을 파일로 (바뀐 경우에만 씀). cron 이 1분마다 실행
//   php bin/config.php import   파일의 설정을 DB 로 (먼저 composer migrate)

use App\ConfigSnapshot;
use App\Database;

require dirname(__DIR__) . '/config/env.php';

$command = $argv[1] ?? '';
$path = dirname(__DIR__) . '/' . ConfigSnapshot::PATH;

try {
    if ($command === 'export') {
        echo ConfigSnapshot::write(Database::connection(), $path) ? ConfigSnapshot::PATH . " 갱신\n" : "변경 없음\n";
    } elseif ($command === 'import') {
        if (!is_file($path)) {
            throw new RuntimeException(ConfigSnapshot::PATH . ' 이 없습니다.');
        }
        ConfigSnapshot::import(Database::connection(), (string) file_get_contents($path));
        echo ConfigSnapshot::PATH . " 불러옴 (" . Database::path() . ")\n";
    } else {
        fwrite(STDERR, "사용법: php bin/config.php export|import\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
