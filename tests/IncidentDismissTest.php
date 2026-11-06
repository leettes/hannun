<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\Monitoring\IncidentActions;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 대시보드 '최근 장애' 확인 처리. 샘플 장애: #6 진행 중, #3 복구됨
 */
final class IncidentDismissTest extends TestCase
{
    private TestClient $client;
    private User $operator;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $this->operator = $this->client->loginAs('operator');
    }

    public function testDismissedIncidentLeavesRecentListOnly(): void
    {
        $title = $this->title(6);
        $this->assertStringContainsString('action="/incidents/6/dismiss"', $this->client->get('/')->body);

        $response = $this->client->post('/incidents/6/dismiss', ['reason' => '  배포 중 일시 오류  ']);

        $this->assertSame('/#recent-incidents', $response->headers['Location']);
        $row = $this->row(6);
        $this->assertNotNull($row['dismissed_at']);
        $this->assertSame($this->operator->id, (int) $row['dismissed_by_user_id']);
        $this->assertSame('open', $row['status'], '장애 상태는 그대로');
        $this->assertSame(['note', '최근 장애 목록에서 확인: 배포 중 일시 오류'], $this->lastEvent(6));

        $dashboard = $this->client->get('/')->body;
        $this->assertStringContainsString("장애를 확인했습니다", $dashboard);
        $this->assertStringNotContainsString('action="/incidents/6/dismiss"', $dashboard);
        $this->assertStringNotContainsString('확인했습니다', $this->client->get('/')->body, '안내는 한 번만');
        $this->assertStringContainsString($title, $this->client->get('/incidents')->body, '장애 센터에는 남는다');
        $this->assertStringContainsString('배포 중 일시 오류', $this->client->get('/incidents/6')->body, '타임라인에 사유');
    }

    public function testReasonIsOptional(): void
    {
        $this->client->post('/incidents/6/dismiss');

        $this->assertNotNull($this->row(6)['dismissed_at']);
        $this->assertSame(['note', '최근 장애 목록에서 확인'], $this->lastEvent(6));
    }

    public function testAlreadyDismissedAndTooLongReasonAreRejected(): void
    {
        $this->client->post('/incidents/6/dismiss', ['reason' => str_repeat('가', IncidentActions::DISMISS_REASON_MAX + 1)]);
        $this->assertNull($this->row(6)['dismissed_at']);
        $this->assertStringContainsString('자까지 입력할 수 있습니다', $this->client->get('/')->body);

        $this->client->post('/incidents/6/dismiss');
        $this->client->post('/incidents/6/dismiss');
        $this->assertStringContainsString('이미 확인한 장애입니다', $this->client->get('/')->body);
        $this->assertSame(1, (int) $this->client->pdo()->query(
            "SELECT COUNT(*) FROM incident_events WHERE incident_id = 6 AND message LIKE '최근 장애 목록에서 확인%'"
        )->fetchColumn());
    }

    public function testReopenBringsIncidentBack(): void
    {
        $this->client->post('/incidents/3/dismiss');
        $this->assertNotNull($this->row(3)['dismissed_at']);

        $this->client->post('/incidents/3', [
            'seen_status' => 'resolved', 'status' => 'open',
            'seen_assignee' => (string) ($this->row(3)['assignee_user_id'] ?? ''), 'assignee' => (string) ($this->row(3)['assignee_user_id'] ?? ''),
            'seen_root_cause' => (string) $this->row(3)['root_cause'], 'root_cause' => (string) $this->row(3)['root_cause'],
            'seen_action_taken' => (string) $this->row(3)['action_taken'], 'action_taken' => (string) $this->row(3)['action_taken'],
        ]);

        $row = $this->row(3);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['dismissed_at'], '다시 열면 최근 장애에 다시 나온다');
        $this->assertNull($row['dismissed_by_user_id']);
    }

    public function testViewerCannotDismiss(): void
    {
        $viewer = new TestClient();
        $viewer->loginAs('viewer');

        $this->assertStringNotContainsString('/dismiss"', $viewer->get('/')->body, '버튼 없음');
        $this->assertSame(403, $viewer->post('/incidents/6/dismiss')->status);
        $this->assertNull($viewer->pdo()->query('SELECT dismissed_at FROM incidents WHERE id = 6')->fetchColumn());
    }

    public function testUnknownIncidentIs404(): void
    {
        $this->assertSame(404, $this->client->post('/incidents/999/dismiss')->status);
    }

    private function title(int $id): string
    {
        return (string) $this->client->pdo()->query("SELECT title FROM incidents WHERE id = {$id}")->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->client->pdo()->query("SELECT * FROM incidents WHERE id = {$id}")->fetch();
    }

    /**
     * @return array{string, ?string}
     */
    private function lastEvent(int $id): array
    {
        return $this->client->pdo()->query(
            "SELECT type, message FROM incident_events WHERE incident_id = {$id} ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_NUM);
    }
}
