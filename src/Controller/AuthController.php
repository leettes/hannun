<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\LoginThrottle;
use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Middleware\Authenticate;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\View;
use PDOException;

final class AuthController extends Controller
{
    /** 존재하지 않는 계정도 같은 시간이 걸리도록 비교하는 더미 해시 */
    private const DUMMY_HASH = '$2y$12$QYSl0ww9kevja/u6lzmaMuBCHlCORsexAgj11rs2sNQeBlVtY/Nra';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly UserProvider $users,
        private readonly LoginThrottle $throttle,
    ) {
        parent::__construct($view, $data);
    }

    public function showLogin(Request $request): Response
    {
        if ($request->attribute('user') instanceof User) {
            return Response::redirect($this->safeNext($request->query('next')));
        }

        return $this->form(
            next: $request->query('next'),
            notice: $request->query('expired') === '1' ? '장시간 사용하지 않아 로그아웃되었습니다. 다시 로그인하세요.' : null,
        );
    }

    public function login(Request $request): Response
    {
        $session = $this->session($request);
        $email = strtolower(trim($request->input('email') ?? ''));
        $password = $request->input('password') ?? '';
        $next = $request->input('next');
        $ip = (string) ($request->attribute('client_ip') ?? $request->remoteAddr());

        if ($email === '' || $password === '') {
            return $this->form($email, $next, error: '이메일과 비밀번호를 입력하세요.', status: 422);
        }

        try {
            if ($this->throttle->tooManyAttempts($email, $ip)) {
                return $this->form($email, $next, error: '로그인 시도가 너무 많습니다. 15분 후 다시 시도하세요.', status: 429);
            }

            $user = $this->users->findByEmail($email);
            $valid = password_verify($password, $user?->passwordHash ?? self::DUMMY_HASH);

            if ($user === null || !$valid || !$user->active) {
                $this->throttle->record($email, $ip, false, $request->header('User-Agent'));

                return $this->form($email, $next, error: '이메일 또는 비밀번호가 올바르지 않습니다.', status: 422);
            }

            $this->throttle->record($email, $ip, true, $request->header('User-Agent'));
            $this->users->recordLogin(
                $user->id,
                password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null,
            );
        } catch (PDOException $e) {
            error_log((string) $e);

            return $this->form($email, $next, error: '사용자 DB에 연결할 수 없습니다. 잠시 후 다시 시도하세요.', status: 503);
        }

        $session->regenerate();
        $session->set(Authenticate::USER_KEY, $user->id);
        $session->set(Authenticate::ACTIVITY_KEY, time());

        return Response::redirect($this->safeNext($next));
    }

    public function logout(Request $request): Response
    {
        $session = $this->session($request);
        $session->clear();
        $session->regenerate();

        return Response::redirect('/login');
    }

    private function form(
        string $email = '',
        ?string $next = null,
        ?string $error = null,
        ?string $notice = null,
        int $status = 200,
    ): Response {
        return Response::html($this->view->render('login', [
            'title' => '로그인',
            'email' => $email,
            'next' => $this->safeNext($next),
            'error' => $error,
            'notice' => $notice,
        ], 'auth-layout'), $status);
    }

    /**
     * 로그인 후 이동할 주소는 이 사이트 내부 경로만 허용한다(오픈 리다이렉트 방지).
     */
    private function safeNext(?string $next): string
    {
        if ($next === null
            || !str_starts_with($next, '/')
            || str_starts_with($next, '//')
            || str_contains($next, '\\')
            || preg_match('/[\x00-\x1f]/', $next)
            || str_starts_with($next, '/login')) {
            return '/';
        }

        return $next;
    }
}
