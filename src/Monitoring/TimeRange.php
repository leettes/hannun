<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;

/**
 * 차트 조회 기간. 미리 정한 기간(최근 1시간 등) 또는 직접 고른 기간이고,
 * 점을 너무 많이 그리지 않도록 기간 길이에 맞춘 묶음 간격(초)을 함께 정한다.
 */
final class TimeRange
{
    public const DEFAULT = '1h';

    /** key => [이름, 초, 묶음 간격(초)] */
    public const PRESETS = [
        '1h' => ['1시간', 3600, 60],
        '3h' => ['3시간', 10800, 60],
        '6h' => ['6시간', 21600, 120],
        '24h' => ['하루', 86400, 600],
        '7d' => ['일주일', 604800, 3600],
    ];

    public const CUSTOM = 'custom';

    /** 직접 고른 기간의 최대 길이. check_results 보관 기간(90일)과 맞춘다 */
    public const MAX_CUSTOM_SECONDS = 90 * 86400;

    /** 직접 고른 기간에서 쓸 묶음 간격 후보 */
    private const BUCKETS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400];

    /** 차트 한 줄에 그릴 최대 점 개수 */
    private const MAX_POINTS = 180;

    /** datetime-local 입력 형식 */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    private function __construct(
        public readonly string $key,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly int $bucketSeconds,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * 쿼리 문자열(range, from, to)에서 기간을 만든다. 잘못된 값이면 기본 기간과 함께 error 를 채운다.
     */
    public static function fromQuery(?string $range, ?string $from, ?string $to, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable();

        if ($range === self::CUSTOM) {
            $start = self::parseInput($from);
            $end = self::parseInput($to);
            $error = match (true) {
                $start === null || $end === null => '시작과 끝 시각을 모두 입력해 주세요.',
                $start >= $end => '끝 시각은 시작 시각보다 뒤여야 합니다.',
                $end->getTimestamp() - $start->getTimestamp() > self::MAX_CUSTOM_SECONDS => '기간은 최대 90일까지 볼 수 있습니다.',
                default => null,
            };
            if ($error === null) {
                return new self(self::CUSTOM, $start, $end, self::bucketFor($end->getTimestamp() - $start->getTimestamp()));
            }

            return self::preset(self::DEFAULT, $now, $error);
        }

        return self::preset($range !== null && isset(self::PRESETS[$range]) ? $range : self::DEFAULT, $now);
    }

    public static function preset(string $key, ?DateTimeImmutable $now = null, ?string $error = null): self
    {
        [, $seconds, $bucket] = self::PRESETS[$key];
        $now ??= new DateTimeImmutable();

        return new self($key, $now->modify("-{$seconds} seconds"), $now, $bucket, $error);
    }

    public function isCustom(): bool
    {
        return $this->key === self::CUSTOM;
    }

    public function label(): string
    {
        return $this->isCustom()
            ? $this->from->format('Y-m-d H:i') . ' ~ ' . $this->to->format('Y-m-d H:i')
            : '최근 ' . self::PRESETS[$this->key][0];
    }

    public function bucketLabel(): string
    {
        $s = $this->bucketSeconds;

        return match (true) {
            $s % 86400 === 0 => ($s / 86400) . '일',
            $s % 3600 === 0 => ($s / 3600) . '시간',
            default => ($s / 60) . '분',
        };
    }

    /**
     * 점이 MAX_POINTS 개를 넘지 않는 가장 촘촘한 묶음 간격
     */
    private static function bucketFor(int $seconds): int
    {
        foreach (self::BUCKETS as $bucket) {
            if ($seconds / $bucket <= self::MAX_POINTS) {
                return $bucket;
            }
        }

        return self::BUCKETS[array_key_last(self::BUCKETS)];
    }

    private static function parseInput(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . self::INPUT_FORMAT, $value);

        return $parsed !== false && $parsed->format(self::INPUT_FORMAT) === $value ? $parsed : null;
    }
}
