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
 * 관리자 계정 관리 (admin 전용 경로에서만 연결한다).
 * 계정은 장애 이력이 참조하므로 삭제하지 않고 비활성화한다.
 */
final class UserController extends Controller
{
    private const FLASH_KEY = 'users_flash';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly UserProvider $users,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->page('users', [
            'title' => '사용자 관리',
            'active' => 'users',
            'users' => $this->users->all(),
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, ['email' => '', 'name' => '', 'role' => 'viewer', 'active' => true, 'phone' => '', 'sms_scope' => User::defaultSmsScope('viewer')]);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request) + ['email' => strtolower(trim($request->input('email') ?? ''))];
        $errors = $this->validate($input, $request, passwordRequired: true);

        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '올바른 이메일을 입력하세요.';
        } elseif ($this->users->findByEmail($input['email']) !== null) {
            $errors['email'] = '이미 등록된 이메일입니다.';
        }

        if ($errors !== []) {
            return $this->form(null, $input, $errors);
        }

        $id = $this->users->create($input['email'], $input['name'], $this->hash($request), $input['role']);
        $this->users->updateContact($id, User::normalizePhone($input['phone']), $input['sms_scope']);

        return $this->done($request, "{$input['name']}({$input['email']}) 계정을 만들었습니다.");
    }

    public function edit(Request $request, string $id): Response
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->notFound('존재하지 않는 사용자입니다.');
        }

        return $this->form($user, [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'active' => $user->active,
            'phone' => $user->phoneLabel() === '-' ? '' : $user->phoneLabel(),
            'sms_scope' => $user->smsScope,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->notFound('존재하지 않는 사용자입니다.');
        }

        $input = $this->input($request) + ['email' => $user->email];
        $errors = $this->validate($input, $request, passwordRequired: false);

        // 자기 계정을 잠그면 관리자가 한 명도 남지 않을 수 있다
        $me = $request->attribute('user');
        if ($me instanceof User && $me->id === $user->id) {
            if ($input['role'] !== 'admin') {
                $errors['role'] = '자기 계정의 관리자 권한은 해제할 수 없습니다.';
            }
            if (!$input['active']) {
                $errors['active'] = '자기 계정은 비활성화할 수 없습니다.';
            }
        }

        if ($errors !== []) {
            return $this->form($user, $input, $errors);
        }

        $password = $request->input('password') ?? '';
        $this->users->update($user->id, $input['name'], $input['role'], $input['active'], $password === '' ? null : $this->hash($request));
        $this->users->updateContact($user->id, User::normalizePhone($input['phone']), $input['sms_scope']);

        return $this->done($request, "{$input['name']}({$user->email}) 계정을 저장했습니다.");
    }

    /**
     * @return array{name: string, role: string, active: bool, phone: string, sms_scope: string}
     */
    private function input(Request $request): array
    {
        return [
            'name' => trim($request->input('name') ?? ''),
            'role' => $request->input('role') ?? '',
            'active' => $request->input('active') === '1',
            'phone' => trim($request->input('phone') ?? ''),
            'sms_scope' => $request->input('sms_scope') ?? User::defaultSmsScope($request->input('role') ?? ''),
        ];
    }

    /**
     * @param array{name: string, role: string, phone: string, sms_scope: string} $input
     * @return array<string, string> 필드 => 오류 메시지
     */
    private function validate(array $input, Request $request, bool $passwordRequired): array
    {
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 50) {
            $errors['name'] = '이름을 50자 이내로 입력하세요.';
        }
        if (!isset(User::ROLES[$input['role']])) {
            $errors['role'] = '역할을 선택하세요.';
        }
        if ($input['phone'] !== '' && User::normalizePhone($input['phone']) === null) {
            $errors['phone'] = '휴대폰 번호를 확인하세요 (예: 010-1234-5678).';
        }
        if (!isset(User::smsScopesFor($input['role'])[$input['sms_scope']])) {
            $errors['sms_scope'] = $input['sms_scope'] === 'all' ? '전체 서비스 알림은 운영자 이상만 받을 수 있습니다.' : '장애 문자 알림을 선택하세요.';
        }

        $password = $request->input('password') ?? '';
        if ($password === '' && !$passwordRequired) {
            return $errors;
        }
        if (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
            $errors['password'] = '비밀번호는 ' . User::MIN_PASSWORD_LENGTH . '자 이상이어야 합니다.';
        } elseif ($password !== ($request->input('password_confirm') ?? '')) {
            $errors['password_confirm'] = '비밀번호가 일치하지 않습니다.';
        }

        return $errors;
    }

    /**
     * @param array{email: string, name: string, role: string, active: bool, phone: string, sms_scope: string} $values
     * @param array<string, string> $errors
     */
    private function form(?User $user, array $values, array $errors = []): Response
    {
        return $this->page('user-form', [
            'title' => $user === null ? '사용자 추가' : '사용자 수정',
            'active' => 'users',
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function find(string $id): ?User
    {
        return ctype_digit($id) ? $this->users->findById((int) $id) : null;
    }

    private function hash(Request $request): string
    {
        return password_hash($request->input('password') ?? '', PASSWORD_DEFAULT);
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect('/users');
    }
}
