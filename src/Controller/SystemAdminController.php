<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use Closure;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\SystemRegistry;
use App\View;

/**
 * 서비스·감시 리소스 등록 (admin 전용 경로에서만 연결한다).
 * 장애·점검 이력이 참조하므로 삭제하지 않고 비활성화한다. 진행 중인 장애가 있으면 비활성화할 수 없다.
 */
final class SystemAdminController extends Controller
{
    private const FLASH_KEY = 'systems_flash';

    private const BASE = '/settings/systems';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly SystemRegistry $registry,
        private readonly UserProvider $users,
        /** @var Closure(string): ?string 웹/API 를 고르지 않았을 때 URL 응답으로 판단 */
        private readonly Closure $detectHttpKind,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        return $this->page('settings-systems', [
            'title' => '서비스 관리',
            'active' => 'settings',
            'systems' => $this->registry->systems(),
            'domains' => $this->registry->domains(),
            'flash' => $this->flash($request),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->systemForm(null, ['code' => '', 'name' => '', 'short_name' => '', 'environment' => 'prod', 'cloud_provider' => 'aws', 'aws_account_id' => '', 'company_id' => '', 'owner_user_ids' => [], 'description' => '', 'admin_url' => '', 'active' => true]);
    }

    public function store(Request $request): Response
    {
        // 새로 등록하면 항상 사용 상태
        $input = ['active' => true, 'code' => strtolower(trim($request->input('code') ?? ''))] + $this->systemInput($request);
        $errors = $this->validateSystem($input);

        if (!preg_match('/^[a-z][a-z0-9-]{1,29}$/', $input['code'])) {
            $errors['code'] = '영문 소문자로 시작하는 2~30자 (영문 소문자, 숫자, -)로 입력하세요.';
        } elseif ($this->registry->codeTaken($input['code'])) {
            $errors['code'] = '이미 쓰고 있는 코드입니다.';
        }

        if ($errors !== []) {
            return $this->systemForm(null, $input, $errors);
        }

        $id = $this->registry->createSystem(self::systemValues($input));

        return $this->done($request, self::BASE . '/' . $id, "{$input['name']} 서비스를 등록했습니다. 감시할 웹/API, EC2, RDS 를 추가하세요.");
    }

    public function edit(Request $request, string $id): Response
    {
        $system = $this->findSystem($id);
        if ($system === null) {
            return $this->notFound('존재하지 않는 서비스입니다.');
        }

        return $this->systemForm($system, [
            'code' => $system['code'],
            'name' => $system['name'],
            'environment' => $system['environment'],
            'cloud_provider' => $system['cloud_provider'],
            'aws_account_id' => (string) ($system['aws_account_id'] ?? ''),
            'company_id' => (string) ($system['company_id'] ?? ''),
            'owner_user_ids' => array_map(strval(...), $system['owner_user_ids']),
            'short_name' => (string) ($system['short_name'] ?? ''),
            'description' => (string) ($system['description'] ?? ''),
            'admin_url' => (string) ($system['admin_url'] ?? ''),
            'active' => (bool) $system['is_active'],
        ], flash: $this->flash($request));
    }

    public function update(Request $request, string $id): Response
    {
        $system = $this->findSystem($id);
        if ($system === null) {
            return $this->notFound('존재하지 않는 서비스입니다.');
        }

        $input = $this->systemInput($request) + ['code' => $system['code']];
        $errors = $this->validateSystem($input);
        if (!$input['active'] && $system['is_active']) {
            $error = $this->activeIncidentError('system_id', (int) $system['id']);
            if ($error !== null) {
                $errors['active'] = $error;
            }
        }

        if ($errors !== []) {
            return $this->systemForm($system, $input, $errors);
        }

        $this->registry->updateSystem((int) $system['id'], self::systemValues($input));

        return $this->done($request, self::BASE, "{$input['name']} 서비스를 저장했습니다.");
    }

    public function createResource(Request $request, string $id): Response
    {
        $system = $this->findSystem($id);
        if ($system === null) {
            return $this->notFound('존재하지 않는 서비스입니다.');
        }

        $type = $request->query('type') ?? 'http';

        return $this->resourceForm($system, null, [
            'type' => isset(SystemRegistry::RESOURCE_TYPES[$type]) ? $type : 'http',
            'name' => '', 'identifier' => '', 'region' => '', 'external_ref' => '', 'kind' => '',
            'instance_type' => '', 'engine' => '', 'max_connections' => '', 'active' => true,
        ]);
    }

    public function storeResource(Request $request, string $id): Response
    {
        $system = $this->findSystem($id);
        if ($system === null) {
            return $this->notFound('존재하지 않는 서비스입니다.');
        }

        $type = $request->input('type') ?? '';
        if (!isset(SystemRegistry::RESOURCE_TYPES[$type])) {
            $type = 'http';
            $errors = ['type' => '종류를 선택하세요.'];
        }
        $input = ['active' => true] + $this->resourceInput($request, $type);
        $errors = ($errors ?? []) + $this->validateResource($input, null);

        if ($errors !== []) {
            return $this->resourceForm($system, null, $input, $errors);
        }

        $this->registry->createResource((int) $system['id'], self::resourceValues($this->withKind($input)));

        return $this->done($request, self::BASE . '/' . $system['id'], "{$input['name']}을(를) 추가했습니다.");
    }

    public function editResource(Request $request, string $id): Response
    {
        $resource = ctype_digit($id) ? $this->registry->resource((int) $id) : null;
        $system = $resource === null ? null : $this->registry->system((int) $resource['system_id']);
        if ($resource === null || $system === null) {
            return $this->notFound('존재하지 않는 리소스입니다.');
        }

        $config = $resource['config'];

        return $this->resourceForm($system, $resource, [
            'type' => $resource['type'],
            'name' => $resource['name'],
            'identifier' => $resource['identifier'],
            'region' => (string) ($resource['region'] ?? ''),
            'external_ref' => (string) ($resource['external_ref'] ?? ''),
            'kind' => $resource['type'] === 'http' ? SystemRegistry::httpKind($config, (string) $resource['identifier']) : '',
            'instance_type' => (string) ($config['instance_type'] ?? ''),
            'engine' => (string) ($config['engine'] ?? ''),
            'max_connections' => (string) ($config['max_connections'] ?? ''),
            'active' => (bool) $resource['is_active'],
        ]);
    }

    public function updateResource(Request $request, string $id): Response
    {
        $resource = ctype_digit($id) ? $this->registry->resource((int) $id) : null;
        $system = $resource === null ? null : $this->registry->system((int) $resource['system_id']);
        if ($resource === null || $system === null) {
            return $this->notFound('존재하지 않는 리소스입니다.');
        }

        $input = $this->resourceInput($request, $resource['type']);
        $errors = $this->validateResource($input, (int) $resource['id']);
        if (!$input['active'] && $resource['is_active']) {
            $error = $this->activeIncidentError('resource_id', (int) $resource['id']);
            if ($error !== null) {
                $errors['active'] = $error;
            }
        }

        if ($errors !== []) {
            return $this->resourceForm($system, $resource, $input, $errors);
        }

        $this->registry->updateResource((int) $resource['id'], self::resourceValues($this->withKind($input)));

        return $this->done($request, self::BASE . '/' . $system['id'], "{$input['name']}을(를) 저장했습니다.");
    }

    /**
     * 도메인 정보 수동 입력. 자동 수집(RDAP·WHOIS)이 안 되거나 틀린 항목만 채운다.
     */
    public function editDomain(Request $request, string $domain): Response
    {
        $rows = $this->domainRows($domain);
        if ($rows === []) {
            return $this->notFound('사용 중인 웹/API 주소의 도메인이 아닙니다.');
        }
        $override = $this->registry->domainOverride($domain);

        return $this->domainForm($domain, $rows, [
            'registrar' => (string) ($override['registrar'] ?? ''),
            'expires_at' => (string) ($override['expires_at'] ?? ''),
            'nameservers' => implode("\n", $override['nameservers'] ?? []),
            'memo' => (string) ($override['memo'] ?? ''),
        ], $override);
    }

    public function updateDomain(Request $request, string $domain): Response
    {
        $rows = $this->domainRows($domain);
        if ($rows === []) {
            return $this->notFound('사용 중인 웹/API 주소의 도메인이 아닙니다.');
        }

        $input = [
            'registrar' => trim($request->input('registrar') ?? ''),
            'expires_at' => trim($request->input('expires_at') ?? ''),
            'nameservers' => trim($request->input('nameservers') ?? ''),
            'memo' => trim($request->input('memo') ?? ''),
        ];
        $nameservers = array_values(array_unique(array_filter(array_map(
            static fn (string $ns): string => strtolower(rtrim(trim($ns), '.')),
            preg_split('/[\s,]+/', $input['nameservers']) ?: [],
        ))));

        $errors = [];
        if (mb_strlen($input['registrar']) > 100) {
            $errors['registrar'] = '100자 이내로 입력하세요.';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input['expires_at']);
        if ($input['expires_at'] !== '' && ($date === false || $date->format('Y-m-d') !== $input['expires_at'])) {
            $errors['expires_at'] = '날짜를 YYYY-MM-DD 형식으로 입력하세요.';
        }
        foreach ($nameservers as $ns) {
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $ns)) {
                $errors['nameservers'] = "네임서버 이름을 확인하세요: {$ns}";
                break;
            }
        }
        if (count($nameservers) > 13) {
            $errors['nameservers'] = '네임서버는 13개까지 입력할 수 있습니다.';
        }
        if (mb_strlen($input['memo']) > 500) {
            $errors['memo'] = '메모는 500자 이내로 입력하세요.';
        }
        if ($errors !== []) {
            return $this->domainForm($domain, $rows, $input, $this->registry->domainOverride($domain), $errors);
        }

