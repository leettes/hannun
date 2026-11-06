<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Security\ClientIpResolver;
use App\Security\IpAllowList;
use Closure;

/**
 * 허용된 IP/CIDR 에서만 접근을 허용한다. 접속자 IP는 request 의 client_ip 속성으로 넘긴다.
 */
final class IpRestriction implements Middleware
{
    /**
     * @param Closure(string): Response $denied 차단된 IP를 받아 응답을 만든다
     * @param list<string> $exemptPaths 제한하지 않는 경로 (외부 헬스체크 등)
     */
    public function __construct(
        private readonly IpAllowList $allowList,
        private readonly ClientIpResolver $resolver,
        private readonly Closure $denied,
        private readonly array $exemptPaths = [],
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $ip = $this->resolver->resolve($request);
        $request = $request->withAttribute('client_ip', $ip);

        if (in_array($request->path, $this->exemptPaths, true) || $this->allowList->allows($ip)) {
            return $next($request);
        }

        error_log(sprintf('[ip-restriction] denied %s %s %s', $ip, $request->method, $request->path));

        return ($this->denied)($ip);
    }
}
