<?php
use App\Monitoring\AppRegistry;

/** @var list<array<string, mixed>> $accounts @var ?string $flash */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '개발자 계정',
    'sub' => '앱을 올리는 Apple Developer · Google Play Console 계정. 비밀번호·API 키는 여기에 두지 말고 비밀번호 관리자에 보관하세요.',
    'aside' => '<a class="btn" href="/settings/app-accounts/new?platform=google">Google 계정 등록</a> <a class="btn btn-primary" href="/settings/app-accounts/new?platform=apple">Apple 계정 등록</a>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <?php if ($accounts === []): ?>
        <div class="empty">등록된 개발자 계정이 없습니다. 오른쪽 위에서 등록하세요.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-main">이름</th><th class="col-tag">플랫폼</th><th class="col-code">팀 ID</th><th class="col-email">로그인 이메일</th><th class="col-person">관리회사</th><th class="col-date">멤버십 만료</th><th class="col-text">앱</th><th class="col-tag">상태</th></tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr<?= $a['is_active'] ? '' : ' class="muted"' ?>>
                        <td class="cell-main"><a href="/settings/app-accounts/<?= (int) $a['id'] ?>"><?= e($a['name']) ?></a></td>
                        <td><?= e(AppRegistry::ACCOUNT_PLATFORMS[$a['platform']] ?? $a['platform']) ?></td>
                        <td class="mono nowrap"><?= e($a['team_id'] ?? '-') ?></td>
                        <td class="break"><?= e($a['login_email'] ?? '-') ?></td>
                        <td<?= $a['company_name'] === null ? ' class="muted"' : '' ?>><?= $a['company_id'] === null ? '미지정' : '<a href="/settings/companies/' . (int) $a['company_id'] . '">' . e($a['company_name']) . '</a>' ?></td>
                        <td class="nowrap"><?= $a['platform'] === 'apple' ? ($a['is_active'] ? expiry_cell($a['membership_expires_at'], '미입력') : e($a['membership_expires_at'] ?? '-')) : '<span class="muted">만료 없음</span>' ?></td>
                        <td><?= e($a['apps'] ?? '-') ?></td>
                        <td><?= $a['is_active'] ? '사용' : '비활성' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
