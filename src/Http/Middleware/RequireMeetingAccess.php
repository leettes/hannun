<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\User;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Security\TwoFactor;
use Closure;

/**
 * 회의록 경로(/meetings…)의 관문: 회의록 권한 + 2단계 인증 + 유휴 재인증.
 * 역할(viewer/operator/admin)과 별개다. 관리자라도 회의록 권한이 없으면 막는다.
 */
final class RequireMeetingAccess implements Middleware
{
    /**
     * @param list<string> $prefixes 보호할 경로
     * @param Closure(Request): Response $forbidden 회의록 권한 없음
     * @param Closure(): Response $unavailable APP_KEY 미설정
     */
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly array $prefixes,
        private readonly Closure $forbidden,
        private readonly Closure $unavailable,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        if (!$this->protects($request->path)) {
            return $next($request);
        }
        $user = $request->attribute('user');
        $session = $request->attribute('session');
        if (!$user instanceof User || !$session instanceof Session) {
            return $next($request); // Authenticate 가 로그인으로 보낸다
        }
        if (!$this->twoFactor->available()) {
            return ($this->unavailable)();
        }
        if (!$user->canReadMeetings()) {
            return ($this->forbidden)($request);
        }
        $back = http_build_query(['next' => $request->method === 'GET' ? $request->uri() : '/meetings']);
        if (!$user->hasTotp()) {
            return Response::redirect('/me/2fa?' . $back);
        }
        if (!$this->twoFactor->isVerified($session, $user)) {
            return Response::redirect('/2fa?' . $back);
        }

        $this->twoFactor->touch($session, $user);

        return $next($request)->withDefaultHeaders(['Cache-Control' => 'no-store']);
    }

    private function protects(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
