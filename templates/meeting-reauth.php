<?php
/** 본인 확인 (시안의 재인증 창). @var string $next @var string $action @var ?string $error @var string $csrfToken */
?>
<section class="card reauth-card">
    <h2 style="margin:0 0 6px">본인 확인</h2>
    <p class="muted small" style="margin:0"><strong><?= e($action) ?></strong> 은(는) 민감한 작업이라 인증 앱의 6자리 코드를 한 번 더 입력합니다. 확인하면 <?= intdiv(App\Security\TwoFactor::REAUTH_SECONDS, 60) ?>분 동안 다시 묻지 않습니다.</p>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert" style="margin-top:12px"><?= e($error) ?></div>
    <?php endif ?>
    <form class="form" method="post" action="/meetings/reauth" style="margin-top:14px">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input class="otp-input" type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus aria-label="인증 코드">
        <div class="form-actions">
            <a class="btn" href="<?= e(preg_match('#^/meetings/\d+#', $next, $m) ? $m[0] : '/meetings') ?>">취소</a>
            <button class="btn btn-primary" type="submit">확인</button>
        </div>
    </form>
</section>
