<?php

declare(strict_types=1);

namespace Tests;

use App\Monitoring\AppReviewCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AppReviewCollectorTest extends TestCase
{
    private PDO $pdo;

    private int $appId;

    protected function setUp(): void
    {
        $this->pdo = (new TestClient())->pdo();
        $miri = (int) $this->pdo->query("SELECT id FROM systems WHERE code = 'miri'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO apps (system_id, platform, name, store_url) VALUES (?, 'ios', '미리', 'https://apps.apple.com/kr/app/id123456')")->execute([$miri]);
        $this->appId = (int) $this->pdo->lastInsertId();
    }

    /** 피드 응답 JSON. $ids 는 리뷰 ID 목록 (rating 은 ID 끝자리 기준 1~5) */
    private static function feed(array $ids, array $overrides = []): string
    {
        $entries = array_map(static fn (string $id): array => ($overrides[$id] ?? []) + [
            'author' => ['name' => ['label' => "작성자{$id}"], 'uri' => ['label' => 'https://itunes.apple.com/kr/reviews/id1']],
            'updated' => ['label' => '2026-11-06T17:39:54-07:00'],
            'im:rating' => ['label' => (string) ((int) substr($id, -1) % 5 + 1)],
            'im:version' => ['label' => '1.2.3'],
            'id' => ['label' => $id],
            'title' => ['label' => "제목{$id}"],
            'content' => ['label' => "내용{$id}", 'attributes' => ['type' => 'text']],
            'im:voteSum' => ['label' => '2'],
            'im:voteCount' => ['label' => '3'],
        ], $ids);

        return json_encode(['feed' => ['author' => [], 'entry' => $entries]], JSON_THROW_ON_ERROR);
    }

    /** @param array<int, ?string> $pages 페이지 번호 => 응답 (없으면 빈 피드) */
    private function collector(array $pages, array &$requested = []): AppReviewCollector
    {
        return new AppReviewCollector(fn (): PDO => $this->pdo, static function (string $url) use ($pages, &$requested): ?string {
            $requested[] = $url;
            preg_match('#page=(\d+)/#', $url, $m);

            return array_key_exists((int) $m[1], $pages) ? $pages[(int) $m[1]] : '{"feed":{"author":{}}}';
        });
    }

    private function ids(int $from, int $count): array
    {
        return array_map(strval(...), range($from, $from + $count - 1));
    }

    public function testCollectsPagesAndStoresReviews(): void
    {
        $requested = [];
        $result = $this->collector([1 => self::feed($this->ids(1000, 50)), 2 => self::feed($this->ids(2000, 7))], $requested)->run();

        $this->assertSame([], $result['errors']);
        $this->assertSame(['미리(iOS) 새 리뷰 57'], $result['collected']);
        $this->assertCount(2, $requested, '50개 미만 페이지에서 멈춘다');
        $this->assertSame('https://itunes.apple.com/kr/rss/customerreviews/page=1/id=123456/sortby=mostrecent/json', $requested[0]);

        $row = $this->pdo->query("SELECT * FROM app_store_reviews WHERE external_review_id = '1004'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['5', '제목1004', '내용1004', '작성자1004', '1.2.3', 'KOR', 'itunes_rss'], [(string) $row['rating'], $row['title'], $row['content'], $row['reviewer_name'], $row['app_version'], $row['territory'], $row['source']]);
        $expected = (new \DateTimeImmutable('2026-11-06T17:39:54-07:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        $this->assertSame($expected, $row['review_created_at'], 'ISO 시각을 서버 시간대(운영은 KST)로');
        $this->assertNull($row['developer_reply']);
        $this->assertSame(['vote_sum' => 2, 'vote_count' => 3], json_decode((string) $row['raw_data'], true));

        $log = $this->pdo->query('SELECT * FROM app_store_sync_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['reviews', 'success', 57], [$log['sync_type'], $log['sync_status'], (int) $log['records_count']]);
    }

    public function testRunsDailyAndStopsAtKnownReviews(): void
    {
        $this->collector([1 => self::feed($this->ids(1000, 50)), 2 => self::feed($this->ids(2000, 50)), 3 => self::feed($this->ids(3000, 10))])->run();
        $this->assertSame(110, (int) $this->pdo->query('SELECT COUNT(*) FROM app_store_reviews')->fetchColumn());

        // 하루가 안 지났으면 받지 않는다
        $requested = [];
        $this->collector([], $requested)->run();
        $this->assertSame([], $requested);

        // 하루 뒤: 새 리뷰 2개 + 고쳐진 리뷰 1개가 맨 앞에 → 2페이지가 모두 받은 그대로라 거기서 멈춘다
        $this->pdo->exec("UPDATE app_store_sync_logs SET started_at = datetime(started_at, '-1 day')");
        $page1 = self::feed(['9001', '9002', ...$this->ids(1000, 48)], ['1001' => ['im:rating' => ['label' => '1'], 'content' => ['label' => '별로예요']]]);
        $requested = [];
        $result = $this->collector([1 => $page1, 2 => self::feed($this->ids(1048, 2) + $this->ids(2000, 50)), 3 => self::feed($this->ids(3000, 10))], $requested)->run();
        $this->assertSame(['미리(iOS) 새 리뷰 2, 수정 1'], $result['collected']);
        $this->assertCount(2, $requested);
        $this->assertSame(['1', '별로예요'], array_map(strval(...), $this->pdo->query("SELECT rating, content FROM app_store_reviews WHERE external_review_id = '1001'")->fetch(PDO::FETCH_NUM)));
    }

    public function testEmptyFeedKeepsReviewsAndFailureRetriesLater(): void
    {
        $this->collector([1 => self::feed($this->ids(1000, 3))])->run();

        // 빈 피드(가끔 그렇게 온다): 성공 0건, 기존 리뷰는 그대로
        $this->pdo->exec("UPDATE app_store_sync_logs SET started_at = datetime(started_at, '-1 day')");
        $this->assertSame(['collected' => [], 'errors' => []], $this->collector([])->run());
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM app_store_reviews')->fetchColumn());

        // 받지 못하면 실패로 남기고 1시간 뒤 다시
        $this->pdo->exec("UPDATE app_store_sync_logs SET started_at = datetime(started_at, '-1 day')");
        $result = $this->collector([1 => null])->run();
        $this->assertSame(['미리(iOS): App Store 리뷰 피드를 받지 못함'], $result['errors']);
        $latest = $this->pdo->query('SELECT sync_status, error_code FROM app_store_sync_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_NUM);
        $this->assertSame(['failed', 'feed_unavailable'], $latest);

        $requested = [];
        $this->collector([], $requested)->run();
        $this->assertSame([], $requested, '1시간 안에는 다시 하지 않는다');
        $this->pdo->exec("UPDATE app_store_sync_logs SET started_at = datetime(started_at, '-61 minutes') WHERE sync_status = 'failed'");
        $this->collector([], $requested)->run();
        $this->assertCount(1, $requested);
    }

    public function testParse(): void
    {
        $this->assertNull(AppReviewCollector::parse('not json'));
        $this->assertNull(AppReviewCollector::parse('{"foo":1}'));
        $this->assertSame([], AppReviewCollector::parse('{"feed":{"author":{}}}'));

        // 하나뿐이면 객체로 온다. 평점이 없는 항목(앱 정보)은 건너뛴다
        $single = json_decode(self::feed(['77']), true);
        $single['feed']['entry'] = $single['feed']['entry'][0];
        $this->assertCount(1, AppReviewCollector::parse(json_encode($single)));
        $appInfo = json_decode(self::feed(['77']), true);
        unset($appInfo['feed']['entry'][0]['im:rating']);
        $this->assertSame([], AppReviewCollector::parse(json_encode($appInfo)));

        $this->assertSame('123', AppReviewCollector::trackId('https://apps.apple.com/kr/app/x/id123?l=en', null));
        $this->assertSame('456', AppReviewCollector::trackId(null, '{"trackId":456}'));
        $this->assertNull(AppReviewCollector::trackId(null, null));
    }

    public function testAppWithoutTrackIdFails(): void
    {
        $this->pdo->exec("UPDATE apps SET store_url = NULL WHERE id = {$this->appId}");
        $result = $this->collector([])->run();
        $this->assertStringContainsString('앱 id 가 없음', $result['errors'][0]);
    }
}
