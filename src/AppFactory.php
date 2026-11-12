<?php

declare(strict_types=1);

namespace App;

use App\Alerts\AlertIngestor;
use App\Alerts\SnsMessage;
use App\Auth\DatabaseLoginThrottle;
use App\Auth\DatabaseUserProvider;
use App\Auth\LoginThrottle;
use App\Auth\UserProvider;
use App\Controller\AlertController;
use App\Controller\ArchitectureController;
use App\Controller\AuthController;
use App\Controller\AppAccountController;
use App\Controller\AppController;
use App\Controller\AppDetailController;
use App\Controller\AwsAccountController;
use App\Controller\CompanyController;
use App\Controller\DashboardController;
use App\Controller\ErrorController;
use App\Controller\HealthController;
use App\Controller\IncidentController;
use App\Controller\ProfileController;
use App\Controller\SystemAdminController;
use App\Monitoring\HttpKindDetector;
use App\Controller\SystemController;
use App\Controller\UserController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\IpRestriction;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Monitoring\DatabaseMonitoringRepository;
use App\Monitoring\IncidentActions;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\AppRegistry;
use App\Monitoring\SystemRegistry;
use App\Notifications\Notifier;
use App\Security\ClientIpResolver;
use App\Security\IpAllowList;
use Closure;
use Throwable;

final class AppFactory
{
    /** 로그인/IP 제한 없이 접근 가능한 외부 헬스체크 경로 */
    private const HEALTH_PATH = '/health';

    /** 외부 알림 수신 경로. 로그인/세션/IP 제한 대신 토큰·SNS 서명으로 인증한다 (SNS 는 발신 IP 가 고정되지 않음) */
    private const ALERT_PATHS = ['/api/alerts/uptime-kuma', '/api/alerts/sns'];

