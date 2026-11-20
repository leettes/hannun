<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\User;
use App\ConfigSnapshot;
use App\Security\AuditLog;
use App\Security\Totp;
use App\Security\TwoFactor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

/**
 * 회의록 관문: 회의록 권한 + 2단계 인증 + 유휴 재인증, 그리고 감사 로그 (docs/MEETINGS.md 1단계)
 */
final class MeetingAccessTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';  // TestClient::loginAs 의 비밀번호

    public function testAdminWithoutMeetingAccessIsBlocked(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $this->assertSame(403, $client->get('/meetings')->status, '관리자라도 회의록 권한이 없으면 못 본다');
        $this->assertStringNotContainsString('href="/meetings"', $client->get('/')->body, '메뉴에도 없다');
    }

    public function testEnrollThenAccess(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'viewer', 'read');

        $first = $client->get('/meetings');
        $this->assertSame(302, $first->status);
        $this->assertSame('/me/2fa?next=%2Fmeetings', $first->headers['Location'], '미등록이면 등록 화면으로');

        $setup = $client->get('/me/2fa?next=/meetings');
        $this->assertSame(200, $setup->status);
        $this->assertStringContainsString('<svg', $setup->body, 'QR 코드는 서버에서 만든 SVG');
        $secret = $this->pendingSecret($client);

        $wrong = Totp::code($secret, Totp::step(time()) + 5);
        $this->assertSame(422, $client->post('/me/2fa', ['code' => $wrong, 'next' => '/meetings'])->status);
        $done = $client->post('/me/2fa', ['code' => Totp::code($secret, Totp::step(time())), 'next' => '/meetings']);
        $this->assertSame('/meetings', $done->headers['Location']);

        $stored = (string) $client->pdo()->query("SELECT totp_secret FROM users WHERE id = {$me->id}")->fetchColumn();
        $this->assertStringStartsWith('v1:', $stored, '비밀값은 암호화해 저장');
        $this->assertStringNotContainsString($secret, $stored);

        $page = $client->get('/meetings');
        $this->assertSame(200, $page->status);
        $this->assertSame('no-store', $page->headers['Cache-Control']);
        $this->assertStringContainsString('href="/meetings"', $client->get('/')->body, '권한이 있으면 메뉴에 보인다');
        $this->assertSame(1, $this->audits($client, 'auth.totp_enable', $me->id));
    }

    public function testVerifyAfterIdleAndCodeCannotBeReused(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'operator', 'write');
        $secret = $this->enroll($client);

        // 유휴 시간이 지나면 다시 입력
        $client->session->set(TwoFactor::SESSION_KEY, ['user' => $me->id, 'activity' => time() - 16 * 60]);
        $this->assertSame('/2fa?next=%2Fmeetings', $client->get('/meetings')->headers['Location']);

        // 등록할 때 쓴 코드(같은 30초 단계)는 다시 쓸 수 없다
        $this->assertSame(422, $client->post('/2fa', ['code' => Totp::code($secret, Totp::step(time())), 'next' => '/meetings'])->status);

        $ok = $client->post('/2fa', ['code' => Totp::code($secret, Totp::step(time()) + 1), 'next' => '/meetings']);
        $this->assertSame('/meetings', $ok->headers['Location']);
        $this->assertSame(200, $client->get('/meetings')->status);
        $this->assertSame(1, $this->audits($client, 'auth.totp_ok', $me->id));
        $this->assertSame(1, $this->audits($client, 'auth.totp_fail', $me->id));
    }

    public function testReauthWithRealCode(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'viewer', 'write');
        $secret = $this->enroll($client);

        $form = $client->get('/meetings/reauth?next=/meetings/list&action=' . rawurlencode('내보내기'));
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('내보내기', $form->body);
        $this->assertSame(422, $client->post('/meetings/reauth', ['code' => Totp::code($secret, Totp::step(time())), 'next' => '/meetings/list'])->status, '등록 때 쓴 코드는 재사용 불가');
        $this->assertNull($client->session->get(TwoFactor::REAUTH_KEY));

        $ok = $client->post('/meetings/reauth', ['code' => Totp::code($secret, Totp::step(time()) + 1), 'next' => '//evil.example.com']);
        $this->assertSame('/', $ok->headers['Location'], '돌아갈 주소는 같은 사이트만');
        $this->assertSame($me->id, $client->session->get(TwoFactor::REAUTH_KEY)['user'] ?? null);
    }

    public function testLockAfterFiveFailures(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'viewer', 'read');
        $secret = $this->enroll($client);
        $client->session->remove(TwoFactor::SESSION_KEY);

        $wrong = Totp::code($secret, Totp::step(time()) + 5);
        for ($i = 1; $i < TwoFactor::MAX_FAILURES; $i++) {
            $this->assertSame(422, $client->post('/2fa', ['code' => $wrong])->status);
        }
        $this->assertSame(429, $client->post('/2fa', ['code' => $wrong])->status, '5번째 실패에서 잠김');
        $this->assertSame(429, $client->post('/2fa', ['code' => Totp::code($secret, Totp::step(time()) + 1)])->status, '잠기면 맞는 코드도 거부');
        $this->assertSame(302, $client->get('/meetings')->status);
        $this->assertSame(TwoFactor::MAX_FAILURES, $this->audits($client, 'auth.totp_fail', $me->id));
    }

    public function testVerificationIsBoundToUser(): void
    {
        $client = new TestClient();
        $this->grant($client, 'viewer', 'read');
        $this->enroll($client);

        // 같은 브라우저에서 다른 사용자로 로그인하면 통과 기록이 이어지지 않는다
        $other = $this->grant($client, 'operator', 'read');
        $client->pdo()->exec("UPDATE users SET totp_secret = 'v1:x', totp_enabled_at = '2026-01-01 00:00:00' WHERE id = {$other->id}");
        $this->assertSame('/2fa?next=%2Fmeetings', $client->get('/meetings')->headers['Location']);
    }

    public function testUserCannotDisableWhileHavingMeetingAccess(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'viewer', 'read');
        $this->enroll($client);

        $this->assertSame(422, $client->post('/me/2fa/disable', ['password' => self::PASSWORD])->status);
        $this->assertTrue($client->users->findById($me->id)?->hasTotp());

        $client->users->updateMeetingAccess($me->id, 'none');
        $this->assertSame(422, $client->post('/me/2fa/disable', ['password' => 'wrong'])->status);
        $this->assertSame('/me', $client->post('/me/2fa/disable', ['password' => self::PASSWORD])->headers['Location']);
        $this->assertFalse($client->users->findById($me->id)?->hasTotp());
        $this->assertSame(1, $this->audits($client, 'auth.totp_disable', $me->id));
    }

    public function testAdminGrantsAccessAndResetsTwoFactorWithAuditTrail(): void
    {
        $client = new TestClient();
        $admin = $client->loginAs('admin');
        $target = $client->addUser('staff@example.com', self::PASSWORD, 'viewer');
        $form = ['name' => $target->name, 'role' => 'viewer', 'active' => '1', 'phone' => '', 'sms_scope' => 'owned'];

        $this->assertStringContainsString('name="meeting_access"', $client->get('/users/' . $target->id)->body);
        $this->assertSame(422, $client->post('/users/' . $target->id, $form + ['meeting_access' => 'all'])->status);
        $this->assertSame('/users', $client->post('/users/' . $target->id, $form + ['meeting_access' => 'read'])->headers['Location']);
        $this->assertSame('read', $client->users->findById($target->id)?->meetingAccess);
        $client->post('/users/' . $target->id, $form + ['meeting_access' => 'read']);
        $this->assertSame(1, $this->audits($client, 'user.meeting_access', $admin->id), '바뀔 때만 기록');

        $client->pdo()->exec("UPDATE users SET totp_secret = 'v1:x', totp_enabled_at = '2026-01-01 00:00:00', totp_last_step = 1 WHERE id = {$target->id}");
        $this->assertStringContainsString('2fa-reset', $client->get('/users/' . $target->id)->body);
        $this->assertSame('/users', $client->post('/users/' . $target->id . '/2fa-reset')->headers['Location']);
        $this->assertFalse($client->users->findById($target->id)?->hasTotp());
        $this->assertSame(['secret' => null, 'last_step' => null], $client->users->totpState($target->id));

        $logs = $client->get('/settings/audit-logs');
        $this->assertSame(200, $logs->status);
        $this->assertStringContainsString('회의록 권한 변경', $logs->body);
        $this->assertStringContainsString('2단계 인증 초기화', $logs->body);
        $this->assertStringContainsString('"to":"read"', html_entity_decode($logs->body));
        $this->assertSame(200, $client->get('/settings/audit-logs?action=user.totp_reset&from=2020-01-01&user=' . $admin->id)->status);
    }

    public function testNonAdminCannotSeeAuditLogsOrChangeAccess(): void
    {
        $client = new TestClient();
        $client->loginAs('operator');
        $this->assertSame(403, $client->get('/settings/audit-logs')->status);
        $this->assertSame(403, $client->post('/users/1/2fa-reset')->status);
    }

    public function testWithoutAppKeyMeetingsAreUnavailable(): void
    {
        $client = new TestClient(['app_key' => '']);
        $this->grant($client, 'viewer', 'read');

        $this->assertSame(503, $client->get('/meetings')->status);
        $this->assertSame(503, $client->get('/me/2fa')->status, '키 없이 비밀값을 평문 저장하지 않는다');
    }

    public function testSnapshotLeavesOutMeetingAndTotpData(): void
    {
        $client = new TestClient();
        $me = $this->grant($client, 'viewer', 'write');
        $this->enroll($client);
        $sql = ConfigSnapshot::export($client->pdo());

        $this->assertArrayNotHasKey('audit_logs', ConfigSnapshot::TABLES);
        foreach (['meeting_access', 'totp_secret', 'totp_enabled_at', 'totp_last_step', 'INTO audit_logs', 'v1:'] as $needle) {
            $this->assertStringNotContainsString($needle, $sql);
        }

        // 불러와도 권한·등록 상태는 그대로
        ConfigSnapshot::import($client->pdo(), $sql);
        $after = $client->users->findById($me->id);
        $this->assertSame('write', $after?->meetingAccess);
        $this->assertTrue($after->hasTotp());
    }

    public function testPurgeKeepsAYearAndRecordsItself(): void
    {
        $client = new TestClient();
        $pdo = $client->pdo();
        $pdo->exec("INSERT INTO audit_logs (action, created_at) VALUES ('meeting.view', '2024-01-01 00:00:00'), ('meeting.view', '2026-11-01 00:00:00')");

        $audit = new AuditLog($client->pdo(...));
        $this->assertSame(1, $audit->purge(strtotime('2026-11-20 00:00:00')));
        $this->assertSame(['audit.purge', 'meeting.view'], $pdo->query('SELECT action FROM audit_logs ORDER BY action')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(0, $audit->purge(strtotime('2026-11-20 00:00:00')));
    }

    private function grant(TestClient $client, string $role, string $access): User
    {
        $user = $client->loginAs($role);
        $client->users->updateMeetingAccess($user->id, $access);

        return $client->users->findById($user->id) ?? throw new \LogicException();
    }

    /**
     * 등록하고 통과까지 한다
     */
    private function enroll(TestClient $client): string
    {
        $client->get('/me/2fa');
        $secret = $this->pendingSecret($client);
        $response = $client->post('/me/2fa', ['code' => Totp::code($secret, Totp::step(time()))]);
        $this->assertSame(302, $response->status);
        $this->assertSame(200, $client->get('/meetings')->status);

        return $secret;
    }

    private function pendingSecret(TestClient $client): string
    {
        $pending = $client->session->get(TwoFactor::PENDING_KEY);
        $this->assertIsArray($pending);

        return (string) $pending['secret'];
    }

    private function audits(TestClient $client, string $action, int $userId): int
    {
        $statement = $client->pdo()->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND user_id = ?');
        $statement->execute([$action, $userId]);

        return (int) $statement->fetchColumn();
    }
}
