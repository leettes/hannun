<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * iOS 앱 리뷰를 App Store 공개 리뷰 피드(RSS, JSON)에서 모은다 (cron). 키가 필요 없다. docs/APPS.md 5단계
 * https://itunes.apple.com/kr/rss/customerreviews/page={1~10}/id={trackId}/sortby=mostrecent/json
 *
 * - 한국 스토어, 최신순 페이지당 50개, 최대 10페이지(500개). 이미 받은 그대로의 리뷰만 있는 페이지가 나오면 거기서 멈춘다
 * - 리뷰 ID 로 중복 없이 넣고, 고쳐진 리뷰(별점·내용·버전)는 덮어쓴다. 지우지 않는다:
 *   피드가 가끔 빈 응답을 주므로 비어 있다고 리뷰가 없어졌다고 보지 않는다
 * - 피드에는 개발자 답변이 없다. 그래서 source = itunes_rss 리뷰는 화면에서 답변 여부를 표시하지 않는다
 * - 앱마다 성공하면 하루 뒤, 실패하면 1시간 뒤 다시 받는다. 실행 결과는 app_store_sync_logs (sync_type = reviews)
 */
final class AppReviewCollector
{
    public const SYNC_TYPE = 'reviews';

    public const SOURCE = 'itunes_rss';

    private const INTERVAL = 86400 - 3600;

    private const RETRY_INTERVAL = 3600;

    /** cron 1분마다 실행되므로 한 번에 몇 개만 (앱 하나에 최대 10번 요청) */
    private const PER_RUN = 3;

    private const MAX_PAGES = 10;

    private const KEEP_LOG_DAYS = 90;

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
     * 받을 차례가 된 앱들을 받는다.
     *
     * @param ?int $appId 이 앱만, 일정과 상관없이 바로 (bin/collect-reviews.php)
     * @return array{collected: list<string>, errors: list<string>}
     */
    public function run(?int $appId = null): array
    {
        $pdo = ($this->connection)();
        $result = ['collected' => [], 'errors' => []];

        foreach ($appId === null ? $this->due($pdo) : $this->find($pdo, $appId) as $app) {
            $outcome = $this->collect($pdo, $app);
            $label = "{$app['name']}(iOS)";
            if ($outcome['error'] !== null) {
                $result['errors'][] = "{$label}: {$outcome['error']}";
            } elseif ($outcome['new'] + $outcome['updated'] > 0) {
                $result['collected'][] = "{$label} 새 리뷰 {$outcome['new']}" . ($outcome['updated'] > 0 ? ", 수정 {$outcome['updated']}" : '');
            }
        }

        $pdo->prepare('DELETE FROM app_store_sync_logs WHERE started_at < ?')
            ->execute([date('Y-m-d H:i:s', time() - self::KEEP_LOG_DAYS * 86400)]);

        return $result;
    }

