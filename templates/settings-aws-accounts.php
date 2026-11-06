<?php
/** @var list<array<string, mixed>> $accounts @var ?string $flash */
?>
<?= $this->partial('partials/page-head', [
    'heading' => 'AWS 계정',
    'sub' => '서비스별 AWS 계정(루트 계정) 관리 정보. 비밀번호·액세스 키는 여기에 두지 말고 비밀번호 관리자에 보관하세요.',
    'aside' => '<a class="btn btn-primary" href="/settings/aws-accounts/new">계정 등록</a>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <?php if ($accounts === []): ?>
        <div class="empty">등록된 AWS 계정이 없습니다. 오른쪽 위 '계정 등록'으로 시작하세요.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-main">별칭</th><th class="col-code">계정 ID</th><th class="col-person">관리회사</th><th class="col-email">루트 이메일</th><th class="col-tag">루트 MFA</th><th class="col-text">사용 서비스</th><th class="col-text">메모</th></tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td class="cell-main"><a href="/settings/aws-accounts/<?= (int) $a['id'] ?>"><?= e($a['alias']) ?></a></td>
                        <td class="mono nowrap"><?= e(aws_account_label($a['account_id'])) ?></td>
                        <td<?= $a['company_name'] === null ? ' class="muted"' : '' ?>><?= $a['company_id'] === null ? '미지정' : '<a href="/settings/companies/' . (int) $a['company_id'] . '">' . e($a['company_name']) . '</a>' ?></td>
                        <td class="break"><?= e($a['root_email'] ?? '-') ?></td>
                        <td class="nowrap<?= $a['mfa_enabled'] === 1 ? '' : ' t-warn' ?>"><?= e(mfa_label($a['mfa_enabled'])) ?></td>
                        <td><?= e($a['systems'] ?? '-') ?></td>
                        <td class="small muted"><?= e(mb_strimwidth((string) ($a['notes'] ?? ''), 0, 60, '…')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
