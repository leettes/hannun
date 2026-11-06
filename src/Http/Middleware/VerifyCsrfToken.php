<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\View;
use Closure;

/**
 * 세션별 토큰을 발급하고 POST 요청의 _token(또는 X-CSRF-Token 헤더)을 검증한다.
 * 템플릿에서는 $csrfToken 으로 사용한다.
 */
final class VerifyCsrfToken implements Middleware
{
    public const SESSION_KEY = '_csrf';

    /**
     * @param Closure(): Response $rejected
     */
    public function __construct(
        private readonly View $view,
        private readonly Closure $rejected,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            return $next($request);
        }

        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = $request->input('_token') ?? $request->header('X-CSRF-Token') ?? '';
            if (!hash_equals($token, $sent)) {
                return ($this->rejected)();
            }
        }

        $this->view->share('csrfToken', $token);

        return $next($request);
    }
}
