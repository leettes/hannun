<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\Http\Middleware\Authenticate;
use App\Security\TwoFactor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 기능 추적 3단계 (docs/MEETINGS.md)
 */
final class FeaturesTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    private TestClient $client;

    /** @var array<string, User> */
    private array $people = [];

    private int $appId;

    protected function setUp(): void
    {
        $this->client = new TestClient();
        $people = [
            'operator' => ['operator', 'write'],   // 회의록 작성 + 기능 등록
            'viewer' => ['viewer', 'none'],        // 기능 조회만
            'reader' => ['viewer', 'read'],        // 회의록 열람 가능, 기능 등록 불가
            'outsider' => ['operator', 'read'],    // 회의록 권한은 있지만 참석자가 아님
        ];
        foreach ($people as $name => [$role, $access]) {
            $user = $this->client->addUser("{$name}@example.com", self::PASSWORD, $role);
            $this->client->users->updateMeetingAccess($user->id, $access);
            $this->people[$name] = $this->client->users->findById($user->id) ?? throw new \LogicException();
        }
        $pdo = $this->client->pdo();
        $pdo->exec("INSERT INTO apps (system_id, platform, name, bundle_id) VALUES (1, 'ios', '미리 앱', 'kr.miri.app')");
        $this->appId = (int) $pdo->lastInsertId();
    }

    public function testViewerSeesButCannotChange(): void
    {
        $this->as('operator');
        $id = $this->create();

        $this->as('viewer');
        $this->assertSame(200, $this->client->get('/features')->status);
        $detail = $this->client->get("/features/{$id}");
        $this->assertSame(200, $detail->status);
        $this->assertStringNotContainsString('/status"', $detail->body, '상태 변경 폼 없음');
        $this->assertSame(403, $this->client->get('/features/new')->status);
        $this->assertSame(403, $this->client->post('/features', $this->form())->status);
        $this->assertSame(403, $this->client->post("/features/{$id}/status", ['status' => 'planned'])->status);
        $this->assertSame(403, $this->client->post("/features/{$id}/notes", ['note' => 'x'])->status);
        $this->assertSame(403, $this->client->get("/features/{$id}/edit")->status);
    }

    public function testCreateValidation(): void
    {
        $this->as('operator');
        $this->assertSame(422, $this->client->post('/features', $this->form(['system_id' => '']))->status);
        $this->assertSame(422, $this->client->post('/features', $this->form(['system_id' => '2', 'app_id' => (string) $this->appId]))->status, '다른 서비스의 앱');
        $this->assertSame(422, $this->client->post('/features', $this->form(['target_version' => '1.0 beta!']))->status);
        $this->assertSame(422, $this->client->post('/features', $this->form(['status' => 'released']))->status, '처음부터 배포됨은 안 됨');
        $this->assertSame(422, $this->client->post('/features', $this->form(['title' => '']))->status);
        $this->assertSame(0, (int) $this->client->pdo()->query('SELECT COUNT(*) FROM features')->fetchColumn());
    }

    public function testStatusTransitionsAndTimeline(): void
    {
        $this->as('operator');
        $id = $this->create(['target_version' => '1.4.0']);

        $this->assertSame(422, $this->client->post("/features/{$id}/status", ['status' => 'released', 'released_version' => '1.4.0'])->status, '요청에서 바로 배포됨 불가');
        $this->assertSame(422, $this->client->post("/features/{$id}/status", ['status' => 'requested'])->status, '같은 상태');
        $this->assertSame(302, $this->client->post("/features/{$id}/status", ['status' => 'in_progress', 'note' => '착수'])->status);
        $this->assertSame(422, $this->client->post("/features/{$id}/status", ['status' => 'released', 'released_version' => ''])->status, '배포 버전 필수');

        $this->assertSame(302, $this->client->post("/features/{$id}/status", ['status' => 'released', 'released_version' => '1.4.1'])->status);
        $row = $this->row($id);
        $this->assertSame(['released', '1.4.1'], [$row['status'], $row['released_version']]);
        $this->assertNotNull($row['released_at']);

        $this->assertSame(302, $this->client->post("/features/{$id}/status", ['status' => 'in_progress', 'note' => '버그 수정'])->status, '배포 후 재작업');
        $row = $this->row($id);
        $this->assertSame([null, null], [$row['released_version'], $row['released_at']], '배포됨에서 벗어나면 배포 정보 지움');

        $this->assertSame(302, $this->client->post("/features/{$id}/notes", ['note' => '고객사 확인 필요'])->status);
        $this->assertSame(422, $this->client->post("/features/{$id}/notes", ['note' => '  '])->status);

        $events = $this->client->pdo()->query("SELECT type, from_value, to_value, note FROM feature_events WHERE feature_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([
            ['created', null, 'requested', null],
            ['status', 'requested', 'in_progress', '착수'],
            ['status', 'in_progress', 'released', 'v1.4.1'],
            ['status', 'released', 'in_progress', '버그 수정'],
            ['note', null, null, '고객사 확인 필요'],
        ], $events);
        $page = $this->client->get("/features/{$id}")->body;
        $this->assertStringContainsString('요청 → 개발 중', $page);
        $this->assertStringContainsString('고객사 확인 필요', $page);
    }

    public function testEditRecordsChangedFieldsOnly(): void
    {
        $this->as('operator');
        $id = $this->create();
        $this->assertSame(200, $this->client->get("/features/{$id}/edit")->status);

        $this->client->post("/features/{$id}", $this->form());
        $this->assertSame(0, $this->events($id, 'edit'), '바뀐 게 없으면 기록 없음');

        $this->client->post("/features/{$id}", $this->form(['title' => '새 이름', 'app_id' => (string) $this->appId, 'assignee_user_id' => (string) $this->people['viewer']->id]));
        $note = (string) $this->client->pdo()->query("SELECT note FROM feature_events WHERE feature_id = {$id} AND type = 'edit'")->fetchColumn();
        $this->assertSame('앱, 이름, 담당자', $note);
        $this->assertSame('requested', $this->row($id)['status'], '수정으로는 상태가 바뀌지 않는다');
    }

    public function testOpenFeaturesOnSystemAndAppPages(): void
    {
        $this->as('operator');
        $this->create(['title' => '서비스 기능']);
        $this->create(['title' => '앱 기능', 'app_id' => (string) $this->appId]);
        $done = $this->create(['title' => '끝난 기능', 'status' => 'in_progress']);
        $this->client->post("/features/{$done}/status", ['status' => 'released', 'released_version' => '2.0.0']);

        $system = $this->client->get('/systems/' . $this->client->pdo()->query('SELECT code FROM systems WHERE id = 1')->fetchColumn())->body;
        $this->assertStringContainsString('서비스 기능', $system);
        $this->assertStringContainsString('앱 기능', $system);
        $this->assertStringNotContainsString('끝난 기능', $system, '배포된 기능은 진행 중에서 빠진다');

        $app = $this->client->get('/apps/' . $this->appId)->body;
        $this->assertStringContainsString('앱 기능', $app);
        $this->assertStringNotContainsString('서비스 기능', $app);

        $list = $this->client->get('/features')->body;
        $this->assertStringNotContainsString('끝난 기능', $list, '기본 탭은 진행 중');
        $this->assertStringContainsString('끝난 기능', $this->client->get('/features?status=released')->body);
        $this->assertStringContainsString('끝난 기능', $this->client->get('/features?status=all')->body);
        $this->assertStringNotContainsString('앱 기능', $this->client->get('/features?app=999')->body);
    }

    public function testRegisterFromMeetingAndSourceLinkVisibility(): void
    {
        $this->as('operator');
        $meeting = $this->client->post('/meetings', [
            'title' => '비공개 기획 회의',
            'held_at' => '2026-11-20T10:00',
            'system_id' => '1',
            'visibility' => 'attendees',
            'body' => '본문',
            'decisions' => "- [ ] 결제 모듈 교체\n- 알림 문구 수정",
            'attendees' => [(string) $this->people['operator']->id, (string) $this->people['reader']->id],
        ]);
        $meetingId = (int) basename($meeting->headers['Location']);

        $detail = $this->client->get("/meetings/{$meetingId}")->body;
        $this->assertStringContainsString("/meetings/{$meetingId}/features/new", $detail);

        $form = $this->client->get("/meetings/{$meetingId}/features/new?line=1");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('value="알림 문구 수정"', $form->body, '결정 사항이 이름에 채워진다');
        $this->assertStringContainsString('결제 모듈 교체', $form->body);

        $created = $this->client->post("/meetings/{$meetingId}/features", $this->form(['title' => '결제 모듈 교체', 'status' => 'planned']));
        $featureId = (int) basename($created->headers['Location']);
        $this->assertSame($meetingId, (int) $this->row($featureId)['meeting_id']);
        $this->assertStringContainsString('결제 모듈 교체', $this->client->get("/meetings/{$meetingId}")->body, '회의록에 등록한 기능 표시');
        $this->assertStringContainsString("href=\"/meetings/{$meetingId}\"", $this->client->get("/features/{$featureId}")->body);

        // 참석자(회의록 열람 가능): 링크
        $this->as('reader');
        $page = $this->client->get("/features/{$featureId}")->body;
        $this->assertStringContainsString("href=\"/meetings/{$meetingId}\"", $page);
        $this->assertStringNotContainsString('비공개 기획 회의', $page, '회의 제목은 기능 화면에 넣지 않는다');
        $this->assertSame(403, $this->client->get("/meetings/{$meetingId}/features/new")->status, '조회 역할은 기능 등록 불가');

        // 회의록 권한은 있지만 참석자가 아님 / 회의록 권한 없음: 링크 없음
        foreach (['outsider', 'viewer'] as $name) {
            $this->as($name);
            $page = $this->client->get("/features/{$featureId}")->body;
            $this->assertStringContainsString('회의에서 등록됨', $page, $name);
            $this->assertStringNotContainsString("href=\"/meetings/{$meetingId}\"", $page, $name);
        }
        $this->as('outsider');
        $this->assertSame(404, $this->client->get("/meetings/{$meetingId}/features/new")->status, '볼 수 없는 회의에서는 등록 불가');
        $this->assertSame(404, $this->client->post("/meetings/{$meetingId}/features", $this->form())->status);
        $this->as('viewer');
        $this->assertSame(403, $this->client->get("/meetings/{$meetingId}/features/new")->status, '회의록 관문');
    }

    private function as(string $name): void
    {
        $user = $this->people[$name];
        $session = $this->client->session;
        $session->clear();
        $session->set(Authenticate::USER_KEY, $user->id);
        $session->set(Authenticate::ACTIVITY_KEY, time());
        if ($user->meetingAccess !== 'none') {
            $this->client->pdo()->exec("UPDATE users SET totp_secret = 'v1:x', totp_enabled_at = '2026-01-01 00:00:00' WHERE id = {$user->id} AND totp_enabled_at IS NULL");
            $session->set(TwoFactor::SESSION_KEY, ['user' => $user->id, 'activity' => time()]);
        }
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            'title' => '푸시 알림 개선',
            'system_id' => '1',
            'app_id' => '',
            'status' => 'requested',
            'target_version' => '',
            'assignee_user_id' => '',
            'description' => '',
        ];
    }

    /**
     * @param array<string, string> $override
     */
    private function create(array $override = []): int
    {
        $response = $this->client->post('/features', $this->form($override));
        $this->assertSame(302, $response->status, '기능 등록');

        return (int) basename($response->headers['Location']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->client->pdo()->query("SELECT * FROM features WHERE id = {$id}")->fetch();
    }

    private function events(int $id, string $type): int
    {
        return (int) $this->client->pdo()->query("SELECT COUNT(*) FROM feature_events WHERE feature_id = {$id} AND type = '{$type}'")->fetchColumn();
    }
}
