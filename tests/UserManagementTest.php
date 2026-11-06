<?php

declare(strict_types=1);

namespace Tests;

use App\App;
use App\Auth\User;
use App\Http\Request;
use App\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class UserManagementTest extends TestCase
{
    private const PASSWORD = 'long enough password';

    public function testRouteRoleIsEnforced(): void
    {
        $router = new Router();
        $router->get('/ops', fn (): array => ['ok' => true], 'operator');
        $app = new App($router);
        $request = static fn (?User $user): Request => (new Request('GET', '/ops'))->withAttribute('user', $user);
        $user = static fn (string $role): User => new User(1, 'a@example.com', 'A', $role, true, 'h');

        $this->assertSame(403, $app->handle($request(null))->status);
        $this->assertSame(403, $app->handle($request($user('viewer')))->status);
        $this->assertSame(200, $app->handle($request($user('operator')))->status);
        $this->assertSame(200, $app->handle($request($user('admin')))->status, '상위 역할도 허용');
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function access(): iterable
    {
        // {me} 는 로그인한 본인 id 로 바뀐다
        foreach (['GET /users', 'GET /users/new', 'GET /users/{me}', 'POST /users', 'POST /users/{me}'] as $route) {
            yield "viewer {$route}" => ['viewer', $route, 403];
            yield "operator {$route}" => ['operator', $route, 403];
        }
        yield 'admin list' => ['admin', 'GET /users', 200];
        yield 'admin new' => ['admin', 'GET /users/new', 200];
        yield 'admin edit' => ['admin', 'GET /users/{me}', 200];
        yield 'admin unknown' => ['admin', 'GET /users/999', 404];
        yield 'admin non-numeric' => ['admin', 'GET /users/abc', 404];
    }

    #[DataProvider('access')]
    public function testOnlyAdminCanManageUsers(string $role, string $route, int $status): void
    {
        $client = new TestClient();
        $me = $client->loginAs($role);
        $userCount = count($client->users->all());
        [$method, $path] = explode(' ', str_replace('{me}', (string) $me->id, $route));

        $response = $method === 'GET'
            ? $client->get($path)
            : $client->post($path, ['email' => 'x@example.com', 'name' => 'x', 'role' => 'admin', 'active' => '1', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD]);

        $this->assertSame($status, $response->status);
        if ($status === 403) {
            $this->assertStringContainsString('접근 권한이 없습니다', $response->body);
            $this->assertSame($role, $client->users->findById($me->id)?->role, 'POST 가 실행되지 않는다');
            $this->assertCount($userCount, $client->users->all());
        }
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->assertSame('/login?next=%2Fusers', (new TestClient())->get('/users')->headers['Location']);
    }

    public function testNavLinkOnlyForAdmin(): void
    {
        $admin = new TestClient();
        $admin->loginAs('admin');
        $operator = new TestClient();
        $operator->loginAs('operator');

        $this->assertStringContainsString('href="/users"', $admin->get('/')->body);
        $this->assertStringNotContainsString('href="/users"', $operator->get('/')->body);
    }

    public function testAdminCreatesUserWhoCanLogIn(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $response = $client->post('/users', [
            'email' => ' New@Example.com ', 'name' => '신규 운영자', 'role' => 'operator',
            'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
        ]);

        $this->assertSame('/users', $response->headers['Location']);
        $list = $client->get('/users')->body;
        $this->assertStringContainsString('계정을 만들었습니다', $list);
        $this->assertStringContainsString('new@example.com', $list);
        $this->assertStringNotContainsString('계정을 만들었습니다', $client->get('/users')->body, '안내는 한 번만');

        $user = $client->users->findByEmail('new@example.com');
        $this->assertSame('operator', $user?->role);
        $this->assertTrue(password_verify(self::PASSWORD, $user->passwordHash));

        $client->post('/logout');
        $this->assertSame(302, $client->post('/login', ['email' => 'new@example.com', 'password' => self::PASSWORD])->status);
        $this->assertSame(200, $client->get('/')->status);
        $this->assertSame(403, $client->get('/users')->status, '운영자는 사용자 관리 불가');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidNewUsers(): iterable
    {
        $ok = ['email' => 'x@example.com', 'name' => 'X', 'role' => 'viewer', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD];
        yield 'bad email' => [['email' => 'not-an-email'] + $ok, '올바른 이메일을 입력하세요'];
        yield 'duplicate email' => [['email' => 'ADMIN@example.com'] + $ok, '이미 등록된 이메일입니다'];
        yield 'empty name' => [['name' => ' '] + $ok, '이름을 50자 이내로'];
        yield 'unknown role' => [['role' => 'root'] + $ok, '역할을 선택하세요'];
        yield 'short password' => [['password' => 'short', 'password_confirm' => 'short'] + $ok, '6자 이상이어야'];
        yield 'mismatch' => [['password_confirm' => 'different password!'] + $ok, '비밀번호가 일치하지 않습니다'];
    }

    /**
     * @param array<string, string> $body
     */
    #[DataProvider('invalidNewUsers')]
    public function testInvalidNewUserIsRejected(array $body, string $message): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $userCount = count($client->users->all());

        $response = $client->post('/users', $body);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($message, $response->body);
        $this->assertCount($userCount, $client->users->all());
    }

    public function testAdminChangesRoleDeactivatesAndResetsPassword(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $id = $client->users->create('op@example.com', '운영자', password_hash('old password 1234', PASSWORD_BCRYPT, ['cost' => 4]), 'operator');

        $client->post("/users/{$id}", ['name' => '조회 전용', 'role' => 'viewer', 'active' => '1']);
        $user = $client->users->findById($id);
        $this->assertSame(['조회 전용', 'viewer', true], [$user?->name, $user?->role, $user?->active]);
        $this->assertTrue(password_verify('old password 1234', $user->passwordHash), '비밀번호를 비우면 유지');

        $client->post("/users/{$id}", ['name' => '조회 전용', 'role' => 'viewer', 'active' => '0', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD]);
        $user = $client->users->findById($id);
        $this->assertFalse($user?->active);
        $this->assertTrue(password_verify(self::PASSWORD, $user->passwordHash));
    }

    public function testDeactivatedUserIsLoggedOutOnNextRequest(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('operator');
        $client->users->update($me->id, $me->name, 'operator', false);

        $this->assertSame(302, $client->get('/')->status);
    }

    public function testRoleChangeAppliesImmediately(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('admin');
        $client->users->update($me->id, $me->name, 'viewer', true);

        $this->assertSame(403, $client->get('/users')->status);
    }

    public function testAdminCannotLockThemselvesOut(): void
    {
        $client = new TestClient();
        $me = $client->loginAs('admin');

        $demote = $client->post("/users/{$me->id}", ['name' => $me->name, 'role' => 'operator', 'active' => '1']);
        $deactivate = $client->post("/users/{$me->id}", ['name' => $me->name, 'role' => 'admin', 'active' => '0']);

        $this->assertSame(422, $demote->status);
        $this->assertStringContainsString('관리자 권한은 해제할 수 없습니다', $demote->body);
        $this->assertSame(422, $deactivate->status);
        $this->assertStringContainsString('비활성화할 수 없습니다', $deactivate->body);
        $this->assertSame('admin', $client->users->findById($me->id)?->role);
        $this->assertTrue($client->users->findById($me->id)?->active);
    }

    public function testCsrfIsRequired(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');

        $response = $client->post('/users', ['_token' => 'forged', 'email' => 'x@example.com', 'name' => 'X', 'role' => 'admin']);

        $this->assertSame(419, $response->status);
        $this->assertNull($client->users->findByEmail('x@example.com'));
    }
}
