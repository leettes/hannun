<?php

declare(strict_types=1);

namespace Tests;

use App\Monitoring\TimeRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeRangeTest extends TestCase
{
    private const NOW = '2026-11-04 15:30:00';

    /**
     * @return iterable<string, array{?string, string, int, int}>
     */
    public static function presets(): iterable
    {
        yield 'default' => [null, '1h', 3600, 60];
        yield 'unknown' => ['2y', '1h', 3600, 60];
        yield '3h' => ['3h', '3h', 10800, 60];
        yield '6h' => ['6h', '6h', 21600, 120];
        yield 'day' => ['24h', '24h', 86400, 600];
        yield 'week' => ['7d', '7d', 604800, 3600];
    }

    #[DataProvider('presets')]
    public function testPresetEndsNow(?string $query, string $key, int $seconds, int $bucket): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $range = TimeRange::fromQuery($query, null, null, $now);

        $this->assertSame($key, $range->key);
        $this->assertEquals($now, $range->to);
        $this->assertSame($seconds, $range->to->getTimestamp() - $range->from->getTimestamp());
        $this->assertSame($bucket, $range->bucketSeconds);
        $this->assertNull($range->error);
        $this->assertLessThanOrEqual(180, $seconds / $bucket, '점 180개 이하');
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function customRanges(): iterable
    {
        yield '2 hours' => ['2026-11-01T09:00', '2026-11-01T11:00', 60, '1분'];
        yield '3 days' => ['2026-11-01T00:00', '2026-11-04T00:00', 1800, '30분'];
        yield '30 days' => ['2026-10-01T00:00', '2026-10-31T00:00', 21600, '6시간'];
        yield '90 days' => ['2026-08-06T00:00', '2026-11-04T00:00', 43200, '12시간'];
    }

    #[DataProvider('customRanges')]
    public function testCustomRangePicksBucket(string $from, string $to, int $bucket, string $bucketLabel): void
    {
        $range = TimeRange::fromQuery('custom', $from, $to);

        $this->assertTrue($range->isCustom());
        $this->assertNull($range->error);
        $this->assertSame($from, $range->from->format(TimeRange::INPUT_FORMAT));
        $this->assertSame($bucket, $range->bucketSeconds);
        $this->assertSame($bucketLabel, $range->bucketLabel());
        $this->assertSame(str_replace('T', ' ', $from) . ' ~ ' . str_replace('T', ' ', $to), $range->label());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function invalidCustomRanges(): iterable
    {
        yield 'missing' => [null, '2026-11-01T11:00'];
        yield 'garbage' => ['어제', '2026-11-01T11:00'];
        yield 'invalid date' => ['2026-02-30T10:00', '2026-03-01T11:00'];
        yield 'reversed' => ['2026-11-01T11:00', '2026-11-01T10:00'];
        yield 'same' => ['2026-11-01T11:00', '2026-11-01T11:00'];
        yield 'too long' => ['2026-01-01T00:00', '2026-11-01T00:00'];
    }

    #[DataProvider('invalidCustomRanges')]
    public function testInvalidCustomRangeFallsBackToDefault(?string $from, ?string $to): void
    {
        $range = TimeRange::fromQuery('custom', $from, $to, new DateTimeImmutable(self::NOW));

        $this->assertSame(TimeRange::DEFAULT, $range->key);
        $this->assertNotNull($range->error);
        $this->assertSame('최근 1시간', $range->label());
    }
}
