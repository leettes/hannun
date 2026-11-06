<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\SystemRegistry;
use App\View;

/**
 * AWS 계정(루트 계정) 관리 정보 (admin 전용 경로에서만 연결한다).
 * 계정 ID, 별칭, 루트 이메일, MFA 여부, 메모만 둔다. 비밀번호·액세스 키는 저장하지 않는다.
 */
final class AwsAccountController extends Controller
{
    private const FLASH_KEY = 'aws_accounts_flash';

    private const BASE = '/settings/aws-accounts';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly SystemRegistry $registry,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->page('settings-aws-accounts', [
            'title' => 'AWS 계정',
            'active' => 'aws-accounts',
            'accounts' => $this->registry->awsAccounts(),
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, ['account_id' => '', 'alias' => '', 'company_id' => '', 'root_email' => '', 'mfa_enabled' => '', 'notes' => '']);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request) + ['account_id' => preg_replace('/[\s-]/', '', $request->input('account_id') ?? '') ?? ''];
        $errors = $this->validate($input);
        if (!preg_match('/^\d{12}$/', $input['account_id'])) {
            $errors['account_id'] = '12자리 숫자 계정 ID 를 입력하세요 (콘솔 오른쪽 위 계정 메뉴).';
        } elseif ($this->registry->awsAccountIdTaken($input['account_id'])) {
            $errors['account_id'] = '이미 등록된 계정입니다.';
        }

        if ($errors !== []) {
            return $this->form(null, $input, $errors);
        }

        $this->registry->createAwsAccount(self::values($input) + ['account_id' => $input['account_id']]);

        return $this->done($request, "{$input['alias']}({$input['account_id']}) 계정을 등록했습니다. 서비스 관리에서 서비스에 연결하세요.");
    }

    public function edit(Request $request, string $id): Response
    {
        $account = $this->find($id);
        if ($account === null) {
            return $this->notFound('존재하지 않는 계정입니다.');
        }

        return $this->form($account, [
            'account_id' => $account['account_id'],
            'alias' => $account['alias'],
            'company_id' => (string) ($account['company_id'] ?? ''),
            'root_email' => (string) ($account['root_email'] ?? ''),
            'mfa_enabled' => $account['mfa_enabled'] === null ? '' : (string) $account['mfa_enabled'],
            'notes' => (string) ($account['notes'] ?? ''),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $account = $this->find($id);
        if ($account === null) {
            return $this->notFound('존재하지 않는 계정입니다.');
        }

        $input = $this->input($request) + ['account_id' => $account['account_id']];
        $errors = $this->validate($input);
        if ($errors !== []) {
            return $this->form($account, $input, $errors);
        }

        $this->registry->updateAwsAccount((int) $account['id'], self::values($input));

        return $this->done($request, "{$input['alias']}({$account['account_id']}) 계정을 저장했습니다.");
    }

    /**
     * @return array{alias: string, company_id: string, root_email: string, mfa_enabled: string, notes: string}
     */
    private function input(Request $request): array
    {
        return [
            'alias' => trim($request->input('alias') ?? ''),
            'company_id' => $request->input('company_id') ?? '',
            'root_email' => strtolower(trim($request->input('root_email') ?? '')),
            'mfa_enabled' => $request->input('mfa_enabled') ?? '',
            'notes' => trim($request->input('notes') ?? ''),
        ];
    }

    /**
     * @param array{alias: string, company_id: string, root_email: string, mfa_enabled: string, notes: string} $input
     * @return array<string, string>
     */
    private function validate(array $input): array
    {
        $errors = [];
        if ($input['alias'] === '' || mb_strlen($input['alias']) > 50) {
            $errors['alias'] = '별칭을 50자 이내로 입력하세요 (예: 미리 운영).';
        }
        if ($input['company_id'] !== '' && (!ctype_digit($input['company_id']) || $this->registry->company((int) $input['company_id']) === null)) {
            $errors['company_id'] = '등록된 관리회사를 선택하세요.';
        }
        if ($input['root_email'] !== '' && !filter_var($input['root_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['root_email'] = '올바른 이메일을 입력하세요.';
        }
        if (!in_array($input['mfa_enabled'], ['', '0', '1'], true)) {
            $errors['mfa_enabled'] = 'MFA 상태를 선택하세요.';
        }
        if (mb_strlen($input['notes']) > 500) {
            $errors['notes'] = '메모는 500자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * @param array{alias: string, company_id: string, root_email: string, mfa_enabled: string, notes: string} $input
     * @return array{alias: string, company_id: ?int, root_email: ?string, mfa_enabled: ?bool, notes: ?string}
     */
    private static function values(array $input): array
    {
        return [
            'alias' => $input['alias'],
            'company_id' => $input['company_id'] === '' ? null : (int) $input['company_id'],
            'root_email' => $input['root_email'] === '' ? null : $input['root_email'],
            'mfa_enabled' => $input['mfa_enabled'] === '' ? null : $input['mfa_enabled'] === '1',
            'notes' => $input['notes'] === '' ? null : $input['notes'],
        ];
    }

    /**
     * @param array<string, mixed>|null $account
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(?array $account, array $values, array $errors = []): Response
    {
        return $this->page('settings-aws-account-form', [
            'title' => $account === null ? 'AWS 계정 등록' : (string) $account['alias'],
            'active' => 'aws-accounts',
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
        return ctype_digit($id) ? $this->registry->awsAccount((int) $id) : null;
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect(self::BASE);
    }
}
