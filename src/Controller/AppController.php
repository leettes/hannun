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
 * 서비스별 앱 (iOS / Android / 웹앱). admin 전용 경로에서만 연결한다.
 * 플랫폼은 등록 후 바꾸지 않는다. 삭제하지 않고 비활성화한다.
 */
final class AppController extends Controller
{
    private const FLASH_KEY = 'apps_flash';

    private const BASE = '/settings/apps';

    /** iOS 번들 ID: 영문·숫자·-·. 로 점이 하나 이상 */
    private const IOS_BUNDLE = '/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/';

    /** Android 패키지명: 영문으로 시작하는 부분을 점으로 이은 것 */
    private const ANDROID_PACKAGE = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/';

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
        $platform = $request->query('platform');
        $platform = isset(AppRegistry::PLATFORMS[(string) $platform]) ? $platform : null;
        // 관리회사 필터: 회사 ID 또는 none(미지정). 목록에 있는 값만 받는다
        $companies = $this->apps->companiesWithApps();
        $company = $request->query('company');
        $company = in_array((string) $company, array_map(strval(...), array_column($companies, 'id')), true)
            ? ($company === AppRegistry::NO_COMPANY ? $company : (int) $company)
            : null;

        return $this->page('settings-apps', [
            'title' => '앱',
            'active' => 'apps',
            'apps' => $this->apps->apps($platform, $company),
            'platform' => $platform,
            'platformCounts' => $this->apps->platformCounts($company),
            'companies' => $companies,
            'company' => $company,
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): Response
    {
        $platform = $request->query('platform') ?? 'ios';
        $systemId = $request->query('system') ?? '';

        return $this->form(null, [
            'system_id' => ctype_digit($systemId) ? $systemId : '',
            'platform' => isset(AppRegistry::PLATFORMS[$platform]) ? $platform : 'ios',
            'name' => '', 'bundle_id' => '', 'store_url' => '', 'account_id' => '',
            'version' => '', 'released_at' => '', 'notes' => '', 'active' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        $platform = $request->input('platform') ?? '';
        $errors = [];
        if (!isset(AppRegistry::PLATFORMS[$platform])) {
            $platform = 'ios';
            $errors['platform'] = '플랫폼을 선택하세요.';
        }
        $input = ['active' => true] + $this->input($request, $platform);
        if ($platform === 'ios') {
            $input['version'] = '';
            $input['released_at'] = '';
        }
        $errors += $this->validate($input, null, null);
        if ($errors !== []) {
            return $this->form(null, $input, $errors);
        }

        $this->apps->createApp(self::values($input) + ['platform' => $platform]);

        return $this->done($request, "{$input['name']}(" . AppRegistry::PLATFORMS[$platform] . ') 앱을 등록했습니다.');
    }

    public function edit(Request $request, string $id): Response
    {
        $app = $this->find($id);
        if ($app === null) {
            return $this->notFound('존재하지 않는 앱입니다.');
        }

        return $this->form($app, [
            'system_id' => (string) $app['system_id'],
            'platform' => $app['platform'],
            'name' => $app['name'],
            'bundle_id' => (string) ($app['bundle_id'] ?? ''),
            'store_url' => (string) ($app['store_url'] ?? ''),
            'account_id' => (string) ($app['account_id'] ?? ''),
            'version' => (string) ($app['version'] ?? ''),
            'released_at' => (string) ($app['released_at'] ?? ''),
            'notes' => (string) ($app['notes'] ?? ''),
            'active' => (bool) $app['is_active'],
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $app = $this->find($id);
        if ($app === null) {
            return $this->notFound('존재하지 않는 앱입니다.');
        }

        $input = $this->input($request, $app['platform']) + ['active' => $request->input('active') === '1'];
        if ($app['platform'] === 'ios') {
            // iOS 버전·업데이트일은 App Store 자동 조회 값만 쓴다
            $input['version'] = (string) ($app['version'] ?? '');
            $input['released_at'] = (string) ($app['released_at'] ?? '');
        }
        $errors = $this->validate($input, (int) $app['id'], $app);
        if ($errors !== []) {
            return $this->form($app, $input, $errors);
        }

        $this->apps->updateApp((int) $app['id'], self::values($input));

        return $this->done($request, "{$input['name']}(" . AppRegistry::PLATFORMS[$app['platform']] . ') 앱을 저장했습니다.');
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request, string $platform): array
    {
        $web = $platform === 'web';

        return [
            'system_id' => $request->input('system_id') ?? '',
            'platform' => $platform,
            'name' => trim($request->input('name') ?? ''),
            'bundle_id' => $web ? '' : trim($request->input('bundle_id') ?? ''),
            'store_url' => trim($request->input('store_url') ?? ''),
            // 웹앱은 개발자 계정이 없다
            'account_id' => $web ? '' : $request->input('account_id') ?? '',
            'version' => trim($request->input('version') ?? ''),
            'released_at' => trim($request->input('released_at') ?? ''),
            'notes' => trim($request->input('notes') ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current 수정 중인 앱 (지금 연결된 비활성 서비스·계정은 그대로 둘 수 있다)
     * @return array<string, string>
     */
    private function validate(array $input, ?int $id, ?array $current): array
    {
        $errors = [];
        $platform = $input['platform'];

        $system = ctype_digit($input['system_id']) ? $this->registry->system((int) $input['system_id']) : null;
        if ($system === null || (!$system['is_active'] && (string) ($current['system_id'] ?? '') !== $input['system_id'])) {
            $errors['system_id'] = '사용 중인 서비스를 선택하세요.';
        }
        if ($input['name'] === '' || mb_strlen($input['name']) > 50) {
            $errors['name'] = '앱 이름을 50자 이내로 입력하세요.';
        }

        // iOS 는 스토어 주소에 id 가 있으면 번들 ID 를 비워 둘 수 있다 (cron 이 App Store 에서 채운다)
        $iosFromStore = $platform === 'ios' && $input['bundle_id'] === '' && preg_match('#^https://apps\.apple\.com/.*/id\d+#', $input['store_url']);
        if ($platform !== 'web' && !$iosFromStore) {
            $pattern = $platform === 'ios' ? self::IOS_BUNDLE : self::ANDROID_PACKAGE;
            if ($input['bundle_id'] === '' || strlen($input['bundle_id']) > 155 || !preg_match($pattern, $input['bundle_id'])) {
                $errors['bundle_id'] = $platform === 'ios'
                    ? '번들 ID 를 입력하세요 (예: kr.example.app). App Store 주소(…/id123)를 넣으면 비워 둘 수 있습니다.'
                    : '패키지명을 입력하세요 (예: kr.example.app).';
            } elseif (($other = $this->apps->appUsingBundleId($platform, $input['bundle_id'], $id)) !== null) {
                $app = $this->apps->app($other);
                $errors['bundle_id'] = sprintf('이미 %s 서비스의 %s 앱에 등록되어 있습니다.', $app['system_name'] ?? '?', $app['name'] ?? '?');
            }
        }

        if ($input['store_url'] !== '' && (!preg_match('#^https?://#i', $input['store_url']) || !filter_var($input['store_url'], FILTER_VALIDATE_URL) || strlen($input['store_url']) > 500)) {
            $errors['store_url'] = 'http:// 또는 https:// 로 시작하는 주소를 입력하세요.';
        }

        if ($input['account_id'] !== '') {
            $account = ctype_digit($input['account_id']) ? $this->apps->account((int) $input['account_id']) : null;
            $keep = (string) ($current['account_id'] ?? '') === $input['account_id'];
            if ($account === null || $account['platform'] !== (AppRegistry::ACCOUNT_FOR[$platform] ?? null) || (!$account['is_active'] && !$keep)) {
                $errors['account_id'] = AppRegistry::ACCOUNT_PLATFORMS[AppRegistry::ACCOUNT_FOR[$platform]] . ' 개발자 계정 중에서 선택하세요.';
            }
        }

        if (mb_strlen($input['version']) > 30) {
            $errors['version'] = '30자 이내로 입력하세요.';
        }
        if ($input['released_at'] !== '' && !self::validDate($input['released_at'])) {
            $errors['released_at'] = '날짜를 YYYY-MM-DD 형식으로 입력하세요.';
        }
        if (mb_strlen($input['notes']) > 500) {
            $errors['notes'] = '메모는 500자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{system_id: int, name: string, bundle_id: ?string, store_url: ?string, account_id: ?int, version: ?string, released_at: ?string, notes: ?string, active: bool}
     */
    private static function values(array $input): array
    {
        $blank = static fn (string $v): ?string => $v === '' ? null : $v;

        return [
            'system_id' => (int) $input['system_id'],
            'name' => $input['name'],
            'bundle_id' => $blank($input['bundle_id']),
            'store_url' => $blank($input['store_url']),
            'account_id' => $input['account_id'] === '' ? null : (int) $input['account_id'],
            'version' => $blank($input['version']),
            'released_at' => $blank($input['released_at']),
            'notes' => $blank($input['notes']),
            'active' => $input['active'],
        ];
    }

    /**
     * @param array<string, mixed>|null $app
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $app, array $values, array $errors = []): Response
    {
        $platform = (string) $values['platform'];
        $accountPlatform = AppRegistry::ACCOUNT_FOR[$platform] ?? null;

        return $this->page('settings-app-form', [
            'title' => $app === null ? '앱 등록' : (string) $app['name'],
            'active' => 'apps',
            'app' => $app,
            // 사용 중인 서비스·계정, 그리고 지금 연결된 것
            'systems' => array_values(array_filter(
                $this->registry->systems(),
                static fn (array $s): bool => $s['is_active'] || (string) $s['id'] === $values['system_id'],
            )),
            'accounts' => $accountPlatform === null ? [] : array_values(array_filter(
                $this->apps->accounts($accountPlatform),
                static fn (array $a): bool => $a['is_active'] || (string) $a['id'] === $values['account_id'],
            )),
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->apps->app((int) $id) : null;
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->set(self::FLASH_KEY, $message);

        return Response::redirect(self::BASE);
    }
}
