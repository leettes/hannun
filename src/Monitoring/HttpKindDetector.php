<?php

declare(strict_types=1);

namespace App\Monitoring;

use Closure;
use PDO;

/**
 * 웹/API 리소스의 기본 구분: 주소가 JSON 을 돌려주면 API, 그 외(HTML 등)는 웹.
 * 관리자가 고르지 않았을 때만 쓴다. 고른 값(resources.config.kind)은 바꾸지 않는다.
 */
final class HttpKindDetector
{
    private const TIMEOUT_SECONDS = 8;

    /**
     * 응답의 Content-Type 이 JSON 이거나, 본문이 JSON 객체·배열이면 API.
     */
    public static function kindOf(?string $contentType, string $body): string
    {
        if ($contentType !== null && preg_match('#[/+]json\b#i', $contentType)) {
            return 'api';
        }
        $body = ltrim($body);
        if (($body[0] ?? '') === '{' || ($body[0] ?? '') === '[') {
            json_decode($body);
            if (json_last_error() === JSON_ERROR_NONE) {
                return 'api';
            }
        }

        return 'web';
    }

    /**
     * 주소를 한 번 GET 해서 구분을 정한다. 요청이 실패하면 null.
     */
    public static function detect(string $url): ?string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'monitoring-dashboard/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.9, */*;q=0.8'],
        ]);
        $body = curl_exec($curl);
        $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        if (!is_string($body)) {
            return null;
        }

        return self::kindOf(is_string($contentType) ? $contentType : null, substr($body, 0, 65536));
    }

    /**
     * 구분이 없는 웹/API 리소스를 찾아 채운다 (cron). 요청이 실패한 리소스는 다음 실행 때 다시 시도한다.
     *
     * @param Closure(): PDO $connection
     * @param Closure(string): ?string $detect URL => 'web' | 'api' | null
     * @return list<string> 채운 리소스 ("이름: 웹")
     */
    public static function fillMissing(Closure $connection, Closure $detect): array
    {
        $pdo = $connection();
        $rows = $pdo->query(
            "SELECT id, name, identifier FROM resources
             WHERE type = 'http' AND is_active = 1 AND json_extract(COALESCE(config, '{}'), '$.kind') IS NULL",
        )->fetchAll(PDO::FETCH_ASSOC);

        $filled = [];
        $update = $pdo->prepare("UPDATE resources SET config = json_set(COALESCE(config, '{}'), '$.kind', ?) WHERE id = ?");
        foreach ($rows as $row) {
            $kind = $detect((string) $row['identifier']);
            if ($kind === null || !isset(SystemRegistry::HTTP_KINDS[$kind])) {
                continue;
            }
            $update->execute([$kind, (int) $row['id']]);
            $filled[] = $row['name'] . ': ' . SystemRegistry::HTTP_KINDS[$kind];
        }

        return $filled;
    }
}
