<?php
use App\Controller\AppDetailController;
use App\Monitoring\AppRegistry;
use App\Monitoring\Status;
use App\Monitoring\SystemRegistry;
use App\Monitoring\Thresholds;

/**
 * 앱 상세. 같은 서비스·같은 이름의 플랫폼별 앱을 모아 본다.
 *
 * @var array<string, mixed> $app 연 앱 @var list<array<string, mixed>> $siblings 플랫폼별 앱 (자기 포함)
 * @var ?array<string, mixed> $system 서비스 (MonitoringRepository::system)
 * @var array{average: ?float, count: int, platforms: array<string, array{average: float, count: int}>} $storeRating
 * @var int $days @var string $from @var string $to
 * @var list<array{date: string, platform: string, downloads: ?int, active_users: ?int}> $daily
 * @var array{total: ?int, recent: ?int, previous: ?int, last_date: ?string} $downloads
 * @var array{count: int, average: ?float, distribution: array<int, int>, unanswered: int} $reviewSummary
 * @var list<array<string, mixed>> $reviews
 * @var ?App\Auth\User $currentUser
 */
$canEdit = isset($currentUser) && $currentUser->can('admin');
$byPlatform = [];
foreach ($siblings as $a) {
    $byPlatform[$a['platform']] ??= $a;
}
$active = array_filter($siblings, static fn (array $a): bool => (bool) $a['is_active']);
$icon = null;
foreach ($siblings as $a) {
    if (!empty($a['icon_file'])) {
        $icon = $a['icon_file'];
        break;
    }
}
$hue = crc32((string) $app['system_code']) % 360;
$latestCheck = max(array_filter(array_column($siblings, 'checked_at')) ?: [null]);
$lastEdited = max(array_column($siblings, 'updated_at'));
$firstCreated = min(array_column($siblings, 'created_at'));
$num = static fn (?int $v): string => $v === null ? '-' : number_format($v);

// 증감 (최근 기간 vs 앞 기간)
$delta = null;
if ($downloads['recent'] !== null && $downloads['previous'] !== null && $downloads['previous'] > 0) {
    $delta = ($downloads['recent'] - $downloads['previous']) / $downloads['previous'] * 100;
}

// 다운로드 차트: 날짜 × 플랫폼 누적 막대
$dates = [];
for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
    $dates[date('Y-m-d', $t)] = ['ios' => 0, 'android' => 0, 'web' => 0];
}
$hasDaily = false;
foreach ($daily as $d) {
    if (isset($dates[$d['date']]) && $d['downloads'] !== null) {
        $dates[$d['date']][$d['platform']] = $d['downloads'];
        $hasDaily = true;
    }
}
$dailyTotals = array_map(static fn (array $p): int => array_sum($p), $dates);
$platformLabel = static fn (string $p): string => AppRegistry::PLATFORMS[$p] ?? $p;

$stores = [
    'ios' => ['label' => 'Apple App Store', 'mark' => 'A'],
    'android' => ['label' => 'Google Play', 'mark' => '▶'],
    'web' => ['label' => '웹앱', 'mark' => 'W'],
];
$sslLeft = null;
$sslExpires = null;
foreach ($system['http'] ?? [] as $m) {
    if ($m['ssl_days_left'] !== null && ($sslLeft === null || $m['ssl_days_left'] < $sslLeft)) {
        $sslLeft = $m['ssl_days_left'];
        $sslExpires = $m['ssl_expires_at'];
    }
}
$stars = static function (float $rating): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $fill = max(0, min(1, $rating - $i + 1));
        $out .= '<span class="star" style="--fill: ' . round($fill * 100) . '%" aria-hidden="true">★</span>';
    }

    return '<span class="stars" title="' . e(number_format($rating, 1)) . '점">' . $out . '</span>';
};
?>
<header class="dash-head app-detail-head">
    <nav class="app-crumb" aria-label="위치">
        <a href="<?= $canEdit ? '/settings/apps' : '/systems/' . e($app['system_code']) ?>"><?= $canEdit ? '앱 관리' : e($app['system_name']) ?></a>
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
        <h1><?= e($app['name']) ?></h1>
    </nav>
    <div class="dash-head-aside">
        <span class="dash-updated" title="App Store 조회 시각 (cron 하루 한 번)">
            <span class="meta-live" aria-hidden="true"></span>
            최근 조회: <span class="num"><?= e($latestCheck === null ? '-' : str_replace('-', '.', substr((string) $latestCheck, 0, 16))) ?></span>
        </span>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary btn-add" href="/settings/apps/new?system=<?= (int) $app['system_id'] ?>">
                <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>앱 추가
            </a>
        <?php endif ?>
    </div>
