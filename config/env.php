<?php

declare(strict_types=1);

// .env 로드 (웹/CLI 공통)

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

date_default_timezone_set(App\Env::get('APP_TIMEZONE', 'Asia/Seoul'));
