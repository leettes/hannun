<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\SystemRegistry;
use App\View;

/**
 * 관리회사 (AWS 계정을 관리·결제하는 법인): 법인명, 대표자, 사업자번호, 메모 (admin 전용 경로에서만 연결한다).
 * AWS 계정이 참조하므로 삭제하지 않는다.
 */
final class CompanyController extends Controller
{
    private const FLASH_KEY = 'companies_flash';

    private const BASE = '/settings/companies';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly SystemRegistry $registry,
    ) {
        parent::__construct($view, $data);
    }

    /**
     * 사업자등록번호 검증번호 (국세청 규칙). 숫자 10자리만 넣는다.
     */
    public static function validBusinessNo(string $number): bool
    {
        if (!preg_match('/^\d{10}$/', $number)) {
            return false;
        }
        $d = array_map(intval(...), str_split($number));
        $weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $d[$i] * $w;
        }
        $sum += intdiv($d[8] * 5, 10);

        return (10 - $sum % 10) % 10 === $d[9];
    }

    public function index(Request $request): Response
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->page('settings-companies', [
            'title' => '관리회사',
            'active' => 'companies',
            'companies' => $this->registry->companies(),
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, ['name' => '', 'ceo_name' => '', 'business_no' => '', 'notes' => '']);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request);
        $errors = $this->validate($input, null);
        if ($errors !== []) {
            return $this->form(null, $input, $errors);
        }

        $this->registry->createCompany(self::values($input));

        return $this->done($request, "{$input['name']}을(를) 등록했습니다. AWS 계정에서 관리회사로 지정하세요.");
    }

    public function edit(Request $request, string $id): Response
    {
        $company = $this->find($id);
        if ($company === null) {
            return $this->notFound('존재하지 않는 관리회사입니다.');
        }

        return $this->form($company, [
            'name' => $company['name'],
            'ceo_name' => (string) ($company['ceo_name'] ?? ''),
            'business_no' => $company['business_no'] === null ? '' : business_no_label($company['business_no']),
            'notes' => (string) ($company['notes'] ?? ''),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $company = $this->find($id);
        if ($company === null) {
            return $this->notFound('존재하지 않는 관리회사입니다.');
        }

        $input = $this->input($request);
        $errors = $this->validate($input, (int) $company['id']);
        if ($errors !== []) {
            return $this->form($company, $input, $errors);
        }

        $this->registry->updateCompany((int) $company['id'], self::values($input));

        return $this->done($request, "{$input['name']}을(를) 저장했습니다.");
    }

    /**
     * @return array{name: string, ceo_name: string, business_no: string, notes: string}
     */
    private function input(Request $request): array
    {
        return [
            'name' => trim($request->input('name') ?? ''),
            'ceo_name' => trim($request->input('ceo_name') ?? ''),
            'business_no' => trim($request->input('business_no') ?? ''),
            'notes' => trim($request->input('notes') ?? ''),
        ];
    }

    /**
     * @param array{name: string, ceo_name: string, business_no: string, notes: string} $input
     * @return array<string, string>
     */
    private function validate(array $input, ?int $id): array
    {
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = '법인명을 100자 이내로 입력하세요.';
        }
        if (mb_strlen($input['ceo_name']) > 50) {
            $errors['ceo_name'] = '대표자는 50자 이내로 입력하세요.';
        }
        $number = self::digits($input['business_no']);
        if ($input['business_no'] !== '') {
            if (!self::validBusinessNo($number)) {
                $errors['business_no'] = '사업자번호 10자리를 확인하세요 (예: 123-45-67890).';
            } elseif ($this->registry->businessNoTaken($number, $id)) {
                $errors['business_no'] = '이미 등록된 사업자번호입니다.';
            }
        }
        if (mb_strlen($input['notes']) > 500) {
            $errors['notes'] = '메모는 500자 이내로 입력하세요.';
        }

        return $errors;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/[\s-]/', '', $value) ?? '';
    }

    /**
     * @param array{name: string, ceo_name: string, business_no: string, notes: string} $input
     * @return array{name: string, ceo_name: ?string, business_no: ?string, notes: ?string}
     */
    private static function values(array $input): array
    {
        $blank = static fn (string $v): ?string => $v === '' ? null : $v;

        return [
            'name' => $input['name'],
            'ceo_name' => $blank($input['ceo_name']),
            'business_no' => $blank(self::digits($input['business_no'])),
            'notes' => $blank($input['notes']),
        ];
    }

    /**
     * @param array<string, mixed>|null $company
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(?array $company, array $values, array $errors = []): Response
    {
        return $this->page('settings-company-form', [
            'title' => $company === null ? '관리회사 등록' : (string) $company['name'],
            'active' => 'companies',
            'company' => $company,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->registry->company((int) $id) : null;
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect(self::BASE);
    }
}
