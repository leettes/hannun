<?php

declare(strict_types=1);

namespace Tests;

use App\Alerts\HttpChecker;
use PHPUnit\Framework\TestCase;

final class HttpCheckerIntervalTest extends TestCase
{
    public function testWithoutIntervalChecksEveryRun(): void
    {
        $now = strtotime('2026-11-19 12:00:00');
        $this->assertTrue(HttpChecker::due([], '2026-11-19 11:59:50', $now));
        $this->assertTrue(HttpChecker::due(['check_interval' => 1], '2026-11-19 11:59:50', $now));
    }

    public function testFiveMinuteInterval(): void
    {
        $config = ['check_interval' => 5];
        $now = strtotime('2026-11-19 12:00:00');
        $this->assertTrue(HttpChecker::due($config, null, $now), '처음에는 바로');
        $this->assertFalse(HttpChecker::due($config, '2026-11-19 11:56:00', $now), '4분 지남');
        $this->assertTrue(HttpChecker::due($config, '2026-11-19 11:55:20', $now), 'cron 이 조금 늦어도 5분 주기 유지');
        $this->assertTrue(HttpChecker::due($config, '2026-11-19 11:50:00', $now));
    }
}
