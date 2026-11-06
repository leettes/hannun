<?php
/**
 * 도메인 정보 수동 입력. 비운 항목은 자동 수집(RDAP·WHOIS) 값을 쓴다.
 *
 * @var string $domain
 * @var list<array<string, mixed>> $rows 이 도메인을 쓰는 웹/API 리소스
 * @var ?array<string, mixed> $auto 자동 수집 값 (resources.domain_info)
 * @var ?array<string, mixed> $override 저장된 수동 입력
 * @var array{registrar: string, expires_at: string, nameservers: string, memo: string} $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 */
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$autoText = static fn (?string $value): string => '자동 수집: ' . ($value === null || $value === '' ? '없음' : $value);
$autoExpires = isset($auto['expires_at']) ? substr((string) $auto['expires_at'], 0, 10) : null;
$used = implode(', ', array_map(static fn (array $r): string => $r['system_name'] . ' ' . $r['name'], $rows));
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '서비스 관리',
    'crumbHref' => '/settings/systems',
    'heading' => '<span class="mono">' . e($domain) . '</span>',
    'sub' => '사용: ' . e($used),
]) ?>

<section class="card narrow">
    <div class="alert alert-info">
        자동 수집이 안 되거나 틀린 항목만 입력하세요. <b>비운 항목은 자동 수집 값</b>을 씁니다. 모두 비우고 저장하면 자동 수집 값으로 되돌립니다.
        <?php if (!empty($auto['error'])): ?><br>최근 자동 조회: <?= e($auto['error']) ?> (<?= e((string) ($auto['checked_at'] ?? '-')) ?>)<?php endif ?>
    </div>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="/settings/domains/<?= e(rawurlencode($domain)) ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="form-row">
            <label>
                <span>등록업체</span>
                <input type="text" name="registrar" value="<?= e($values['registrar']) ?>" maxlength="100" placeholder="<?= e((string) ($auto['registrar'] ?? '예: 가비아')) ?>">
                <span class="muted small"><?= e($autoText($auto['registrar'] ?? null)) ?></span>
                <?= $error('registrar') ?>
            </label>
            <label>
                <span>도메인 만료일</span>
                <input type="date" name="expires_at" value="<?= e($values['expires_at']) ?>">
                <span class="muted small"><?= e($autoText($autoExpires)) ?></span>
                <?= $error('expires_at') ?>
            </label>
        </div>
        <label>
            <span>네임서버</span>
            <textarea name="nameservers" rows="4" class="mono" placeholder="<?= e(implode("\n", $auto['nameservers'] ?? []) ?: "ns1.example.com\nns2.example.com") ?>"><?= e($values['nameservers']) ?></textarea>
            <span class="muted small">한 줄에 하나. <?= e($autoText(implode(', ', $auto['nameservers'] ?? []))) ?></span>
            <?= $error('nameservers') ?>
        </label>
        <label>
            <span>메모</span>
            <textarea name="memo" rows="3" maxlength="500" placeholder="예: 가비아 법인 계정, 자동 연장, 담당 홍길동"><?= e($values['memo']) ?></textarea>
            <?= $error('memo') ?>
        </label>
        <?php if ($override !== null): ?>
            <p class="muted small">마지막 수정: <?= e((string) $override['updated_at']) ?><?= $override['updated_by'] === null ? '' : ' · ' . e((string) $override['updated_by']) ?></p>
        <?php endif ?>
        <div class="form-actions">
            <a class="btn" href="/settings/systems">취소</a>
            <button class="btn btn-primary" type="submit">저장</button>
        </div>
    </form>
</section>
