<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

final class IpAllowList
{
    /**
     * @param list<string>|null $ranges null 이면 모든 IP 허용
     */
    private function __construct(private readonly ?array $ranges)
    {
    }

    public static function allowAll(): self
    {
        return new self(null);
    }

    /**
     * 쉼표로 구분한 IP/CIDR 목록. 잘못된 값이 있으면 예외(설정 실수로 열리지 않게).
     */
    public static function fromString(string $list): self
    {
        $ranges = array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $v): bool => $v !== ''));

        foreach ($ranges as $range) {
            if (!IpMatcher::isValid($range)) {
                throw new InvalidArgumentException("잘못된 IP/CIDR 설정: {$range}");
            }
        }

        return new self($ranges);
    }

    public function allows(string $ip): bool
    {
        if ($this->ranges === null) {
            return true;
        }

        foreach ($this->ranges as $range) {
            if (IpMatcher::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public function isUnrestricted(): bool
    {
        return $this->ranges === null;
    }
}
