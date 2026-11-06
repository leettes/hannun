<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Database;
use Closure;
use PDO;

/**
 * 앱 아이콘을 스토어에서 내려받아 public/app-icons/ 에 저장한다 (cron).
 * 화면 CSP 가 외부 이미지를 막으므로(img-src 'self') 직접 보관한다.
 *
 * - iOS: App Store 조회 API (스토어 주소의 id, 없으면 번들 ID)의 artworkUrl512
 * - Android: Google Play 상세 페이지의 아이콘 이미지
 * - 웹앱: 앱 주소 페이지의 apple-touch-icon, 없으면 icon
 *
 * 가져온 뒤 7일마다 다시 확인하고, 실패하면 6시간 뒤 다시 시도한다. 한 번에 몇 개씩만 처리한다(1분 cron).
 */
final class AppIconFetcher
{
    public const DIRECTORY = 'public/app-icons';

    private const REFRESH_INTERVAL = 7 * 86400;

    private const RETRY_INTERVAL = 6 * 3600;

    private const PER_RUN = 5;

    private const MAX_BYTES = 1_000_000;

    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', IMAGETYPE_ICO => 'ico'];

    /**
     * @param Closure(): PDO $connection
     * @param Closure(string): ?string $get URL → 응답 본문 (200 이 아니면 null)
     * @param string $directory 아이콘을 저장할 디렉터리 (웹 경로 /app-icons/)
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly Closure $get,
        private readonly string $directory,
    ) {
    }

    /**
     * @return array{fetched: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $pdo = ($this->connection)();
        $statement = $pdo->prepare(
            'SELECT id, platform, name, bundle_id, store_url, icon_file FROM apps
             WHERE is_active = 1 AND (icon_checked_at IS NULL
                OR (icon_error IS NULL AND icon_checked_at < ?)
                OR (icon_error IS NOT NULL AND icon_checked_at < ?))
             ORDER BY icon_checked_at IS NOT NULL, icon_checked_at, id
             LIMIT ' . self::PER_RUN,
        );
        $statement->execute([date('Y-m-d H:i:s', time() - self::REFRESH_INTERVAL), date('Y-m-d H:i:s', time() - self::RETRY_INTERVAL)]);
        $update = $pdo->prepare('UPDATE apps SET icon_file = ?, icon_checked_at = ?, icon_error = ? WHERE id = ?');

        $result = ['fetched' => [], 'errors' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $app) {
            $label = "{$app['name']}(" . (AppRegistry::PLATFORMS[$app['platform']] ?? $app['platform']) . ')';
            [$file, $error] = $this->fetch($app);
            if ($error !== null) {
                $result['errors'][] = "{$label}: {$error}";
                $file = $app['icon_file'];   // 실패하면 이전 아이콘을 둔다
            } else {
                if ($app['icon_file'] !== null && $app['icon_file'] !== $file) {
                    @unlink($this->directory . '/' . basename((string) $app['icon_file']));
                }
                $result['fetched'][] = $label;
            }
            $update->execute([$file, Database::now(), $error, $app['id']]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $app
     * @return array{?string, ?string} [파일 이름, 오류]
     */
    private function fetch(array $app): array
    {
        $iconUrl = match ($app['platform']) {
            'ios' => $this->iosIconUrl($app),
            'android' => $this->androidIconUrl($app),
            default => $this->webIconUrl($app),
        };
        if ($iconUrl === null) {
            return [null, '아이콘 주소를 찾지 못함'];
        }

        $body = ($this->get)($iconUrl);
        if ($body === null || $body === '' || strlen($body) > self::MAX_BYTES) {
            return [null, '아이콘 내려받기 실패'];
        }
        $info = @getimagesizefromstring($body);
        $ext = $info === false ? null : self::TYPES[$info[2]] ?? null;
        if ($ext === null) {
            return [null, '이미지가 아님'];
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return [null, '저장 디렉터리를 만들 수 없음'];
        }
        // 내용이 바뀌면 파일 이름도 바뀌어 브라우저 캐시를 피한다
        $file = $app['id'] . '-' . substr(sha1($body), 0, 10) . '.' . $ext;
        if (@file_put_contents($this->directory . '/' . $file, $body) === false) {
            return [null, '아이콘 저장 실패 (' . $this->directory . ' 쓰기 권한 확인)'];
        }

        return [$file, null];
    }

