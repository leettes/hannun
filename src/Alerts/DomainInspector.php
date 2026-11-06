<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Database;
use Closure;
use PDO;

/**
 * 웹/API 리소스 주소의 현재 연결 IP 와 도메인 등록 정보(등록업체, 만료일, 네임서버)를 갱신한다 (cron).
 *
 * - IP: 매 실행 DNS 조회. 바뀌면 결과의 changed 에 담는다 (cron.log)
 * - 도메인 정보: RDAP(rdap.org)로 하루 한 번. RDAP 서버가 없는 TLD(.im 등)는 WHOIS 로 넘어간다.
 *   둘 다 실패하면 이전 값을 두고 error 만 남긴다
 */
final class DomainInspector
{
    private const DOMAIN_INFO_INTERVAL = 86400 - 3600;

    private const RETRY_INTERVAL = 3600;

    /** 2단계 국가 도메인 (example.co.kr 의 등록 도메인은 example.co.kr) */
    private const SECOND_LEVEL = ['co', 'or', 'ne', 'go', 'ac', 're', 'pe', 'com', 'net', 'org'];

    /**
     * @param Closure(): PDO $connection
     * @param Closure(string): list<string> $resolve 호스트 → IPv4 목록
     * @param Closure(string): ?array<string, mixed> $rdap 도메인 → RDAP 응답, 실패하면 null
     * @param (Closure(string): ?string)|null $whois 도메인 → WHOIS 응답 원문, 실패하면 null
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly Closure $resolve,
        private readonly Closure $rdap,
        private readonly ?Closure $whois = null,
    ) {
    }

    /**
     * @return array{resources: int, changed: list<string>, domains: int, errors: list<string>}
     */
    public function run(): array
    {
        $pdo = ($this->connection)();
        $rows = $pdo->query(
            "SELECT r.id, r.name, r.identifier, r.resolved_ips, r.domain_info FROM resources r
             JOIN systems s ON s.id = r.system_id AND s.is_active = 1
             WHERE r.type = 'http' AND r.is_active = 1
             ORDER BY r.id",
        )->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['resources' => 0, 'changed' => [], 'domains' => 0, 'errors' => []];
        $lookedUp = [];
        $update = $pdo->prepare('UPDATE resources SET resolved_ips = ?, resolved_at = ?, domain_info = ? WHERE id = ?');

        foreach ($rows as $row) {
            $host = strtolower((string) parse_url((string) $row['identifier'], PHP_URL_HOST));
            if ($host === '') {
                continue;
            }
            $summary['resources']++;

            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolve)($host);
            sort($ips);
            $ipText = $ips === [] ? null : implode(',', $ips);
            if ($ipText === null) {
                $summary['errors'][] = "{$row['name']}: {$host} DNS 조회 실패";
            } elseif ($row['resolved_ips'] !== null && $row['resolved_ips'] !== $ipText) {
                $summary['changed'][] = "{$row['name']}: {$host} IP 변경 {$row['resolved_ips']} → {$ipText}";
            }

            $info = $row['domain_info'] === null ? null : (array) json_decode((string) $row['domain_info'], true);
            $domain = self::registrableDomain($host);
            if ($domain !== null && $this->domainInfoDue($info, $domain)) {
                $lookedUp[$domain] ??= $this->lookup($domain, $info);
                $info = $lookedUp[$domain];
                if ($info['error'] !== null) {
                    $summary['errors'][] = "{$row['name']}: {$domain} {$info['error']}";
                }
            }

            $update->execute([
                $ipText ?? $row['resolved_ips'],  // 일시적인 DNS 실패에는 마지막 IP 를 둔다
                $ipText === null ? null : Database::now(),
                $info === null ? null : json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['id'],
            ]);
        }
        $summary['domains'] = count($lookedUp);

