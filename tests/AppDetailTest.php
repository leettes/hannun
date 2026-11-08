<?php

declare(strict_types=1);

namespace Tests;

use App\Controller\AppDetailController;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AppDetailTest extends TestCase
{
    /** 미리 서비스에 iOS 'Miri'·Android 'miri ' (대소문자·공백만 다름) 와 다른 이름의 앱 하나를 넣는다 */
    private function seedApps(PDO $pdo): array
    {
        $miri = (int) $pdo->query("SELECT id FROM systems WHERE code = 'miri'")->fetchColumn();
        $info = json_encode(['averageUserRating' => 4.5, 'userRatingCount' => 120], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT INTO apps (system_id, platform, name, bundle_id, store_url, version, released_at, store_info, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$miri, 'ios', 'Miri', 'kr.miri.app', 'https://apps.apple.com/kr/app/id123', '1.3.0', '2026-10-15', $info, '2026-11-08 03:00:00']);
        $ios = (int) $pdo->lastInsertId();
        $insert->execute([$miri, 'android', 'miri ', 'kr.miri.app', 'https://play.google.com/store/apps/details?id=kr.miri.app', '1.2.9', '2026-10-10', null, null]);
        $android = (int) $pdo->lastInsertId();
        $insert->execute([$miri, 'ios', '다른 앱', 'kr.other.app', null, null, null, null, null]);

        return [$ios, $android];
    }

    public function testShowsPlatformsTogetherWithEmptyCollectAreas(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');
        [$ios, $android] = $this->seedApps($client->pdo());

        $page = $client->get("/apps/{$ios}");
        $this->assertSame(200, $page->status);
        foreach (['Apple App Store', 'Google Play', 'kr.miri.app', '1.3.0', '1.2.9', '4.5', '평점 120개', '아직 수집된 다운로드 데이터가 없습니다', '아직 수집된 리뷰가 없습니다', '수집 전'] as $text) {
            $this->assertStringContainsString($text, $page->body);
        }
        $this->assertStringNotContainsString('다른 앱', $page->body, '이름이 다른 앱은 따로');
        $this->assertStringNotContainsString('/settings/apps/', $page->body, '조회 권한은 수정 링크 없음');
        $this->assertStringContainsString('Google Play', $client->get("/apps/{$android}")->body, 'Android 에서 열어도 같은 묶음');

        $this->assertSame(404, $client->get('/apps/9999')->status);
        $this->assertSame(404, $client->get('/apps/abc')->status);
    }

    public function testShowsCollectedDownloadsAndReviews(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        [$ios, $android] = $this->seedApps($pdo);

        $stat = $pdo->prepare("INSERT INTO app_store_daily_stats (app_id, stat_date, downloads, source, collected_at) VALUES (?, ?, ?, ?, '2026-11-08 00:00:00')");
        foreach (range(0, 59) as $i) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $stat->execute([$ios, $date, $i < 30 ? 30 : 20, 'asc_sales_report']);
            $stat->execute([$android, $date, 10, 'play_stats_installs']);
            // 같은 날을 다른 출처가 또 주면 더하지 않는다
            $stat->execute([$android, $date, 8, 'manual']);
        }
        $review = $pdo->prepare("INSERT INTO app_store_reviews (app_id, external_review_id, rating, title, content, reviewer_name, review_created_at, developer_reply, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $review->execute([$ios, 'r1', 5, '좋아요', '잘 쓰고 있어요', '사용자1', '2026-11-07 10:00:00', null, 'app_store_connect']);
        $review->execute([$android, 'r2', 2, null, '로그인이 안 돼요', '사용자2', '2026-11-08 09:00:00', '확인 중입니다', 'google_play']);
        // 공개 피드 리뷰는 답변 정보가 없으므로 미답변으로 세지 않는다
        $review->execute([$ios, 'r3', 4, null, '피드 리뷰', '사용자3', '2026-11-01 09:00:00', null, 'itunes_rss']);

        $body = $client->get("/apps/{$ios}")->body;
        foreach (['2,100', '1,200', '▲ 33.3%', '잘 쓰고 있어요', '로그인이 안 돼요', '답변 대기 1', '미답변', '답변함', 'class="dl-bar dl-ios"'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        $this->assertLessThan(strpos($body, '잘 쓰고 있어요'), strpos($body, '로그인이 안 돼요'), '최근 리뷰 먼저');
        $this->assertStringContainsString('/settings/apps/' . $ios, $body, '관리자는 수정 링크');
        $this->assertStringContainsString('최근 7일', $client->get("/apps/{$ios}?days=7")->body);
    }

    public function testAdminPageAndDeveloperConsoleLinks(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        [$ios] = $this->seedApps($pdo);
        $pdo->exec("INSERT INTO app_store_accounts (platform, name, login_email) VALUES ('apple', '미리 Apple', 'dev@miri.example.com')");
        $account = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE apps SET account_id = {$account} WHERE id = {$ios}");

        // 관리자 페이지 주소가 없으면 관리자에게 등록 안내
        $body = $client->get("/apps/{$ios}")->body;
        $this->assertStringContainsString('서비스 관리에서 등록', $body);
        foreach (['href="https://appstoreconnect.apple.com/apps/123/distribution"', 'href="https://play.google.com/console"', 'App Store Connect ↗', 'Google Play Console ↗',
            'href="/settings/app-accounts/' . $account . '"', 'dev@miri.example.com', 'iOS 개발자 계정'] as $text) {
            $this->assertStringContainsString($text, $body);
        }

        $pdo->exec("UPDATE systems SET admin_url = 'https://admin.miri.example.com' WHERE code = 'miri'");
        $body = $client->get("/apps/{$ios}")->body;
        $this->assertSame(3, substr_count($body, 'href="https://admin.miri.example.com" target="_blank"'), '상단·기본 정보·빠른 작업');

        // 조회 권한: 바깥 링크는 보이고, 내부 설정·로그인 이메일은 안 보인다
        $client->loginAs('viewer');
        $body = $client->get("/apps/{$ios}")->body;
        $this->assertStringContainsString('href="https://admin.miri.example.com"', $body);
        $this->assertStringContainsString('App Store Connect ↗', $body);
        $this->assertStringContainsString('미리 Apple', $body);
        $this->assertStringNotContainsString('dev@miri.example.com', $body);
        $this->assertStringNotContainsString('/settings/', $body);
    }

    public function testDashboardAppIconsLinkToAppDetailByPlatform(): void
    {
        $client = new TestClient();
        $client->loginAs('viewer');
        [$ios, $android] = $this->seedApps($client->pdo());
        $client->pdo()->exec("UPDATE apps SET icon_file = '{$ios}-abc.png' WHERE id = {$ios}");

        $body = $client->get('/')->body;
        $this->assertStringContainsString('<a class="svc-logo" href="/apps/' . $ios . '" title="Miri 앱 상세"', $body, '아이콘 로고도 앱 상세로');
        $miriCard = substr($body, strpos($body, 'href="/systems/miri"'));
        $miriCard = substr($miriCard, 0, strpos($miriCard, '</article>'));
        foreach (['<a class="svc-app" href="/apps/' . $ios . '"', '<a class="svc-app" href="/apps/' . $android . '"', 'title="Miri (iOS) 앱 상세"', 'title="miri  (Android) 앱 상세"'] as $text) {
            $this->assertStringContainsString($text, $miriCard);
        }
        $this->assertLessThan(strpos($miriCard, '>Android<'), strpos($miriCard, '>iOS<'), 'iOS · Android 영역');
        $this->assertStringContainsString('2개', $miriCard, 'iOS 2개 (Miri, 다른 앱)');
    }

    public function testStoreRatingIsWeightedByCount(): void
    {
        $rating = AppDetailController::storeRating([
            ['platform' => 'ios', 'store_info' => '{"averageUserRating":4.0,"userRatingCount":100}'],
            ['platform' => 'android', 'store_info' => '{"averageUserRating":5.0,"userRatingCount":300}'],
            ['platform' => 'web', 'store_info' => null],
        ]);
        $this->assertSame(4.8, $rating['average']);
        $this->assertSame(400, $rating['count']);
        $this->assertSame(['ios', 'android'], array_keys($rating['platforms']));
        $this->assertNull(AppDetailController::storeRating([])['average']);
    }
}
