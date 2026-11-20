<?php
/** @var ?string $next @var ?string $error @var string $csrfToken */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '2단계 인증',
    'sub' => '회의록을 보려면 인증 앱의 6자리 코드를 입력하세요. ' . e((string) intdiv(App\Security\TwoFactor::LOCK_SECONDS, 60)) . '분 동안 ' . App\Security\TwoFactor::MAX_FAILURES . '번 틀리면 잠깁니다.',
]) ?>

<section class="card narrow">
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif ?>
    <form class="form" method="post" action="/2fa">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($next !== null): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif ?>
        <label>
            <span>인증 코드</span>
            <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus>
        </label>
        <div class="form-actions">
            <span class="muted small">인증 앱을 잃어버렸으면 관리자에게 초기화를 요청하세요.</span>
            <button class="btn btn-primary" type="submit">확인</button>
        </div>
    </form>
</section>
