<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class ProfileTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';  // TestClient::loginAs 의 비밀번호

    public function testEveryRoleCanOpenProfileFromSidebar(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');

        $page = $client->get('/me');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('비밀번호 변경', $page->body);
        $this->assertStringContainsString('name="sms_scope"', $page->body);
        $this->assertStringNotContainsString('value="all"', $page->body, '조회 역할은 전체 서비스를 고를 수 없다');
        $this->assertStringContainsString('href="/me"', $client->get('/')->body);
    }

    public function testUpdateContact(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('operator');

        $response = $client->post('/me/contact', ['phone' => '010-1234-5678', 'sms_scope' => 'all']);
        $this->assertSame('/me', $response->headers['Location']);
        $saved = $client->users->findById($me->id);
        $this->assertSame('01012345678', $saved?->phone);
        $this->assertSame('all', $saved->smsScope);
        $this->assertStringContainsString('연락처를 저장했습니다', $client->get('/me')->body);

        $this->assertSame(422, $client->post('/me/contact', ['phone' => '12'])->status);
        $this->assertSame(422, $client->post('/me/contact', ['phone' => '', 'sms_scope' => 'bogus'])->status);
        $client->post('/me/contact', ['phone' => '', 'sms_scope' => 'none']);
        $this->assertSame([null, 'none'], [$client->users->findById($me->id)?->phone, $client->users->findById($me->id)?->smsScope]);
    }

    public function testViewerCannotTurnOnAllIncidentSms(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('viewer');

        $this->assertSame(422, $client->post('/me/contact', ['phone' => '010-1111-2222', 'sms_scope' => 'all'])->status);
        $this->assertSame('owned', $client->users->findById($me->id)?->smsScope);
    }

    public function testChangePassword(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('viewer');

        $wrong = $client->post('/me/password', ['current_password' => 'nope', 'password' => 'new password 1', 'password_confirm' => 'new password 1']);
        $this->assertSame(422, $wrong->status);
        $this->assertStringContainsString('현재 비밀번호가 올바르지 않습니다', $wrong->body);

        $mismatch = $client->post('/me/password', ['current_password' => self::PASSWORD, 'password' => 'new password 1', 'password_confirm' => 'other']);
        $this->assertSame(422, $mismatch->status);

        $same = $client->post('/me/password', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD]);
        $this->assertSame(422, $same->status, '같은 비밀번호로는 바꾸지 않는다');

        $ok = $client->post('/me/password', ['current_password' => self::PASSWORD, 'password' => 'new password 1', 'password_confirm' => 'new password 1']);
        $this->assertSame('/me', $ok->headers['Location']);
        $hash = $client->users->findById($me->id)?->passwordHash ?? '';
        $this->assertTrue(password_verify('new password 1', $hash));
        $this->assertSame('viewer', $client->users->findById($me->id)?->role, '역할·이름은 그대로');
    }

    public function testGuestIsSentToLogin(): void
    {
        $client = new TestClient();

        $this->assertSame(302, $client->get('/me')->status);
    }
}