    /**
     * 앱 하나를 받는다. 실행 기록을 남긴다.
     *
     * @param array{id: int|string, name: string, store_url: ?string, store_info: ?string} $app
     * @return array{new: int, updated: int, pages: int, error: ?string}
     */
    public function collect(PDO $pdo, array $app): array
    {
        $startedAt = Database::now();
        $pdo->prepare("INSERT INTO app_store_sync_logs (app_id, sync_type, sync_status, started_at) VALUES (?, ?, 'running', ?)")
            ->execute([$app['id'], self::SYNC_TYPE, $startedAt]);
        $logId = (int) $pdo->lastInsertId();

        $outcome = ['new' => 0, 'updated' => 0, 'pages' => 0, 'error' => null];
        $code = null;
        try {
            $trackId = self::trackId($app['store_url'] ?? null, $app['store_info'] ?? null);
            if ($trackId === null) {
                [$code, $outcome['error']] = ['no_track_id', '스토어 주소에 앱 id 가 없음 (App Store 조회가 끝나면 채워짐)'];
            } else {
                for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                    $body = ($this->get)(self::feedUrl($trackId, $page));
                    $reviews = $body === null ? null : self::parse($body);
                    if ($reviews === null) {
                        // 첫 페이지부터 못 받으면 실패, 중간이면 받은 데까지 (partial)
                        [$code, $outcome['error']] = ['feed_unavailable', $page === 1 ? 'App Store 리뷰 피드를 받지 못함' : "{$page}페이지부터 받지 못함"];
                        break;
                    }
                    $outcome['pages'] = $page;
                    if ($reviews === []) {
                        break;
                    }
                    [$new, $updated] = $this->save($pdo, (int) $app['id'], $reviews);
                    $outcome['new'] += $new;
                    $outcome['updated'] += $updated;
                    // 이 페이지가 모두 이미 받은 그대로면 그 뒤도 받은 것이다
                    if ($new + $updated === 0 || count($reviews) < 50) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            [$code, $outcome['error']] = ['exception', mb_substr($e->getMessage(), 0, 300)];
        }

        $status = match (true) {
            $outcome['error'] === null => 'success',
            $outcome['pages'] > 0 => 'partial',
            default => 'failed',
        };
        $pdo->prepare('UPDATE app_store_sync_logs SET sync_status = ?, records_count = ?, finished_at = ?, error_code = ?, error_message = ? WHERE id = ?')
            ->execute([$status, $outcome['new'] + $outcome['updated'], Database::now(), $code, $outcome['error'], $logId]);

        return $outcome;
    }

    /**
     * 스토어 주소의 /id123, 없으면 App Store 조회 결과(store_info)의 trackId
     */
    public static function trackId(?string $storeUrl, ?string $storeInfo): ?string
    {
        if (preg_match('#/id(\d+)#', (string) $storeUrl, $m)) {
            return $m[1];
        }
        $trackId = json_decode((string) $storeInfo, true)['trackId'] ?? null;

        return is_int($trackId) || (is_string($trackId) && ctype_digit($trackId)) ? (string) $trackId : null;
    }

    public static function feedUrl(string $trackId, int $page): string
    {
        return "https://itunes.apple.com/kr/rss/customerreviews/page={$page}/id={$trackId}/sortby=mostrecent/json";
    }

    /**
     * 피드 JSON → 리뷰 목록. 형식이 아니면 null, 리뷰가 없으면 [].
     *
     * @return list<array{id: string, rating: int, title: ?string, content: ?string, author: ?string, version: ?string, updated_at: ?string, raw: array<string, mixed>}>|null
     */
    public static function parse(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !is_array($json['feed'] ?? null)) {
            return null;
        }
        $entries = $json['feed']['entry'] ?? [];
        // 하나뿐이면 배열이 아니라 객체로 온다
        if (is_array($entries) && isset($entries['id'])) {
            $entries = [$entries];
        }

        $label = static fn (array $e, string $key): ?string => isset($e[$key]['label']) && is_scalar($e[$key]['label']) && trim((string) $e[$key]['label']) !== ''
            ? trim((string) $e[$key]['label']) : null;
        $reviews = [];
        foreach (is_array($entries) ? $entries : [] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $id = $label($e, 'id');
            $rating = (int) ($label($e, 'im:rating') ?? 0);
            // 앱 정보 항목(평점 없음) 등은 건너뛴다
            if ($id === null || !ctype_digit($id) || $rating < 1 || $rating > 5) {
                continue;
            }
            $reviews[] = [
                'id' => $id,
                'rating' => $rating,
                'title' => self::cut($label($e, 'title'), 500),
                'content' => self::cut($label($e, 'content'), 10000),
                'author' => self::cut(isset($e['author']['name']['label']) ? trim((string) $e['author']['name']['label']) : null, 255),
                'version' => self::cut($label($e, 'im:version'), 50),
                'updated_at' => self::localTime($label($e, 'updated')),
                'raw' => array_filter([
                    'vote_sum' => isset($e['im:voteSum']['label']) ? (int) $e['im:voteSum']['label'] : null,
                    'vote_count' => isset($e['im:voteCount']['label']) ? (int) $e['im:voteCount']['label'] : null,
                ], static fn ($v): bool => $v !== null),
            ];
        }

        return $reviews;
    }

