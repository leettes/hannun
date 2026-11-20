<?php
use App\Auth\User;

/**
 * 2단계 인증 등록·해제
 *
 * @var User $me @var ?string $secret 등록 중인 비밀값 (이미 등록했으면 null) @var ?string $qr SVG
 * @var ?string $next @var ?string $error @var string $csrfToken
 */
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '내 정보',
    'crumbHref' => '/me',
    'heading' => '2단계 인증',
    'sub' => '로그인 비밀번호와 별개로, 휴대폰 인증 앱의 6자리 코드를 한 번 더 확인합니다. 회의록 권한이 있으면 필수입니다.',
]) ?>

<?php if ($error !== null): ?>
    <div class="alert alert-error narrow" role="alert"><?= e($error) ?></div>
<?php endif ?>

<?php if ($me->hasTotp()): ?>
    <section class="card narrow">
        <p>2단계 인증을 <strong><?= e(fmt_time($me->totpEnabledAt, 'Y-m-d H:i')) ?></strong> 에 등록했습니다.</p>
        <p class="muted small">휴대폰을 바꾸거나 인증 앱을 지웠다면 관리자에게 2단계 인증 초기화를 요청하세요.</p>
        <?php if ($me->canReadMeetings()): ?>
            <p class="muted small">회의록 권한이 있어 직접 해제할 수 없습니다.</p>
        <?php else: ?>
            <form class="form" method="post" action="/me/2fa/disable">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <input type="text" name="username" value="<?= e($me->email) ?>" autocomplete="username" hidden>
                <label>
                    <span>비밀번호 확인</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <div class="form-actions">
                    <button class="btn" type="submit">2단계 인증 해제</button>
                </div>
            </form>
        <?php endif ?>
    </section>
<?php else: ?>
    <section class="card narrow">
        <ol class="steps">
            <li>휴대폰에 인증 앱을 설치합니다 (Google Authenticator, Microsoft Authenticator, 1Password 등).</li>
            <li>앱에서 QR 코드를 찍습니다. 찍을 수 없으면 아래 키를 직접 입력합니다.</li>
            <li>앱에 나온 6자리 코드를 입력합니다.</li>
        </ol>
        <div class="totp-qr"><?= $qr /* 서버에서 만든 SVG */ ?></div>
        <p class="muted small">직접 입력할 키: <code class="totp-secret"><?= e((string) $secret) ?></code> (시간 기준, 6자리, 30초)</p>
        <form class="form" method="post" action="/me/2fa">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <?php if ($next !== null && $next !== ''): ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
            <?php endif ?>
            <label>
                <span>인증 코드</span>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus>
            </label>
            <div class="form-actions">
                <a class="btn" href="/me">취소</a>
                <button class="btn btn-primary" type="submit">등록</button>
            </div>
        </form>
    </section>
<?php endif ?>