    /**
     * @param array<string, mixed> $app
     */
    private function iosIconUrl(array $app): ?string
    {
        $query = AppStoreLookup::query($app['store_url'], $app['bundle_id']);
        if ($query === null) {
            return null;
        }
        $body = ($this->get)("https://itunes.apple.com/lookup?{$query}&country=kr");
        $json = $body === null ? null : json_decode($body, true);

        return is_array($json) ? self::parseItunesIcon($json) : null;
    }

    /**
     * @param array<string, mixed> $app
     */
    private function androidIconUrl(array $app): ?string
    {
        $package = $app['bundle_id'] ?? null;
        if ($package === null && preg_match('/[?&]id=([A-Za-z0-9._]+)/', (string) $app['store_url'], $m)) {
            $package = $m[1];
        }
        if ($package === null) {
            return null;
        }
        $html = ($this->get)('https://play.google.com/store/apps/details?id=' . rawurlencode((string) $package) . '&hl=ko');

        return $html === null ? null : self::parsePlayIcon($html);
    }

    /**
     * @param array<string, mixed> $app
     */
    private function webIconUrl(array $app): ?string
    {
        $url = (string) ($app['store_url'] ?? '');
        if ($url === '') {
            return null;
        }
        $html = ($this->get)($url);

        return self::parseWebIcon($html ?? '', $url);
    }

    /**
     * @param array<string, mixed> $json App Store 조회 응답
     */
    public static function parseItunesIcon(array $json): ?string
    {
        $result = $json['results'][0] ?? null;
        $url = $result['artworkUrl512'] ?? $result['artworkUrl100'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Google Play 상세 페이지의 앱 아이콘. 크기 지정(=s..., =w...-h...)은 256 정사각으로 바꾼다.
     */
    public static function parsePlayIcon(string $html): ?string
    {
        $patterns = [
            '/<img[^>]+itemprop="image"[^>]+src="([^"]+)"/i',
            '/<img[^>]+src="([^"]+)"[^>]+itemprop="image"/i',
            '/<img[^>]+src="([^"]+)"[^>]+alt="(?:아이콘 이미지|Icon image)"/i',
            '/<img[^>]+alt="(?:아이콘 이미지|Icon image)"[^>]+src="([^"]+)"/i',
            '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) && str_contains($m[1], 'googleusercontent.com')) {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

                return (string) preg_replace('/=[^\/=]*$/', '', $url) . '=s256';
            }
        }

        return null;
    }

    /**
     * 웹 페이지의 apple-touch-icon, 없으면 icon, 없으면 /favicon.ico. 상대 주소는 페이지 기준으로 푼다.
     */
    public static function parseWebIcon(string $html, string $pageUrl): ?string
    {
        $links = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\brel=["\']([^"\']+)["\']/i', $tag, $rel) && preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) {
                    $links[strtolower(trim($rel[1]))] ??= html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
                }
            }
        }
        $href = $links['apple-touch-icon'] ?? $links['apple-touch-icon-precomposed'] ?? $links['icon'] ?? $links['shortcut icon'] ?? '/favicon.ico';

        return self::resolveUrl($href, $pageUrl);
    }

    private static function resolveUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return $parts['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?: '/';

        return $origin . $dir . $href;
    }

    /**
     * HTTPS·HTTP 만, 리다이렉트 3번까지, 15초.
     */
    public static function httpGet(string $url): ?string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXFILESIZE => 5_000_000,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; monitoring-dashboard/1.0)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ko-KR,ko;q=0.9'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return is_string($body) && $status === 200 ? $body : null;
    }
}