    /**
     * @param list<array{id: string, rating: int, title: ?string, content: ?string, author: ?string, version: ?string, updated_at: ?string, raw: array<string, mixed>}> $reviews
     * @return array{int, int} [새로 넣은 수, 바뀐 수]
     */
    private function save(PDO $pdo, int $appId, array $reviews): array
    {
        $find = $pdo->prepare('SELECT rating, title, content, app_version, review_updated_at FROM app_store_reviews WHERE app_id = ? AND external_review_id = ?');
        $insert = $pdo->prepare(
            "INSERT INTO app_store_reviews (app_id, external_review_id, rating, title, content, reviewer_name, territory, app_version,
                review_created_at, review_updated_at, raw_data, source)
             VALUES (?, ?, ?, ?, ?, ?, 'KOR', ?, ?, ?, ?, ?)",
        );
        $update = $pdo->prepare(
            "UPDATE app_store_reviews SET rating = ?, title = ?, content = ?, reviewer_name = ?, app_version = ?, review_updated_at = ?, raw_data = ?,
                updated_at = datetime('now', '+9 hours') WHERE app_id = ? AND external_review_id = ?",
        );

        $new = 0;
        $updated = 0;
        $pdo->beginTransaction();
        try {
            foreach ($reviews as $r) {
                $raw = $r['raw'] === [] ? null : json_encode($r['raw'], JSON_THROW_ON_ERROR);
                $find->execute([$appId, $r['id']]);
                $old = $find->fetch(PDO::FETCH_ASSOC);
                if ($old === false) {
                    // 피드는 마지막 수정 시각만 준다. 처음 본 시점의 값을 작성일로 둔다
                    $insert->execute([$appId, $r['id'], $r['rating'], $r['title'], $r['content'], $r['author'], $r['version'], $r['updated_at'], $r['updated_at'], $raw, self::SOURCE]);
                    $new++;
                } elseif ([(int) $old['rating'], $old['title'], $old['content'], $old['app_version'], $old['review_updated_at']] !== [$r['rating'], $r['title'], $r['content'], $r['version'], $r['updated_at']]) {
                    $update->execute([$r['rating'], $r['title'], $r['content'], $r['author'], $r['version'], $r['updated_at'], $raw, $appId, $r['id']]);
                    $updated++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [$new, $updated];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function due(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            "SELECT p.id, p.name, p.store_url, p.store_info, l.sync_status, l.started_at
             FROM apps p
             LEFT JOIN app_store_sync_logs l ON l.id = (
                SELECT id FROM app_store_sync_logs WHERE app_id = p.id AND sync_type = ? ORDER BY started_at DESC, id DESC LIMIT 1)
             WHERE p.platform = 'ios' AND p.is_active = 1
               AND (l.id IS NULL
                 OR (l.sync_status = 'success' AND l.started_at < ?)
                 OR (l.sync_status <> 'success' AND l.started_at < ?))
             ORDER BY l.started_at IS NOT NULL, l.started_at, p.id
             LIMIT " . self::PER_RUN,
        );
        $statement->execute([self::SYNC_TYPE, date('Y-m-d H:i:s', time() - self::INTERVAL), date('Y-m-d H:i:s', time() - self::RETRY_INTERVAL)]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function find(PDO $pdo, int $appId): array
    {
        $statement = $pdo->prepare("SELECT id, name, store_url, store_info FROM apps WHERE id = ? AND platform = 'ios'");
        $statement->execute([$appId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 피드 요청. 브라우저처럼 보이는 User-Agent('Mozilla/5.0 (compatible; …)')를 보내면 피드가 빈 결과를 주므로
     * 앱 아이콘용 AppIconFetcher::httpGet 대신 평범한 User-Agent 를 쓴다 (2026-11-08 확인).
     */
    public static function httpGet(string $url): ?string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXFILESIZE => 5_000_000,
            CURLOPT_USERAGENT => 'hannun-review-collector/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return is_string($body) && $status === 200 ? $body : null;
    }

    /**
     * ISO 8601 (2026-11-06T17:39:54-07:00) → 서버 시간대 Y-m-d H:i:s
     */
    private static function localTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