        return $summary;
    }

    /**
     * @param array<string, mixed>|null $info
     */
    private function domainInfoDue(?array $info, string $domain): bool
    {
        if ($info === null || ($info['domain'] ?? null) !== $domain) {
            return true;
        }
        $interval = ($info['error'] ?? null) === null ? self::DOMAIN_INFO_INTERVAL : self::RETRY_INTERVAL;

        return strtotime((string) ($info['checked_at'] ?? '')) < time() - $interval;
    }

    /**
     * @param array<string, mixed>|null $previous
     * @return array{domain: string, registrar: ?string, expires_at: ?string, nameservers: list<string>, checked_at: string, error: ?string}
     */
    private function lookup(string $domain, ?array $previous): array
    {
        $response = ($this->rdap)($domain);
        $parsed = $response === null ? null : self::parseRdap($response);
        if ($parsed === null && $this->whois !== null) {
            $text = ($this->whois)($domain);
            $parsed = $text === null ? null : self::parseWhois($text);
        }
        $same = ($previous['domain'] ?? null) === $domain;

        return [
            'domain' => $domain,
            'registrar' => $parsed['registrar'] ?? ($same ? $previous['registrar'] ?? null : null),
            'expires_at' => $parsed['expires_at'] ?? ($same ? $previous['expires_at'] ?? null : null),
            'nameservers' => $parsed['nameservers'] ?? ($same ? $previous['nameservers'] ?? [] : []),
            'checked_at' => Database::now(),
            'error' => $parsed === null ? '도메인 정보(RDAP·WHOIS) 조회 실패' : null,
        ];
    }

    /**
     * @param array<string, mixed> $rdap
     * @return array{registrar: ?string, expires_at: ?string, nameservers: list<string>}|null
     */
    public static function parseRdap(array $rdap): ?array
    {
        if (($rdap['objectClassName'] ?? null) !== 'domain') {
            return null;
        }

        $registrar = null;
        foreach ($rdap['entities'] ?? [] as $entity) {
            if (!in_array('registrar', $entity['roles'] ?? [], true)) {
                continue;
            }
            foreach ($entity['vcardArray'][1] ?? [] as $field) {
                if (($field[0] ?? null) === 'fn' && is_string($field[3] ?? null)) {
                    $registrar = $field[3];
                }
            }
        }

        $expires = null;
        foreach ($rdap['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? null) === 'expiration' && ($time = strtotime((string) ($event['eventDate'] ?? ''))) !== false) {
                $expires = date('Y-m-d H:i:s', $time);
            }
        }

        $nameservers = array_values(array_filter(array_map(
            static fn (array $ns): string => strtolower(rtrim((string) ($ns['ldhName'] ?? ''), '.')),
            $rdap['nameservers'] ?? [],
        )));
        sort($nameservers);

        return ['registrar' => $registrar, 'expires_at' => $expires, 'nameservers' => $nameservers];
    }

    /**
     * WHOIS 원문에서 등록업체, 만료일, 네임서버. 형식이 레지스트리마다 달라 흔한 키를 찾는다.
     *
     * @return array{registrar: ?string, expires_at: ?string, nameservers: list<string>}|null 만료일·네임서버 둘 다 없으면 null
     */
    public static function parseWhois(string $text): ?array
    {
        $registrar = null;
        $expires = null;
        $nameservers = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Za-z][A-Za-z \/-]*?)\s*:\s*(\S.*?)\s*$/', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            $value = $m[2];
            if (in_array($key, ['registrar', 'sponsoring registrar', 'registrar name', 'authorized agency'], true)) {
                $registrar ??= $value;
            } elseif ($expires === null && preg_match('/expir|paid-till|renewal date/', $key)) {
                $expires = self::parseWhoisDate($value);
            } elseif (in_array($key, ['name server', 'nameserver', 'nserver', 'primary name server', 'host name'], true)) {
                $ns = strtolower(rtrim((string) preg_split('/\s+/', $value)[0], '.'));
                if (str_contains($ns, '.')) {
                    $nameservers[] = $ns;
                }
            }
        }
        $nameservers = array_values(array_unique($nameservers));
        sort($nameservers);

        if ($expires === null && $nameservers === []) {
            return null;
        }

        return ['registrar' => $registrar, 'expires_at' => $expires, 'nameservers' => $nameservers];
    }

    /**
     * 13/07/2027 00:59:58 (일/월/연), 2027. 01. 22., 2027-01-22T06:35:04Z, 22-Jan-2027 등
     */
    private static function parseWhoisDate(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?/', $value, $m)) {
            $time = strtotime(sprintf('%s-%02d-%02d %s', $m[3], $m[2], $m[1], $m[4] ?? '00:00:00'));
        } elseif (preg_match('/^(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.?/', $value, $m)) {
            $time = strtotime(sprintf('%s-%02d-%02d', $m[1], $m[2], $m[3]));
        } else {
            $time = strtotime(preg_replace('/\s*\(.*\)$/', '', $value) ?? $value);
        }

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /**
     * 등록 단위 도메인. api.example.com → example.com, www.example.co.kr → example.co.kr
     */
    public static function registrableDomain(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) {
            return null;
        }
        $labels = explode('.', rtrim($host, '.'));
        $count = count($labels);
        $take = $count >= 3 && strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    /**
     * rdap.org 가 도메인의 등록 기관 RDAP 서버로 보내 준다.
     *
     * @return array<string, mixed>|null
     */
    public static function rdap(string $domain): ?array
    {
        $curl = curl_init('https://rdap.org/domain/' . rawurlencode($domain));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
            CURLOPT_USERAGENT => 'monitoring-dashboard/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $json = is_string($body) && $status === 200 ? json_decode($body, true) : null;

        return is_array($json) ? $json : null;
    }

    /**
     * whois.iana.org 에서 TLD 의 WHOIS 서버를 찾아 조회한다.
     */
    public static function whois(string $domain): ?string
    {
        static $servers = [];
        $tld = substr((string) strrchr('.' . $domain, '.'), 1);
        if (!array_key_exists($tld, $servers)) {
            $iana = self::whoisQuery('whois.iana.org', $tld);
            $servers[$tld] = $iana !== null && preg_match('/^(?:refer|whois):\s*(\S+)/mi', $iana, $m) ? $m[1] : null;
        }

        return $servers[$tld] === null ? null : self::whoisQuery($servers[$tld], $domain);
    }

    private static function whoisQuery(string $server, string $query): ?string
    {
        $socket = @fsockopen($server, 43, $errno, $errstr, 10);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, 10);
        fwrite($socket, $query . "\r\n");
        $body = stream_get_contents($socket);
        fclose($socket);

        return is_string($body) && trim($body) !== '' ? $body : null;
    }
}
