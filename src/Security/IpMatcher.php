<?php

declare(strict_types=1);

namespace App\Security;

/**
 * IPv4/IPv6 주소와 CIDR(예: 10.0.0.0/8, 2001:db8::/32) 비교.
 */
final class IpMatcher
{
    public static function matches(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = self::split($cidr);
        $ipBin = self::toBinary($ip);
        $subnetBin = self::toBinary($subnet);

        if ($ipBin === null || $subnetBin === null || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits ??= strlen($ipBin) * 8;
        $fullBytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    public static function isValid(string $cidr): bool
    {
        [$subnet, $bits] = self::split($cidr);
        $binary = self::toBinary($subnet);

        return $binary !== null && ($bits === null || ($bits >= 0 && $bits <= strlen($binary) * 8));
    }

    public static function isValidIp(string $ip): bool
    {
        return self::toBinary($ip) !== null;
    }

    /**
     * @return array{string, ?int}
     */
    private static function split(string $cidr): array
    {
        $cidr = trim($cidr);
        if (!str_contains($cidr, '/')) {
            return [$cidr, null];
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        return [$subnet, ctype_digit($bits) ? (int) $bits : -1];
    }

    /**
     * IPv4-mapped IPv6(::ffff:1.2.3.4)는 IPv4로 취급한다.
     */
    private static function toBinary(string $ip): ?string
    {
        $binary = @inet_pton(trim($ip));
        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($binary, 12);
        }

        return $binary;
    }
}
