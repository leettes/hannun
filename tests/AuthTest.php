<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\LoginThrottle;
use App\Http\Middleware\Authenticate;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AuthTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    public function testGuestIsRedirectedToLoginWithNext(): void
    {
        $response = (new TestClient())->get('/systems/miri');

        $this->assertSame(302, $response->status);
        $this->assertSame('/login?next=%2Fsystems%2Fmiri', $response->headers['Location']);
    }

    public function testHealthIsPublic(): void
    {
        $this->assertSame(200, (new TestClient())->get('/health')->status);
    }

    public function testLoginWithValidCredentialsRedirectsToNext(): void
    {
        $client = new TestClient();
        $user = $client->addUser('admin@example.com', self::PASSWORD);

        $response = $client->post('/login', ['email' => ' Admin@Example.com ', 'password' => self::PASSWORD, 'next' => '/incidents']);

        $this->assertSame(302, $response->status);
        $this->assertSame('/incidents', $response->headers['Location']);
        $this->assertSame($user->id, $client->session->get(Authenticate::USER_KEY));
        $this->assertNotNull($client->users->findById($user->id)?->lastLoginAt, '로그인 시각 기록');
        $this->assertSame(200, $client->get('/incidents')->status);
    }

    public function testWrongPasswordShowsErrorAndRecordsFailure(): void
    {
        $client = new TestClient();
        $client->addUser('admin@example.com', self::PASSWORD);

        $response = $client->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('이메일 또는 비밀번호가 올바르지 않습니다', $response->body);
        $this->assertNull($client->session->get(Authenticate::USER_KEY));
        $this->assertFalse($client->throttle->attempts[0]['success']);
    }

    public function testUnknownEmailGetsSameError(): void
    {
        $response = (new TestClient())->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('이메일 또는 비밀번호가 올바르지 않습니다', $response->body);
    }

    public function testInactiveUserCannotLogin(): void
    {
        $client = new TestClient();
        $client->addUser('old@example.com', self::PASSWORD, active: false);

        $this->assertSame(422, $client->post('/login', ['email' => 'old@example.com', 'password' => self::PASSWORD])->status);
    }

    public function testLockedOutAfterTooManyFailures(): void
    {
        $client = new TestClient();
        $client->addUser('admin@example.com', self::PASSWORD);

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_EMAIL; $i++) {
            $client->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        }
        $response = $client->post('/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(429, $response->status);
        $this->assertNull($client->session->get(Authenticate::USER_KEY));
    }

    public function testPostWithoutValidCsrfTokenIsRejected(): void
    {
        $client = new TestClient();
        $client->addUser('admin@example.com', self::PASSWORD);

        $response = $client->post('/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD, '_token' => 'forged']);

        $this->assertSame(419, $response->status);
        $this->assertNull($client->session->get(Authenticate::USER_KEY));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unsafeNextProvider(): iterable
    {
        yield ['https://evil.example.com'];
        yield ['//evil.example.com'];
        yield ['/\\evil.example.com'];
        yield ['/login'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNextProvider')]
    public function testExternalNextIsIgnored(string $next): void
    {
        $client = new TestClient();
        $client->addUser('admin@example.com', self::PASSWORD);

        $response = $client->post('/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD, 'next' => $next]);

        $this->assertSame('/', $response->headers['Location']);
    }

    public function testLogoutClearsSession(): void
    {
        $client = new TestClient();
        $client->loginAs();

        $response = $client->post('/logout');

        $this->assertSame('/login', $response->headers['Location']);
        $this->assertSame(302, $client->get('/')->status);
    }

    public function testIdleSessionExpires(): void
    {
        $client = new TestClient(['idle_minutes' => 30]);
        $client->loginAs();
        $client->session->set(Authenticate::ACTIVITY_KEY, time() - 31 * 60);

        $response = $client->get('/');

        $this->assertSame('/login?next=%2F&expired=1', $response->headers['Location']);
        $this->assertStringContainsString('로그아웃되었습니다', $client->get('/login?expired=1')->body);
    }

    public function testLoggedInUserVisitingLoginIsRedirected(): void
    {
        $client = new TestClient();
        $client->loginAs();

        $this->assertSame(302, $client->get('/login')->status);
    }

    public function testViewerSeesReadOnlyNotice(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');

        $this->assertStringContainsString('조회 권한 계정은', $client->get('/incidents/6')->body);
    }
}
