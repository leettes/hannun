<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class DomainOverrideTest extends TestCase
{
    private TestClient $client;

    private string $domain;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $this->client->loginAs('admin');
        $pdo = $this->client->pdo();
        $pdo->exec("UPDATE resources SET is_active = 0 WHERE type = 'http'");
        $this->client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '하이어 API', 'identifier' => 'https://prod-api.higher.im/health', 'kind' => 'api']);
        // 자동 수집: .im 은 등록업체를 주지 않는다
        $pdo->exec(<<<'SQL'
            UPDATE resources SET domain_info = '{"domain":"higher.im","registrar":null,"expires_at":"2027-07-13 00:59:58","nameservers":["ns-163.awsdns-20.com"],"checked_at":"2026-11-06 17:21:54","error":null}'
            WHERE identifier = 'https://prod-api.higher.im/health'
            SQL);
        $this->domain = 'higher.im';
    }

    public function testAdminEditsDomainInfoAndItIsShownEverywhere(): void
    {
        $form = $this->client->get('/settings/domains/higher.im');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('자동 수집: 2027-07-13', $form->body);
        $this->assertStringContainsString('/settings/domains/higher.im', $this->client->get('/settings/systems')->body, '목록에서 수정 링크');

        $response = $this->client->post('/settings/domains/higher.im', [
            'registrar' => 'Gabia, Inc.', 'expires_at' => '2028-07-13', 'nameservers' => '', 'memo' => '법인 계정, 자동 연장',
        ]);
        $this->assertSame('/settings/systems', $response->headers['Location']);

        $page = $this->client->get('/settings/systems')->body;
        foreach (['가비아 (Gabia, Inc.)', '2028-07-13', '법인 계정, 자동 연장', '수동', 'AWS Route 53'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        $detail = $this->client->get('/systems/miri')->body;
        $this->assertStringContainsString('2028-07-13', $detail, '서비스 상세에도 수동 값');
        $this->assertStringNotContainsString('/settings/domains/', $detail, '서비스 상세에는 수정 링크 없음');

        // cron 이 자동 값을 갱신해도 수동 값이 우선
        $this->client->pdo()->exec("UPDATE resources SET domain_info = json_set(domain_info, '$.expires_at', '2027-08-01 00:00:00')");
        $this->assertStringContainsString('2028-07-13', $this->client->get('/settings/systems')->body);

        // 모두 비우면 자동 값으로 되돌린다
        $this->client->post('/settings/domains/higher.im', ['registrar' => '', 'expires_at' => '', 'nameservers' => '', 'memo' => '']);
        $this->assertSame(0, (int) $this->client->pdo()->query('SELECT COUNT(*) FROM domain_overrides')->fetchColumn());
        $page = $this->client->get('/settings/systems')->body;
        $this->assertStringContainsString('2027-08-01', $page);
        $this->assertStringNotContainsString('Gabia', $page);
    }

    public function testNameserversAreNormalized(): void
    {
        $this->client->post('/settings/domains/higher.im', ['nameservers' => "NS2.Gabia.co.kr.\nns1.gabia.co.kr, ns1.gabia.co.kr"]);
        $saved = $this->client->pdo()->query("SELECT nameservers FROM domain_overrides WHERE domain = 'higher.im'")->fetchColumn();
        $this->assertSame(['ns1.gabia.co.kr', 'ns2.gabia.co.kr'], json_decode((string) $saved, true));
        $this->assertStringContainsString('가비아', $this->client->get('/settings/systems')->body);
    }

    public function testRejectsInvalidInput(): void
    {
        $response = $this->client->post('/settings/domains/higher.im', ['expires_at' => '2027-02-30', 'nameservers' => 'not a host!']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('YYYY-MM-DD', $response->body);
        $this->assertStringContainsString('네임서버 이름을 확인하세요', $response->body);
        $this->assertSame(0, (int) $this->client->pdo()->query('SELECT COUNT(*) FROM domain_overrides')->fetchColumn());
    }

    public function testUnknownDomainAndNonAdmin(): void
    {
        $this->assertSame(404, $this->client->get('/settings/domains/unknown.com')->status);
        $this->client->loginAs('operator');
        $this->assertSame(403, $this->client->get('/settings/domains/higher.im')->status);
        $this->assertSame(403, $this->client->post('/settings/domains/higher.im', ['registrar' => 'x'])->status);
    }
}
