<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AppsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function access(): iterable
    {
        $routes = ['GET /settings/apps', 'GET /settings/apps/new', 'POST /settings/apps', 'GET /settings/apps/1', 'POST /settings/apps/1',
            'GET /settings/app-accounts', 'GET /settings/app-accounts/new', 'POST /settings/app-accounts', 'GET /settings/app-accounts/1', 'POST /settings/app-accounts/1'];
        foreach ($routes as $route) {
            yield "viewer {$route}" => ['viewer', $route, 403];
            yield "operator {$route}" => ['operator', $route, 403];
        }
        yield 'admin apps' => ['admin', 'GET /settings/apps', 200];
        yield 'admin apps android tab' => ['admin', 'GET /settings/apps?platform=android', 200];
        yield 'admin new app' => ['admin', 'GET /settings/apps/new?platform=web', 200];
        yield 'admin accounts' => ['admin', 'GET /settings/app-accounts', 200];
        yield 'admin new account' => ['admin', 'GET /settings/app-accounts/new?platform=google', 200];
        yield 'admin unknown app' => ['admin', 'GET /settings/apps/999', 404];
        yield 'admin unknown account' => ['admin', 'GET /settings/app-accounts/999', 404];
    }

    #[DataProvider('access')]
    public function testOnlyAdminCanManage(string $role, string $route, int $status): void
    {
        $client = new TestClient();
        $client->loginAs($role);
        [$method, $path] = explode(' ', $route);

        $response = $method === 'GET' ? $client->get($path) : $client->post($path, ['platform' => 'apple', 'name' => 'x', 'system_id' => '1']);

        $this->assertSame($status, $response->status);
        $this->assertSame(0, (int) $client->pdo()->query('SELECT (SELECT COUNT(*) FROM apps) + (SELECT COUNT(*) FROM app_store_accounts)')->fetchColumn());
    }

    public function testRegisterAccountAndAppsAndShowThem(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $pdo->exec("INSERT INTO companies (name) VALUES ('계정회사'), ('서비스회사')");
        $accountCompany = (int) $pdo->query("SELECT id FROM companies WHERE name = '계정회사'")->fetchColumn();
        $serviceCompany = (int) $pdo->query("SELECT id FROM companies WHERE name = '서비스회사'")->fetchColumn();
        $miri = (int) $pdo->query("SELECT id FROM systems WHERE code = 'miri'")->fetchColumn();
        $pdo->exec("UPDATE systems SET company_id = {$serviceCompany} WHERE id = {$miri}");

        // 개발자 계정: Apple 은 팀 ID·만료일, Google 은 만료 없음
        $soon = date('Y-m-d', strtotime('+10 days'));
        $this->assertSame(302, $client->post('/settings/app-accounts', [
            'platform' => 'apple', 'name' => '미리 Apple', 'team_id' => 'ab12cd34ef', 'login_email' => 'Dev@Example.com',
            'company_id' => (string) $accountCompany, 'membership_expires_at' => $soon,
        ])->status);
        $this->assertSame(302, $client->post('/settings/app-accounts', [
            'platform' => 'google', 'name' => '미리 Google', 'team_id' => 'IGNORED123', 'membership_expires_at' => '2030-01-01',
        ])->status);
        $apple = $pdo->query("SELECT * FROM app_store_accounts WHERE platform = 'apple'")->fetch();
        $google = $pdo->query("SELECT * FROM app_store_accounts WHERE platform = 'google'")->fetch();
        $this->assertSame(['AB12CD34EF', 'dev@example.com'], [$apple['team_id'], $apple['login_email']]);
        $this->assertSame([null, null], [$google['team_id'], $google['membership_expires_at']], 'Google 은 팀 ID·만료일 없음');

        $accounts = $client->get('/settings/app-accounts')->body;
        $this->assertStringContainsString('(10일)', $accounts);
        $this->assertStringContainsString('⚠', $accounts, '30일 안 만료 경고');
        $this->assertStringContainsString('만료 없음', $accounts);

        // 앱: iOS(계정 회사), Android(계정에 회사 없음 → 서비스 회사), 웹앱
        $this->assertSame(302, $client->post('/settings/apps', [
            'platform' => 'ios', 'system_id' => (string) $miri, 'name' => '미리', 'bundle_id' => 'kr.miri.app',
            'account_id' => (string) $apple['id'],
            'store_url' => 'https://apps.apple.com/kr/app/id123',
        ])->status);
        // Android 는 버전·업데이트일을 직접 입력한다 (iOS 는 App Store 자동 조회)
        $this->assertSame(302, $client->post('/settings/apps', [
            'platform' => 'android', 'system_id' => (string) $miri, 'name' => '미리', 'bundle_id' => 'kr.miri.app', 'account_id' => (string) $google['id'],
            'version' => '2.4.1', 'released_at' => '2026-10-28',
            'store_url' => 'https://play.google.com/store/apps/details?id=kr.miri.app',
        ])->status, '플랫폼이 다르면 같은 번들 ID 가능');
        $this->assertSame(302, $client->post('/settings/apps', [
            'platform' => 'web', 'system_id' => (string) $miri, 'name' => '미리 웹앱', 'bundle_id' => 'ignored', 'account_id' => (string) $apple['id'],
            'store_url' => 'https://app.miri.example.com',
        ])->status);
        $web = $pdo->query("SELECT bundle_id, account_id FROM apps WHERE platform = 'web'")->fetch();
        $this->assertSame([null, null], [$web['bundle_id'], $web['account_id']], '웹앱은 번들 ID·계정 없음');

        $list = $client->get('/settings/apps')->body;
        foreach (['kr.miri.app', '2.4.1', '2026-10-28', '미리 Apple', '계정회사', '서비스회사', 'https://apps.apple.com/kr/app/id123'] as $text) {
            $this->assertStringContainsString($text, $list);
        }
        foreach (['App Store', 'Google Play', '앱 열기', 'href="https://app.miri.example.com"', 'target="_blank"'] as $text) {
            $this->assertStringContainsString($text, $list, '스토어 상세 이동 버튼');
        }
        $this->assertStringNotContainsString('주소 없음', $list);
        $pdo->exec("UPDATE apps SET store_url = NULL WHERE platform = 'web'");
        $this->assertStringContainsString('주소 없음', $client->get('/settings/apps')->body, '주소가 없으면 버튼 대신 안내');
        $this->assertStringContainsString('<span class="tab-count">1</span>', $client->get('/settings/apps?platform=ios')->body);
        $this->assertStringNotContainsString('미리 웹앱', $client->get('/settings/apps?platform=ios')->body);

        $this->assertStringContainsString('미리 Apple', $client->get('/settings/companies')->body, '관리회사에 개발자 계정');
        $this->assertStringContainsString('미리 미리', $client->get('/settings/app-accounts')->body, '계정에 앱 이름');
        $this->assertStringContainsString('/settings/apps/', $client->get('/systems/miri')->body, '관리자는 서비스 상세에서 수정 링크');

        // 서비스 상세: 조회 권한에도 보이고, 수정 링크는 없다
        $client->loginAs('viewer');
        $detail = $client->get('/systems/miri')->body;
        foreach (['<h2>앱</h2>', 'kr.miri.app', '2.4.1', '미리 웹앱'] as $text) {
            $this->assertStringContainsString($text, $detail);
        }
        $this->assertStringNotContainsString('/settings/apps/', $detail);
        $this->assertStringNotContainsString('<h2>앱</h2>', $client->get('/systems/koi')->body, '앱이 없으면 섹션 없음');
    }

    public function testValidation(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $client->post('/settings/app-accounts', ['platform' => 'google', 'name' => 'G']);
        $google = (int) $pdo->query('SELECT id FROM app_store_accounts')->fetchColumn();
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => 'A', 'bundle_id' => 'kr.a.app']);

        $cases = [
            [['platform' => 'ios', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.a.app'], '이미'],
            [['platform' => 'ios', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'nodot'], '번들 ID 를 입력하세요'],
            [['platform' => 'android', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.1bad'], '패키지명을 입력하세요'],
            [['platform' => 'ios', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.b.app', 'account_id' => (string) $google], 'Apple 개발자 계정 중에서'],
            [['platform' => 'ios', 'system_id' => '999', 'name' => 'B', 'bundle_id' => 'kr.b.app'], '사용 중인 서비스'],
            [['platform' => 'android', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.b.app', 'released_at' => '2026-02-30'], 'YYYY-MM-DD'],
            [['platform' => 'ios', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.b.app', 'store_url' => 'ftp://x'], 'http://'],
            [['platform' => 'tv', 'system_id' => '1', 'name' => 'B', 'bundle_id' => 'kr.b.app'], '플랫폼을 선택하세요'],
        ];
        foreach ($cases as [$body, $message]) {
            $response = $client->post('/settings/apps', $body);
            $this->assertSame(422, $response->status, $message);
            $this->assertStringContainsString($message, $response->body);
        }
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM apps')->fetchColumn());

        $this->assertStringContainsString('팀 ID 는', $client->post('/settings/app-accounts', ['platform' => 'apple', 'name' => 'A', 'team_id' => 'short'])->body);
        $this->assertSame(422, $client->post('/settings/app-accounts', ['platform' => 'apple', 'name' => 'A', 'company_id' => '999'])->status);
    }

    public function testAccountInUseCannotBeDeactivatedAndPlatformIsFixed(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $client->post('/settings/app-accounts', ['platform' => 'apple', 'name' => 'A']);
        $account = (int) $pdo->query('SELECT id FROM app_store_accounts')->fetchColumn();
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '앱', 'bundle_id' => 'kr.a.app', 'account_id' => (string) $account]);
        $app = (int) $pdo->query('SELECT id FROM apps')->fetchColumn();

        $response = $client->post("/settings/app-accounts/{$account}", ['name' => 'A', 'active' => '0']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('개발자 계정을 먼저 바꾸세요', $response->body);

        // 앱을 비활성화하면 계정도 비활성화할 수 있고, 앱은 비활성 계정을 그대로 둔 채 저장할 수 있다
        $this->assertSame(302, $client->post("/settings/apps/{$app}", ['system_id' => '1', 'name' => '앱', 'bundle_id' => 'kr.a.app', 'account_id' => (string) $account, 'active' => '0', 'platform' => 'android'])->status);
        $this->assertSame('ios', $pdo->query("SELECT platform FROM apps WHERE id = {$app}")->fetchColumn(), '플랫폼은 바뀌지 않음');
        $this->assertSame(302, $client->post("/settings/app-accounts/{$account}", ['name' => 'A', 'active' => '0'])->status);
        $this->assertSame(302, $client->post("/settings/apps/{$app}", ['system_id' => '1', 'name' => '앱', 'bundle_id' => 'kr.a.app', 'account_id' => (string) $account, 'active' => '1'])->status);
        // 새 앱에는 비활성 계정을 고를 수 없다
        $this->assertSame(422, $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '새 앱', 'bundle_id' => 'kr.b.app', 'account_id' => (string) $account])->status);
    }

    public function testFilterByCompany(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $pdo->exec("INSERT INTO companies (name) VALUES ('가회사'), ('나회사')");
        $a = (int) $pdo->query("SELECT id FROM companies WHERE name = '가회사'")->fetchColumn();
        $b = (int) $pdo->query("SELECT id FROM companies WHERE name = '나회사'")->fetchColumn();
        $client->post('/settings/app-accounts', ['platform' => 'apple', 'name' => '가 Apple', 'company_id' => (string) $a]);
        $apple = (int) $pdo->query('SELECT id FROM app_store_accounts')->fetchColumn();
        $pdo->exec("UPDATE systems SET company_id = {$b} WHERE id = 2");
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '가앱', 'bundle_id' => 'kr.ga.app', 'account_id' => (string) $apple]);
        $client->post('/settings/apps', ['platform' => 'android', 'system_id' => '2', 'name' => '나앱', 'bundle_id' => 'kr.na.app']);
        $pdo->exec("UPDATE systems SET company_id = NULL WHERE id = 3");
        $client->post('/settings/apps', ['platform' => 'web', 'system_id' => '3', 'name' => '미지정앱']);

        $all = $client->get('/settings/apps')->body;
        foreach (['가앱', '나앱', '미지정앱', '?company=' . $a, '?company=none'] as $text) {
            $this->assertStringContainsString($text, $all);
        }

        $onlyA = $client->get("/settings/apps?company={$a}")->body;
        $this->assertStringContainsString('가앱', $onlyA);
        $this->assertStringNotContainsString('나앱', $onlyA);
        $this->assertStringContainsString('platform=ios&amp;company=' . $a, $onlyA, '플랫폼 탭이 회사 필터를 유지');

        $none = $client->get('/settings/apps?company=none')->body;
        $this->assertStringContainsString('미지정앱', $none);
        $this->assertStringNotContainsString('가앱', $none);

        $this->assertStringNotContainsString('가앱', $client->get("/settings/apps?platform=android&company={$b}")->body);
        $this->assertStringContainsString('나앱', $client->get("/settings/apps?platform=android&company={$b}")->body);
        $this->assertStringContainsString('미지정앱', $client->get('/settings/apps?company=999')->body, '모르는 회사는 전체');
    }
}
