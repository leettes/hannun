<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\Http\Middleware\Authenticate;
use App\Meetings\MeetingDocument;
use App\Meetings\MeetingRepository;
use App\Security\TwoFactor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 회의록 시안 반영: 메인 대시보드, 회의 할 일, 참석자 라벨, 녹취, 내보내기·재확인
 */
final class MeetingTasksTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';
    private const DECISIONS = "- [ ] 번역 언어값: DB에 저장된 번역 요청 언어 확인\n- [ ] 공지사항: 버전 업그레이드 공지 DB 등록 (참석자 5)\n- [ ] 마케팅: 경쟁사 채널 조사";
    private const TRANSCRIPT = "참석자 1 00:05\n미션 지급 현황부터 볼게요\n\n참석자 2 00:41\n리뷰 작업은 다음 주에\n\n참석자 5 57:40\n공지는 제가 넣겠습니다";

    private TestClient $client;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->client = new TestClient();
        foreach (['writer' => 'write', 'reader' => 'read', 'other' => 'write'] as $name => $access) {
            $user = $this->client->addUser("{$name}@example.com", self::PASSWORD, 'operator');
            $this->client->users->updateMeetingAccess($user->id, $access);
            $this->people[$name] = $this->client->users->findById($user->id) ?? throw new \LogicException();
        }
    }

    public function testRegisterDecisionsAsTasksAndDashboard(): void
    {
        $this->as('writer');
        $id = $this->meeting(['visibility' => 'all', 'subtitle' => '코이 미션 · 번역', 'topics' => '코이, 앱 리뷰']);

        $detail = $this->client->get("/meetings/{$id}")->body;
        $this->assertStringContainsString('결정 사항 3건을 할 일로 등록', $detail);

        $this->client->post("/meetings/{$id}/tasks/from-decisions", ['service' => '코이']);
        $this->client->post("/meetings/{$id}/tasks/from-decisions", ['service' => '코이']); // 두 번 눌러도 한 번만
        $rows = $this->client->pdo()->query("SELECT service, item_enc, task_enc, assignee_label, status FROM meeting_tasks WHERE meeting_id = {$id} ORDER BY sort")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertSame(['코이', null, 'todo'], [$rows[0]['service'], $rows[0]['assignee_label'], $rows[0]['status']]);
        $this->assertSame('참석자 5', $rows[1]['assignee_label']);
        $this->assertStringStartsWith('v1:', $rows[0]['item_enc'], '항목·할 일은 암호화');
        $this->assertStringNotContainsString('번역', $rows[0]['item_enc'] . $rows[0]['task_enc']);

        $main = $this->client->get('/meetings');
        $this->assertSame(200, $main->status);
        $this->assertStringContainsString('코이 미션 · 번역', $main->body, '최근 회의 카드에 주제');
        $this->assertStringContainsString('번역 언어값', $main->body);
        $this->assertStringContainsString('class="svc svc-koi"', $main->body);
        $this->assertStringContainsString('참석자 5', $main->body, '계정 연결 전 담당은 라벨');
        $this->assertMatchesRegularExpression('/담당 미지정<\/div><div class="stat-value">2</', $main->body);
        $this->assertStringContainsString('번역 언어값', $this->client->get('/meetings?q=' . rawurlencode('번역'))->body, '암호화된 할 일도 검색');
        $this->assertStringNotContainsString('번역 언어값', $this->client->get('/meetings?q=' . rawurlencode('경쟁사'))->body);
        $this->assertSame(['meeting.create', 'meeting.view', 'meeting.tasks'], $this->actions($id), '메인 대시보드는 회의별 열람 기록을 남기지 않는다');
    }

    public function testInlineStatusAndAssigneeChanges(): void
    {
        $this->as('writer');
        $id = $this->meeting(['visibility' => 'all']);
        $this->client->post("/meetings/{$id}/tasks/from-decisions", ['service' => '코이']);
        $taskId = (int) $this->client->pdo()->query("SELECT id FROM meeting_tasks WHERE meeting_id = {$id} ORDER BY sort LIMIT 1")->fetchColumn();

        $this->assertSame('/meetings#tasks', $this->client->post("/meetings/tasks/{$taskId}/status", ['status' => 'doing', 'back' => '/meetings#tasks'])->headers['Location']);
        $this->assertSame('/', $this->client->post("/meetings/tasks/{$taskId}/status", ['status' => 'done', 'back' => '//evil.example.com'])->headers['Location'], '돌아갈 주소는 같은 사이트만');
        $this->assertNotNull($this->client->pdo()->query("SELECT done_at FROM meeting_tasks WHERE id = {$taskId}")->fetchColumn());
        $this->client->post("/meetings/tasks/{$taskId}/assignee", ['assignee_user_id' => (string) $this->people['reader']->id]);
        $this->assertSame(422, $this->client->post("/meetings/tasks/{$taskId}/status", ['status' => 'bogus'])->status);

        $page = $this->client->get("/meetings/tasks/{$taskId}");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('대기 → 진행', $page->body);
        $this->assertStringContainsString('담당 미지정 → Tester reader@example.com', $page->body);

        $this->client->post("/meetings/tasks/{$taskId}/notes", ['memo' => '쿼리 공유함']);
        $this->assertStringNotContainsString('쿼리 공유함', (string) $this->client->pdo()->query("SELECT note FROM meeting_task_events WHERE type = 'note'")->fetchColumn(), '메모도 암호화');
        $this->assertStringContainsString('쿼리 공유함', $this->client->get("/meetings/tasks/{$taskId}")->body);

        $this->as('reader');
        $this->assertSame(200, $this->client->get("/meetings/tasks/{$taskId}")->status);
        $this->assertSame(403, $this->client->post("/meetings/tasks/{$taskId}/status", ['status' => 'todo'])->status, '열람 권한은 바꿀 수 없다');
        $this->assertStringNotContainsString('data-autosubmit', $this->client->get('/meetings')->body);
    }

    public function testTasksFollowMeetingVisibility(): void
    {
        $this->as('writer');
        $id = $this->meeting(['visibility' => 'private']);
        $this->client->post("/meetings/{$id}/tasks/from-decisions", ['service' => '코이']);
        $taskId = (int) $this->client->pdo()->query("SELECT id FROM meeting_tasks WHERE meeting_id = {$id} LIMIT 1")->fetchColumn();

        $this->as('other');
        $this->assertStringNotContainsString('번역 언어값', $this->client->get('/meetings')->body);
        $this->assertSame(404, $this->client->get("/meetings/tasks/{$taskId}")->status);
        $this->assertSame(404, $this->client->post("/meetings/tasks/{$taskId}/status", ['status' => 'done'])->status);
        $this->assertSame(404, $this->client->post("/meetings/{$id}/tasks", ['task' => '몰래 추가'])->status);
    }

    public function testParticipantLabelsLinkToStaff(): void
    {
        $this->as('writer');
        $id = $this->transcriptMeeting(['visibility' => 'attendees']);
        $meeting = (new MeetingRepository($this->client->pdo(...), $this->crypto()))->findFor($id, $this->people['writer']->id);
        $labels = array_column($meeting['participants'], 'label');
        $this->assertContains('참석자 5', $labels);
        $this->assertSame(58, (int) $meeting['duration_minutes'], '녹취 마지막 발언 시각');
        $this->client->post("/meetings/{$id}/tasks/from-decisions", ['service' => '코이']);

        $this->as('reader');
        $this->assertSame(404, $this->client->get("/meetings/{$id}")->status, '참석자로 연결되기 전에는 못 본다');

        $this->as('writer');
        $five = array_values(array_filter($meeting['participants'], static fn (array $p): bool => $p['label'] === '참석자 5'))[0];
        $this->client->post("/meetings/{$id}/participants/{$five['id']}", ['user_id' => (string) $this->people['reader']->id]);
        $this->assertStringContainsString('Tester reader@example.com', $this->client->get('/meetings')->body, '라벨 담당이 이름으로');

        $this->as('reader');
        $this->assertSame(200, $this->client->get("/meetings/{$id}")->status, '연결되면 참석자만 공개도 본다');
        $this->assertSame(403, $this->client->post("/meetings/{$id}/participants/{$five['id']}", ['user_id' => ''])->status, '작성자만 연결');
        $this->assertContains('meeting.participant', $this->actions($id));
    }

    public function testTranscriptTabSearchAndDownloadNeedsReauth(): void
    {
        $this->as('writer');
        $id = $this->transcriptMeeting(['visibility' => 'all']);

        $tab = $this->client->get("/meetings/{$id}?tab=transcript&q=" . rawurlencode('리뷰'));
        $this->assertStringContainsString('<mark>리뷰</mark>', $tab->body);
        $this->assertStringContainsString('1 / 3개 발언', $tab->body);
        $this->assertStringContainsString('1 / 3개 발언', $this->client->get("/meetings/{$id}?tab=transcript&who=" . rawurlencode('참석자 5'))->body);

        $first = $this->client->get("/meetings/{$id}/transcript");
        $this->assertStringStartsWith('/meetings/reauth?', $first->headers['Location']);
        $this->assertStringStartsWith('/meetings/reauth?', $this->client->get("/meetings/{$id}/export")->headers['Location']);

        $this->reauthed();
        $download = $this->client->get("/meetings/{$id}/transcript");
        $this->assertSame(self::TRANSCRIPT, $download->body);
        $this->assertStringContainsString('attachment;', $download->headers['Content-Disposition']);
        $export = $this->client->get("/meetings/{$id}/export");
        $this->assertStringContainsString('## 확인 / 할 일', $export->body);
        $this->assertContains('meeting.transcript', $this->actions($id));
        $this->assertContains('meeting.export', $this->actions($id));

        $history = $this->client->get("/meetings/{$id}?tab=history")->body;
        $this->assertStringContainsString('작성 (녹취로 작성)', $history);
        $this->assertStringContainsString('회의록 내보내기', $history);
    }

    public function testTranscriptFormValidation(): void
    {
        $this->as('writer');
        $this->assertSame(200, $this->client->get('/meetings/new/transcript')->status);
        $this->assertSame(422, $this->client->post('/meetings/transcript', ['title' => 'x', 'held_at' => '2026-11-20T10:00', 'visibility' => 'all', 'transcript' => '그냥 글'])->status, '발언 줄 없음');
        $this->assertSame(422, $this->client->post('/meetings/transcript', ['title' => '', 'held_at' => '', 'visibility' => 'all', 'transcript' => self::TRANSCRIPT])->status, '제목·일시 없음');

        $this->as('reader');
        $this->assertSame(403, $this->client->post('/meetings/transcript', ['title' => 'x', 'held_at' => '2026-11-20T10:00', 'visibility' => 'all', 'transcript' => self::TRANSCRIPT])->status);
    }

    public function testDocumentRendering(): void
    {
        $doc = MeetingDocument::render(implode("\n", [
            '# 11/11 회의록',
            '2026-11-11',
            '요약 문단입니다. 참석자 7명이 모였습니다.',
            '',
            '## 1. 코이 미션 (참석자 3)',
            '### 미션 지급 현황 (녹취)',
            '- 재화(녹취상 "블루", 불명확) 확인',
            '    - 하위 항목 <script>x</script>',
            '- **결론:** 데이터를 보고 결정 (참석자 4)',
            '## 2. 신규 앱 (결론 없음)',
            '## 3. 확인 / 할 일',
            '- [ ] 체크리스트는 할 일 블록으로',
        ]), '<div class="TASKS"></div>');

        $html = $doc['html'];
        $this->assertStringContainsString('<p class="doc-lead">요약 문단입니다. 참석자 7명이 모였습니다.</p>', $html, '인원수는 칩이 아님');
        $this->assertStringContainsString('<h2 id="s1">1. 코이 미션 (참석자 3)</h2>', $html);
        $this->assertStringContainsString('<span class="unclear"', $html);
        $this->assertStringContainsString('<li class="decision">', $html);
        $this->assertStringContainsString('<span class="who">참석자 4</span>', $html);
        $this->assertStringContainsString('<ul><li>하위 항목 &lt;script&gt;', $html, '중첩 목록, 이스케이프');
        $this->assertStringContainsString('<span class="no-conclusion">결론 없음</span>', $html);
        $this->assertStringContainsString('<div class="TASKS"></div>', $html);
        $this->assertStringNotContainsString('체크리스트는', $html, '할 일 섹션은 블록으로 대체');
        $this->assertSame(['코이 미션', '신규 앱', '확인 / 할 일'], array_column($doc['toc'], 'title'));
        $this->assertSame('미션 지급 현황', $doc['toc'][0]['sub'][0]['title']);
    }

    private function as(string $name): void
    {
        $user = $this->people[$name];
        $session = $this->client->session;
        $session->clear();
        $session->set(Authenticate::USER_KEY, $user->id);
        $session->set(Authenticate::ACTIVITY_KEY, time());
        $this->client->pdo()->exec("UPDATE users SET totp_secret = 'v1:x', totp_enabled_at = '2026-01-01 00:00:00' WHERE id = {$user->id} AND totp_enabled_at IS NULL");
        $session->set(TwoFactor::SESSION_KEY, ['user' => $user->id, 'activity' => time()]);
    }

    private function reauthed(): void
    {
        $id = (int) $this->client->session->get(Authenticate::USER_KEY);
        $this->client->session->set(TwoFactor::REAUTH_KEY, ['user' => $id, 'at' => time()]);
    }

    /**
     * @param array<string, string> $override
     */
    private function meeting(array $override = []): int
    {
        $response = $this->client->post('/meetings', $override + [
            'title' => '11/20 회의록',
            'held_at' => '2026-11-20T10:00',
            'visibility' => 'attendees',
            'body' => "요약\n\n## 1. 안건\n- 내용",
            'decisions' => self::DECISIONS,
            'attendees' => [(string) $this->people['writer']->id],
        ]);
        $this->assertSame(302, $response->status);

        return (int) basename($response->headers['Location']);
    }

    /**
     * @param array<string, string> $override
     */
    private function transcriptMeeting(array $override = []): int
    {
        $response = $this->client->post('/meetings/transcript', $override + [
            'title' => '',
            'held_at' => '',
            'visibility' => 'all',
            'transcript' => self::TRANSCRIPT,
            'transcript_name' => '1120.txt',
            'document' => "# 11/20 회의록\n\n2026-11-20\n\n요약 문단\n\n## 1. 안건\n- 내용\n\n## 2. 확인 / 할 일\n\n" . self::DECISIONS,
        ]);
        $this->assertSame(302, $response->status, '녹취로 작성');

        return (int) basename($response->headers['Location']);
    }

    private function crypto(): \App\Security\Crypto
    {
        return \App\Security\Crypto::fromBase64(TestClient::APP_KEY) ?? throw new \LogicException();
    }

    /**
     * @return list<string>
     */
    private function actions(int $meetingId): array
    {
        return $this->client->pdo()->query("SELECT action FROM audit_logs WHERE target_type = 'meeting' AND target_id = {$meetingId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
