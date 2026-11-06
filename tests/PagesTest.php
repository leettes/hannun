<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class PagesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard' => ['/', 200, '서비스 상태'];
        yield 'system' => ['/systems/miri', 200, '미리'];
        yield 'incidents' => ['/incidents', 200, '장애 센터'];
        yield 'incidents filtered' => ['/incidents?status=open', 200, '장애 센터'];
        yield 'incident' => ['/incidents/6', 200, 'API 헬스체크 실패'];
        yield 'unknown system' => ['/systems/nope', 404, '등록되지 않은 서비스'];
        yield 'unknown incident' => ['/incidents/999', 404, '존재하지 않는 장애'];
        yield 'unknown page' => ['/nope', 404, '페이지를 찾을 수 없습니다'];
    }

    #[DataProvider('pages')]
    public function testPageRendersForLoggedInUser(string $uri, int $status, string $expected): void
    {
        $client = new TestClient();
        $client->loginAs();

        $response = $client->get($uri);

        $this->assertSame($status, $response->status);
        $this->assertStringContainsString($expected, $response->body);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function refreshPages(): iterable
    {
        yield 'dashboard' => ['/', true];
        yield 'system' => ['/systems/miri', true];
        yield 'incidents' => ['/incidents?status=open', true];
        yield 'incident form' => ['/incidents/6', false];
        yield 'settings' => ['/settings/systems', false];
        yield 'not found' => ['/nope', false];
    }

    /**
     * 상태를 보는 화면만 자동 새로고침한다 (입력 중인 폼이 날아가지 않게).
     */
    #[DataProvider('refreshPages')]
    public function testAutoRefreshOnlyOnStatusPages(string $uri, bool $refresh): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $body = $client->get($uri)->body;

        $this->assertSame($refresh, str_contains($body, '<meta http-equiv="refresh" content="60">'));
        $this->assertSame($refresh, str_contains($body, 'data-refresh-seconds="60"'));
    }

    public function testDashboardCardShowsLatestResponseTime(): void
    {
        $client = new TestClient();
        $client->loginAs();

        $body = $client->get('/')->body;
        // 미리: 웹 412ms, API 138ms → 가장 느린 값
        $this->assertStringContainsString('title="웹 412ms · API 138ms"', $body);
        $this->assertStringContainsString('최대 412ms', $body);
    }

    public function testSettingsMenuGroupsAdminPages(): void
    {
        $admin = new TestClient();
        $admin->loginAs('admin');

        $body = $admin->get('/')->body;
        $this->assertStringContainsString('<details class="nav-group">', $body, '설정 밖에서는 접혀 있다');
        foreach (['/settings/systems' => '서비스 관리', '/settings/aws-accounts' => 'AWS 계정', '/users' => '사용자 관리'] as $href => $label) {
            $this->assertStringContainsString('href="' . $href . '">' . $label . '</a>', $body);
        }
        $this->assertStringContainsString('<details class="nav-group" open>', $admin->get('/users')->body, '설정 안에서는 펼친다');

        $operator = new TestClient();
        $operator->loginAs('operator');
        $this->assertStringNotContainsString('nav-group', $operator->get('/')->body, '관리자만');
    }

    public function testSystemPageShowsResponseTimeChart(): void
    {
        $client = new TestClient();
        $client->loginAs();

        $body = $client->get('/systems/miri')->body;
        $this->assertStringContainsString('응답 시간', $body);
        $this->assertStringContainsString('최근 1시간 · 1분 평균', $body);
        $this->assertStringContainsString('<a class="tab is-active" href="?range=1h">1시간</a>', $body);
        $this->assertStringContainsString('class="chart-data"', $body);
        $this->assertStringContainsString('/assets/chart.js', $body);

        $body = $client->get('/systems/miri?range=7d')->body;
        $this->assertStringContainsString('최근 일주일 · 1시간 평균', $body);
        $this->assertStringContainsString('<a class="tab is-active" href="?range=7d">일주일</a>', $body);

        $body = $client->get('/systems/miri?range=custom&from=2026-11-01T00:00&to=2026-11-02T00:00')->body;
        $this->assertStringContainsString('2026-11-01 00:00 ~ 2026-11-02 00:00 · 10분 평균', $body);
        $this->assertStringContainsString('<details class="range-custom" open>', $body);

        $body = $client->get('/systems/miri?range=custom&from=2026-11-02T00:00&to=2026-11-01T00:00')->body;
        $this->assertStringContainsString('끝 시각은 시작 시각보다 뒤여야 합니다.', $body);
        $this->assertStringContainsString('최근 1시간 · 1분 평균', $body);
    }

    public function testSecurityHeadersAreSet(): void
    {
        $response = (new TestClient())->get('/login');

        $this->assertSame('DENY', $response->headers['X-Frame-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    }
}
