<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\AppRegistry;
use App\Monitoring\MonitoringRepository;
use App\View;

/**
 * 앱 상세 (/apps/{id}). 모든 역할이 본다. 같은 서비스·같은 이름의 iOS/Android/웹앱을 한 화면에 모은다.
 *
 * 다운로드·리뷰는 app_store_daily_stats·app_store_reviews 에서 읽는다. 수집기가 붙기 전에는 빈 영역으로 보인다 (docs/APPS.md 5단계).
 */
final class AppDetailController extends Controller
{
    /** 다운로드 통계 기간(일) */
    public const PERIODS = [7, 30, 90];

    private const DEFAULT_PERIOD = 30;

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly AppRegistry $apps,
    ) {
        parent::__construct($view, $data);
    }

    public function show(Request $request, string $id): Response
    {
        $app = ctype_digit($id) ? $this->apps->app((int) $id) : null;
        if ($app === null) {
            return $this->notFound('등록되지 않은 앱입니다.');
        }

        $siblings = $this->apps->siblings($app);
        $ids = array_map(static fn (array $a): int => (int) $a['id'], $siblings);

        $days = (int) ($request->query('days') ?? self::DEFAULT_PERIOD);
        $days = in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
        // 기간 끝 = 마지막으로 집계된 날 (스토어 리포트는 1~2일 늦다). 기록이 없으면 오늘
        $downloads = $this->apps->downloadTotals($ids, $days);
        $to = $downloads['last_date'] ?? date('Y-m-d');
        $from = date('Y-m-d', strtotime("{$to} -" . ($days - 1) . ' days'));

        return $this->page('app', [
            'title' => $app['name'] . ' · 앱',
            'active' => 'apps',
            'app' => $app,
            'siblings' => $siblings,
            'system' => $this->data->system((string) $app['system_code']),
            'storeRating' => self::storeRating($siblings),
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'daily' => $this->apps->dailyStats($ids, $from, $to),
            'downloads' => $downloads,
            'reviewSummary' => $this->apps->reviewSummary($ids),
            'reviews' => $this->apps->recentReviews($ids, 3),
        ]);
    }

    /**
     * 스토어가 공개한 평점. 지금은 iOS 만 (App Store 조회 API 의 averageUserRating). 여러 개면 평점 수로 가중 평균.
     *
     * @param list<array<string, mixed>> $apps
     * @return array{average: ?float, count: int, platforms: array<string, array{average: float, count: int}>}
     */
    public static function storeRating(array $apps): array
    {
        $platforms = [];
        $sum = 0.0;
        $count = 0;
        foreach ($apps as $a) {
            // store_info = App Store 조회 API 결과 일부 (AppStoreLookup::parse 의 raw)
            $raw = json_decode((string) ($a['store_info'] ?? ''), true);
            if (!is_array($raw) || !isset($raw['averageUserRating'], $raw['userRatingCount'])) {
                continue;
            }
            $avg = (float) $raw['averageUserRating'];
            $n = (int) $raw['userRatingCount'];
            $platforms[(string) $a['platform']] = ['average' => round($avg, 1), 'count' => $n];
            $sum += $avg * $n;
            $count += $n;
        }

        return [
            'average' => $count > 0 ? round($sum / $count, 1) : null,
            'count' => $count,
            'platforms' => $platforms,
        ];
    }
}
