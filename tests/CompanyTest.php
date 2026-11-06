<?php

declare(strict_types=1);

namespace Tests;

use App\Controller\CompanyController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class CompanyTest extends TestCase
{
    /** 검증번호가 맞는 사업자번호 */
    private const VALID_NO = '1248100998';

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function access(): iterable
    {
        foreach (['GET /settings/companies', 'GET /settings/companies/new', 'POST /settings/companies', 'GET /settings/companies/1', 'POST /settings/companies/1'] as $route) {
            yield "viewer {$route}" => ['viewer', $route, 403];
            yield "operator {$route}" => ['operator', $route, 403];
        }
        yield 'admin list' => ['admin', 'GET /settings/companies', 200];
        yield 'admin new' => ['admin', 'GET /settings/companies/new', 200];
        yield 'admin unknown' => ['admin', 'GET /settings/companies/999', 404];
    }

    #[DataProvider('access')]
    public function testOnlyAdminCanManageCompanies(string $role, string $route, int $status): void
    {
        $client = new TestClient();
        $client->loginAs($role);
        [$method, $path] = explode(' ', $route);

        $response = $method === 'GET' ? $client->get($path) : $client->post($path, ['name' => 'x']);

        $this->assertSame($status, $response->status);
        $this->assertSame(0, (int) $client->pdo()->query('SELECT COUNT(*) FROM companies')->fetchColumn());
    }

    public function testBusinessNoChecksum(): void
    {
        $this->assertTrue(CompanyController::validBusinessNo(self::VALID_NO));
        $this->assertFalse(CompanyController::validBusinessNo('1248100999'));
        $this->assertFalse(CompanyController::validBusinessNo('124810099'));
    }

    public function testRegisterEditAndAssignToAwsAccount(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $response = $client->post('/settings/companies', ['name' => '주식회사 예시', 'ceo_name' => '홍길동', 'business_no' => '124-81-00998', 'notes' => '세금계산서 김OO']);
        $this->assertSame('/settings/companies', $response->headers['Location']);
        $row = $client->pdo()->query('SELECT id, business_no FROM companies')->fetch();
        $this->assertSame(self::VALID_NO, $row['business_no'], '숫자만 저장');
        $id = (int) $row['id'];

        $list = $client->get('/settings/companies')->body;
        foreach (['주식회사 예시', '홍길동', '124-81-00998'] as $text) {
            $this->assertStringContainsString($text, $list);
        }

        // 잘못된 번호, 중복 번호
        $this->assertSame(422, $client->post('/settings/companies', ['name' => 'B', 'business_no' => '1248100999'])->status);
        $dup = $client->post('/settings/companies', ['name' => 'B', 'business_no' => self::VALID_NO]);
        $this->assertSame(422, $dup->status);
        $this->assertStringContainsString('이미 등록된 사업자번호', $dup->body);
        // 자기 번호로 저장은 된다
        $this->assertSame(302, $client->post("/settings/companies/{$id}", ['name' => '주식회사 예시2', 'ceo_name' => '', 'business_no' => self::VALID_NO])->status);

        // AWS 계정에 관리회사 지정
        $account = $client->pdo()->query('SELECT id, alias, account_id FROM aws_accounts ORDER BY id LIMIT 1')->fetch();
        $this->assertStringContainsString('주식회사 예시2', $client->get("/settings/aws-accounts/{$account['id']}")->body, '선택 목록');
        $this->assertSame(302, $client->post("/settings/aws-accounts/{$account['id']}", ['alias' => $account['alias'], 'company_id' => (string) $id])->status);
        $this->assertSame($id, (int) $client->pdo()->query("SELECT company_id FROM aws_accounts WHERE id = {$account['id']}")->fetchColumn());
        $this->assertStringContainsString('주식회사 예시2', $client->get('/settings/aws-accounts')->body);
        $this->assertStringContainsString($account['alias'], $client->get('/settings/companies')->body, '관리 계정 표시');
        $this->assertStringContainsString('주식회사 예시2', $client->get('/?view=company')->body, '대시보드 관리회사별 보기');

        // 없는 회사는 거부, 비우면 해제
        $this->assertSame(422, $client->post("/settings/aws-accounts/{$account['id']}", ['alias' => $account['alias'], 'company_id' => '999'])->status);
        $client->post("/settings/aws-accounts/{$account['id']}", ['alias' => $account['alias'], 'company_id' => '']);
        $this->assertNull($client->pdo()->query("SELECT company_id FROM aws_accounts WHERE id = {$account['id']}")->fetchColumn() ?: null);
    }

    public function testServiceCompanyOverridesAwsAccountCompany(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $pdo->exec("INSERT INTO companies (name) VALUES ('계정회사'), ('버셀회사')");
        $accountCompany = (int) $pdo->query("SELECT id FROM companies WHERE name = '계정회사'")->fetchColumn();
        $vercelCompany = (int) $pdo->query("SELECT id FROM companies WHERE name = '버셀회사'")->fetchColumn();
        $system = $pdo->query("SELECT id, code, name, environment, aws_account_id FROM systems WHERE code = 'miri'")->fetch();
        $pdo->exec("UPDATE aws_accounts SET company_id = {$accountCompany} WHERE id = {$system['aws_account_id']}");

        $this->assertStringContainsString('AWS 계정의 관리회사 사용', $client->get("/settings/systems/{$system['id']}")->body);
        $this->assertStringContainsString('계정회사', $client->get('/settings/systems')->body, '비우면 AWS 계정의 회사');

        // Vercel 로 바꾸고 관리회사를 직접 지정
        $response = $client->post("/settings/systems/{$system['id']}", [
            'name' => $system['name'], 'environment' => $system['environment'], 'cloud_provider' => 'vercel',
            'company_id' => (string) $vercelCompany, 'active' => '1',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame($vercelCompany, (int) $pdo->query("SELECT company_id FROM systems WHERE id = {$system['id']}")->fetchColumn());
        $this->assertStringContainsString($system['name'], $client->get('/settings/companies')->body);

        $dashboard = $client->get('/?view=company')->body;
        $group = substr($dashboard, (int) strpos($dashboard, '버셀회사'));
        $this->assertStringContainsString('/systems/miri', substr($group, 0, (int) strpos($group, 'company-group-head', 10) ?: strlen($group)), '버셀회사 묶음에 표시');

        $this->assertSame(422, $client->post("/settings/systems/{$system['id']}", [
            'name' => $system['name'], 'environment' => $system['environment'], 'cloud_provider' => 'vercel', 'company_id' => '999', 'active' => '1',
        ])->status);
    }
}
