<?php
use App\Auth\User;

/**
 * @var ?User $user 수정 대상 (null 이면 추가)
 * @var array{email: string, name: string, role: string, active: bool, phone: string, sms_scope: string, meeting_access: string} $values
 * @var array<string, string> $errors
 * @var string $csrfToken @var User $currentUser
 */
$isNew = $user === null;
$isSelf = !$isNew && $user->id === $currentUser->id;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '사용자 관리',
    'crumbHref' => '/users',
    'heading' => $isNew ? '사용자 추가' : e($user->name),
    'sub' => $isNew ? '로그인할 관리자 계정을 만듭니다.' : e($user->email),
]) ?>

<section class="card narrow">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/users' : '/users/' . $user->id ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($isNew): ?>
            <label>
                <span>이메일</span>
                <input type="email" name="email" value="<?= e($values['email']) ?>" autocomplete="off" required>
                <?= $error('email') ?>
            </label>
        <?php endif ?>
        <label>
            <span>이름</span>
            <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="50" required>
            <?= $error('name') ?>
        </label>
        <div class="form-row">
            <label>
                <span>역할</span>
                <select name="role">
                    <?php foreach (User::ROLES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['role'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('role') ?>
            </label>
            <?php if ($isNew): ?>
                <input type="hidden" name="active" value="1">
            <?php else: ?>
                <label>
                    <span>상태</span>
                    <select name="active">
                        <option value="1"<?= $values['active'] ? ' selected' : '' ?>>사용</option>
                        <option value="0"<?= $values['active'] ? '' : ' selected' ?>>비활성 (로그인 불가)</option>
                    </select>
                    <?= $error('active') ?>
                </label>
            <?php endif ?>
        </div>
        <div class="form-row">
            <label>
                <span>휴대폰 번호</span>
                <input type="tel" name="phone" value="<?= e($values['phone']) ?>" placeholder="010-1234-5678" autocomplete="off">
                <?= $error('phone') ?>
            </label>
            <label>
                <span>장애 문자 알림</span>
                <select name="sms_scope">
                    <?php foreach (User::SMS_SCOPES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['sms_scope'] ? ' selected' : '' ?>><?= e($label) ?><?= $key === 'all' ? ' (운영자 이상)' : '' ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">담당 서비스: 담당자로 지정된 서비스만 받습니다. 안함이면 담당자여도 받지 않습니다.</span>
                <?= $error('sms_scope') ?>
            </label>
        </div>
        <div class="form-row">
            <label>
                <span>회의록 권한</span>
                <select name="meeting_access">
                    <?php foreach (User::MEETING_ACCESS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['meeting_access'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">역할과 별개입니다. 권한이 있으면 2단계 인증을 등록해야 회의록을 볼 수 있습니다. 변경은 감사 로그에 남습니다.</span>
                <?= $error('meeting_access') ?>
            </label>
            <?php if (!$isNew): ?>
                <div class="field">
                    <span>2단계 인증</span>
                    <span><?= $user->hasTotp() ? '등록 (' . e(fmt_time($user->totpEnabledAt, 'Y-m-d')) . ')' : '<span class="muted">미등록</span>' ?></span>
                    <?php if ($user->hasTotp()): ?>
                        <button class="btn btn-small" type="submit" form="totp-reset">초기화</button>
                        <span class="muted small">인증 앱을 잃어버렸을 때. 다음에 회의록을 열 때 다시 등록합니다.</span>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>
        <div class="form-row">
            <label>
                <span><?= $isNew ? '비밀번호' : '새 비밀번호' ?></span>
                <input type="password" name="password" autocomplete="new-password" minlength="<?= User::MIN_PASSWORD_LENGTH ?>"<?= $isNew ? ' required' : ' placeholder="바꾸지 않으려면 비워 두세요"' ?>>
                <?= $error('password') ?>
            </label>
            <label>
                <span>비밀번호 확인</span>
                <input type="password" name="password_confirm" autocomplete="new-password">
                <?= $error('password_confirm') ?>
            </label>
        </div>
        <div class="form-actions">
            <span class="muted small"><?= $isSelf
                ? '자기 계정의 관리자 권한 해제와 비활성화는 할 수 없습니다.'
                : '비밀번호는 ' . User::MIN_PASSWORD_LENGTH . '자 이상' ?></span>
            <a class="btn" href="/users">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '추가' : '저장' ?></button>
        </div>
    </form>
    <?php if (!$isNew && $user->hasTotp()): ?>
        <form id="totp-reset" method="post" action="/users/<?= $user->id ?>/2fa-reset" hidden>
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        </form>
    <?php endif ?>
</section>