</header>

<section class="app-hero">
    <div class="card app-id" style="--hue: <?= (int) $hue ?>">
        <span class="svc-logo app-logo">
            <?php if ($icon !== null): ?>
                <img src="/app-icons/<?= e(rawurlencode((string) $icon)) ?>" width="96" height="96" alt="">
            <?php else: ?>
                <span><?= e(mb_substr((string) $app['name'], 0, 1)) ?></span>
            <?php endif ?>
        </span>
        <div class="app-id-text">
            <h2><?= e($app['name']) ?></h2>
            <p class="muted"><?= e(($system['description'] ?? '') !== '' ? $system['description'] : $app['system_name'] . ' 서비스의 앱') ?></p>
            <div class="app-id-tags">
                <span class="svc-status <?= $active === [] ? 's-unknown' : 's-up' ?>"><span class="dot <?= $active === [] ? 's-unknown' : 's-up' ?>"></span><?= $active === [] ? '비활성' : '운영중' ?></span>
                <?php foreach ($siblings as $a): ?>
                    <span class="kind-tag<?= $a['is_active'] ? '' : ' is-off' ?>"><?= e($platformLabel($a['platform'])) ?><?= $a['version'] !== null ? ' ' . e($a['version']) : '' ?></span>
                <?php endforeach ?>
                <?php if ($canEdit): ?>
                    <a class="icon-button icon-button-sm" href="/settings/apps/<?= (int) $app['id'] ?>" title="앱 수정" aria-label="앱 수정">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M11 2.5 13.5 5 6 12.5H3.5V10L11 2.5Z"/></svg>
                    </a>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="card kpi">
        <div class="kpi-label"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2v8M4.5 6.5 8 10l3.5-3.5M3 13.5h10"/></svg>총 다운로드 수</div>
        <?php if ($downloads['total'] === null): ?>
            <div class="kpi-value muted">수집 전</div>
            <div class="kpi-sub muted">스토어 리포트 연동 후 표시</div>
        <?php else: ?>
            <div class="kpi-value"><?= $num($downloads['total']) ?></div>
            <div class="kpi-sub">
                <?php if ($delta !== null): ?>
                    <span class="<?= $delta >= 0 ? 't-up' : 't-down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= number_format(abs($delta), 1) ?>%</span> <span class="muted">(직전 <?= $days ?>일 대비)</span>
                <?php else: ?>
                    <span class="muted">최근 <?= $days ?>일 <?= $num($downloads['recent']) ?></span>
                <?php endif ?>
            </div>
            <span class="kpi-spark t-accent"><?= sparkline(array_values($dailyTotals), null, 120, 40) ?></span>
        <?php endif ?>
    </div>

    <div class="card kpi">
        <div class="kpi-label"><svg class="kpi-star" viewBox="0 0 16 16" aria-hidden="true"><path d="m8 1.8 1.9 3.9 4.3.6-3.1 3 .7 4.2L8 11.5l-3.8 2 .7-4.2-3.1-3 4.3-.6L8 1.8Z"/></svg>평균 평점</div>
        <?php
        $ratingAvg = $storeRating['average'] ?? $reviewSummary['average'];
        $ratingCount = $storeRating['average'] !== null ? $storeRating['count'] : $reviewSummary['count'];
        ?>
        <?php if ($ratingAvg === null): ?>
            <div class="kpi-value muted">-</div>
            <div class="kpi-sub muted">평점 정보 없음</div>
        <?php else: ?>
            <div class="kpi-value"><?= number_format((float) $ratingAvg, 1) ?> <small>/ 5.0</small></div>
            <div class="kpi-sub muted">평점 <?= number_format($ratingCount) ?>개<?= $storeRating['platforms'] !== [] ? ' · ' . e(implode(', ', array_map($platformLabel, array_keys($storeRating['platforms'])))) . ' 기준' : '' ?></div>
            <span class="kpi-bars" aria-hidden="true">
                <?php $maxDist = max($reviewSummary['distribution']) ?: 1 ?>
                <?php foreach ([1, 2, 3, 4, 5] as $r): ?>
                    <span style="height: <?= $reviewSummary['count'] > 0 ? max(8, round($reviewSummary['distribution'][$r] / $maxDist * 100)) : ($r * 18) ?>%"></span>
                <?php endforeach ?>
            </span>
        <?php endif ?>
    </div>

    <div class="card kpi">
        <div class="kpi-label"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5"/></svg>최신 버전</div>
        <?php $versions = array_filter($siblings, static fn (array $a): bool => $a['version'] !== null) ?>
        <?php if ($versions === []): ?>
            <div class="kpi-value muted">-</div>
            <div class="kpi-sub muted">버전 정보 없음</div>
        <?php else: ?>
            <ul class="kpi-versions">
                <?php foreach ($versions as $a): ?>
                    <li><span class="muted"><?= e($platformLabel($a['platform'])) ?></span> <strong><?= e($a['version']) ?></strong> <span class="muted small"><?= e($a['released_at'] ?? '') ?></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</section>

