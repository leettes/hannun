<?php /** @var string $email @var string $next @var ?string $error @var ?string $notice @var string $csrfToken */ ?>
<main class="auth-card card">
    <div class="brand brand-center"><img class="brand-mark" src="<?= asset('logo.svg') ?>" alt="" width="24" height="24">한눈</div>
    <p class="muted center">통합 모니터링 · 계정으로 로그인하세요.</p>

    <?php if ($notice !== null): ?>
        <div class="alert alert-info" role="status"><?= e($notice) ?></div>
    <?php endif ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif ?>

    <form class="form" method="post" action="/login">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <label>
            <span>이메일</span>
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required<?= $email === '' ? ' autofocus' : '' ?>>
        </label>
        <label>
            <span>비밀번호</span>
            <input type="password" name="password" autocomplete="current-password" required<?= $email !== '' ? ' autofocus' : '' ?>>
        </label>
        <button class="btn btn-primary btn-block" type="submit">로그인</button>
    </form>
    <p class="muted small center">허용된 IP 또는 VPN에서만 접속할 수 있습니다.</p>
</main>
