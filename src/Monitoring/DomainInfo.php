<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Alerts\DomainInspector;
use PDO;

/**
 * 자동 수집한 도메인 정보(resources.domain_info)에 관리자가 입력한 값(domain_overrides)을 덮어 화면용으로 만든다.
 */
final class DomainInfo
{
    /** 수동 입력할 수 있는 항목 */
    public const FIELDS = ['registrar', 'expires_at', 'nameservers'];

    /**
     * 웹/API 주소의 등록 단위 도메인. IP 주소면 null
     *
     * @param array<string, mixed>|null $info
     */
    public static function domainOf(string $host, ?array $info): ?string
    {
        $domain = $info['domain'] ?? null;

        return is_string($domain) && $domain !== '' ? $domain : DomainInspector::registrableDomain(strtolower($host));
    }

    /**
     * @return array<string, array{registrar: ?string, expires_at: ?string, nameservers: ?list<string>, memo: ?string, updated_at: string, updated_by: ?string}>
     */
    public static function overrides(PDO $pdo): array
    {
        $rows = [];
        foreach ($pdo->query(
            'SELECT o.domain, o.registrar, o.expires_at, o.nameservers, o.memo, o.updated_at, u.name AS updated_by
             FROM domain_overrides o LEFT JOIN users u ON u.id = o.updated_by_user_id',
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['nameservers'] = $row['nameservers'] === null ? null : array_values((array) json_decode((string) $row['nameservers'], true));
            $rows[(string) $row['domain']] = $row;
        }

        return $rows;
    }

    /**
     * 수동 값이 있는 항목만 바꾼다. 'manual' 에 바뀐 항목, 'memo' 에 메모를 담는다.
     * 만료일을 직접 넣었으면 자동 조회 오류는 보이지 않는다.
     *
     * @param array<string, mixed>|null $auto
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>|null
     */
    public static function merge(?array $auto, ?array $override, ?string $domain): ?array
    {
        if ($override === null) {
            return $auto;
        }
        $info = ($auto ?? []) + ['domain' => $domain, 'registrar' => null, 'expires_at' => null, 'nameservers' => [], 'error' => null];
        $info['manual'] = [];
        foreach (self::FIELDS as $field) {
            if ($override[$field] !== null) {
                $info[$field] = $override[$field];
                $info['manual'][] = $field;
            }
        }
        if (in_array('expires_at', $info['manual'], true)) {
            $info['error'] = null;
        }
        $info['memo'] = $override['memo'];

        return $info;
    }
}
