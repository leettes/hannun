<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Request;
use App\Security\ClientIpResolver;
use App\Security\IpAllowList;
use App\Security\IpMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class IpRestrictionTest extends TestCase
{
    /**
     * @return iterable<array{string, string, bool}>
     */
    public static function matchCases(): iterable
    {
        yield ['10.1.2.3', '10.0.0.0/8', true];
        yield ['11.0.0.1', '10.0.0.0/8', false];
        yield ['192.168.1.130', '192.168.1.128/25', true];
        yield ['192.168.1.127', '192.168.1.128/25', false];
        yield ['203.0.113.10', '203.0.113.10', true];
        yield ['203.0.113.11', '203.0.113.10', false];
        yield ['1.2.3.4', '0.0.0.0/0', true];
        yield ['2001:db8::1', '2001:db8::/32', true];
        yield ['2001:db9::1', '2001:db8::/32', false];
        yield ['::ffff:10.0.0.5', '10.0.0.0/8', true];
        yield ['10.0.0.5', '2001:db8::/32', false];
        yield ['not-an-ip', '10.0.0.0/8', false];
    }

    #[DataProvider('matchCases')]
    public function testMatches(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, IpMatcher::matches($ip, $cidr));
    }

    public function testInvalidConfigThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpAllowList::fromString('10.0.0.0/8, 10.0.0.0/33');
    }

    public function testBlockedIpGets403(): void
    {
        $client = new TestClient(['allowed_ips' => '10.0.0.0/8']);

        $response = $client->get('/login', ['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('1.2.3.4', $response->body);
    }

    public function testAllowedIpPasses(): void
    {
        $client = new TestClient(['allowed_ips' => '10.0.0.0/8']);

        $this->assertSame(200, $client->get('/login', ['REMOTE_ADDR' => '10.20.30.40'])->status);
    }

    public function testHealthIsNotRestricted(): void
    {
        $client = new TestClient(['allowed_ips' => '10.0.0.0/8']);

        $this->assertSame(200, $client->get('/health', ['REMOTE_ADDR' => '1.2.3.4'])->status);
    }

    public function testEmptyAllowListDeniesOutsideLocal(): void
    {
        $client = new TestClient(['env' => 'production', 'allowed_ips' => '']);

        $this->assertSame(403, $client->get('/login', ['REMOTE_ADDR' => '127.0.0.1'])->status);
    }

    public function testEmptyAllowListAllowsAllInLocal(): void
    {
        $client = new TestClient(['env' => 'local', 'allowed_ips' => '']);

        $this->assertSame(200, $client->get('/login', ['REMOTE_ADDR' => '8.8.8.8'])->status);
    }

    public function testForwardedForIsIgnoredWithoutTrustedProxy(): void
    {
        $client = new TestClient(['allowed_ips' => '203.0.113.10']);

        $response = $client->get('/login', ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10']);

        $this->assertSame(403, $response->status);
    }

    public function testForwardedForFromTrustedProxyIsUsed(): void
    {
        $client = new TestClient(['allowed_ips' => '203.0.113.10', 'trusted_proxies' => '172.31.0.0/16']);

        $response = $client->get('/login', ['REMOTE_ADDR' => '172.31.5.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10']);

        $this->assertSame(200, $response->status);
    }

    public function testSpoofedLeftmostForwardedForIsIgnored(): void
    {
        // 공격자가 XFF 앞부분에 허용 IP를 넣어도, ALB가 붙인 실제 접속 IP(오른쪽)를 기준으로 판단
        $resolver = new ClientIpResolver(IpAllowList::fromString('172.31.0.0/16'));
        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '172.31.5.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 1.2.3.4',
        ]);

        $this->assertSame('1.2.3.4', $resolver->resolve($request));
    }
}
