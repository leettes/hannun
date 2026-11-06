<?php

declare(strict_types=1);

// DB 파일이 없으면 만들고, 적용되지 않은 마이그레이션을 실행한다.

use App\Database;
use App\Migrator;

$root = dirname(__DIR__);
require $root . '/config/env.php';

$path = Database::path();
if ($path !== ':memory:' && !is_dir(dirname($path))) {
    mkdir(dirname($path), 0775, true);
}

$applied = (new Migrator(Database::connection(), $root . '/database/migrations'))->run();

foreach ($applied as $version) {
    echo "Migrated {$version}\n";
}
echo $applied === [] ? "Nothing to migrate. ({$path})\n" : 'Applied ' . count($applied) . " migration(s). ({$path})\n";
