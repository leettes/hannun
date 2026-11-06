<?php

declare(strict_types=1);

namespace Tests;

use App\Monitoring\AppIconFetcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AppIconFetcherTest extends TestCase
{
    private string $dir;

    /** @var array<string, ?string> URL => 응답 */
    private array $responses = [];

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/app-icons-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private static function png(int $seed = 0): string
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, $seed);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function testParsers(): void
    {
        $this->assertSame('https://is1.mzstatic.com/a/512x512bb.jpg', AppIconFetcher::parseItunesIcon(['results' => [['artworkUrl100' => 'https://x/100.jpg', 'artworkUrl512' => 'https://is1.mzstatic.com/a/512x512bb.jpg']]]));
        $this->assertNull(AppIconFetcher::parseItunesIcon(['resultCount' => 0, 'results' => []]));

        $play = '<meta property="og:image" content="https://play-lh.googleusercontent.com/banner=w526-h296"><img src="https://play-lh.googleusercontent.com/ICON=s48-rw" class="T75of" alt="아이콘 이미지" itemprop="image">';
        $this->assertSame('https://play-lh.googleusercontent.com/ICON=s256', AppIconFetcher::parsePlayIcon($play), 'og:image(배너)보다 아이콘 이미지');
        $this->assertNull(AppIconFetcher::parsePlayIcon('<html>찾을 수 없음</html>'));

        $this->assertSame('https://app.example.com/icons/180.png', AppIconFetcher::parseWebIcon('<link rel="icon" href="/favicon.png"><link href="icons/180.png" rel="apple-touch-icon">', 'https://app.example.com/'));
        $this->assertSame('https://app.example.com/favicon.png', AppIconFetcher::parseWebIcon('<link rel="icon" href="/favicon.png">', 'https://app.example.com/a/b'));
        $this->assertSame('https://app.example.com/favicon.ico', AppIconFetcher::parseWebIcon('', 'https://app.example.com/x'));
    }

    public function testFetchesIconsShowsThemAndRetriesLater(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '아이', 'bundle_id' => 'kr.a.app', 'store_url' => 'https://apps.apple.com/kr/app/id123']);
        $client->post('/settings/apps', ['platform' => 'android', 'system_id' => '1', 'name' => '안드', 'bundle_id' => 'kr.a.app']);
        $client->post('/settings/apps', ['platform' => 'web', 'system_id' => '1', 'name' => '웹', 'store_url' => 'https://app.example.com/']);
        $this->assertStringContainsString('app-icon-empty', $client->get('/settings/apps')->body, '가져오기 전에는 첫 글자');

        $this->responses = [
            'https://itunes.apple.com/lookup?id=123&country=kr' => json_encode(['results' => [['artworkUrl512' => 'https://is1.mzstatic.com/i.png']]]),
            'https://is1.mzstatic.com/i.png' => self::png(1),
            'https://play.google.com/store/apps/details?id=kr.a.app&hl=ko' => '<img alt="아이콘 이미지" src="https://play-lh.googleusercontent.com/P=s48">',
            'https://play-lh.googleusercontent.com/P=s256' => self::png(2),
            'https://app.example.com/' => '<html>no icon</html>',
            'https://app.example.com/favicon.ico' => 'not an image',
        ];
        $result = $this->fetcher($client)->run();

        $this->assertSame(['아이(iOS)', '안드(Android)'], $result['fetched']);
        $this->assertSame(['웹(웹앱): 이미지가 아님'], $result['errors']);
        $files = $pdo->query('SELECT icon_file FROM apps ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertMatchesRegularExpression('/^\d+-[0-9a-f]{10}\.png$/', (string) $files[0]);
        $this->assertFileExists($this->dir . '/' . $files[0]);
        $this->assertNull($files[2]);
        $page = $client->get('/settings/apps')->body;
        $this->assertStringContainsString('src="/app-icons/' . $files[0] . '"', $page);
        $this->assertStringContainsString('src="/app-icons/' . $files[0] . '"', $client->get('/systems/' . $pdo->query('SELECT code FROM systems WHERE id = 1')->fetchColumn())->body, '서비스 상세에도');

        // 바로 다시 돌리면 아무것도 하지 않는다 (성공 7일, 실패 6시간 뒤)
        $this->requests = [];
        $this->assertSame(['fetched' => [], 'errors' => []], $this->fetcher($client)->run());
        $this->assertSame([], $this->requests);

        // 스토어 주소가 바뀌면 다시 가져오고, 아이콘이 바뀌면 이전 파일을 지운다
        $id = (int) $pdo->query("SELECT id FROM apps WHERE platform = 'ios'")->fetchColumn();
        $client->post("/settings/apps/{$id}", ['system_id' => '1', 'name' => '아이', 'bundle_id' => 'kr.a.app', 'store_url' => 'https://apps.apple.com/kr/app/id456', 'active' => '1']);
        $this->responses['https://itunes.apple.com/lookup?id=456&country=kr'] = json_encode(['results' => [['artworkUrl512' => 'https://is1.mzstatic.com/j.png']]]);
        $this->responses['https://is1.mzstatic.com/j.png'] = self::png(3);
        $this->assertSame(['아이(iOS)'], $this->fetcher($client)->run()['fetched']);
        $this->assertFileDoesNotExist($this->dir . '/' . $files[0]);

        // 실패하면 이전 아이콘을 둔다
        $pdo->exec("UPDATE apps SET icon_checked_at = '2000-01-01 00:00:00' WHERE platform = 'android'");
        $before = $pdo->query("SELECT icon_file FROM apps WHERE platform = 'android'")->fetchColumn();
        unset($this->responses['https://play-lh.googleusercontent.com/P=s256']);
        $this->assertSame(['안드(Android): 아이콘 내려받기 실패'], $this->fetcher($client)->run()['errors']);
        $this->assertSame($before, $pdo->query("SELECT icon_file FROM apps WHERE platform = 'android'")->fetchColumn());
    }

    private function fetcher(TestClient $client): AppIconFetcher
    {
        return new AppIconFetcher($client->pdo(...), function (string $url): ?string {
            $this->requests[] = $url;

            return $this->responses[$url] ?? null;
        }, $this->dir);
    }
}
