<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 샘플 장애: #6 진행 중(미지정), #5 확인됨, #3 복구됨
 */
final class IncidentActionsTest extends TestCase
{
    private TestClient $client;
    private User $operator;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $this->operator = $this->client->loginAs('operator');
    }

    public function testOperatorAcknowledgesAssignsAndAddsNote(): void
    {
        $response = $this->submit(6, ['status' => 'acknowledged', 'assignee' => (string) $this->operator->id, 'note' => '  원인 확인 중  ']);

        $this->assertSame('/incidents/6', $response->headers['Location']);
        $row = $this->row(6);
        $this->assertSame('acknowledged', $row['status']);
        $this->assertNotNull($row['acknowledged_at']);
        $this->assertSame($this->operator->id, (int) $row['assignee_user_id']);
        $this->assertSame([
            ['assigned', '담당자: ' . $this->operator->name],
            ['acknowledged', null],
            ['note', '원인 확인 중'],
        ], $this->newEvents(6, 3));

        $page = $this->client->get('/incidents/6')->body;
        $this->assertStringContainsString('저장했습니다', $page);
        $this->assertStringContainsString('원인 확인 중', $page);
        $this->assertStringNotContainsString('저장했습니다', $this->client->get('/incidents/6')->body, '안내는 한 번만');
    }

    public function testResolveRequiresActionTaken(): void
    {
        $rejected = $this->submit(5, ['status' => 'resolved', 'root_cause' => '로그 누적']);
        $this->assertSame(422, $rejected->status);
        $this->assertStringContainsString('조치 내용을 입력하세요', $rejected->body);
        $this->assertStringContainsString('로그 누적', $rejected->body, '입력값 유지');
        $this->assertSame('acknowledged', $this->row(5)['status']);

        $this->submit(5, ['status' => 'resolved', 'root_cause' => '로그 누적', 'action_taken' => '로그 로테이션 설정']);
        $row = $this->row(5);
        $this->assertSame(['resolved', '로그 누적', '로그 로테이션 설정'], [$row['status'], $row['root_cause'], $row['action_taken']]);
        $this->assertNotNull($row['resolved_at']);
        $this->assertSame([['resolved', null]], $this->newEvents(5, 1));
    }

    public function testDisallowedTransitionIsRejected(): void
    {
        $response = $this->submit(5, ['status' => 'open']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('바꿀 수 없습니다', $response->body);
    }

    public function testReopenResolvedIncident(): void
    {
        $this->submit(3, ['status' => 'open', 'note' => '같은 증상 재발']);

        $row = $this->row(3);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['resolved_at']);
        $this->assertSame([['reopened', null], ['note', '같은 증상 재발']], $this->newEvents(3, 2));
    }

    public function testCannotReopenWhileSameAlertIsActive(): void
    {
        $this->client->pdo()->exec(
            "INSERT INTO incidents (system_id, source, alert_key, title, started_at)
             SELECT system_id, source, alert_key, '재발', '2026-01-01 00:00:00' FROM incidents WHERE id = 3"
        );

        $response = $this->submit(3, ['status' => 'open']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('진행 중이라 다시 열 수 없습니다', $response->body);
        $this->assertSame('resolved', $this->row(3)['status']);
    }

    public function testStaleFormDoesNotOverwriteOtherUsersChange(): void
    {
        $stale = $this->form(6);
        $this->submit(6, ['status' => 'resolved', 'action_taken' => '다른 사람이 복구']);

        $response = $this->client->post('/incidents/6', ['status' => 'acknowledged'] + $stale);
        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('다른 사용자가 먼저', $response->body);
        $this->assertSame('resolved', $this->row(6)['status']);

        $noteOnly = $this->client->post('/incidents/6', ['note' => '오래된 화면에서도 메모는 남긴다'] + $stale);
        $this->assertSame(302, $noteOnly->status);
        $this->assertSame([['note', '오래된 화면에서도 메모는 남긴다']], $this->newEvents(6, 1));
    }

    public function testNothingChangedIsRejected(): void
    {
        $response = $this->submit(6, ['note' => '   ']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('변경된 내용이 없습니다', $response->body);
    }

    public function testAssigneeMustBeActiveOperator(): void
    {
        $viewer = $this->client->addUser('viewer2@example.com', 'correct horse battery', 'viewer');
        $inactive = $this->client->addUser('gone@example.com', 'correct horse battery', 'operator', active: false);

        foreach ([$viewer, $inactive] as $user) {
            $response = $this->submit(6, ['assignee' => (string) $user->id]);
            $this->assertSame(422, $response->status);
            $this->assertStringContainsString('운영자 이상의 사용 중인 계정만', $response->body);
        }

        $options = $this->client->get('/incidents/6')->body;
        $this->assertStringContainsString($this->operator->name, $options);
        $this->assertStringNotContainsString($viewer->name, $options);
        $this->assertStringNotContainsString($inactive->name, $options);
    }

    public function testViewerCannotUpdate(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');

        $this->assertStringNotContainsString('name="note"', $client->get('/incidents/6')->body);
        $this->assertSame(403, $client->post('/incidents/6', ['status' => 'acknowledged', 'seen_status' => 'open'])->status);
        $this->assertSame('open', $client->pdo()->query('SELECT status FROM incidents WHERE id = 6')->fetchColumn());
    }

    public function testUnknownIncident(): void
    {
        $this->assertSame(404, $this->client->post('/incidents/999', ['note' => 'x'])->status);
    }

    /**
     * 화면을 연 상태 그대로의 폼 값 (seen_* 포함)
     *
     * @return array<string, string>
     */
    private function form(int $id): array
    {
        $row = $this->row($id);
        $values = [
            'status' => $row['status'],
            'assignee' => (string) $row['assignee_user_id'],
            'root_cause' => (string) $row['root_cause'],
            'action_taken' => (string) $row['action_taken'],
        ];

        return $values + array_combine(array_map(static fn (string $k): string => 'seen_' . $k, array_keys($values)), $values) + ['note' => ''];
    }

    /**
     * @param array<string, string> $changes
     */
    private function submit(int $id, array $changes): Response
    {
        return $this->client->post("/incidents/{$id}", $changes + $this->form($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->client->pdo()->query("SELECT * FROM incidents WHERE id = {$id}")->fetch();
    }

    /**
     * 이 테스트에서 운영자가 남긴 최근 이벤트 (오래된 순)
     *
     * @return list<array{string, ?string}>
     */
    private function newEvents(int $id, int $count): array
    {
        $rows = $this->client->pdo()->query(
            "SELECT type, message FROM incident_events WHERE incident_id = {$id} AND user_id = {$this->operator->id} ORDER BY id DESC LIMIT {$count}"
        )->fetchAll(\PDO::FETCH_NUM);

        return array_reverse($rows);
    }
}
