<?php

declare(strict_types=1);

namespace Tests;

use App\Alerts\DomainInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class DomainInspectorTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = [];

    /** @var list<string> */
    private array $rdapCalls = [];

    private bool $rdapFails = false;

    private ?string $whoisText = null;

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function hosts(): iterable
    {
        yield 'subdomain' => ['wapi.woobansi.com', 'woobansi.com'];
        yield 'apex' => ['kdes.net', 'kdes.net'];
        yield 'co.kr' => ['www.example.co.kr', 'example.co.kr'];
        yield 'plain kr' => ['api.example.kr', 'example.kr'];
        yield 'ip' => ['10.0.0.5', null];
        yield 'localhost' => ['localhost', null];
    }

    #[DataProvider('hosts')]
    public function testRegistrableDomain(string $host, ?string $domain): void
    {
        $this->assertSame($domain, DomainInspector::registrableDomain($host));
    }

    public function testParseRdap(): void
    {
        $this->assertSame([
            'registrar' => 'Gabia, Inc.',
            'expires_at' => date('Y-m-d H:i:s', strtotime('2027-01-22T06:35:04Z')),
            'nameservers' => ['ns.gabia.net', 'ns1.gabia.co.kr'],
        ], DomainInspector::parseRdap(self::rdapResponse('Gabia, Inc.')));
        $this->assertNull(DomainInspector::parseRdap(['errorCode' => 404]));
    }

    public function testParseWhois(): void
    {
        $im = "Domain Name:\thigher.im\nDomain Details\nExpiry Date: 13/07/2027 00:59:58\n"
            . "Name Server:ns-1258.awsdns-29.org.\nName Server:ns-163.awsdns-20.com.\n";
        $this->assertSame([
            'registrar' => null,
            'expires_at' => '2027-07-13 00:59:58',
            'nameservers' => ['ns-1258.awsdns-29.org', 'ns-163.awsdns-20.com'],
        ], DomainInspector::parseWhois($im));

        $kr = "도메인이름 : example.kr\nAuthorized Agency : Gabia, Inc.\nExpiration Date : 2027. 01. 22.\n"
            . "Primary Name Server\n   Host Name : ns.gabia.co.kr\n";
        $this->assertSame([
            'registrar' => 'Gabia, Inc.',
            'expires_at' => '2027-01-22 00:00:00',
            'nameservers' => ['ns.gabia.co.kr'],
        ], DomainInspector::parseWhois($kr));

        $gtld = "Registrar WHOIS Server: whois.example\nRegistrar: Example Registrar\n"
            . "Registry Expiry Date: 2027-01-22T06:35:04Z\nName Server: NS1.EXAMPLE.COM\n";
        $this->assertSame('Example Registrar', DomainInspector::parseWhois($gtld)['registrar']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2027-01-22T06:35:04Z')), DomainInspector::parseWhois($gtld)['expires_at']);

        $this->assertNull(DomainInspector::parseWhois("No match for \"nothing.im\"\n"));
    }

    public function testFallsBackToWhoisWithoutRdap(): void
    {
        $client = new TestClient();
        $id = (int) $client->pdo()->query("SELECT id FROM resources WHERE type = 'http' ORDER BY id LIMIT 1")->fetchColumn();
        $this->rdapFails = true;
        $this->whoisText = "Expiry Date: 13/07/2027 00:59:58\nName Server:ns-163.awsdns-20.com.\n";

        $summary = $this->inspector($client)->run();

        $this->assertSame([], array_values(array_filter($summary['errors'], static fn (string $e): bool => str_contains($e, '도메인 정보'))));
        $info = json_decode((string) $client->pdo()->query("SELECT domain_info FROM resources WHERE id = {$id}")->fetchColumn(), true);
        $this->assertSame('2027-07-13 00:59:58', $info['expires_at']);
        $this->assertSame(['ns-163.awsdns-20.com'], $info['nameservers']);
        $this->assertNull($info['error']);
    }

    public function testUpdatesIpsAndDomainInfoAndShowsThem(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->pdo()->exec("UPDATE resources SET is_active = 0 WHERE type = 'http'");
        $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '미리 API', 'identifier' => 'https://api2.miri.example.com/health']);
        $client->post('/settings/systems/1/resources', ['type' => 'http', 'name' => '미리 웹', 'identifier' => 'https://web2.miri.example.com/']);
        $this->dns = ['api2.miri.example.com' => ['3.37.150.107'], 'web2.miri.example.com' => ['15.165.1.2', '15.165.1.1']];

        $summary = $this->inspector($client)->run();

        $this->assertSame(['resources' => 2, 'changed' => [], 'domains' => 1, 'errors' => []], $summary);
        $this->assertSame(['example.com'], $this->rdapCalls, '같은 도메인은 한 번만 조회');
        $rows = $client->pdo()->query("SELECT name, resolved_ips, domain_info FROM resources WHERE type = 'http' AND is_active = 1 ORDER BY id")->fetchAll();
        $this->assertSame(['3.37.150.107', '15.165.1.1,15.165.1.2'], array_column($rows, 'resolved_ips'));
        $this->assertSame('Gabia, Inc.', json_decode($rows[0]['domain_info'], true)['registrar']);

        $page = $client->get('/settings/systems')->body;
        foreach (['api2.miri.example.com', '3.37.150.107', '15.165.1.1, 15.165.1.2', '가비아 (Gabia, Inc.)', '2027-01-22'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        $this->assertStringContainsString('3.37.150.107', $client->get('/settings/systems/1')->body, '리소스 목록에도 IP');
        $detail = $client->get('/systems/miri')->body;
        foreach (['도메인 · 연결 IP', '3.37.150.107', '가비아 (Gabia, Inc.)', '2027-01-22'] as $text) {
            $this->assertStringContainsString($text, $detail, '서비스 상세에도 표시');
        }

        // 다음 실행: IP 가 바뀌면 알리고, 도메인 정보는 하루 안에는 다시 조회하지 않는다
        $this->dns['api2.miri.example.com'] = ['3.37.150.200'];
        $summary = $this->inspector($client)->run();
        $this->assertSame(['미리 API: api2.miri.example.com IP 변경 3.37.150.107 → 3.37.150.200'], $summary['changed']);
        $this->assertSame(0, $summary['domains']);
        $this->assertCount(1, $this->rdapCalls);
    }

    public function testFailuresKeepPreviousValues(): void
    {
        $client = new TestClient();
        $id = (int) $client->pdo()->query("SELECT id FROM resources WHERE type = 'http' ORDER BY id LIMIT 1")->fetchColumn();
        $host = (string) parse_url((string) $client->pdo()->query("SELECT identifier FROM resources WHERE id = {$id}")->fetchColumn(), PHP_URL_HOST);
        $this->dns = [$host => ['1.2.3.4']];
        $this->inspector($client)->run();

        // DNS·RDAP 실패: 마지막 IP 와 도메인 정보를 두고 오류만 남긴다. 도메인 정보는 이미 하루가 지났다고 가정
        $client->pdo()->exec("UPDATE resources SET domain_info = json_set(domain_info, '$.checked_at', '2000-01-01 00:00:00')");
        $this->dns = [];
        $this->rdapFails = true;
        $summary = $this->inspector($client)->run();

        $this->assertNotSame([], $summary['errors']);
        $row = $client->pdo()->query("SELECT resolved_ips, domain_info FROM resources WHERE id = {$id}")->fetch();
        $info = json_decode($row['domain_info'], true);
        $this->assertSame('1.2.3.4', $row['resolved_ips']);
        $this->assertSame('Gabia, Inc.', $info['registrar']);
        $this->assertSame('도메인 정보(RDAP·WHOIS) 조회 실패', $info['error']);
    }

    private function inspector(TestClient $client): DomainInspector
    {
        return new DomainInspector(
            $client->pdo(...),
            fn (string $host): array => $this->dns[$host] ?? [],
            function (string $domain): ?array {
                $this->rdapCalls[] = $domain;

                return $this->rdapFails ? null : self::rdapResponse('Gabia, Inc.');
            },
            fn (string $domain): ?string => $this->whoisText,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function rdapResponse(string $registrar): array
    {
        return [
            'objectClassName' => 'domain',
            'entities' => [
                ['roles' => ['registrant'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Someone']]]],
                ['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', $registrar]]]],
            ],
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2020-01-22T06:35:04Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-01-22T06:35:04Z'],
            ],
            'nameservers' => [['ldhName' => 'NS1.GABIA.CO.KR'], ['ldhName' => 'ns.gabia.net.']],
        ];
    }
}
