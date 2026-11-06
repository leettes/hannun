<?php
use App\Auth\User;

/**
 * @var User $me @var array{phone: string, sms_scope: string} $values @var array<string, string> $errors
 * @var ?string $flash @var string $csrfToken
 */
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'heading' => '내 정보',
    'sub' => '장애 문자를 받을 연락처와 로그인 비밀번호를 바꿉니다.',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash narrow" role="status"><?= e($flash) ?></div>
<?php endif ?>

<section class="card narrow profile-card">
    <span class="avatar avatar-lg" aria-hidden="true"><?= e(avatar_text($me->name)) ?></span>
    <div>
        <div class="profile-name"><?= e($me->name) ?></div>
        <div class="muted"><?= e($me->email) ?> · <?= e($me->roleLabel()) ?></div>
        <div class="muted small">이름·역할은 관리자가 사용자 관리에서 바꿉니다.</div>
    </div>
</section>

<section class="section narrow">
    <div class="section-head"><h2>연락처</h2></div>
    <div class="card">
        <form class="form" method="post" action="/me/contact">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <div class="form-row">
                <label>
                    <span>휴대폰 번호</span>
                    <input type="tel" name="phone" value="<?= e($values['phone']) ?>" placeholder="010-1234-5678" autocomplete="tel">
                    <?= $error('phone') ?>
                </label>
                <label>
                    <span>장애 문자 알림</span>
                    <select name="sms_scope">
                        <?php foreach (User::smsScopesFor($me->role) as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $values['sms_scope'] ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= $error('sms_scope') ?>
                </label>
            </div>
            <span class="muted small">담당 서비스: 내가 담당자인 서비스만 · 전체 서비스: 모든 서비스 (운영자 이상) · 안함: 받지 않음. 번호를 비우면 문자를 받지 않습니다.</span>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">연락처 저장</button>
            </div>
        </form>
    </div>
</section>

<section class="section narrow">
    <div class="section-head"><h2>비밀번호 변경</h2></div>
    <div class="card">
        <form class="form" method="post" action="/me/password">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <input type="text" name="username" value="<?= e($me->email) ?>" autocomplete="username" hidden>
            <label>
                <span>현재 비밀번호</span>
                <input type="password" name="current_password" autocomplete="current-password" required>
                <?= $error('current_password') ?>
            </label>
            <div class="form-row">
                <label>
                    <span>새 비밀번호</span>
                    <input type="password" name="password" autocomplete="new-password" minlength="<?= User::MIN_PASSWORD_LENGTH ?>" required>
                    <?= $error('password') ?>
                </label>
                <label>
                    <span>새 비밀번호 확인</span>
                    <input type="password" name="password_confirm" autocomplete="new-password" required>
                    <?= $error('password_confirm') ?>
                </label>
            </div>
            <div class="form-actions">
                <span class="muted small"><?= User::MIN_PASSWORD_LENGTH ?>자 이상</span>
                <button class="btn btn-primary" type="submit">비밀번호 변경</button>
            </div>
        </form>
    </div>
</section>
