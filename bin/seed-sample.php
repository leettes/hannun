<?php

declare(strict_types=1);

// 빈 DB 에 화면 확인용 샘플 데이터(5개 시스템, 리소스, 점검 이력, 장애)를 넣는다.
// 시각은 실행 시점 기준이므로 시간이 지나면 '마지막 점검'이 오래된 것으로 보인다.

use App\Database;
use App\Env;
use App\Sample\SampleSeeder;

require dirname(__DIR__) . '/config/env.php';

if (Env::get('APP_ENV', 'production') === 'production') {
    fwrite(STDERR, 'APP_ENV=production 에서는 샘플 데이터를 넣지 않습니다.' . PHP_EOL);
    exit(1);
}

try {
    $counts = (new SampleSeeder(Database::connection()))->run();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

printf("샘플 데이터를 넣었습니다. 시스템 %d개, 리소스 %d개, 장애 %d건 (%s)\n",
    $counts['systems'], $counts['resources'], $counts['incidents'], Database::path());
