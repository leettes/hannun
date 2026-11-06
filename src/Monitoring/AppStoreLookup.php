<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Database;
use Closure;
use PDO;

/**
 * iOS 앱의 버전·최근 업데이트일을 App Store 조회 API 로 갱신한다 (cron, 하루 한 번).
 * https://itunes.apple.com/lookup?id=...&country=kr (무료, 키 불필요). 스토어 주소의 id, 없으면 번들 ID 로 찾는다.
 *
 * - 번들 ID·스토어 주소가 비어 있으면 조회 결과로 채운다 (다른 iOS 앱이 쓰는 번들 ID 면 채우지 않는다)
 * - 실패하면 이전 값을 두고 store_error 만 남긴다. 1시간 뒤 다시 시도한다
 * - Android 는 공식 공개 API 가 없어 직접 입력으로 둔다
 */
final class AppStoreLookup
{
    private const INTERVAL = 86400 - 3600;

    private const RETRY_INTERVAL = 3600;

    private const PER_RUN = 10;

    /**
     * @param Closure(): PDO $connection
     * @param Closure(string): ?string $get URL → 응답 본문 (200 이 아니면 null)
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly Closure $get,
    ) {
    }

    /**
     * @return array{updated: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $pdo = ($this->connection)();
        $statement = $pdo->prepare(
            "SELECT id, name, bundle_id, store_url, version FROM apps
             WHERE platform = 'ios' AND is_active = 1 AND (checked_at IS NULL
                OR (store_error IS NULL AND checked_at < ?)
                OR (store_error IS NOT NULL AND checked_at < ?))
             ORDER BY checked_at IS NOT NULL, checked_at, id
             LIMIT " . self::PER_RUN,
        );
        $statement->execute([date('Y-m-d H:i:s', time() - self::INTERVAL), date('Y-m-d H:i:s', time() - self::RETRY_INTERVAL)]);

        $result = ['updated' => [], 'errors' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $app) {
            $info = $this->lookup($app);
            if (is_string($info)) {
                $pdo->prepare('UPDATE apps SET checked_at = ?, store_error = ? WHERE id = ?')->execute([Database::now(), $info, $app['id']]);
                $result['errors'][] = "{$app['name']}(iOS): {$info}";
                continue;
            }

            $bundleId = $app['bundle_id'];
            if ($bundleId === null && $info['bundle_id'] !== null && !$this->bundleIdTaken($pdo, $info['bundle_id'], (int) $app['id'])) {
                $bundleId = $info['bundle_id'];
            }
            $pdo->prepare(
                'UPDATE apps SET version = ?, released_at = ?, bundle_id = ?, store_url = COALESCE(store_url, ?), store_info = ?, checked_at = ?, store_error = NULL WHERE id = ?',
            )->execute([
                $info['version'],
                $info['released_at'],
                $bundleId,
                $info['store_url'],
                json_encode($info['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Database::now(),
                $app['id'],
            ]);
            // 처음이면 '앱(iOS) 1.2.17', 바뀌면 '앱(iOS) 1.2.16 → 1.2.17', 그대로면 '앱(iOS) 1.2.17'
            $version = match (true) {
                $app['version'] === null || $app['version'] === $info['version'] => (string) ($info['version'] ?? '-'),
                default => "{$app['version']} → {$info['version']}",
            };
            $result['updated'][] = "{$app['name']}(iOS) {$version}";
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $app
     * @return array{version: ?string, released_at: ?string, bundle_id: ?string, store_url: ?string, raw: array<string, mixed>}|string 실패하면 사유
     */
    private function lookup(array $app): array|string
    {
        $query = self::query($app['store_url'], $app['bundle_id']);
        if ($query === null) {
            return '스토어 주소(id)나 번들 ID 가 없음';
        }
        $body = ($this->get)("https://itunes.apple.com/lookup?{$query}&country=kr");
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json)) {
            return 'App Store 조회 실패';
        }

        return self::parse($json) ?? 'App Store 에서 찾을 수 없음 (출시 전이거나 한국 스토어에 없음)';
    }

    /**
     * 스토어 주소의 /id123 이 있으면 id, 없으면 번들 ID
     */
    public static function query(?string $storeUrl, ?string $bundleId): ?string
    {
        if (preg_match('#/id(\d+)#', (string) $storeUrl, $m)) {
            return 'id=' . $m[1];
        }

        return $bundleId === null || $bundleId === '' ? null : 'bundleId=' . rawurlencode($bundleId);
    }

    /**
     * @param array<string, mixed> $json 조회 응답
     * @return array{version: ?string, released_at: ?string, bundle_id: ?string, store_url: ?string, raw: array<string, mixed>}|null 결과가 없으면 null
     */
    public static function parse(array $json): ?array
    {
        $r = $json['results'][0] ?? null;
        if (!is_array($r) || ($r['kind'] ?? 'software') !== 'software') {
            return null;
        }
        $released = isset($r['currentVersionReleaseDate']) ? strtotime((string) $r['currentVersionReleaseDate']) : false;
        $url = is_string($r['trackViewUrl'] ?? null) ? preg_replace('/\?.*$/', '', $r['trackViewUrl']) : null;

        return [
            'version' => is_string($r['version'] ?? null) ? mb_substr($r['version'], 0, 30) : null,
            // 표시용 날짜는 서버 시간대(한국) 기준
            'released_at' => $released === false ? null : date('Y-m-d', $released),
            'bundle_id' => is_string($r['bundleId'] ?? null) ? $r['bundleId'] : null,
            'store_url' => $url !== null && str_starts_with($url, 'https://apps.apple.com/') ? $url : null,
            // 화면·문제 확인용으로 일부만 보관
            'raw' => array_intersect_key($r, array_flip(['trackId', 'trackName', 'bundleId', 'version', 'currentVersionReleaseDate', 'sellerName', 'minimumOsVersion', 'averageUserRating', 'userRatingCount', 'trackViewUrl'])),
        ];
    }

    private function bundleIdTaken(PDO $pdo, string $bundleId, int $exceptId): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM apps WHERE platform = 'ios' AND bundle_id = ? AND id <> ?");
        $statement->execute([$bundleId, $exceptId]);

        return $statement->fetchColumn() !== false;
    }
}