<nav class="app-tabs" aria-label="앱 상세 메뉴">
    <?php foreach (['info' => '앱 정보', 'store' => '스토어 정보', 'downloads' => '다운로드', 'reviews' => '리뷰', 'infra' => '서버 & 인프라', 'domain' => '도메인 & SSL', 'notes' => '메모'] as $key => $label): ?>
        <a class="app-tab<?= $key === 'info' ? ' is-active' : '' ?>" href="#app-<?= $key ?>"><?= e($label) ?></a>
    <?php endforeach ?>
</nav>

<div class="app-grid">
    <section class="card app-panel" id="app-info">
        <div class="app-panel-head">
            <h3>기본 정보</h3>
            <?php if ($canEdit): ?><a class="btn btn-small btn-icon" href="/settings/apps/<?= (int) $app['id'] ?>"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M11 2.5 13.5 5 6 12.5H3.5V10L11 2.5Z"/></svg>수정</a><?php endif ?>
        </div>
        <dl class="kv">
            <dt>앱 이름</dt><dd><?= e($app['name']) ?></dd>
            <dt>서비스</dt><dd><a href="/systems/<?= e($app['system_code']) ?>"><?= e($app['system_name']) ?></a></dd>
            <dt>서비스 설명</dt><dd><?= e(($system['description'] ?? '') !== '' ? $system['description'] : '-') ?></dd>
            <dt>대표 이미지</dt>
            <dd class="kv-icon">
                <?php if ($icon !== null): ?>
                    <img class="app-icon" src="/app-icons/<?= e(rawurlencode((string) $icon)) ?>" width="36" height="36" alt=""> <span class="muted small">스토어에서 자동 수집</span>
                <?php else: ?>
                    <span class="muted">아직 없음 (cron 이 스토어에서 내려받음)</span>
                <?php endif ?>
            </dd>
            <dt>관리회사</dt><dd><?= e($app['company_name'] ?? '미지정') ?></dd>
            <dt>담당자</dt><dd><?= e($system['owner'] ?? '미지정') ?></dd>
            <dt>등록일</dt><dd class="num"><?= e(substr((string) $firstCreated, 0, 10)) ?></dd>
            <dt>최근 수정일</dt><dd class="num"><?= e(substr((string) $lastEdited, 0, 16)) ?></dd>
        </dl>
    </section>

    <section class="card app-panel app-panel-wide" id="app-store">
        <div class="app-panel-head"><h3>스토어 정보</h3></div>
        <div class="store-cols">
            <?php foreach ($stores as $platform => $meta):
                $a = $byPlatform[$platform] ?? null;
                if ($platform === 'web' && $a === null) {
                    continue;
                }
                ?>
                <div class="store-col">
                    <div class="store-col-head">
                        <span class="store-mark store-<?= $platform ?>" aria-hidden="true"><?= $meta['mark'] ?></span>
                        <strong><?= e($meta['label']) ?></strong>
                        <?php if ($a === null): ?>
                            <span class="badge">미등록</span>
                        <?php else: ?>
                            <span class="badge <?= $a['is_active'] ? 's-up' : '' ?>"><?= $a['is_active'] ? '등록됨' : '비활성' ?></span>
                        <?php endif ?>
                    </div>
                    <?php if ($a === null): ?>
                        <p class="muted small store-empty">이 플랫폼 앱이 등록되지 않았습니다.</p>
                        <?php if ($canEdit): ?>
                            <a class="btn btn-small" href="/settings/apps/new?platform=<?= $platform ?>&amp;system=<?= (int) $app['system_id'] ?>">등록하기</a>
                        <?php endif ?>
                    <?php else: ?>
                        <dl class="kv kv-tight">
                            <dt><?= $platform === 'web' ? '앱 주소' : '스토어 링크' ?></dt>
                            <dd>
                                <?php if ($a['store_url'] !== null): ?>
                                    <a class="ellipsis" href="<?= e($a['store_url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($a['store_url']) ?>"><?= e(preg_replace('#^https?://#', '', (string) $a['store_url'])) ?> ↗</a>
                                <?php else: ?>-<?php endif ?>
                            </dd>
                            <?php if ($platform !== 'web'): ?>
                                <dt><?= $platform === 'ios' ? '번들 ID' : '패키지명' ?></dt><dd class="mono small break"><?= e($a['bundle_id'] ?? '-') ?></dd>
                            <?php endif ?>
                            <dt>버전</dt><dd class="num"><?= e($a['version'] ?? '-') ?></dd>
                            <dt>최종 업데이트</dt><dd class="num"><?= e($a['released_at'] ?? '-') ?></dd>
                            <?php if ($platform !== 'web'): ?>
                                <dt>개발자 계정</dt><dd><?= e($a['account_name'] ?? '-') ?></dd>
                            <?php endif ?>
                            <?php if (isset($storeRating['platforms'][$platform])): $pr = $storeRating['platforms'][$platform] ?>
                                <dt>평점</dt><dd><?= $stars($pr['average']) ?> <span class="num"><?= number_format($pr['average'], 1) ?></span> <span class="muted small">(<?= number_format($pr['count']) ?>)</span></dd>
                            <?php endif ?>
                            <dt>정보 출처</dt>
                            <dd>
                                <?php if ($platform === 'ios'): ?>
                                    <?php if ($a['store_error'] !== null): ?>
                                        <span class="t-warn" title="<?= e($a['store_error']) ?>">● 조회 실패</span>
                                    <?php elseif ($a['checked_at'] !== null): ?>
                                        <span class="t-up">● 자동 조회</span> <span class="muted small"><?= e(substr((string) $a['checked_at'], 5, 11)) ?></span>
                                    <?php else: ?>
                                        <span class="muted">● 조회 대기</span>
                                    <?php endif ?>
                                <?php else: ?>
                                    <span class="muted">직접 입력</span>
                                <?php endif ?>
                            </dd>
                        </dl>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    </section>

    <section class="card app-panel app-panel-wide" id="app-downloads">
        <div class="app-panel-head">
            <h3>다운로드 통계</h3>
            <nav class="tabs tabs-inline" aria-label="기간">
                <?php foreach (AppDetailController::PERIODS as $p): ?>
                    <a class="tab<?= $p === $days ? ' is-active' : '' ?>" href="?days=<?= $p ?>#app-downloads">최근 <?= $p ?>일</a>
                <?php endforeach ?>
            </nav>
        </div>
        <div class="dl-kpis">
            <div>
                <div class="kpi-label">기간 신규 다운로드</div>
                <div class="dl-kpi-value"><?= $num($downloads['recent']) ?></div>
            </div>
            <?php foreach (['ios', 'android'] as $p): if (!isset($byPlatform[$p])) { continue; } ?>
                <?php $sum = array_sum(array_column($dates, $p)) ?>
                <div>
                    <div class="kpi-label"><span class="legend-dot legend-<?= $p ?>"></span><?= e($platformLabel($p)) ?></div>
                    <div class="dl-kpi-value"><?= $hasDaily ? number_format($sum) : '-' ?></div>
                </div>
            <?php endforeach ?>
            <div>
                <div class="kpi-label">누적</div>
                <div class="dl-kpi-value"><?= $num($downloads['total']) ?></div>
            </div>
        </div>
        <?php if (!$hasDaily): ?>
            <div class="collect-empty">
                <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="26" width="7" height="14" rx="1.5"/><rect x="17" y="18" width="7" height="22" rx="1.5"/><rect x="28" y="22" width="7" height="18" rx="1.5"/><rect x="39" y="12" width="3" height="28" rx="1.5"/></svg>
                <strong>아직 수집된 다운로드 데이터가 없습니다</strong>
                <span class="muted small">iOS 는 App Store Connect 판매 리포트, Android 는 Play Console 통계 리포트를 하루 한 번 가져와 표시합니다. (연동 예정 · docs/APPS.md 5단계)</span>
            </div>
        <?php else: ?>
            <?php
            $maxDay = max($dailyTotals) ?: 1;
            $n = count($dates);
            $w = 640;
            $h = 160;
            $bw = $w / $n;
            ?>
            <div class="dl-chart">
                <svg viewBox="0 0 <?= $w ?> <?= $h + 18 ?>" preserveAspectRatio="none" role="img" aria-label="최근 <?= $days ?>일 일별 다운로드">
                    <?php foreach ([0.5, 1] as $g): ?>
                        <line class="dl-grid" x1="0" x2="<?= $w ?>" y1="<?= round($h - $h * $g, 1) ?>" y2="<?= round($h - $h * $g, 1) ?>"/>
                    <?php endforeach ?>
                    <?php $i = 0; foreach ($dates as $date => $vals): $y = $h; ?>
                        <?php foreach (['ios', 'android', 'web'] as $p): if ($vals[$p] <= 0) { continue; } $bh = $vals[$p] / $maxDay * ($h - 4); $y -= $bh ?>
                            <rect class="dl-bar dl-<?= $p ?>" x="<?= round($i * $bw + $bw * 0.15, 1) ?>" y="<?= round($y, 1) ?>" width="<?= round($bw * 0.7, 1) ?>" height="<?= round($bh, 1) ?>"><title><?= e($date . ' ' . $platformLabel($p) . ' ' . number_format($vals[$p])) ?></title></rect>
                        <?php endforeach ?>
                        <?php if ($i % max(1, intdiv($n, 6)) === 0): ?>
                            <text class="dl-axis" x="<?= round($i * $bw + $bw / 2, 1) ?>" y="<?= $h + 14 ?>" text-anchor="middle"><?= e(substr($date, 5)) ?></text>
                        <?php endif ?>
                    <?php $i++; endforeach ?>
                </svg>
                <div class="muted small">하루 최대 <?= number_format($maxDay) ?> · 마지막 집계 <?= e((string) $downloads['last_date']) ?> (스토어 리포트는 1~2일 늦게 나옵니다)</div>
            </div>
        <?php endif ?>
    </section>

    <section class="card app-panel" id="app-reviews">
        <div class="app-panel-head">
            <h3>리뷰</h3>
            <?php if ($reviewSummary['unanswered'] > 0): ?><span class="badge s-warning">답변 대기 <?= $reviewSummary['unanswered'] ?></span><?php endif ?>
        </div>
        <?php if ($reviewSummary['count'] === 0): ?>
            <?php if ($storeRating['average'] !== null): ?>
                <div class="review-score">
                    <div class="review-avg"><?= number_format($storeRating['average'], 1) ?></div>
                    <div><?= $stars($storeRating['average']) ?><div class="muted small">스토어 평점 <?= number_format($storeRating['count']) ?>개</div></div>
                </div>
            <?php endif ?>
            <div class="collect-empty collect-empty-sm">
                <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 10h32a3 3 0 0 1 3 3v17a3 3 0 0 1-3 3H20l-8 7v-7H8a3 3 0 0 1-3-3V13a3 3 0 0 1 3-3Z"/><path d="M14 19h20M14 25h12"/></svg>
                <strong>아직 수집된 리뷰가 없습니다</strong>
                <span class="muted small">App Store / Google Play 리뷰를 모아 별점 분포와 최근 리뷰, 답변 여부를 보여 줍니다. (연동 예정)</span>
            </div>
        <?php else: ?>
            <div class="review-score">
                <div class="review-avg"><?= number_format((float) $reviewSummary['average'], 1) ?></div>
                <div>
                    <?= $stars((float) $reviewSummary['average']) ?>
                    <div class="muted small">수집한 리뷰 <?= number_format($reviewSummary['count']) ?>개</div>
                </div>
                <ul class="review-dist">
                    <?php $maxDist = max($reviewSummary['distribution']) ?: 1 ?>
                    <?php foreach ($reviewSummary['distribution'] as $r => $cnt): ?>
                        <li><span class="muted small"><?= $r ?>★</span><span class="review-bar"><span style="width: <?= round($cnt / $maxDist * 100) ?>%"></span></span><span class="muted small num"><?= number_format($cnt) ?></span></li>
                    <?php endforeach ?>
                </ul>
            </div>
            <ul class="review-list">
                <?php foreach ($reviews as $r): ?>
                    <li>
                        <div class="review-meta">
                            <?= $stars((float) $r['rating']) ?>
                            <span class="kind-tag"><?= e($platformLabel($r['platform'])) ?></span>
                            <span class="muted small"><?= e($r['reviewer_name'] ?? '익명') ?> · <?= e(substr((string) $r['review_created_at'], 0, 10)) ?><?= $r['app_version'] !== null ? ' · v' . e($r['app_version']) : '' ?></span>
                            <?php if ($r['developer_reply'] === null): ?><span class="badge s-warning">미답변</span><?php else: ?><span class="badge s-up">답변함</span><?php endif ?>
                        </div>
                        <?php if (!empty($r['title'])): ?><strong class="review-title"><?= e($r['title']) ?></strong><?php endif ?>
                        <p class="review-body"><?= e($r['content'] ?? '') ?></p>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <section class="card app-panel" id="app-infra">
        <div class="app-panel-head">
            <h3>서버 & 인프라</h3>
            <a class="btn btn-small btn-icon" href="/systems/<?= e($app['system_code']) ?>">상세보기<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg></a>
        </div>
        <?php if ($system === null): ?>
            <div class="empty">서비스가 비활성 상태입니다.</div>
        <?php else: ?>
            <dl class="kv">
                <dt>클라우드</dt><dd><?= e(cloud_label($system['cloud'] ?? 'aws', $system['aws_alias'] ?? null, $system['aws_account'] === '-' ? null : $system['aws_account'])) ?></dd>
                <?php if (($system['region'] ?? '-') !== '-'): ?><dt>리전</dt><dd><?= e($system['region']) ?></dd><?php endif ?>
                <?php foreach ($system['http'] as $m): ?>
                    <dt><?= e(SystemRegistry::HTTP_KINDS[$m['kind']] ?? '웹/API') ?></dt>
                    <dd class="infra-row">
                        <span class="dot s-<?= e($m['status']) ?>"></span>
                        <span class="break"><?= e($m['host']) ?></span>
                        <span class="muted small num"><?= $m['response_ms'] === null ? '' : number_format($m['response_ms']) . 'ms' ?></span>
                    </dd>
                <?php endforeach ?>
                <?php foreach ($system['ec2'] as $r): ?>
                    <dt>EC2</dt><dd class="mono small"><?= e($r['instance_id']) ?> <span class="muted">(<?= e($r['instance_type']) ?>)</span></dd>
                <?php endforeach ?>
                <?php foreach ($system['rds'] as $r): ?>
                    <dt>DB</dt><dd class="mono small"><?= e($r['identifier']) ?> <span class="muted">(<?= e($r['engine']) ?>)</span></dd>
                <?php endforeach ?>
            </dl>
        <?php endif ?>
    </section>

    <section class="card app-panel" id="app-domain">
        <div class="app-panel-head">
            <h3>도메인 & SSL</h3>
            <?php if ($canEdit && ($system['http'][0]['domain_info']['domain'] ?? null) !== null): ?>
                <a class="btn btn-small btn-icon" href="/settings/domains/<?= e(rawurlencode((string) $system['http'][0]['domain_info']['domain'])) ?>">수정<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg></a>
            <?php endif ?>
        </div>
        <?php if (($system['http'] ?? []) === []): ?>
            <div class="empty">등록된 웹/API 주소가 없습니다.</div>
        <?php else: ?>
            <?php $info = $system['http'][0]['domain_info'] ?? [] ?>
            <dl class="kv">
                <dt>도메인</dt><dd class="break"><strong><?= e($info['domain'] ?? $system['http'][0]['host']) ?></strong></dd>
                <dt>등록업체</dt><dd><?= e(registrar_label($info['registrar'] ?? null)) ?></dd>
                <dt>DNS</dt><dd><?= e(dns_provider($info['nameservers'] ?? [])) ?></dd>
                <dt>도메인 만료</dt><dd><?= expiry_cell($info['expires_at'] ?? null) ?></dd>
                <dt>SSL 인증서</dt>
                <dd class="<?= $sslLeft !== null && $sslLeft < Thresholds::SSL_WARN_DAYS ? 't-warn' : '' ?>">
                    <?= $sslLeft === null ? '<span class="muted">-</span>' : '만료일: ' . e(fmt_time($sslExpires, 'Y.m.d')) . ' <span class="muted small">(' . (int) $sslLeft . '일)</span>' ?>
                </dd>
            </dl>
        <?php endif ?>
    </section>

    <section class="card app-panel" id="app-actions">
        <div class="app-panel-head"><h3>빠른 작업</h3></div>
        <div class="quick-actions">
            <?php foreach ($siblings as $a): if ($a['store_url'] === null) { continue; } ?>
                <a class="btn btn-icon<?= $a['platform'] === 'ios' ? ' btn-primary' : '' ?>" href="<?= e($a['store_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(AppRegistry::STORE_LABELS[$a['platform']] ?? '열기') ?> ↗</a>
            <?php endforeach ?>
            <a class="btn btn-icon" href="/systems/<?= e($app['system_code']) ?>">서비스 상태 · 장애 이력</a>
            <?php if ($canEdit): ?>
                <a class="btn btn-icon" href="/settings/apps/<?= (int) $app['id'] ?>">설정 수정</a>
                <?php if ($app['account_id'] !== null): ?>
                    <a class="btn btn-icon" href="/settings/app-accounts/<?= (int) $app['account_id'] ?>">개발자 계정</a>
                <?php endif ?>
            <?php endif ?>
        </div>
    </section>

    <section class="card app-panel app-panel-full" id="app-notes">
        <div class="app-panel-head"><h3>메모</h3></div>
        <?php $notes = array_filter($siblings, static fn (array $a): bool => trim((string) $a['notes']) !== '') ?>
        <?php if ($notes === []): ?>
            <p class="muted">메모가 없습니다.<?= $canEdit ? ' 앱 수정 화면에서 남길 수 있습니다.' : '' ?></p>
        <?php else: ?>
            <?php foreach ($notes as $a): ?>
                <div class="app-note"><span class="kind-tag"><?= e($platformLabel($a['platform'])) ?></span><p><?= nl2br(e($a['notes'])) ?></p></div>
            <?php endforeach ?>
        <?php endif ?>
    </section>
</div>
