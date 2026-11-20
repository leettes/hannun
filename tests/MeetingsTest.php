<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\ConfigSnapshot;
use App\Http\Middleware\Authenticate;
use App\Meetings\Markdown;
use App\Security\TwoFactor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 회의록 2단계: 작성·열람·수정·휴지통, 공개 범위, 암호화, 마크다운 (docs/MEETINGS.md)
 */
final class MeetingsTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    private TestClient $client;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->client = new TestClient();
        foreach (['author' => 'write', 'attendee' => 'read', 'other' => 'write', 'boss' => 'none'] as $name => $access) {
            $user = $this->client->addUser("{$name}@example.com", self::PASSWORD, $name === 'boss' ? 'admin' : 'operator');
            $this->client->users->updateMeetingAccess($user->id, $access);
            $this->people[$name] = $this->client->users->findById($user->id) ?? throw new \LogicException();
        }
    }

    public function testCreateStoresEncryptedAndShowsRenderedMarkdown(): void
    {
        $this->as('author');
        $this->assertSame(200, $this->client->get('/meetings/new')->status);

        $response = $this->client->post('/meetings', $this->form([
            'body' => "## 안건\n- **매출** 목표 1억\n<script>alert(1)</script>\n[악성](javascript:alert(1))",
            'decisions' => "- [ ] 결제 모듈 교체",
        ]));
        $this->assertSame(302, $response->status);
        $id = (int) basename($response->headers['Location']);

        $row = $this->client->pdo()->query("SELECT * FROM meetings WHERE id = {$id}")->fetch();
        foreach (['매출', '결제 모듈', 'script'] as $plain) {
            $this->assertStringNotContainsString($plain, $row['body_enc'] . $row['decisions_enc'], 'DB 에 평문이 남지 않는다');
        }
        $this->assertSame('2026-11-20 14:00', $row['held_at']);

        $page = $this->client->get('/meetings/' . $id);
        $this->assertSame(200, $page->status);
        $this->assertSame('no-store', $page->headers['Cache-Control']);
        $this->assertStringContainsString('<strong>매출</strong>', $page->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
        $this->assertStringNotContainsString('<script>alert(1)', $page->body);
        $this->assertStringNotContainsString('href="javascript:', $page->body);
        $this->assertStringContainsString('md-check', $page->body);
        $this->assertSame(['meeting.create', 'meeting.view'], $this->actions($id));
    }

    public function testVisibilityRules(): void
    {
        $this->as('author');
        $all = $this->create(['title' => '전체 공개 회의', 'visibility' => 'all']);
        $attendees = $this->create(['title' => '참석자 회의', 'visibility' => 'attendees', 'attendees' => [(string) $this->people['attendee']->id]]);
        $private = $this->create(['title' => '나만 보는 회의', 'visibility' => 'private', 'attendees' => [(string) $this->people['attendee']->id]]);

        $list = $this->client->get('/meetings')->body;
        foreach (['전체 공개 회의', '참석자 회의', '나만 보는 회의'] as $title) {
            $this->assertStringContainsString($title, $list, '작성자는 모두 본다');
        }

        $this->as('attendee');
        $list = $this->client->get('/meetings')->body;
        $this->assertStringContainsString('전체 공개 회의', $list);
        $this->assertStringContainsString('참석자 회의', $list);
        $this->assertStringNotContainsString('나만 보는 회의', $list, '목록에도 제목이 나오지 않는다');
        $this->assertSame(200, $this->client->get("/meetings/{$attendees}")->status);
        $this->assertSame(404, $this->client->get("/meetings/{$private}")->status);

        $this->as('other');
        $list = $this->client->get('/meetings')->body;
        $this->assertStringContainsString('전체 공개 회의', $list);
        $this->assertStringNotContainsString('참석자 회의', $list);
        $this->assertSame(200, $this->client->get("/meetings/{$all}")->status);
        $this->assertSame(404, $this->client->get("/meetings/{$attendees}")->status, '있는지도 알리지 않는다');
        $this->assertSame(404, $this->client->get('/meetings/99999')->status);

        $this->as('boss');
        $this->assertSame(403, $this->client->get("/meetings/{$all}")->status, '관리자라도 회의록 권한이 없으면 막힌다');
    }

    public function testOnlyAuthorCanEditDeleteAndChangeVisibility(): void
    {
        $this->as('author');
        $id = $this->create(['visibility' => 'all']);

        $this->as('other');
        $this->assertSame(403, $this->client->get("/meetings/{$id}/edit")->status);
        $this->assertSame(403, $this->client->post("/meetings/{$id}", $this->form(['title' => '바꿈']))->status);
        $this->assertSame(403, $this->client->post("/meetings/{$id}/delete")->status);
        $this->assertStringNotContainsString("/meetings/{$id}/edit", $this->client->get("/meetings/{$id}")->body);

        $this->as('attendee');
        $this->assertSame(403, $this->client->get('/meetings/new')->status, '열람 권한은 작성 불가');
        $this->assertSame(403, $this->client->post('/meetings', $this->form())->status);

        $this->as('author');
        $this->assertSame(200, $this->client->get("/meetings/{$id}/edit")->status);
        $noReauth = $this->client->post("/meetings/{$id}", $this->form(['visibility' => 'private']));
        $this->assertSame(422, $noReauth->status, '공개 범위 변경은 본인 확인 후');
        $this->assertSame('all', $this->visibility($id));

        $this->reauthed();
        $saved = $this->client->post("/meetings/{$id}", $this->form(['title' => '고친 제목', 'visibility' => 'private']));
        $this->assertSame("/meetings/{$id}", $saved->headers['Location']);
        $this->assertSame('private', $this->visibility($id));
        $this->assertSame(['meeting.create', 'meeting.view', 'meeting.update', 'meeting.visibility'], $this->actions($id));
        $detail = (string) $this->client->pdo()->query("SELECT detail FROM audit_logs WHERE action = 'meeting.update' AND target_id = {$id}")->fetchColumn();
        $this->assertSame(['fields' => ['title']], json_decode($detail, true), '감사 로그에는 바뀐 항목 이름만');

        // 내용이 같으면 수정 기록을 남기지 않는다
        $this->client->post("/meetings/{$id}", $this->form(['title' => '고친 제목', 'visibility' => 'private']));
        $this->assertSame(1, substr_count(implode(',', $this->actions($id)), 'meeting.update'));
    }

    public function testTrashAndRestore(): void
    {
        $this->as('author');
        $id = $this->create(['title' => '지울 회의', 'visibility' => 'all']);

        $this->assertStringStartsWith('/meetings/reauth?', $this->client->post("/meetings/{$id}/delete")->headers['Location'], '삭제는 본인 확인 후');
        $this->assertSame(0, (int) $this->client->pdo()->query("SELECT COUNT(*) FROM meetings WHERE deleted_at IS NOT NULL")->fetchColumn());
        $this->reauthed();
        $this->assertSame('/meetings', $this->client->post("/meetings/{$id}/delete")->headers['Location']);
        $this->assertStringNotContainsString('>지울 회의</a>', $this->client->get('/meetings/list')->body, '목록에서 빠진다');
        $this->assertStringContainsString('>지울 회의</a>', $this->client->get('/meetings/trash')->body);
        $this->assertSame(200, $this->client->get("/meetings/{$id}")->status, '작성자는 휴지통에서 연다');
        $this->assertSame(404, $this->client->get("/meetings/{$id}/edit")->status);

        $this->as('other');
        $this->assertSame(404, $this->client->get("/meetings/{$id}")->status, '지운 회의록은 다른 사람에게 없다');
        $this->assertStringNotContainsString('지울 회의', $this->client->get('/meetings/trash')->body);
        $this->assertSame(404, $this->client->post("/meetings/{$id}/restore")->status);

        $this->as('author');
        $this->assertSame("/meetings/{$id}", $this->client->post("/meetings/{$id}/restore")->headers['Location']);
        $this->assertStringContainsString('>지울 회의</a>', $this->client->get('/meetings/list')->body);
        $this->assertContains('meeting.delete', $this->actions($id));
        $this->assertContains('meeting.restore', $this->actions($id));
    }

    public function testValidationAndFilters(): void
    {
        $this->as('author');
        $this->assertSame(422, $this->client->post('/meetings', $this->form(['title' => '']))->status);
        $this->assertSame(422, $this->client->post('/meetings', $this->form(['held_at' => '2026-02-30T10:00']))->status);
        $this->assertSame(422, $this->client->post('/meetings', $this->form(['visibility' => 'public']))->status);
        $this->assertSame(422, $this->client->post('/meetings', $this->form(['attendees' => ['99999']]))->status);
        $this->assertSame(422, $this->client->post('/meetings', $this->form(['system_id' => '99999']))->status);
        $this->assertSame(0, (int) $this->client->pdo()->query('SELECT COUNT(*) FROM meetings')->fetchColumn());

        $this->create(['title' => '11월 정기 회의', 'held_at' => '2026-11-03T10:00', 'system_id' => '1', 'visibility' => 'all']);
        $this->create(['title' => '10월 회고 100%', 'held_at' => '2026-10-03T10:00', 'visibility' => 'all']);

        $this->assertStringNotContainsString('10월', $this->client->get('/meetings/list?q=정기')->body);
        $this->assertStringContainsString('100%', $this->client->get('/meetings/list?q=' . rawurlencode('100%'))->body);
        $this->assertStringNotContainsString('11월', $this->client->get('/meetings/list?q=' . rawurlencode('0%'))->body, '% 는 와일드카드가 아니라 글자');
        $this->assertStringNotContainsString('10월', $this->client->get('/meetings/list?from=2026-11-01')->body);
        $this->assertStringNotContainsString('10월', $this->client->get('/meetings/list?system=1')->body);
        $this->assertStringContainsString('11월 정기 회의', $this->client->get('/meetings/list?attendee=' . $this->people['author']->id)->body, '작성자는 기본 참석자');
    }

    public function testSnapshotHasNoMeetingData(): void
    {
        $this->as('author');
        $this->create(['title' => '스냅샷 확인']);
        $sql = ConfigSnapshot::export($this->client->pdo());

        $this->assertArrayNotHasKey('meetings', ConfigSnapshot::TABLES);
        $this->assertArrayNotHasKey('meeting_attendees', ConfigSnapshot::TABLES);
        $this->assertStringNotContainsString('INTO meeting', $sql);
        $this->assertStringNotContainsString('스냅샷 확인', $sql);
    }

    public function testMarkdownEscapesAndLimitsLinks(): void
    {
        $html = Markdown::render(implode("\n", [
            '# <img src=x onerror=alert(1)>',
            '[클릭](javascript:alert(1)) [메일](mailto:a@example.com) [데이터](data:text/html,x)',
            '[따옴표](https://a.com/"onmouseover="x)',
            '`<b>코드</b>` **<i>굵게</i>**',
            '```',
            '</code><script>x</script>',
            '```',
        ]));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringNotContainsString('href="data', $html);
        $this->assertStringContainsString('href="mailto:a@example.com"', $html);
        $this->assertStringNotContainsString('" onmouseover', $html, '주소 안의 따옴표로 속성을 열 수 없다');
        $this->assertStringContainsString('<code>&lt;b&gt;코드&lt;/b&gt;</code>', $html);
        $this->assertStringContainsString('<h3>', $html);
    }

    /**
     * 그 사람으로 로그인하고 2단계 인증까지 통과한 상태로
     */
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
     * 6자리 코드 재확인을 마친 상태 (실제 코드 흐름은 MeetingAccessTest)
     */
    private function reauthed(): void
    {
        $id = (int) $this->client->session->get(Authenticate::USER_KEY);
        $this->client->session->set(TwoFactor::REAUTH_KEY, ['user' => $id, 'at' => time()]);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function form(array $override = []): array
    {
        return $override + [
            'title' => '주간 회의',
            'held_at' => '2026-11-20T14:00',
            'system_id' => '',
            'visibility' => 'attendees',
            'body' => '본문',
            'decisions' => '',
            'attendees' => [(string) $this->people['author']->id],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function create(array $override = []): int
    {
        $response = $this->client->post('/meetings', $this->form($override));
        $this->assertSame(302, $response->status, '회의록 저장');

        return (int) basename($response->headers['Location']);
    }

    private function visibility(int $id): string
    {
        return (string) $this->client->pdo()->query("SELECT visibility FROM meetings WHERE id = {$id}")->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function actions(int $meetingId): array
    {
        return $this->client->pdo()->query("SELECT action FROM audit_logs WHERE target_type = 'meeting' AND target_id = {$meetingId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
