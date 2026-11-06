<?php

declare(strict_types=1);

use App\AppFactory;

require __DIR__ . '/env.php';

$root = dirname(__DIR__);
$config = AppFactory::configFromEnv();

ini_set('display_errors', $config['debug'] ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');
error_reporting(E_ALL);

return AppFactory::create($root, $config);
