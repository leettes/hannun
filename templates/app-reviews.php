<?php
use App\Monitoring\AppRegistry;

/**
 * 리뷰 전체 목록. 같은 서비스·같은 이름의 플랫폼별 앱 리뷰를 최신순으로.
 *
 * @var array<string, mixed> $app @var list<string> $platforms
 * @var array{rating: ?int, platform: ?string, unanswered: bool} $filter
 * @var array{count: int, average: ?float, distribution: array<int, int>, unanswered: int} $summary
 * @var list<array<string, mixed>> $reviews @var int $total @var int $page @var int $pages
 */
$base = '/apps/' . (int) $app['id'] . '/reviews';
/** 지금 필터에서 일부만 바꾼 주소. 필터를 바꾸면 1쪽으로 */
$url = static function (array $change) use ($base, $filter): string {
    $q = array_filter([
        'rating' => $filter['rating'],
        'platform' => $filter['platform'],
        'unanswered' => $filter['unanswered'] ? '1' : null,
    ], static fn ($v): bool => $v !== null);
    $q = array_filter($change + $q, static fn ($v): bool => $v !== null && $v !== '');

    return $base . ($q === [] ? '' : '?' . http_build_query($q));
};
$platformLabel = static fn (string $p): string => AppRegistry::PLATFORMS[$p] ?? $p;
$filtered = $filter['rating'] !== null || $filter['platform'] !== null || $filter['unanswered'];
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $app['name'],
    'crumbHref' => '/apps/' . (int) $app['id'] . '#app-reviews',
    'heading' => e($app['name']) . ' 리뷰',
    'sub' => $summary['count'] === 0 ? null : e('수집한 리뷰 ' . number_format($summary['count']) . '개 · 평균 ' . number_format((float) $summary['average'], 1) . '점') . ($summary['unanswered'] > 0 ? e(' · 답변 대기 ' . $summary['unanswered'] . '개') : ''),
]) ?>

<nav class="tabs" aria-label="별점">
    <a class="tab<?= $filter['rating'] === null ? ' is-active' : '' ?>" href="<?= e($url(['rating' => null])) ?>">전체 <span class="tab-count"><?= number_format($summary['count']) ?></span></a>
    <?php foreach ($summary['distribution'] as $r => $n): ?>
        <a class="tab<?= $filter['rating'] === $r ? ' is-active' : '' ?>" href="<?= e($url(['rating' => $r])) ?>"><?= $r ?>★ <span class="tab-count"><?= number_format($n) ?></span></a>
    <?php endforeach ?>
</nav>

<nav class="tabs tabs-filter" aria-label="플랫폼·답변">
    <?php if (count($platforms) > 1): ?>
        <span class="tabs-label">플랫폼</span>
        <a class="tab<?= $filter['platform'] === null ? ' is-active' : '' ?>" href="<?= e($url(['platform' => null])) ?>">전체</a>
        <?php foreach ($platforms as $p): ?>
            <a class="tab<?= $filter['platform'] === $p ? ' is-active' : '' ?>" href="<?= e($url(['platform' => $p])) ?>"><?= e($platformLabel($p)) ?></a>
        <?php endforeach ?>
    <?php endif ?>
    <span class="tabs-label">답변</span>
    <a class="tab<?= !$filter['unanswered'] ? ' is-active' : '' ?>" href="<?= e($url(['unanswered' => null])) ?>">전체</a>
    <a class="tab<?= $filter['unanswered'] ? ' is-active' : '' ?>" href="<?= e($url(['unanswered' => '1'])) ?>" title="App Store Connect·Google Play 에서 받은 리뷰만 답변 여부를 압니다">미답변 <span class="tab-count"><?= number_format($summary['unanswered']) ?></span></a>
</nav>

<section class="card">
    <?php if ($reviews === []): ?>
        <div class="empty"><?= $filtered ? '조건에 맞는 리뷰가 없습니다. <a href="' . e($base) . '">필터 해제</a>' : '아직 수집된 리뷰가 없습니다.' ?></div>
    <?php else: ?>
        <div class="muted small review-count"><?= number_format($total) ?>개 중 <?= number_format(($page - 1) * App\Controller\AppDetailController::REVIEWS_PER_PAGE + 1) ?>–<?= number_format(min($total, $page * App\Controller\AppDetailController::REVIEWS_PER_PAGE)) ?></div>
        <ul class="review-list review-list-full">
            <?php foreach ($reviews as $r): ?>
                <li>
                    <div class="review-meta">
                        <?= stars((float) $r['rating']) ?>
                        <span class="kind-tag"><?= e($platformLabel($r['platform'])) ?></span>
                        <span class="muted small"><?= e($r['reviewer_name'] ?? '익명') ?> · <?= e(substr((string) $r['review_created_at'], 0, 10)) ?><?= $r['app_version'] !== null ? ' · v' . e($r['app_version']) : '' ?><?= $r['territory'] !== null ? ' · ' . e($r['territory']) : '' ?></span>
                        <?php if ($r['developer_reply'] !== null): ?>
                            <span class="badge s-up">답변함</span>
                        <?php elseif (in_array($r['source'], AppRegistry::REPLY_SOURCES, true)): ?>
                            <span class="badge s-warning">미답변</span>
                        <?php endif ?>
                    </div>
                    <?php if (!empty($r['title'])): ?><strong class="review-title"><?= e($r['title']) ?></strong><?php endif ?>
                    <p class="review-body"><?= nl2br(e($r['content'] ?? '')) ?></p>
                    <?php if ($r['developer_reply'] !== null): ?>
                        <div class="review-reply">
                            <span class="muted small">개발자 답변<?= $r['developer_reply_at'] !== null ? ' · ' . e(substr((string) $r['developer_reply_at'], 0, 10)) : '' ?></span>
                            <p><?= nl2br(e($r['developer_reply'])) ?></p>
                        </div>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="쪽">
                <?php if ($page > 1): ?><a class="btn btn-small" href="<?= e($url(['page' => $page - 1])) ?>">← 이전</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">← 이전</span><?php endif ?>
                <span class="muted small num"><?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-small" href="<?= e($url(['page' => $page + 1])) ?>">다음 →</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">다음 →</span><?php endif ?>
            </nav>
        <?php endif ?>
    <?php endif ?>
</section>
