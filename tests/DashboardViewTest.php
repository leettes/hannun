<?php

declare(strict_types=1);

namespace Tests;

use App\Controller\DashboardController;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class DashboardViewTest extends TestCase
{
    public function testGroupsByCompanyAndRemembersChoice(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');
        $pdo = $client->pdo();
        $pdo->exec("INSERT INTO companies (name) VALUES ('나회사'), ('가회사')");
        $companyA = (int) $pdo->query("SELECT id FROM companies WHERE name = '가회사'")->fetchColumn();
        $companyB = (int) $pdo->query("SELECT id FROM companies WHERE name = '나회사'")->fetchColumn();
        $accountOf = static fn (string $code): int => (int) $pdo->query("SELECT aws_account_id FROM systems WHERE code = '{$code}'")->fetchColumn();
        $pdo->exec('UPDATE aws_accounts SET company_id = ' . $companyA . ' WHERE id = ' . $accountOf('miri'));
        $pdo->exec('UPDATE aws_accounts SET company_id = ' . $companyB . ' WHERE id = ' . $accountOf('koi'));
        $alias = (string) $pdo->query('SELECT alias FROM aws_accounts WHERE id = ' . $accountOf('miri'))->fetchColumn();

        $all = $client->get('/')->body;
        $this->assertStringNotContainsString('company-group', $all, '기본은 전체 보기');

        $page = $client->get('/?view=company')->body;
        foreach (['가회사', '나회사', '관리회사 미지정', 'AWS ' . $alias] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        $this->assertLessThan(strpos($page, '나회사'), strpos($page, '가회사'), '회사 이름순');
        $this->assertLessThan(strpos($page, '관리회사 미지정'), strpos($page, '나회사'), '미지정은 마지막');

        // 다시 들어오면(자동 새로고침 포함) 고른 보기를 유지한다
        $this->assertStringContainsString('company-group', $client->get('/')->body);
        $this->assertStringNotContainsString('company-group', $client->get('/?view=all')->body);
        $this->assertStringNotContainsString('company-group', $client->get('/?view=bogus')->body);
    }

    public function testGroupSummary(): void
    {
        $base = ['cloud' => 'aws', 'company_id' => 1, 'company_name' => '가회사'];
        $groups = DashboardController::groupByCompany([
            'a' => $base + ['aws_alias' => '미리 운영', 'status' => 'up'],
            'b' => $base + ['aws_alias' => '코이 운영', 'status' => 'down'],
            'c' => $base + ['aws_alias' => '미리 운영', 'status' => 'up'],
            'd' => ['cloud' => 'vercel', 'company_id' => 1, 'company_name' => '가회사', 'aws_alias' => null, 'status' => 'warning'],
            'e' => ['cloud' => 'vercel', 'company_id' => null, 'company_name' => null, 'aws_alias' => null, 'status' => 'up'],
        ], ['a' => 0, 'b' => 2, 'c' => 0, 'd' => 0, 'e' => 0]);

        $this->assertCount(2, $groups);
        $this->assertSame('가회사', $groups[0]['label']);
        $this->assertSame(['a', 'b', 'c', 'd'], $groups[0]['codes'], '직접 지정한 Vercel 서비스도 같은 회사');
        $this->assertSame(['미리 운영', '코이 운영'], $groups[0]['accounts']);
        $this->assertSame('down', $groups[0]['status']);
        $this->assertSame(2, $groups[0]['incidents']);
        $this->assertSame(['관리회사 미지정', null, ['e']], [$groups[1]['label'], $groups[1]['company_id'], $groups[1]['codes']]);
    }
}
