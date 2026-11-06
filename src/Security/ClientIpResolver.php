<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;

/**
 * 실제 접속자 IP를 구한다.
 * REMOTE_ADDR 가 신뢰하는 프록시(ALB 등)일 때만 X-Forwarded-For 를 오른쪽부터 따라가며
 * 처음 만나는 신뢰하지 않는 주소를 접속자로 본다. 그 외에는 헤더를 무시한다(위조 방지).
 */
final class ClientIpResolver
{
    public function __construct(private readonly IpAllowList $trustedProxies)
    {
    }

    public static function withoutProxies(): self
    {
        return new self(IpAllowList::fromString(''));
    }

    public function resolve(Request $request): string
    {
        $remote = $request->remoteAddr();
        if (!$this->trustedProxies->allows($remote)) {
            return $remote;
        }

        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded === null || $forwarded === '') {
            return $remote;
        }

        $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($hops as $hop) {
            if (!IpMatcher::isValidIp($hop)) {
                break;
            }
            if (!$this->trustedProxies->allows($hop)) {
                return $hop;
            }
            $remote = $hop;
        }

        return $remote;
    }
}
