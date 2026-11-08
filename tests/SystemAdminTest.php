<?php

declare(strict_types=1);

namespace Tests;

use App\Alerts\AlertIngestor;
use App\Monitoring\HttpKindDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class SystemAdminTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function access(): iterable
    {
        $routes = ['GET /settings/systems', 'GET /settings/systems/new', 'GET /settings/systems/1', 'POST /settings/systems',
            'POST /settings/systems/1', 'GET /settings/systems/1/resources/new', 'POST /settings/systems/1/resources',
            'GET /settings/resources/1', 'POST /settings/resources/1'];
        foreach ($routes as $route) {
            yield "viewer {$route}" => ['viewer', $route, 403];
            yield "operator {$route}" => ['operator', $route, 403];
        }
        yield 'admin list' => ['admin', 'GET /settings/systems', 200];
        yield 'admin new' => ['admin', 'GET /settings/systems/new', 200];
        yield 'admin edit' => ['admin', 'GET /settings/systems/1', 200];
        yield 'admin new resource' => ['admin', 'GET /settings/systems/1/resources/new?type=rds', 200];
        yield 'admin edit resource' => ['admin', 'GET /settings/resources/1', 200];
        yield 'admin unknown system' => ['admin', 'GET /settings/systems/999', 404];
        yield 'admin unknown resource' => ['admin', 'GET /settings/resources/abc', 404];
        yield 'admin resource of unknown system' => ['admin', 'GET /settings/systems/999/resources/new', 404];
    }

    #[DataProvider('access')]
    public function testOnlyAdminCanManageSystems(string $role, string $route, int $status): void
    {
        $client = new TestClient();
        $client->loginAs($role);
        $before = $this->rows($client, 'systems') + $this->rows($client, 'resources');
        [$method, $path] = explode(' ', $route);

        $response = $method === 'GET'
            ? $client->get($path)
            : $client->post($path, ['code' => 'new-sys', 'name' => 'X', 'environment' => 'prod', 'active' => '0', 'type' => 'http', 'identifier' => 'https://x.example.com']);

        $this->assertSame($status, $response->status);
        if ($status === 403) {
            $this->assertSame($before, $this->rows($client, 'systems') + $this->rows($client, 'resources'), 'POST 가 실행되지 않는다');
            $this->assertSame(1, $this->value($client, 'SELECT is_active FROM systems WHERE id = 1'));
        }
    }

    public function testNavLinkOnlyForAdmin(): void
    {
        $admin = new TestClient();
        $admin->loginAs('admin');
        $operator = new TestClient();
        $operator->loginAs('operator');

        $this->assertStringContainsString('href="/settings/systems"', $admin->get('/')->body);
        $this->assertStringNotContainsString('href="/settings/systems"', $operator->get('/')->body);
    }

    public function testAdminRegistersSystemWithResourcesShownOnDashboard(): void
    {
        $client = new TestClient(['aws_resources' => true]);  // EC2·RDS 화면 표시
        $admin = $client->loginAs('admin');

        $response = $client->post('/settings/systems', [
            'code' => ' Pay ', 'name' => '결제', 'environment' => 'prod', 'owner_user_ids' => [(string) $admin->id], 'description' => '결제 서비스', 'active' => '1',
        ]);
        $id = $this->value($client, "SELECT id FROM systems WHERE code = 'pay'");
        $this->assertSame("/settings/systems/{$id}", $response->headers['Location']);
        $this->assertStringContainsString('서비스를 등록했습니다', $client->get("/settings/systems/{$id}")->body);
        $this->assertSame($admin->id, $this->value($client, "SELECT user_id FROM system_owners WHERE system_id = {$id}"));

        $this->assertSame(302, $client->post("/settings/systems/{$id}/resources", [
            'type' => 'http', 'name' => '결제 API', 'identifier' => 'https://pay.example.com/health', 'external_ref' => '42',
        ])->status);
        $this->assertSame(302, $client->post("/settings/systems/{$id}/resources", [
            'type' => 'ec2', 'name' => 'pay-api-1', 'identifier' => 'I-0123456789ABCDEF0', 'region' => '', 'instance_type' => 't3.small',
        ])->status);
        $this->assertSame(302, $client->post("/settings/systems/{$id}/resources", [
            'type' => 'rds', 'name' => 'pay-db', 'identifier' => 'pay-prod-db', 'region' => 'ap-northeast-2', 'engine' => 'MySQL 8.0', 'max_connections' => '300',
        ])->status);

        $this->assertSame('i-0123456789abcdef0', $this->value($client, "SELECT identifier FROM resources WHERE type = 'ec2' AND system_id = {$id}"), '소문자로 저장');
        $this->assertSame('{"engine":"MySQL 8.0","max_connections":300}', $this->value($client, "SELECT config FROM resources WHERE type = 'rds' AND system_id = {$id}"));
        $this->assertNull($this->value($client, "SELECT region FROM resources WHERE type = 'http' AND system_id = {$id}"));

        $page = $client->get('/systems/pay');
        $this->assertSame(200, $page->status);
        foreach (['결제 API', 'pay-api-1', 't3.small', 'pay-prod-db', 'MySQL 8.0'] as $text) {
            $this->assertStringContainsString($text, $page->body);
        }
        $this->assertStringContainsString('href="/systems/pay"', $client->get('/')->body);

        // Uptime Kuma 모니터와 연결 (모니터 ID 우선, 없으면 URL)
        $ingestor = new AlertIngestor($client->pdo(...));
        $this->assertSame('결제 API', $ingestor->findResource('http', '42', null)['name'] ?? null);
        $this->assertSame('결제 API', $ingestor->findResource('http', null, 'https://pay.example.com/health')['name'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidSystems(): iterable
    {
        $ok = ['code' => 'pay', 'name' => '결제', 'environment' => 'prod', 'owner_user_ids' => [], 'active' => '1'];
        yield 'bad code' => [['code' => '1pay'] + $ok, '영문 소문자로 시작하는'];
        yield 'code with space' => [['code' => 'my pay'] + $ok, '영문 소문자로 시작하는'];
        yield 'duplicate code' => [['code' => 'MIRI'] + $ok, '이미 쓰고 있는 코드입니다'];
        yield 'empty name' => [['name' => ''] + $ok, '이름을 50자 이내로'];
        yield 'bad environment' => [['environment' => 'qa'] + $ok, '환경을 선택하세요'];
        yield 'unknown owner' => [['owner_user_ids' => ['999']] + $ok, '사용 중인 계정을 선택하세요'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidSystems')]
    public function testInvalidSystemIsRejected(array $input, string $message): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $before = $this->rows($client, 'systems');

        $response = $client->post('/settings/systems', $input);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($message, $response->body);
        $this->assertSame($before, $this->rows($client, 'systems'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidResources(): iterable
    {
        yield 'bad url' => [['type' => 'http', 'name' => 'a', 'identifier' => 'ftp://x.example.com'], 'http:// 또는 https://'];
        yield 'not url' => [['type' => 'http', 'name' => 'a', 'identifier' => 'example.com'], 'http:// 또는 https://'];
        yield 'monitor id not numeric' => [['type' => 'http', 'name' => 'a', 'identifier' => 'https://n.example.com', 'external_ref' => 'abc'], '모니터 ID(숫자)'];
        yield 'bad instance id' => [['type' => 'ec2', 'name' => 'a', 'identifier' => 'i-123'], '인스턴스 ID 를 입력하세요'];
        yield 'bad rds id' => [['type' => 'rds', 'name' => 'a', 'identifier' => 'db_1'], 'DB 식별자를 입력하세요'];
        yield 'bad region' => [['type' => 'rds', 'name' => 'a', 'identifier' => 'db-1', 'region' => 'seoul'], '리전 코드를 입력하세요'];
        yield 'bad max connections' => [['type' => 'rds', 'name' => 'a', 'identifier' => 'db-1', 'max_connections' => '0'], '1 이상의 숫자'];
        yield 'empty name' => [['type' => 'ec2', 'name' => '', 'identifier' => 'i-0123456789abcdef0'], '이름을 50자 이내로'];
        yield 'bad type' => [['type' => 'lambda', 'name' => 'a', 'identifier' => 'https://n.example.com'], '종류를 선택하세요'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidResources')]
    public function testInvalidResourceIsRejected(array $input, string $message): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $before = $this->rows($client, 'resources');

        $response = $client->post('/settings/systems/1/resources', $input);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($message, $response->body);
        $this->assertSame($before, $this->rows($client, 'resources'));
    }

    public function testResourceAlreadyRegisteredElsewhereIsRejected(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $url = (string) $this->value($client, "SELECT identifier FROM resources WHERE type = 'http' AND system_id = 2 LIMIT 1");
        $this->assertNotSame('', $url);

        $response = $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '중복', 'identifier' => $url]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('이미 ', $response->body);
        $this->assertStringContainsString('등록되어 있습니다', $response->body);
    }

    public function testEditResourceKeepsTypeAndOtherConfig(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $id = $this->value($client, "SELECT id FROM resources WHERE type = 'ec2' ORDER BY id LIMIT 1");
        $client->pdo()->exec("UPDATE resources SET config = json_set(COALESCE(config, '{}'), '$.custom', 'keep') WHERE id = {$id}");

        $response = $client->post("/settings/resources/{$id}", [
            'type' => 'http', 'name' => '새 이름', 'identifier' => 'i-0aaaaaaaaaaaaaaaa', 'instance_type' => '', 'active' => '1',
        ]);

        $this->assertSame(302, $response->status);
        $row = $client->pdo()->query("SELECT type, name, identifier, config FROM resources WHERE id = {$id}")->fetch();
        $this->assertSame(['ec2', '새 이름', 'i-0aaaaaaaaaaaaaaaa'], [$row['type'], $row['name'], $row['identifier']], '종류는 바뀌지 않는다');
        $this->assertSame(['custom' => 'keep'], json_decode($row['config'], true), '비운 instance_type 만 빠진다');

        $again = $client->post("/settings/resources/{$id}", ['name' => '새 이름', 'identifier' => 'i-0aaaaaaaaaaaaaaaa', 'active' => '1']);
        $this->assertSame(302, $again->status, '같은 값으로 다시 저장 (자기 자신은 중복 아님)');
    }

    public function testSidebarShowsTwoLetterServiceTilesWithOptionalShortName(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $form = ['name' => '미리도화', 'environment' => 'prod', 'cloud_provider' => 'aws', 'active' => '1'];

        $client->post('/settings/systems/1', $form);
        $this->assertStringContainsString('<span aria-hidden="true">미리</span>', $client->get('/')->body, '약칭이 없으면 이름 앞 두 글자');

        $this->assertSame(302, $client->post('/settings/systems/1', ['short_name' => ' 도화 '] + $form)->status);
        $this->assertSame('도화', $this->value($client, 'SELECT short_name FROM systems WHERE id = 1'));
        $page = $client->get('/')->body;
        $this->assertStringContainsString('<span aria-hidden="true">도화</span>', $page);
        $this->assertStringContainsString('title="미리도화', $page, '전체 이름은 툴팁');

        $this->assertSame(422, $client->post('/settings/systems/1', ['short_name' => '세글자'] + $form)->status);
        $client->post('/settings/systems/1', ['short_name' => ''] + $form);
        $this->assertNull($this->value($client, 'SELECT short_name FROM systems WHERE id = 1'), '비우면 약칭 없음');
    }

    public function testAdminUrlIsSavedValidatedAndShown(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $form = fn (string $url): array => ['name' => '미리', 'environment' => 'prod', 'cloud_provider' => 'aws', 'admin_url' => $url, 'active' => '1'];
        $saved = fn (): ?string => $client->pdo()->query('SELECT admin_url FROM systems WHERE id = 1')->fetchColumn() ?: null;

        foreach (['javascript:alert(1)', 'ftp://admin.example.com', 'admin.example.com', 'https://'] as $bad) {
            $this->assertSame(422, $client->post('/settings/systems/1', $form($bad))->status, $bad);
        }
        $this->assertNull($saved());

        $this->assertSame(302, $client->post('/settings/systems/1', $form(' https://admin.miri.example.com/login '))->status);
        $this->assertSame('https://admin.miri.example.com/login', $saved());
        $this->assertStringContainsString('value="https://admin.miri.example.com/login"', $client->get('/settings/systems/1')->body);
        $code = (string) $client->pdo()->query('SELECT code FROM systems WHERE id = 1')->fetchColumn();
        $this->assertStringContainsString('href="https://admin.miri.example.com/login" target="_blank"', $client->get("/systems/{$code}")->body, '서비스 상세 바로가기');

        $this->assertSame(302, $client->post('/settings/systems/1', $form(''))->status);
        $this->assertNull($saved(), '비우면 지운다');
    }

    public function testSystemCanHaveSeveralOwners(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $a = $client->addUser('a@example.com', 'correct horse battery', 'operator');
        $b = $client->addUser('b@example.com', 'correct horse battery', 'viewer');
        $owners = fn (): array => array_map(intval(...), $client->pdo()->query('SELECT user_id FROM system_owners WHERE system_id = 1 ORDER BY user_id')->fetchAll(\PDO::FETCH_COLUMN));
        $form = fn (array $ids): array => ['name' => '미리', 'environment' => 'prod', 'cloud_provider' => 'aws', 'owner_user_ids' => $ids, 'active' => '1'];

        $this->assertSame(302, $client->post('/settings/systems/1', $form([(string) $a->id, (string) $b->id, (string) $a->id]))->status);
        $this->assertSame([$a->id, $b->id], $owners(), '중복은 한 번만');

        $edit = $client->get('/settings/systems/1')->body;
        $this->assertStringContainsString('value="' . $a->id . '" checked', $edit);
        $this->assertStringContainsString('value="' . $b->id . '" checked', $edit);

        $code = $this->value($client, 'SELECT code FROM systems WHERE id = 1');
        $this->assertStringContainsString('Tester a@example.com, Tester b@example.com', $client->get("/systems/{$code}")->body, '서비스 상세에 담당자 이름을 쉼표로 이어서 표시');
        $this->assertStringNotContainsString('Tester a@example.com, Tester b@example.com', $client->get('/')->body, '대시보드 서비스 상태에는 담당자를 표시하지 않는다');

        $client->post('/settings/systems/1', $form([]));
        $this->assertSame([], $owners(), '모두 빼면 미지정');
        $this->assertStringContainsString('미지정', $client->get('/settings/systems')->body);
    }

    public function testHttpResourceKindIsChosenOrDetectedFromJsonResponse(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->httpKinds['https://json.example.com/health'] = 'api';
        $client->httpKinds['https://www.example.com/'] = 'web';
        $kind = fn (string $name): mixed => $this->value($client, "SELECT json_extract(config, '$.kind') FROM resources WHERE name = '{$name}'");

        // 직접 고르면 그 값 (응답과 상관없이)
        $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '고른 웹', 'identifier' => 'https://json.example.com/health', 'kind' => 'web']);
        $this->assertSame('web', $kind('고른 웹'));

        // 고르지 않으면 응답으로 판단: JSON 이면 API, 아니면 웹
        $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '자동 API', 'identifier' => 'https://json.example.com/v2']);
        $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '자동 웹', 'identifier' => 'https://www.example.com/']);
        $this->assertNull($kind('자동 API'), '판단 실패 (가짜 응답 없음) 면 비워 두고 cron 이 다시 시도');
        $this->assertSame('web', $kind('자동 웹'));

        $client->httpKinds['https://json.example.com/v2'] = 'api';
        $filled = HttpKindDetector::fillMissing($client->pdo(...), fn (string $url): ?string => $client->httpKinds[$url] ?? null);
        $this->assertContains('자동 API: API', $filled);
        $this->assertSame('api', $kind('자동 API'));

        $this->assertSame(422, $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '잘못', 'identifier' => 'https://x.example.com/', 'kind' => 'db'])->status, '웹·API 외 값은 거부');

        $page = $client->get('/systems/' . $this->value($client, 'SELECT code FROM systems WHERE id = 1'))->body;
        $this->assertStringContainsString('<span class="kind-tag">API</span>', $page);
    }

    public function testHttpKindOfResponse(): void
    {
        $this->assertSame('api', HttpKindDetector::kindOf('application/json; charset=utf-8', ''));
        $this->assertSame('api', HttpKindDetector::kindOf('application/problem+json', ''));
        $this->assertSame('api', HttpKindDetector::kindOf('text/html', ' {"status":"ok"}'), '본문이 JSON 이면 API');
        $this->assertSame('web', HttpKindDetector::kindOf('text/html; charset=utf-8', '<!doctype html><html></html>'));
        $this->assertSame('web', HttpKindDetector::kindOf(null, '{ not json'));
    }

    public function testCannotDeactivateWithActiveIncident(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $incident = $client->pdo()->query("SELECT id, system_id, resource_id FROM incidents WHERE status <> 'resolved' AND resource_id IS NOT NULL ORDER BY id LIMIT 1")->fetch();
        $this->assertIsArray($incident, '샘플에 진행 중인 장애가 있어야 한다');
        $resource = $client->pdo()->query("SELECT name, identifier FROM resources WHERE id = {$incident['resource_id']}")->fetch();
        $system = $client->pdo()->query("SELECT name, environment FROM systems WHERE id = {$incident['system_id']}")->fetch();

        $response = $client->post("/settings/resources/{$incident['resource_id']}", ['name' => $resource['name'], 'identifier' => $resource['identifier'], 'active' => '0']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString("#{$incident['id']}", $response->body);

        $response = $client->post("/settings/systems/{$incident['system_id']}", ['name' => $system['name'], 'environment' => $system['environment'], 'active' => '0']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('먼저 복구 처리하세요', $response->body);

        $this->assertSame(1, $this->value($client, "SELECT is_active FROM resources WHERE id = {$incident['resource_id']}"));
        $this->assertSame(1, $this->value($client, "SELECT is_active FROM systems WHERE id = {$incident['system_id']}"));
    }

    public function testDeactivatedSystemIsHiddenAndStopsReceivingAlerts(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->post('/settings/systems', ['code' => 'pay', 'name' => '결제', 'environment' => 'prod', 'active' => '1']);
        $id = $this->value($client, "SELECT id FROM systems WHERE code = 'pay'");
        $client->post("/settings/systems/{$id}/resources", ['type' => 'http', 'name' => '결제 API', 'identifier' => 'https://pay.example.com']);
        $resourceId = $this->value($client, "SELECT id FROM resources WHERE system_id = {$id}");
        $ingestor = new AlertIngestor($client->pdo(...));
        $this->assertNotNull($ingestor->findResource('http', null, 'https://pay.example.com'));

        // 리소스 비활성화 → 연결 해제, 다시 사용하면 연결
        $client->post("/settings/resources/{$resourceId}", ['name' => '결제 API', 'identifier' => 'https://pay.example.com', 'active' => '0']);
        $this->assertNull($ingestor->findResource('http', null, 'https://pay.example.com'));
        $client->post("/settings/resources/{$resourceId}", ['name' => '결제 API', 'identifier' => 'https://pay.example.com', 'active' => '1']);
        $this->assertNotNull($ingestor->findResource('http', null, 'https://pay.example.com'));

        // 서비스 비활성화 → 대시보드에서 숨기고 알림 대상에서도 빠진다
        $this->assertSame('/settings/systems', $client->post("/settings/systems/{$id}", ['name' => '결제', 'environment' => 'prod', 'active' => '0'])->headers['Location']);
        $this->assertNull($ingestor->findResource('http', null, 'https://pay.example.com'));
        $this->assertStringNotContainsString('href="/systems/pay"', $client->get('/')->body);
        $this->assertSame(404, $client->get('/systems/pay')->status);
        $this->assertStringContainsString('비활성', $client->get('/settings/systems')->body);
    }

    private function rows(TestClient $client, string $table): int
    {
        return (int) $client->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function value(TestClient $client, string $sql): mixed
    {
        $value = $client->pdo()->query($sql)->fetchColumn();

        return is_string($value) && ctype_digit($value) ? (int) $value : ($value === false ? null : $value);
    }
}