        sort($nameservers);
        $values = [
            'registrar' => $input['registrar'] === '' ? null : $input['registrar'],
            'expires_at' => $input['expires_at'] === '' ? null : $input['expires_at'],
            'nameservers' => $nameservers === [] ? null : $nameservers,
            'memo' => $input['memo'] === '' ? null : $input['memo'],
        ];
        $user = $request->attribute('user');
        $this->registry->saveDomainOverride($domain, $values, $user instanceof User ? $user->id : null);

        return $this->done($request, self::BASE, array_filter($values) === []
            ? "{$domain} 도메인 정보를 자동 수집 값으로 되돌렸습니다."
            : "{$domain} 도메인 정보를 저장했습니다.");
    }

    /**
     * @return list<array<string, mixed>> 이 도메인을 쓰는 웹/API 리소스
     */
    private function domainRows(string $domain): array
    {
        return array_values(array_filter($this->registry->domains(), static fn (array $d): bool => $d['domain'] === $domain));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $values
     * @param array<string, mixed>|null $override
     * @param array<string, string> $errors
     */
    private function domainForm(string $domain, array $rows, array $values, ?array $override, array $errors = []): Response
    {
        return $this->page('settings-domain-form', [
            'title' => $domain,
            'active' => 'settings',
            'domain' => $domain,
            'rows' => $rows,
            'auto' => array_values(array_filter(array_column($rows, 'domain_auto')))[0] ?? null,
            'override' => $override,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array{name: string, environment: string, cloud_provider: string, aws_account_id: string, company_id: string, owner_user_ids: list<string>, description: string, active: bool}
     */
    private function systemInput(Request $request): array
    {
        return [
            'name' => trim($request->input('name') ?? ''),
            'short_name' => trim($request->input('short_name') ?? ''),
            'environment' => $request->input('environment') ?? '',
            'cloud_provider' => $request->input('cloud_provider') ?? 'aws',
            // AWS 가 아니면 계정 연결을 지운다
            'aws_account_id' => ($request->input('cloud_provider') ?? 'aws') === 'aws' ? $request->input('aws_account_id') ?? '' : '',
            'company_id' => $request->input('company_id') ?? '',
            'owner_user_ids' => array_values(array_unique($request->inputList('owner_user_ids'))),
            'description' => trim($request->input('description') ?? ''),
            'admin_url' => trim($request->input('admin_url') ?? ''),
            'active' => $request->input('active') === '1',
        ];
    }

    /**
     * @param array{name: string, environment: string, cloud_provider: string, aws_account_id: string, company_id: string, owner_user_ids: list<string>, description: string} $input
     * @return array<string, string> 필드 => 오류 메시지
     */
    private function validateSystem(array $input): array
    {
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 50) {
            $errors['name'] = '이름을 50자 이내로 입력하세요.';
        }
        if (mb_strlen($input['short_name']) > 2) {
            $errors['short_name'] = '약칭은 2글자 이내로 입력하세요.';
        }
        if (!isset(SystemRegistry::ENVIRONMENTS[$input['environment']])) {
            $errors['environment'] = '환경을 선택하세요.';
        }
        if (!isset(SystemRegistry::CLOUD_PROVIDERS[$input['cloud_provider']])) {
            $errors['cloud_provider'] = '클라우드를 선택하세요.';
        }
        if ($input['aws_account_id'] !== '' && (!ctype_digit($input['aws_account_id']) || $this->registry->awsAccount((int) $input['aws_account_id']) === null)) {
            $errors['aws_account_id'] = '등록된 AWS 계정을 선택하세요.';
        }
        if ($input['company_id'] !== '' && (!ctype_digit($input['company_id']) || $this->registry->company((int) $input['company_id']) === null)) {
            $errors['company_id'] = '등록된 관리회사를 선택하세요.';
        }
        foreach ($input['owner_user_ids'] as $ownerId) {
            $owner = ctype_digit($ownerId) ? $this->users->findById((int) $ownerId) : null;
            if ($owner === null || !$owner->active) {
                $errors['owner_user_ids'] = '사용 중인 계정을 선택하세요.';
                break;
            }
        }
        if (mb_strlen($input['description']) > 200) {
            $errors['description'] = '설명은 200자 이내로 입력하세요.';
        }
        if ($input['admin_url'] !== '' && !self::validWebUrl($input['admin_url'])) {
            $errors['admin_url'] = 'http:// 또는 https:// 로 시작하는 주소를 500자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * @param array{code: string, name: string, environment: string, cloud_provider: string, aws_account_id: string, company_id: string, owner_user_ids: list<string>, description: string, active: bool} $input
     * @return array{code: string, name: string, environment: string, cloud_provider: string, aws_account_id: ?int, company_id: ?int, owner_user_ids: list<int>, description: ?string, active: bool}
     */
    private static function systemValues(array $input): array
    {
        return [
            'aws_account_id' => $input['aws_account_id'] === '' ? null : (int) $input['aws_account_id'],
            'company_id' => $input['company_id'] === '' ? null : (int) $input['company_id'],
            'owner_user_ids' => array_map(intval(...), $input['owner_user_ids']),
            'short_name' => $input['short_name'] === '' ? null : $input['short_name'],
            'description' => $input['description'] === '' ? null : $input['description'],
            'admin_url' => $input['admin_url'] === '' ? null : $input['admin_url'],
        ] + $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceInput(Request $request, string $type): array
    {
        $identifier = trim($request->input('identifier') ?? '');

        return [
            'type' => $type,
            'name' => trim($request->input('name') ?? ''),
            // AWS 식별자는 소문자로 저장된다 (CloudWatch 차원 값과 맞춤)
            'identifier' => $type === 'http' ? $identifier : strtolower($identifier),
            'region' => $type === 'http' ? '' : strtolower(trim($request->input('region') ?? '')),
            'external_ref' => $type === 'http' ? trim($request->input('external_ref') ?? '') : '',
            'kind' => $type === 'http' ? $request->input('kind') ?? '' : '',
            'instance_type' => $type === 'ec2' ? trim($request->input('instance_type') ?? '') : '',
            'engine' => $type === 'rds' ? trim($request->input('engine') ?? '') : '',
            'max_connections' => $type === 'rds' ? trim($request->input('max_connections') ?? '') : '',
            'active' => $request->input('active') === '1',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function validateResource(array $input, ?int $resourceId): array
    {
        $errors = [];
        $type = $input['type'];
        if ($input['name'] === '' || mb_strlen($input['name']) > 50) {
            $errors['name'] = '이름을 50자 이내로 입력하세요.';
        }

        $identifierError = match ($type) {
            'http' => preg_match('#^https?://#i', $input['identifier']) && filter_var($input['identifier'], FILTER_VALIDATE_URL) && strlen($input['identifier']) <= 500
                ? null : 'http:// 또는 https:// 로 시작하는 주소를 입력하세요.',
            'ec2' => preg_match('/^i-([0-9a-f]{8}|[0-9a-f]{17})$/', $input['identifier'])
                ? null : '인스턴스 ID 를 입력하세요 (예: i-0123456789abcdef0).',
            'rds' => preg_match('/^[a-z][a-z0-9-]{0,62}$/', $input['identifier']) && !str_ends_with($input['identifier'], '-') && !str_contains($input['identifier'], '--')
                ? null : 'DB 식별자를 입력하세요 (영문 소문자로 시작, 영문·숫자·-).',
        };
        if ($identifierError !== null) {
            $errors['identifier'] = $identifierError;
        } elseif (($other = $this->registry->resourceUsing($type, 'identifier', $input['identifier'], $resourceId)) !== null) {
            $errors['identifier'] = $this->takenMessage($other);
        }

        if ($type === 'http' && $input['kind'] !== '' && !isset(SystemRegistry::HTTP_KINDS[$input['kind']])) {
            $errors['kind'] = '웹 또는 API 중 하나를 선택하세요.';
        }
        if ($input['region'] !== '' && !preg_match('/^[a-z]{2}(-[a-z]+)+-\d+$/', $input['region'])) {
            $errors['region'] = '리전 코드를 입력하세요 (예: ap-northeast-2). 비우면 기본 리전.';
        }
        if ($input['external_ref'] !== '') {
            if (!ctype_digit($input['external_ref'])) {
                $errors['external_ref'] = 'Uptime Kuma 모니터 ID(숫자)를 입력하세요.';
            } elseif (($other = $this->registry->resourceUsing($type, 'external_ref', $input['external_ref'], $resourceId)) !== null) {
                $errors['external_ref'] = $this->takenMessage($other);
            }
        }
        if (mb_strlen($input['instance_type']) > 30 || mb_strlen($input['engine']) > 30) {
            $errors[$type === 'ec2' ? 'instance_type' : 'engine'] = '30자 이내로 입력하세요.';
        }
        if ($input['max_connections'] !== '' && (!ctype_digit($input['max_connections']) || (int) $input['max_connections'] < 1)) {
            $errors['max_connections'] = '1 이상의 숫자를 입력하세요.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{type: string, name: string, identifier: string, region: ?string, external_ref: ?string, config: array<string, mixed>, active: bool}
     */
    private static function resourceValues(array $input): array
    {
        $blank = static fn (string $v): ?string => $v === '' ? null : $v;
        $config = [];
        foreach (SystemRegistry::CONFIG_FIELDS[$input['type']] as $key) {
            $config[$key] = $key === 'max_connections'
                ? ($input[$key] === '' ? null : (int) $input[$key])
                : $blank($input[$key]);
        }

        return [
            'type' => $input['type'],
            'name' => $input['name'],
            'identifier' => $input['identifier'],
            'region' => $blank($input['region']),
            'external_ref' => $blank($input['external_ref']),
            'config' => $config,
            'active' => $input['active'],
        ];
    }

    /**
     * 웹/API 를 고르지 않았으면 주소를 한 번 호출해 JSON 이면 API, 아니면 웹으로 정한다.
     * 호출이 실패하면 비워 두고 cron 이 다시 시도한다.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function withKind(array $input): array
    {
        if ($input['type'] === 'http' && $input['kind'] === '') {
            $kind = ($this->detectHttpKind)($input['identifier']);
            $input['kind'] = isset(SystemRegistry::HTTP_KINDS[(string) $kind]) ? (string) $kind : '';
        }

        return $input;
    }

    private function takenMessage(int $resourceId): string
    {
        $other = $this->registry->resource($resourceId);
        $system = $other === null ? null : $this->registry->system((int) $other['system_id']);

        return sprintf('이미 %s 서비스의 %s에 등록되어 있습니다.', $system['name'] ?? '?', $other['name'] ?? '?');
    }

    private function activeIncidentError(string $column, int $id): ?string
    {
        $ids = $this->registry->activeIncidentIds($column, $id);

        return $ids === []
            ? null
            : '진행 중인 장애(' . implode(', ', array_map(static fn (int $i): string => "#{$i}", $ids)) . ')를 먼저 복구 처리하세요.';
    }

    /**
     * @param array<string, mixed>|null $system
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function systemForm(?array $system, array $values, array $errors = [], ?string $flash = null): Response
    {
        return $this->page('settings-system-form', [
            'title' => $system === null ? '서비스 등록' : (string) $system['name'],
            'active' => 'settings',
            'system' => $system,
            'resources' => $system === null ? [] : $this->registry->resources((int) $system['id']),
            'awsAccounts' => $this->registry->awsAccounts(),
            'companies' => $this->registry->companies(),
            'owners' => array_values(array_filter($this->users->all(), static fn ($u): bool => $u->active || in_array((string) $u->id, $values['owner_user_ids'], true))),
            'values' => $values,
            'errors' => $errors,
            'flash' => $flash,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, mixed> $system
     * @param array<string, mixed>|null $resource
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function resourceForm(array $system, ?array $resource, array $values, array $errors = []): Response
    {
        return $this->page('settings-resource-form', [
            'title' => $resource === null ? '리소스 추가' : (string) $resource['name'],
            'active' => 'settings',
            'system' => $system,
            'resource' => $resource,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>|null
     */
    /** 관리자 페이지 주소: http(s) 이고 호스트가 있는 500자 이내 */
    private static function validWebUrl(string $url): bool
    {
        $parts = parse_url($url);

        return mb_strlen($url) <= 500
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']);
    }

    private function findSystem(string $id): ?array
    {
        return ctype_digit($id) ? $this->registry->system((int) $id) : null;
    }

    private function flash(Request $request): ?string
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return is_string($flash) ? $flash : null;
    }

    private function done(Request $request, string $location, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect($location);
    }
}
