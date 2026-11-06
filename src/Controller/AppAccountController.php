<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\AppRegistry;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\SystemRegistry;
use App\View;

/**
 * 개발자 계정 (Apple Developer / Google Play Console). admin 전용 경로에서만 연결한다.
 * 비밀번호·API 키는 저장하지 않는다. 앱이 참조하므로 삭제하지 않고 비활성화한다.
 */
final class AppAccountController extends Controller
{
    private const FLASH_KEY = 'app_accounts_flash';

    private const BASE = '/settings/app-accounts';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly AppRegistry $apps,
        private readonly SystemRegistry $registry,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->page('settings-app-accounts', [
            'title' => '개발자 계정',
            'active' => 'app-accounts',
            'accounts' => $this->apps->accounts(),
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): Response
    {
        $platform = $request->query('platform') ?? 'apple';

        return $this->form(null, [
            'platform' => isset(AppRegistry::ACCOUNT_PLATFORMS[$platform]) ? $platform : 'apple',
            'name' => '', 'team_id' => '', 'login_email' => '', 'company_id' => '', 'membership_expires_at' => '', 'notes' => '', 'active' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        $platform = $request->input('platform') ?? '';
        $input = ['active' => true] + $this->input($request, $platform);
        $errors = $this->validate($input, null);
        if (!isset(AppRegistry::ACCOUNT_PLATFORMS[$platform])) {
            $errors['platform'] = '플랫폼을 선택하세요.';
        }
        if ($errors !== []) {
            return $this->form(null, $input, $errors);
        }

        $this->apps->createAccount(self::values($input) + ['platform' => $platform]);

        return $this->done($request, "{$input['name']} 계정을 등록했습니다.");
    }

    public function edit(Request $request, string $id): Response
    {
        $account = $this->find($id);
        if ($account === null) {
            return $this->notFound('존재하지 않는 개발자 계정입니다.');
        }

        return $this->form($account, [
            'platform' => $account['platform'],
            'name' => $account['name'],
            'team_id' => (string) ($account['team_id'] ?? ''),
            'login_email' => (string) ($account['login_email'] ?? ''),
            'company_id' => (string) ($account['company_id'] ?? ''),
            'membership_expires_at' => (string) ($account['membership_expires_at'] ?? ''),
            'notes' => (string) ($account['notes'] ?? ''),
            'active' => (bool) $account['is_active'],
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $account = $this->find($id);
        if ($account === null) {
            return $this->notFound('존재하지 않는 개발자 계정입니다.');
        }

        $input = $this->input($request, $account['platform']) + ['active' => $request->input('active') === '1'];
        $errors = $this->validate($input, (int) $account['id']);
        if (!$input['active'] && $account['is_active'] && ($used = $this->apps->activeAppsOfAccount((int) $account['id'])) !== []) {
            $errors['active'] = '사용 중인 앱(' . implode(', ', $used) . ')의 개발자 계정을 먼저 바꾸세요.';
        }
        if ($errors !== []) {
            return $this->form($account, $input, $errors);
        }

        $this->apps->updateAccount((int) $account['id'], self::values($input));

        return $this->done($request, "{$input['name']} 계정을 저장했습니다.");
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request, string $platform): array
    {
        $apple = $platform === 'apple';

        return [
            'platform' => $platform,
            'name' => trim($request->input('name') ?? ''),
            // 팀 ID 와 멤버십 만료일은 Apple 만
            'team_id' => $apple ? strtoupper(trim($request->input('team_id') ?? '')) : '',
            'login_email' => strtolower(trim($request->input('login_email') ?? '')),
            'company_id' => $request->input('company_id') ?? '',
            'membership_expires_at' => $apple ? trim($request->input('membership_expires_at') ?? '') : '',
            'notes' => trim($request->input('notes') ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function validate(array $input, ?int $id): array
    {
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 50) {
            $errors['name'] = '계정 이름을 50자 이내로 입력하세요 (예: 미리 Apple).';
        }
        if ($input['team_id'] !== '' && !preg_match('/^[A-Z0-9]{10}$/', $input['team_id'])) {
            $errors['team_id'] = '팀 ID 는 영문 대문자·숫자 10자리입니다 (Membership details 에서 확인).';
        }
        if ($input['login_email'] !== '' && !filter_var($input['login_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['login_email'] = '올바른 이메일을 입력하세요.';
        }
        if ($input['company_id'] !== '' && (!ctype_digit($input['company_id']) || $this->registry->company((int) $input['company_id']) === null)) {
            $errors['company_id'] = '등록된 관리회사를 선택하세요.';
        }
        if ($input['membership_expires_at'] !== '' && !self::validDate($input['membership_expires_at'])) {
            $errors['membership_expires_at'] = '날짜를 YYYY-MM-DD 형식으로 입력하세요.';
        }
        if (mb_strlen($input['notes']) > 500) {
            $errors['notes'] = '메모는 500자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, team_id: ?string, login_email: ?string, company_id: ?int, membership_expires_at: ?string, notes: ?string, active: bool}
     */
    private static function values(array $input): array
    {
        $blank = static fn (string $v): ?string => $v === '' ? null : $v;

        return [
            'name' => $input['name'],
            'team_id' => $blank($input['team_id']),
            'login_email' => $blank($input['login_email']),
            'company_id' => $input['company_id'] === '' ? null : (int) $input['company_id'],
            'membership_expires_at' => $blank($input['membership_expires_at']),
            'notes' => $blank($input['notes']),
            'active' => $input['active'],
        ];
    }

    /**
     * @param array<string, mixed>|null $account
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $account, array $values, array $errors = []): Response
    {
        return $this->page('settings-app-account-form', [
            'title' => $account === null ? '개발자 계정 등록' : (string) $account['name'],
            'active' => 'app-accounts',
            'account' => $account,
            'companies' => $this->registry->companies(),
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->apps->account((int) $id) : null;
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect(self::BASE);
    }
}
