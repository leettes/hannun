<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\View;

/**
 * 내 정보: 로그인한 본인의 연락처(장애 문자)와 비밀번호를 바꾼다. 모든 역할이 쓸 수 있다.
 * 이름·역할·사용 여부는 관리자가 사용자 관리에서 바꾼다.
 */
final class ProfileController extends Controller
{
    private const FLASH_KEY = 'profile_flash';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly UserProvider $users,
    ) {
        parent::__construct($view, $data);
    }

    public function show(Request $request): Response
    {
        $me = $this->me($request);
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->form($me, [
            'phone' => $me->phoneLabel() === '-' ? '' : $me->phoneLabel(),
            'sms_scope' => $me->smsScope,
        ], flash: is_string($flash) ? $flash : null);
    }

    public function updateContact(Request $request): Response
    {
        $me = $this->me($request);
        $values = [
            'phone' => trim($request->input('phone') ?? ''),
            'sms_scope' => $request->input('sms_scope') ?? $me->smsScope,
        ];

        $errors = [];
        if ($values['phone'] !== '' && User::normalizePhone($values['phone']) === null) {
            $errors['phone'] = '휴대폰 번호를 확인하세요 (예: 010-1234-5678).';
        }
        // 전체 서비스는 운영자 이상만 받는다
        if (!isset(User::smsScopesFor($me->role)[$values['sms_scope']])) {
            $errors['sms_scope'] = $values['sms_scope'] === 'all' ? '전체 서비스 알림은 운영자 이상만 받을 수 있습니다.' : '장애 문자 알림을 선택하세요.';
        }
        if ($errors !== []) {
            return $this->form($me, $values, $errors);
        }

        $this->users->updateContact($me->id, User::normalizePhone($values['phone']), $values['sms_scope']);

        return $this->done($request, '연락처를 저장했습니다.');
    }

    public function updatePassword(Request $request): Response
    {
        $me = $this->me($request);
        $current = $request->input('current_password') ?? '';
        $password = $request->input('password') ?? '';

        $errors = [];
        if (!password_verify($current, $me->passwordHash)) {
            $errors['current_password'] = '현재 비밀번호가 올바르지 않습니다.';
        }
        if (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
            $errors['password'] = '비밀번호는 ' . User::MIN_PASSWORD_LENGTH . '자 이상이어야 합니다.';
        } elseif ($password !== ($request->input('password_confirm') ?? '')) {
            $errors['password_confirm'] = '비밀번호가 일치하지 않습니다.';
        } elseif ($password === $current) {
            $errors['password'] = '현재 비밀번호와 다른 비밀번호를 입력하세요.';
        }
        if ($errors !== []) {
            return $this->form($me, [
                'phone' => $me->phoneLabel() === '-' ? '' : $me->phoneLabel(),
                'sms_scope' => $me->smsScope,
            ], $errors);
        }

        $this->users->update($me->id, $me->name, $me->role, $me->active, password_hash($password, PASSWORD_DEFAULT));
        // 비밀번호가 바뀌면 세션 ID 도 새로 만든다
        $this->session($request)->regenerate();

        return $this->done($request, '비밀번호를 바꿨습니다.');
    }

    private function me(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('로그인한 사용자만 쓸 수 있다 (Authenticate 미들웨어).');
        }

        // 세션의 사용자는 요청 처음에 읽은 값이라 저장 직후 화면에는 DB 값을 다시 읽는다
        return $this->users->findById($user->id) ?? $user;
    }

    /**
     * @param array{phone: string, sms_scope: string} $values
     * @param array<string, string> $errors
     */
    private function form(User $me, array $values, array $errors = [], ?string $flash = null): Response
    {
        return $this->page('profile', [
            'title' => '내 정보',
            'active' => 'profile',
            'me' => $me,
            'values' => $values,
            'errors' => $errors,
            'flash' => $flash,
        ], $errors === [] ? 200 : 422);
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect('/me');
    }
}
