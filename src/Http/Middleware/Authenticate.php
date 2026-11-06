<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\View;
use Closure;

/**
 * 로그인하지 않았거나 세션이 만료되면 /login 으로 보낸다.
 * 로그인 사용자는 request 의 user 속성, 템플릿의 $currentUser 로 사용한다.
 */
final class Authenticate implements Middleware
{
    public const USER_KEY = 'user_id';
    public const ACTIVITY_KEY = 'last_activity';

    /**
     * @param list<string> $publicPaths 로그인 없이 접근 가능한 경로
     */
    public function __construct(
        private readonly UserProvider $users,
        private readonly View $view,
        private readonly int $idleTimeoutSeconds,
        private readonly array $publicPaths = [],
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $session = $request->attribute('session');
        $user = $session instanceof Session ? $this->currentUser($session) : null;
        $isPublic = in_array($request->path, $this->publicPaths, true);

        if ($user === null) {
            $this->view->share('currentUser', null);
            $expired = $session instanceof Session && $session->get('expired') === true;
            if ($session instanceof Session) {
                $session->remove('expired');
            }

            if ($isPublic) {
                return $next($request);
            }

            return Response::redirect('/login?' . http_build_query(array_filter([
                'next' => $request->method === 'GET' ? $request->uri() : null,
                'expired' => $expired ? '1' : null,
            ])));
        }

        $session->set(self::ACTIVITY_KEY, time());
        $this->view->share('currentUser', $user);

        return $next($request->withAttribute('user', $user))
            ->withDefaultHeaders(['Cache-Control' => 'no-store']);
    }

    private function currentUser(Session $session): ?User
    {
        $id = $session->get(self::USER_KEY);
        if (!is_int($id)) {
            return null;
        }

        $lastActivity = $session->get(self::ACTIVITY_KEY);
        if (!is_int($lastActivity) || time() - $lastActivity > $this->idleTimeoutSeconds) {
            $this->logout($session);
            $session->set('expired', true);

            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null || !$user->active) {
            $this->logout($session);

            return null;
        }

        return $user;
    }

    private function logout(Session $session): void
    {
        $session->remove(self::USER_KEY);
        $session->remove(self::ACTIVITY_KEY);
    }
}
