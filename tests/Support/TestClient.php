<?php

declare(strict_types=1);

namespace Tests\Support;

use App\App;
use App\Alerts\AlertIngestor;
use App\AppFactory;
use App\Auth\DatabaseUserProvider;
use App\Auth\User;
use App\Database;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Migrator;
use App\Monitoring\DatabaseMonitoringRepository;
use App\Monitoring\IncidentActions;
use App\Monitoring\AppRegistry;
use App\Monitoring\SystemRegistry;
use App\Notifications\Notifier;
use App\Sample\SampleSeeder;
use PDO;

/**
 * 브라우저 한 개를 흉내 낸다: 요청 사이에 같은 세션을 유지한다.
 * 사용자와 화면 데이터는 샘플 데이터를 적재한 메모리 SQLite 에 있다(처음 필요할 때 만든다).
 */
final class TestClient
{
    public readonly DatabaseUserProvider $users;
    public readonly InMemoryLoginThrottle $throttle;
    public const ALERT_TOKEN = 'test-alert-token';
    public const SNS_TOPIC = 'arn:aws:sns:ap-northeast-2:111111111111:monitoring-alarms';

    public Session $session;

    /** @var array<string, string> 가짜 HTTPS GET 응답 (URL => 본문) */
    public array $httpResponses = [];

    /** @var array<string, string> 가짜 웹/API 판단 결과 (URL => web|api), 없으면 판단 실패 */
    public array $httpKinds = [];

    /** @var list<string> 요청한 URL */
    public array $httpRequests = [];

    public readonly FakeSms $sms;
    public readonly Notifier $notifier;
    private readonly App $app;
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->users = new DatabaseUserProvider($this->pdo(...));
        $this->throttle = new InMemoryLoginThrottle();
        $this->session = new Session();
        $this->sms = new FakeSms();
        $this->notifier = new Notifier($this->pdo(...), $this->sms, 'https://monitor.example.com');

        $this->app = AppFactory::create(
            dirname(__DIR__, 2),
            $config + [
                'env' => 'local',
                'debug' => false,
                'allowed_ips' => '',
                'trusted_proxies' => '',
                'idle_minutes' => 120,
                'secure_cookie' => false,
                'alert_webhook_token' => self::ALERT_TOKEN,
                'sns_topic_arns' => self::SNS_TOPIC,
            ],
            $this->users,
            $this->throttle,
            fn (): Session => $this->session,
            new DatabaseMonitoringRepository($this->pdo(...)),
            new IncidentActions($this->pdo(...), $this->notifier),
            new AlertIngestor($this->pdo(...), $this->notifier),
            function (string $url): ?string {
                $this->httpRequests[] = $url;

                return $this->httpResponses[$url] ?? null;
            },
            $this->notifier,
            new SystemRegistry($this->pdo(...)),
            fn (string $url): ?string => $this->httpKinds[$url] ?? null,
            new AppRegistry($this->pdo(...)),
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::open(':memory:');
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
            (new SampleSeeder($this->pdo))->run();
        }

        return $this->pdo;
    }

    /**
     * @param array<string, string> $server
     */
    public function get(string $uri, array $server = []): Response
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->app->handle(new Request('GET', $path, $query, [], $server + ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /**
     * @param array<string, string> $body CSRF 토큰은 자동으로 붙인다 (_token 을 직접 주면 그 값을 사용)
     * @param array<string, string> $server
     */
    public function post(string $path, array $body = [], array $server = []): Response
    {
        $body += ['_token' => $this->csrfToken()];

        return $this->app->handle(new Request('POST', $path, [], $body, $server + ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /**
     * 본문 원문을 그대로 보낸다 (JSON Webhook 등). CSRF 토큰 없음.
     *
     * @param array<string, string> $server
     */
    public function postRaw(string $path, string $content, array $server = []): Response
    {
        return $this->app->handle(new Request('POST', $path, [], [], $server + ['REMOTE_ADDR' => '203.0.113.50'], $content));
    }

    public function csrfToken(): string
    {
        if ($this->session->get(VerifyCsrfToken::SESSION_KEY) === null) {
            $this->get('/login');
        }

        return (string) $this->session->get(VerifyCsrfToken::SESSION_KEY);
    }

    public function addUser(string $email, string $password, string $role = 'admin', bool $active = true): User
    {
        // 테스트 속도를 위해 낮은 비용으로 해시
        $id = $this->users->create($email, 'Tester ' . $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $role);
        if (!$active) {
            $this->users->update($id, 'Tester ' . $email, $role, false);
        }

        return $this->users->findById($id) ?? throw new \LogicException('user not created');
    }

    public function loginAs(string $role = 'admin'): User
    {
        $user = $this->addUser($role . '@example.com', 'correct horse battery', $role);
        $this->session->set(Authenticate::USER_KEY, $user->id);
        $this->session->set(Authenticate::ACTIVITY_KEY, time());

        return $user;
    }
}
