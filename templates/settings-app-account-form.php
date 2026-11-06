<?php
use App\Monitoring\AppRegistry;

/**
 * @var ?array<string, mixed> $account 수정 대상 (null 이면 등록)
 * @var list<array<string, mixed>> $companies
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 */
$isNew = $account === null;
$platform = (string) $values['platform'];
$apple = $platform === 'apple';
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '개발자 계정',
    'crumbHref' => '/settings/app-accounts',
    'heading' => $isNew ? e(AppRegistry::ACCOUNT_PLATFORMS[$platform]) . ' 계정 등록' : e($account['name']),
    'sub' => $apple ? 'Apple Developer Program (developer.apple.com)' : 'Google Play Console (play.google.com/console)',
]) ?>

<?php if ($isNew): ?>
    <nav class="tabs" aria-label="플랫폼">
        <?php foreach (AppRegistry::ACCOUNT_PLATFORMS as $key => $label): ?>
            <a class="tab<?= $key === $platform ? ' is-active' : '' ?>" href="/settings/app-accounts/new?platform=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<section class="card narrow">
    <div class="alert alert-info">계정 <b>비밀번호, API 키, 인증서 파일</b>은 입력하지 마세요. 비밀번호 관리자에 보관하고, 여기에는 누가 어디에 보관하는지만 메모하세요.</div>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/settings/app-accounts' : '/settings/app-accounts/' . (int) $account['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($isNew): ?>
            <input type="hidden" name="platform" value="<?= e($platform) ?>">
            <?= $error('platform') ?>
        <?php endif ?>
        <div class="form-row">
            <label>
                <span>계정 이름</span>
                <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="50" placeholder="<?= $apple ? '미리 Apple' : '미리 Google' ?>" required>
                <?= $error('name') ?>
            </label>
            <?php if ($apple): ?>
                <label>
                    <span>팀 ID</span>
                    <input type="text" name="team_id" value="<?= e($values['team_id']) ?>" maxlength="10" class="mono" placeholder="AB12CD34EF" autocomplete="off">
                    <span class="muted small">Account → Membership details 의 Team ID</span>
                    <?= $error('team_id') ?>
                </label>
            <?php endif ?>
        </div>
        <div class="form-row">
            <label>
                <span>로그인 이메일</span>
                <input type="email" name="login_email" value="<?= e($values['login_email']) ?>" placeholder="dev@example.com" autocomplete="off">
                <?= $error('login_email') ?>
            </label>
            <label>
                <span>관리회사</span>
                <select name="company_id">
                    <option value="">미지정 (서비스의 관리회사 사용)</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $values['company_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">계정을 소유·결제하는 법인. <a href="/settings/companies/new">관리회사 등록</a></span>
                <?= $error('company_id') ?>
            </label>
        </div>
        <?php if ($apple): ?>
            <label>
                <span>멤버십 만료일</span>
                <input type="date" name="membership_expires_at" value="<?= e($values['membership_expires_at']) ?>">
                <span class="muted small">Apple Developer Program 은 해마다 갱신합니다. 만료되면 앱이 스토어에서 내려갑니다.</span>
                <?= $error('membership_expires_at') ?>
            </label>
        <?php endif ?>
        <label>
            <span>메모</span>
            <textarea name="notes" rows="3" maxlength="500" placeholder="예: 비밀번호는 1Password '앱' 보관함, 2단계 인증 기기 홍길동 휴대폰, 결제 카드 법인 1234"><?= e($values['notes']) ?></textarea>
            <?= $error('notes') ?>
        </label>
        <?php if (!$isNew): ?>
            <label>
                <span>상태</span>
                <select name="active">
                    <option value="1"<?= $values['active'] ? ' selected' : '' ?>>사용</option>
                    <option value="0"<?= $values['active'] ? '' : ' selected' ?>>비활성 (앱 선택 목록에서 숨김)</option>
                </select>
                <?= $error('active') ?>
            </label>
        <?php endif ?>
        <div class="form-actions">
            <span class="muted small"><?= $isNew ? '' : '플랫폼은 바꿀 수 없습니다.' ?></span>
            <a class="btn" href="/settings/app-accounts">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>
