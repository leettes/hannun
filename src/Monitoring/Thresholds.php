<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * 초기 모니터링 기준(안). docs/PLAN.md 3장과 값을 맞춘다.
 */
final class Thresholds
{
    public const HTTP_SLOW_MS = 2000;
    public const SSL_WARN_DAYS = 30;

    public const EC2_CPU = 80;
    public const EC2_MEMORY = 85;
    public const EC2_DISK = 80;

    public const RDS_CPU = 80;
    public const RDS_CONNECTIONS_RATIO = 80;
    public const RDS_FREE_MEMORY_MIN = 10;
    public const RDS_FREE_STORAGE_MIN = 20;

    /**
     * @param array{up: bool, response_ms: ?int, ssl_days_left: ?int} $http
     */
    public static function http(array $http): string
    {
        if (!$http['up']) {
            return Status::DOWN;
        }
        if (($http['response_ms'] ?? 0) >= self::HTTP_SLOW_MS
            || ($http['ssl_days_left'] !== null && $http['ssl_days_left'] < self::SSL_WARN_DAYS)) {
            return Status::WARNING;
        }

        return Status::UP;
    }

    /**
     * @param array{status_check_ok: bool, cpu: float, memory: float, disk: float} $ec2
     */
    public static function ec2(array $ec2): string
    {
        if (!$ec2['status_check_ok']) {
            return Status::DOWN;
        }
        if ($ec2['cpu'] >= self::EC2_CPU || $ec2['memory'] >= self::EC2_MEMORY || $ec2['disk'] >= self::EC2_DISK) {
            return Status::WARNING;
        }

        return Status::UP;
    }

    /**
     * @param array{available: bool, cpu: float, connections: int, max_connections: int, free_memory: float, free_storage: float} $rds
     */
    public static function rds(array $rds): string
    {
        if (!$rds['available']) {
            return Status::DOWN;
        }
        if ($rds['cpu'] >= self::RDS_CPU
            || self::connectionRatio($rds) >= self::RDS_CONNECTIONS_RATIO
            || $rds['free_memory'] < self::RDS_FREE_MEMORY_MIN
            || $rds['free_storage'] < self::RDS_FREE_STORAGE_MIN) {
            return Status::WARNING;
        }

        return Status::UP;
    }

    /**
     * @param array{connections: int, max_connections: int} $rds
     */
    public static function connectionRatio(array $rds): float
    {
        return $rds['max_connections'] > 0 ? $rds['connections'] / $rds['max_connections'] * 100 : 0.0;
    }
}
