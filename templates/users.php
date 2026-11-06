<?php
/** @var list<App\Auth\User> $users @var ?string $flash @var App\Auth\User $currentUser */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '사용자 관리',
    'sub' => '조회: 화면 조회 · 운영자: 장애 조치 기록 · 관리자: 사용자 관리까지 · 문자 알림: 심각·높음 장애 발생과 복구',
    'aside' => '<a class="btn btn-primary" href="/users/new">사용자 추가</a>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th class="col-main">이름</th><th class="col-email">이메일</th><th class="col-tag">역할</th><th class="col-tag">상태</th><th class="col-phone">휴대폰</th><th class="col-short">문자 알림</th><th class="col-time">마지막 로그인</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr<?= $u->active ? '' : ' class="muted"' ?>>
                    <td class="cell-main">
                        <a href="/users/<?= $u->id ?>"><?= e($u->name) ?></a>
                        <?php if ($u->id === $currentUser->id): ?><span class="muted small">(나)</span><?php endif ?>
                    </td>
                    <td class="break"><?= e($u->email) ?></td>
                    <td><?= e($u->roleLabel()) ?></td>
                    <td><?= $u->active ? '사용' : '비활성' ?></td>
                    <td class="nowrap"><?= e($u->phoneLabel()) ?></td>
                    <td class="nowrap"><?= $u->phone === null && $u->smsScope !== 'none' ? '<span class="muted">' . e($u->smsScopeLabel()) . ' (번호 없음)</span>' : e($u->smsScopeLabel()) ?></td>
                    <td class="nowrap"><?= e(fmt_time($u->lastLoginAt, 'Y-m-d H:i')) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
