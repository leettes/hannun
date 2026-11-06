<?php
/** @var list<array<string, mixed>> $companies @var ?string $flash */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '관리회사',
    'sub' => '서비스를 관리·결제하는 법인. AWS 계정마다 지정하거나, Vercel 등은 서비스 관리에서 서비스에 직접 지정합니다.',
    'aside' => '<a class="btn btn-primary" href="/settings/companies/new">관리회사 등록</a>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <?php if ($companies === []): ?>
        <div class="empty">등록된 관리회사가 없습니다. 오른쪽 위 '관리회사 등록'으로 시작하세요.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-main">법인명</th><th class="col-person">대표자</th><th class="col-code">사업자번호</th><th class="col-text">AWS 계정</th><th class="col-text">개발자 계정</th><th class="col-text">서비스</th><th class="col-text">메모</th></tr>
                </thead>
                <tbody>
                <?php foreach ($companies as $c): ?>
                    <tr>
                        <td class="cell-main"><a href="/settings/companies/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a></td>
                        <td><?= e($c['ceo_name'] ?? '-') ?></td>
                        <td class="mono nowrap"><?= e(business_no_label($c['business_no'])) ?></td>
                        <td><?= e($c['accounts'] ?? '-') ?></td>
                        <td><?= e($c['app_accounts'] ?? '-') ?></td>
                        <td><?= e($c['systems'] ?? '-') ?></td>
                        <td class="small muted"><?= e(mb_strimwidth((string) ($c['notes'] ?? ''), 0, 60, '…')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
