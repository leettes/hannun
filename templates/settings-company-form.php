<?php
/**
 * @var ?array<string, mixed> $company 수정 대상 (null 이면 등록)
 * @var array{name: string, ceo_name: string, business_no: string, notes: string} $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 */
$isNew = $company === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '관리회사',
    'crumbHref' => '/settings/companies',
    'heading' => $isNew ? '관리회사 등록' : e($company['name']),
    'sub' => $isNew ? 'AWS 계정을 관리·결제하는 법인 정보를 등록합니다.' : null,
]) ?>

<section class="card narrow">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/settings/companies' : '/settings/companies/' . (int) $company['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <label>
            <span>법인명</span>
            <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="100" placeholder="주식회사 예시" required>
            <?= $error('name') ?>
        </label>
        <div class="form-row">
            <label>
                <span>대표자</span>
                <input type="text" name="ceo_name" value="<?= e($values['ceo_name']) ?>" maxlength="50" placeholder="홍길동">
                <?= $error('ceo_name') ?>
            </label>
            <label>
                <span>사업자번호</span>
                <input type="text" name="business_no" value="<?= e($values['business_no']) ?>" inputmode="numeric" maxlength="12" placeholder="123-45-67890" autocomplete="off">
                <?= $error('business_no') ?>
            </label>
        </div>
        <label>
            <span>메모</span>
            <textarea name="notes" rows="3" maxlength="500" placeholder="예: 세금계산서 담당 김OO, 결제 카드 법인 1234"><?= e($values['notes']) ?></textarea>
            <?= $error('notes') ?>
        </label>
        <div class="form-actions">
            <a class="btn" href="/settings/companies">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>