    /**
     * @return array{env: string, debug: bool, allowed_ips: string, trusted_proxies: string, idle_minutes: int, secure_cookie: bool, alert_webhook_token?: string, sns_topic_arns?: string, aws_resources?: bool}
     */
    public static function configFromEnv(): array
    {
        return [
            'env' => Env::get('APP_ENV', 'production'),
            'debug' => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            'allowed_ips' => Env::get('ALLOWED_IPS', ''),
            'trusted_proxies' => Env::get('TRUSTED_PROXIES', ''),
            'idle_minutes' => max(1, (int) Env::get('SESSION_IDLE_MINUTES', '120')),
            'secure_cookie' => filter_var(Env::get('SESSION_SECURE_COOKIE', 'false'), FILTER_VALIDATE_BOOL),
            'alert_webhook_token' => Env::get('ALERT_WEBHOOK_TOKEN', ''),
            'sns_topic_arns' => Env::get('SNS_TOPIC_ARNS', ''),
            'aws_resources' => filter_var(Env::get('AWS_RESOURCES', 'false'), FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array{env: string, debug: bool, allowed_ips: string, trusted_proxies: string, idle_minutes: int, secure_cookie: bool, alert_webhook_token?: string, sns_topic_arns?: string, aws_resources?: bool} $config
     * @param (Closure(Request): Session)|null $sessionFactory
     * @param (Closure(string): ?string)|null $httpGet SNS 인증서·구독 확인 URL 조회
     * @param (Closure(string): ?string)|null $detectHttpKind 웹/API 구분을 고르지 않았을 때 URL 로 판단 ('web' | 'api' | null)
     */
    public static function create(
        string $root,
        array $config,
        ?UserProvider $users = null,
        ?LoginThrottle $throttle = null,
        ?Closure $sessionFactory = null,
        ?MonitoringRepository $data = null,
        ?IncidentActions $incidentActions = null,
        ?AlertIngestor $alerts = null,
        ?Closure $httpGet = null,
        ?Notifier $notifier = null,
        ?SystemRegistry $registry = null,
        ?Closure $detectHttpKind = null,
        ?AppRegistry $apps = null,
    ): App {
        $view = new View($root . '/templates');
        // EC2·RDS 화면은 AWS 연동 단계 전까지 숨긴다 (.env AWS_RESOURCES=true 로 표시)
        $view->share('showAwsResources', (bool) ($config['aws_resources'] ?? false));
        $data ??= new DatabaseMonitoringRepository(Database::connection(...));
        $users ??= new DatabaseUserProvider(Database::connection(...));
        $throttle ??= new DatabaseLoginThrottle(Database::connection(...));
        $notifier ??= Notifier::fromEnv(Database::connection(...));
        $incidentActions ??= new IncidentActions(Database::connection(...), $notifier);
        $alerts ??= new AlertIngestor(Database::connection(...), $notifier);
        $registry ??= new SystemRegistry(Database::connection(...));
        $apps ??= new AppRegistry(Database::connection(...));
        $sns = new SnsMessage(
            array_values(array_filter(array_map('trim', explode(',', $config['sns_topic_arns'] ?? '')))),
            $httpGet ?? SnsMessage::httpGet(...),
        );
        $sessionFactory ??= static fn (Request $r): Session => Session::start($config['secure_cookie'] || $r->isSecure());

        $resolve = static fn (string $class): object => match ($class) {
            HealthController::class => new HealthController(),
            AlertController::class => new AlertController($alerts, $sns, trim($config['alert_webhook_token'] ?? ''), $notifier),
            AuthController::class => new AuthController($view, $data, $users, $throttle),
            UserController::class => new UserController($view, $data, $users),
            ProfileController::class => new ProfileController($view, $data, $users),
            SystemAdminController::class => new SystemAdminController($view, $data, $registry, $users, $detectHttpKind ?? HttpKindDetector::detect(...)),
            AwsAccountController::class => new AwsAccountController($view, $data, $registry),
            CompanyController::class => new CompanyController($view, $data, $registry),
            AppController::class => new AppController($view, $data, $apps, $registry),
            AppAccountController::class => new AppAccountController($view, $data, $apps, $registry),
            SystemController::class => new SystemController($view, $data, $apps),
            DashboardController::class => new DashboardController($view, $data, $apps),
            AppDetailController::class => new AppDetailController($view, $data, $apps),
            ArchitectureController::class => new ArchitectureController($view, $data, $apps),
            IncidentController::class => new IncidentController($view, $data, $incidentActions, $users, $notifier),
            default => new $class($view, $data),
        };

        $router = new Router($resolve);
        $router->get('/', [DashboardController::class, 'index']);
        $router->get('/systems/{code}', [SystemController::class, 'show']);
        $router->get('/apps/{id}', [AppDetailController::class, 'show']);
        $router->get('/architecture', [ArchitectureController::class, 'index']);
        $router->get('/incidents', [IncidentController::class, 'index']);
        $router->get('/incidents/{id}', [IncidentController::class, 'show']);
        $router->post('/incidents/{id}', [IncidentController::class, 'update'], 'operator');
        $router->post('/incidents/{id}/dismiss', [IncidentController::class, 'dismiss'], 'operator');
        $router->get('/login', [AuthController::class, 'showLogin']);
        $router->post('/login', [AuthController::class, 'login']);
        $router->post('/logout', [AuthController::class, 'logout']);
        $router->get('/me', [ProfileController::class, 'show']);
        $router->post('/me/contact', [ProfileController::class, 'updateContact']);
        $router->post('/me/password', [ProfileController::class, 'updatePassword']);
        $router->get(self::HEALTH_PATH, [HealthController::class, 'index']);
        $router->post(self::ALERT_PATHS[0], [AlertController::class, 'uptimeKuma']);
        $router->post(self::ALERT_PATHS[1], [AlertController::class, 'sns']);
        $router->get('/users', [UserController::class, 'index'], 'admin');
        $router->get('/users/new', [UserController::class, 'create'], 'admin');
        $router->post('/users', [UserController::class, 'store'], 'admin');
        $router->get('/users/{id}', [UserController::class, 'edit'], 'admin');
        $router->post('/users/{id}', [UserController::class, 'update'], 'admin');
        $router->get('/settings/systems', [SystemAdminController::class, 'index'], 'admin');
        $router->get('/settings/systems/new', [SystemAdminController::class, 'create'], 'admin');
        $router->post('/settings/systems', [SystemAdminController::class, 'store'], 'admin');
        $router->get('/settings/systems/{id}', [SystemAdminController::class, 'edit'], 'admin');
        $router->post('/settings/systems/{id}', [SystemAdminController::class, 'update'], 'admin');
        $router->get('/settings/systems/{id}/resources/new', [SystemAdminController::class, 'createResource'], 'admin');
        $router->post('/settings/systems/{id}/resources', [SystemAdminController::class, 'storeResource'], 'admin');
        $router->get('/settings/resources/{id}', [SystemAdminController::class, 'editResource'], 'admin');
        $router->get('/settings/aws-accounts', [AwsAccountController::class, 'index'], 'admin');
        $router->get('/settings/aws-accounts/new', [AwsAccountController::class, 'create'], 'admin');
        $router->post('/settings/aws-accounts', [AwsAccountController::class, 'store'], 'admin');
        $router->get('/settings/aws-accounts/{id}', [AwsAccountController::class, 'edit'], 'admin');
        $router->post('/settings/aws-accounts/{id}', [AwsAccountController::class, 'update'], 'admin');
        $router->get('/settings/companies', [CompanyController::class, 'index'], 'admin');
        $router->get('/settings/companies/new', [CompanyController::class, 'create'], 'admin');
        $router->post('/settings/companies', [CompanyController::class, 'store'], 'admin');
        $router->get('/settings/companies/{id}', [CompanyController::class, 'edit'], 'admin');
        $router->post('/settings/companies/{id}', [CompanyController::class, 'update'], 'admin');
        $router->get('/settings/apps', [AppController::class, 'index'], 'admin');
        $router->get('/settings/apps/new', [AppController::class, 'create'], 'admin');
        $router->post('/settings/apps', [AppController::class, 'store'], 'admin');
        $router->get('/settings/apps/{id}', [AppController::class, 'edit'], 'admin');
        $router->post('/settings/apps/{id}', [AppController::class, 'update'], 'admin');
        $router->get('/settings/app-accounts', [AppAccountController::class, 'index'], 'admin');
        $router->get('/settings/app-accounts/new', [AppAccountController::class, 'create'], 'admin');
        $router->post('/settings/app-accounts', [AppAccountController::class, 'store'], 'admin');
        $router->get('/settings/app-accounts/{id}', [AppAccountController::class, 'edit'], 'admin');
        $router->post('/settings/app-accounts/{id}', [AppAccountController::class, 'update'], 'admin');
        $router->post('/settings/resources/{id}', [SystemAdminController::class, 'updateResource'], 'admin');
        $router->get('/settings/domains/{domain}', [SystemAdminController::class, 'editDomain'], 'admin');
        $router->post('/settings/domains/{domain}', [SystemAdminController::class, 'updateDomain'], 'admin');

        $plainPage = static fn (string $heading, string $message, int $status): Response => Response::html(
            $view->render('error', ['title' => $heading, 'heading' => $heading, 'message' => $message, 'home' => false], 'auth-layout'),
            $status,
        );

        $middleware = [
            new IpRestriction(
                self::allowList($config),
                new ClientIpResolver(IpAllowList::fromString($config['trusted_proxies'])),
                static fn (string $ip): Response => $plainPage('접근이 제한되었습니다', "허용되지 않은 IP({$ip})입니다. VPN 접속 여부를 확인하거나 관리자에게 문의하세요.", 403),
                [self::HEALTH_PATH, ...self::ALERT_PATHS],
            ),
            new StartSession($sessionFactory, [self::HEALTH_PATH, ...self::ALERT_PATHS]),
            new VerifyCsrfToken(
                $view,
                static fn (): Response => $plainPage('요청이 만료되었습니다', '페이지를 새로고침한 뒤 다시 시도하세요.', 419),
            ),
            new Authenticate($users, $view, $config['idle_minutes'] * 60, ['/login', self::HEALTH_PATH, ...self::ALERT_PATHS]),
        ];

        $isApi = static fn (Request $r): bool => str_starts_with($r->path, '/api/') || $r->path === self::HEALTH_PATH;

        $notFound = static fn (Request $r): Response => $isApi($r)
            ? Response::json(['error' => 'Not Found'], 404)
            : $resolve(ErrorController::class)->notFoundPage();

        $onError = static fn (Request $r, Throwable $e): Response => $isApi($r)
            ? Response::json(['error' => 'Internal Server Error'], 500)
            : $plainPage(
                '일시적인 오류가 발생했습니다',
                $config['debug'] ? $e::class . ': ' . $e->getMessage() : '잠시 후 다시 시도하세요. 문제가 계속되면 관리자에게 문의하세요.',
                500,
            );

        $forbidden = static fn (Request $r): Response => $isApi($r)
            ? Response::json(['error' => 'Forbidden'], 403)
            : $resolve(ErrorController::class)->forbiddenPage();

        return new App($router, $middleware, $notFound, $onError, $forbidden);
    }

    /**
     * ALLOWED_IPS 가 비어 있으면 local 환경에서만 전체 허용, 그 외 환경은 전체 차단(설정 누락 시 열리지 않게).
     *
     * @param array{env: string, allowed_ips: string} $config
     */
    private static function allowList(array $config): IpAllowList
    {
        if (trim($config['allowed_ips']) === '') {
            return $config['env'] === 'local' ? IpAllowList::allowAll() : IpAllowList::fromString('');
        }

        return IpAllowList::fromString($config['allowed_ips']);
    }
}
