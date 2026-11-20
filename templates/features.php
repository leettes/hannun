<?php
use App\Features\FeatureRepository;
use App\Monitoring\AppRegistry;

/**
 * @var array{status: ?string, system_id: ?int, app_id: ?int, assignee_id: ?int} $filter
 * @var list<array<string, mixed>> $features @var array<string, int> $counts @var int $total @var int $page @var int $pages
 * @var list<array{id: int, name: string, code: string}> $systems @var list<array{id: int, system_id: int, name: string, platform: string}> $apps
 * @var list<array{id: int, name: string}> $staff @var bool $canEdit @var ?string $flash
 */
$url = static function (array $change) use ($filter): string {
    $q = array_filter([
        'status' => $filter['status'] ?? 'all', 'system' => $filter['system_id'],
        'app' => $filter['app_id'], 'assignee' => $filter['assignee_id'],
    ], static fn ($v): bool => $v !== null);
    $q = array_filter($change + $q, static fn ($v): bool => $v !== null && $v !== '');
    if (($q['status'] ?? null) === 'open') {
        unset($q['status']);
    }

    return '/features' . ($q === [] ? '' : '?' . http_build_query($q));
};
$open = array_sum(array_intersect_key($counts, array_flip(FeatureRepository::OPEN)));
$current = $filter['status'] ?? 'all';
?>
<?= $this->partial('partials/page-head', [
    'heading' => '기능',
    'sub' => '서비스·앱별 기능을 요청부터 배포까지 추적합니다. 등록·상태 변경은 운영자 이상.',
    'aside' => $canEdit ? '<a class="btn btn-primary" href="/features/new">기능 등록</a>' : '',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<nav class="tabs" aria-label="상태">
    <a class="tab<?= $current === 'open' ? ' is-active' : '' ?>" href="<?= e($url(['status' => 'open'])) ?>">진행 중 <span class="tab-count"><?= $open ?></span></a>
    <?php foreach (FeatureRepository::STATUSES as $key => $label): ?>
        <a class="tab<?= $current === $key ? ' is-active' : '' ?>" href="<?= e($url(['status' => $key])) ?>"><?= e($label) ?> <span class="tab-count"><?= $counts[$key] ?></span></a>
    <?php endforeach ?>
    <a class="tab<?= $current === 'all' ? ' is-active' : '' ?>" href="<?= e($url(['status' => 'all'])) ?>">전체 <span class="tab-count"><?= array_sum($counts) ?></span></a>
</nav>

<section class="card">
    <form class="form" method="get" action="/features">
        <input type="hidden" name="status" value="<?= e($current) ?>">
        <div class="form-row form-row-3">
            <label>
                <span>서비스</span>
                <select name="system">
                    <option value="">전체</option>
                    <?php foreach ($systems as $s): ?>
                        <option value="<?= $s['id'] ?>"<?= $filter['system_id'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label>
                <span>앱</span>
                <select name="app">
                    <option value="">전체</option>
                    <?php foreach ($apps as $a): ?>
                        <option value="<?= $a['id'] ?>"<?= $filter['app_id'] === $a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' ' . (AppRegistry::PLATFORMS[$a['platform']] ?? $a['platform'])) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label>
                <span>담당자</span>
                <select name="assignee">
                    <option value="">전체</option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= $u['id'] ?>"<?= $filter['assignee_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
        </div>
        <div class="form-actions">
            <a class="btn" href="/features">초기화</a>
            <button class="btn btn-primary" type="submit">조회</button>
        </div>
    </form>
</section>

<div class="card card-flush">
    <?= $this->partial('partials/feature-list', ['features' => $features, 'showSystem' => true, 'empty' => '조건에 맞는 기능이 없습니다.']) ?>
    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="쪽">
            <?php if ($page > 1): ?><a class="btn btn-small" href="<?= e($url(['page' => $page - 1])) ?>">← 이전</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">← 이전</span><?php endif ?>
            <span class="muted small num"><?= $page ?> / <?= $pages ?> · <?= number_format($total) ?>건</span>
            <?php if ($page < $pages): ?><a class="btn btn-small" href="<?= e($url(['page' => $page + 1])) ?>">다음 →</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">다음 →</span><?php endif ?>
        </nav>
    <?php endif ?>
</div>
