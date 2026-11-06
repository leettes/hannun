<?php
/**
 * @var ?array<string, mixed> $account 수정 대상 (null 이면 등록)
 * @var list<array<string, mixed>> $companies
 * @var array{account_id: string, alias: string, company_id: string, root_email: string, mfa_enabled: string, notes: string} $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 */
$isNew = $account === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => 'AWS 계정',
    'crumbHref' => '/settings/aws-accounts',
    'heading' => $isNew ? 'AWS 계정 등록' : e($account['alias']),
    'sub' => $isNew ? '서비스가 쓰는 AWS 계정의 관리 정보를 등록합니다.' : '계정 ID <span class="mono">' . e(aws_account_label($account['account_id'])) . '</span>',
]) ?>

<section class="card narrow">
    <div class="alert alert-info">루트 계정 <b>비밀번호, 액세스 키, MFA 복구 코드</b>는 입력하지 마세요. 비밀번호 관리자에 보관하고, 여기에는 누가 어디에 보관하는지만 메모하세요.</div>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/settings/aws-accounts' : '/settings/aws-accounts/' . (int) $account['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="form-row">
            <?php if ($isNew): ?>
                <label>
                    <span>계정 ID</span>
                    <input type="text" name="account_id" value="<?= e($values['account_id']) ?>" inputmode="numeric" placeholder="1234-5678-9012" autocomplete="off" required>
                    <span class="muted small">등록 후 바꿀 수 없습니다.</span>
                    <?= $error('account_id') ?>
                </label>
            <?php endif ?>
            <label>
                <span>별칭</span>
                <input type="text" name="alias" value="<?= e($values['alias']) ?>" maxlength="50" placeholder="미리 운영" required>
                <?= $error('alias') ?>
            </label>
        </div>
        <label>
            <span>관리회사</span>
            <select name="company_id">
                <option value="">미지정</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $values['company_id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= $c['business_no'] === null ? '' : ' (' . e(business_no_label($c['business_no'])) . ')' ?></option>
                <?php endforeach ?>
            </select>
            <span class="muted small">이 계정을 관리·결제하는 법인. <a href="/settings/companies/new">관리회사 등록</a></span>
            <?= $error('company_id') ?>
        </label>
        <div class="form-row">
            <label>
                <span>루트 이메일</span>
                <input type="email" name="root_email" value="<?= e($values['root_email']) ?>" placeholder="aws-miri@example.com" autocomplete="off">
                <?= $error('root_email') ?>
            </label>
            <label>
                <span>루트 MFA</span>
                <select name="mfa_enabled">
                    <?php foreach (['' => '모름', '1' => '설정됨', '0' => '안 됨'] as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= (string) $key === $values['mfa_enabled'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">IAM → 보안 자격 증명에서 확인. 루트는 MFA 를 꼭 켜 두세요.</span>
                <?= $error('mfa_enabled') ?>
            </label>
        </div>
        <label>
            <span>메모</span>
            <textarea name="notes" rows="4" maxlength="500" placeholder="예: 결제 카드 법인 1234, 비밀번호는 1Password '인프라' 보관함, 담당 홍길동"><?= e($values['notes']) ?></textarea>
            <?= $error('notes') ?>
        </label>
        <div class="form-actions">
            <a class="btn" href="/settings/aws-accounts">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>
