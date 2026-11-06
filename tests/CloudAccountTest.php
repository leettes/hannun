<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class CloudAccountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function access(): iterable
    {
        foreach (['GET /settings/aws-accounts', 'GET /settings/aws-accounts/new', 'POST /settings/aws-accounts', 'GET /settings/aws-accounts/1', 'POST /settings/aws-accounts/1'] as $route) {
            yield "viewer {$route}" => ['viewer', $route, 403];
            yield "operator {$route}" => ['operator', $route, 403];
        }
        yield 'admin list' => ['admin', 'GET /settings/aws-accounts', 200];
        yield 'admin new' => ['admin', 'GET /settings/aws-accounts/new', 200];
        yield 'admin unknown' => ['admin', 'GET /settings/aws-accounts/999', 404];
    }

    #[DataProvider('access')]
    public function testOnlyAdminCanManageAwsAccounts(string $role, string $route, int $status): void
    {
        $client = new TestClient();
        $client->loginAs($role);
        $before = (int) $client->pdo()->query('SELECT COUNT(*) FROM aws_accounts')->fetchColumn();
        [$method, $path] = explode(' ', $route);

        $response = $method === 'GET' ? $client->get($path) : $client->post($path, ['account_id' => '999999999999', 'alias' => 'x']);

        $this->assertSame($status, $response->status);
        $this->assertSame($before, (int) $client->pdo()->query('SELECT COUNT(*) FROM aws_accounts')->fetchColumn());
    }

    public function testAdminRegistersAccountAndLinksService(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $response = $client->post('/settings/aws-accounts', [
            'account_id' => '1234-5678-9012', 'alias' => '결제 운영', 'root_email' => ' AWS-Pay@Example.com ', 'mfa_enabled' => '0', 'notes' => '비밀번호는 1Password 인프라 보관함',
        ]);
        $this->assertSame('/settings/aws-accounts', $response->headers['Location']);
        $row = $client->pdo()->query("SELECT id, alias, root_email, mfa_enabled, notes FROM aws_accounts WHERE account_id = '123456789012'")->fetch();
        $this->assertSame(['결제 운영', 'aws-pay@example.com', 0], [$row['alias'], $row['root_email'], $row['mfa_enabled']]);

        $list = $client->get('/settings/aws-accounts')->body;
        foreach (['계정을 등록했습니다', '1234-5678-9012', 'aws-pay@example.com', '안 됨'] as $text) {
            $this->assertStringContainsString($text, $list);
        }

        // 서비스에 연결 → 서비스 관리·서비스 상세에 표시
        $client->post('/settings/systems', ['code' => 'pay', 'name' => '결제', 'environment' => 'prod', 'cloud_provider' => 'aws', 'aws_account_id' => (string) $row['id']]);
        $this->assertSame((int) $row['id'], (int) $client->pdo()->query("SELECT aws_account_id FROM systems WHERE code = 'pay'")->fetchColumn());
        $this->assertStringContainsString('AWS · 결제 운영 (1234-5678-9012)', $client->get('/settings/systems')->body);
        $this->assertStringContainsString('AWS · 결제 운영 (1234-5678-9012)', $client->get('/systems/pay')->body);
        $this->assertStringContainsString('결제', $client->get('/settings/aws-accounts')->body, '사용 서비스');

        // 수정: 계정 ID 는 바뀌지 않는다
        $client->post("/settings/aws-accounts/{$row['id']}", ['account_id' => '000000000000', 'alias' => '결제', 'root_email' => '', 'mfa_enabled' => '1', 'notes' => '']);
        $after = $client->pdo()->query("SELECT account_id, root_email, mfa_enabled, notes FROM aws_accounts WHERE id = {$row['id']}")->fetch();
        $this->assertSame(['123456789012', null, 1, null], [$after['account_id'], $after['root_email'], $after['mfa_enabled'], $after['notes']]);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidAccounts(): iterable
    {
        $ok = ['account_id' => '123456789012', 'alias' => 'a', 'root_email' => '', 'mfa_enabled' => '', 'notes' => ''];
        yield 'short id' => [['account_id' => '12345'] + $ok, '12자리 숫자'];
        yield 'duplicate id' => [['account_id' => '1234 5678 9012'] + $ok, '이미 등록된 계정입니다'];
        yield 'empty alias' => [['alias' => ''] + $ok, '별칭을 50자 이내로'];
        yield 'bad email' => [['root_email' => 'nope'] + $ok, '올바른 이메일'];
        yield 'bad mfa' => [['mfa_enabled' => 'yes'] + $ok, 'MFA 상태를 선택하세요'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidAccounts')]
    public function testInvalidAccountIsRejected(array $input, string $message): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->pdo()->exec("INSERT INTO aws_accounts (account_id, alias) VALUES ('123456789012', '기존')");
        $before = (int) $client->pdo()->query('SELECT COUNT(*) FROM aws_accounts')->fetchColumn();

        $response = $client->post('/settings/aws-accounts', $input);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($message, $response->body);
        $this->assertSame($before, (int) $client->pdo()->query('SELECT COUNT(*) FROM aws_accounts')->fetchColumn());
    }

    public function testCloudDefaultsToAwsAndNonAwsDropsAccount(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->pdo()->exec("INSERT INTO aws_accounts (account_id, alias) VALUES ('210987654321', '웹')");
        $account = (string) $client->pdo()->query("SELECT id FROM aws_accounts WHERE account_id = '210987654321'")->fetchColumn();

        $this->assertStringContainsString('<option value="aws" selected>AWS</option>', $client->get('/settings/systems/new')->body);
        $client->post('/settings/systems', ['code' => 'web', 'name' => '웹', 'environment' => 'prod']);
        $this->assertSame('aws', $client->pdo()->query("SELECT cloud_provider FROM systems WHERE code = 'web'")->fetchColumn(), '기본 AWS');

        $id = (int) $client->pdo()->query("SELECT id FROM systems WHERE code = 'web'")->fetchColumn();
        $client->post("/settings/systems/{$id}", ['name' => '웹', 'environment' => 'prod', 'cloud_provider' => 'vercel', 'aws_account_id' => $account, 'active' => '1']);
        $row = $client->pdo()->query("SELECT cloud_provider, aws_account_id FROM systems WHERE id = {$id}")->fetch();
        $this->assertSame(['vercel', null], [$row['cloud_provider'], $row['aws_account_id']], 'AWS 가 아니면 계정 연결 해제');

        $response = $client->post("/settings/systems/{$id}", ['name' => '웹', 'environment' => 'prod', 'cloud_provider' => 'oracle', 'active' => '1']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('클라우드를 선택하세요', $response->body);
    }

    public function testAwsResourcesHiddenByDefault(): void
    {
        $hidden = new TestClient();
        $hidden->loginAs('admin');
        $shown = new TestClient(['aws_resources' => true]);
        $shown->loginAs('admin');

        foreach (['/systems/miri' => '<h2>EC2</h2>', '/' => 'EC2<span', '/settings/systems/1' => 'EC2 추가'] as $path => $marker) {
            $this->assertStringNotContainsString($marker, $hidden->get($path)->body, "{$path} 숨김");
            $this->assertStringContainsString($marker, $shown->get($path)->body, "{$path} 표시");
        }
        $this->assertStringContainsString('<h2>웹/API</h2>', $hidden->get('/systems/miri')->body, '웹/API 는 그대로');
    }
}
