<?php

declare(strict_types=1);

namespace App\Monitoring;

final class Status
{
    public const UP = 'up';
    public const WARNING = 'warning';
    public const DOWN = 'down';
    public const UNKNOWN = 'unknown';

    /**
     * 나쁜 정도. UNKNOWN(점검 기록 없음·점검 중지·리소스 없음)은 이상이 아니므로 가장 낮다:
     * 다른 상태가 하나라도 있으면 그 상태를 따르고, 화면에도 표시하지 않는다.
     */
    private const RANK = [self::UNKNOWN => -1, self::UP => 0, self::WARNING => 2, self::DOWN => 3];

    private const LABELS = [
        self::UP => '정상',
        self::WARNING => '경고',
        self::DOWN => '장애',
        self::UNKNOWN => '알 수 없음',
    ];

    /**
     * 가장 나쁜 상태를 돌려준다. 비어 있으면 UNKNOWN.
     *
     * @param iterable<string> $statuses
     */
    public static function worst(iterable $statuses): string
    {
        $worst = null;
        foreach ($statuses as $status) {
            if ($worst === null || self::RANK[$status] > self::RANK[$worst]) {
                $worst = $status;
            }
        }

        return $worst ?? self::UNKNOWN;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
