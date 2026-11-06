<?php
use App\Monitoring\AppRegistry;

/**
 * @var list<array<string, mixed>> $apps @var ?string $platform @var array<string, int> $platformCounts @var ?string $flash
 * @var list<array{id: int|string, name: string, count: int}> $companies 앱이 있는 관리회사 @var int|string|null $company
 */
// 필터 링크: 한쪽을 바꿔도 다른 쪽은 유지한다
$href = static function (?string $p, int|string|null $c): string {
    $query = http_build_query(array_filter(['platform' => $p, 'company' => $c === null ? null : (string) $c], static fn ($v): bool => $v !== null));

    return '/settings/apps' . ($query === '' ? '' : '?' . $query);
};
$tabs = ['' => ['전체', array_sum($platformCounts)]];
foreach (AppRegistry::PLATFORMS as $key => $label) {
    $tabs[$key] = [$label, $platformCounts[$key] ?? 0];
}
?>
<?= $this->partial('partials/page-head', [
    'heading' => '앱',
    'sub' => '서비스별 iOS · Android · 웹앱. 앱을 올리는 계정은 <a href="/settings/app-accounts">개발자 계정</a>에서 등록합니다.',
    'aside' => '<a class="btn btn-primary" href="/settings/apps/new' . ($platform === null ? '' : '?platform=' . e($platform)) . '">앱 등록</a>',
    // 아이콘은 cron 이 스토어에서 가져온다
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<nav class="tabs" aria-label="플랫폼">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
        <a class="tab<?= ($platform ?? '') === $key ? ' is-active' : '' ?>" href="<?= e($href($key === '' ? null : $key, $company)) ?>">
            <?= e($label) ?> <span class="tab-count"><?= $count ?></span>
        </a>
    <?php endforeach ?>
</nav>
<?php if ($companies !== []): ?>
    <nav class="tabs tabs-filter" aria-label="관리회사">
        <span class="tabs-label">관리회사</span>
        <a class="tab<?= $company === null ? ' is-active' : '' ?>" href="<?= e($href($platform, null)) ?>">전체</a>
        <?php foreach ($companies as $c): ?>
            <a class="tab<?= (string) $company === (string) $c['id'] ? ' is-active' : '' ?>" href="<?= e($href($platform, $c['id'])) ?>">
                <?= e($c['name']) ?> <span class="tab-count"><?= $c['count'] ?></span>
            </a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<?= $this->partial('partials/app-table', ['apps' => $apps, 'editable' => true, 'showSystem' => true]) ?>
