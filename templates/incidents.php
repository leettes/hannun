<?php
/** @var ?string $status @var array $statusCounts @var int $total @var list<array> $incidents */
$tabs = ['' => ['전체', $total]];
foreach (\App\Monitoring\Incident::STATUS_LABELS as $key => $label) {
    $tabs[$key] = [$label, $statusCounts[$key] ?? 0];
}
?>
<?= $this->partial('partials/page-head', [
    'heading' => '장애 센터',
    'sub' => '장애 발생·복구 이력과 조치 내용을 관리합니다.',
]) ?>

<nav class="tabs">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
        <a class="tab<?= ($status ?? '') === $key ? ' is-active' : '' ?>" href="/incidents<?= $key === '' ? '' : '?status=' . e($key) ?>">
            <?= e($label) ?> <span class="tab-count"><?= $count ?></span>
        </a>
    <?php endforeach ?>
</nav>

<div class="card card-flush">
    <?= $this->partial('partials/incident-table', ['incidents' => $incidents]) ?>
</div>
