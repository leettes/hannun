<?php
use App\Monitoring\SystemRegistry;

/**
 * @var array<string, mixed> $system
 * @var ?array<string, mixed> $resource 수정 대상 (null 이면 추가)
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 * @var bool $showAwsResources
 */
$isNew = $resource === null;
$type = $values['type'];
$systemHref = '/settings/systems/' . (int) $system['id'];
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$identifier = match ($type) {
    'http' => ['label' => '점검 주소 (URL)', 'placeholder' => 'https://api.example.com/health', 'help' => 'Uptime Kuma 모니터의 URL 과 같으면 자동으로 연결됩니다.'],
    'ec2' => ['label' => '인스턴스 ID', 'placeholder' => 'i-0123456789abcdef0', 'help' => 'CloudWatch 알람의 InstanceId 차원과 같아야 합니다.'],
    'rds' => ['label' => 'DB 식별자', 'placeholder' => 'miri-prod-db', 'help' => 'CloudWatch 알람의 DBInstanceIdentifier 차원과 같아야 합니다.'],
};
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $system['name'],
    'crumbHref' => $systemHref,
    'heading' => $isNew ? e(SystemRegistry::RESOURCE_TYPES[$type]) . ' 추가' : e($resource['name']),
    'sub' => e($system['name']) . ' · ' . e(SystemRegistry::RESOURCE_TYPES[$type]),
]) ?>

<?php if ($isNew): ?>
    <nav class="tabs" aria-label="종류">
        <?php foreach (SystemRegistry::RESOURCE_TYPES as $key => $label): ?>
            <?php if (!$showAwsResources && in_array($key, SystemRegistry::AWS_RESOURCE_TYPES, true) && $key !== $type) continue ?>
            <a class="tab<?= $key === $type ? ' is-active' : '' ?>" href="<?= $systemHref ?>/resources/new?type=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<section class="card narrow">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? $systemHref . '/resources' : '/settings/resources/' . (int) $resource['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($isNew): ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <?= $error('type') ?>
        <?php endif ?>
        <?php if ($type === 'http'): ?>
            <fieldset class="choice">
                <legend>구분</legend>
                <div class="choice-options">
                    <?php foreach (SystemRegistry::HTTP_KINDS as $kind => $kindLabel): ?>
                        <label class="choice-option">
                            <input type="radio" name="kind" value="<?= e($kind) ?>"<?= $values['kind'] === $kind ? ' checked' : '' ?>>
                            <span><?= e($kindLabel) ?></span>
                        </label>
                    <?php endforeach ?>
                </div>
                <span class="muted small">고르지 않으면 저장할 때 주소를 호출해 JSON 응답이면 API, 아니면 웹으로 정합니다.</span>
                <?= $error('kind') ?>
            </fieldset>
        <?php endif ?>
        <label>
            <span>이름</span>
            <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="50" placeholder="<?= $type === 'http' ? '미리 API' : ($type === 'ec2' ? 'miri-api-1' : 'miri-prod') ?>" required>
            <span class="muted small">장애 제목과 문자에 쓰입니다.</span>
            <?= $error('name') ?>
        </label>
        <label>
            <span><?= e($identifier['label']) ?></span>
            <input type="<?= $type === 'http' ? 'url' : 'text' ?>" name="identifier" value="<?= e($values['identifier']) ?>" placeholder="<?= e($identifier['placeholder']) ?>" autocomplete="off" required>
            <span class="muted small"><?= e($identifier['help']) ?></span>
            <?= $error('identifier') ?>
        </label>

        <?php if ($type === 'http'): ?>
            <label>
                <span>Uptime Kuma 모니터 ID</span>
                <input type="text" name="external_ref" value="<?= e($values['external_ref']) ?>" inputmode="numeric" placeholder="선택">
                <span class="muted small">모니터 URL 과 점검 주소가 다를 때만 입력합니다 (모니터 주소창의 /dashboard/<b>ID</b>).</span>
                <?= $error('external_ref') ?>
            </label>
        <?php else: ?>
            <div class="form-row">
                <label>
                    <span>리전</span>
                    <input type="text" name="region" value="<?= e($values['region']) ?>" placeholder="비우면 기본 리전">
                    <?= $error('region') ?>
                </label>
                <?php if ($type === 'ec2'): ?>
                    <label>
                        <span>인스턴스 유형</span>
                        <input type="text" name="instance_type" value="<?= e($values['instance_type']) ?>" maxlength="30" placeholder="t3.medium (표시용)">
                        <?= $error('instance_type') ?>
                    </label>
                <?php else: ?>
                    <label>
                        <span>엔진</span>
                        <input type="text" name="engine" value="<?= e($values['engine']) ?>" maxlength="30" placeholder="MySQL 8.0 (표시용)">
                        <?= $error('engine') ?>
                    </label>
                <?php endif ?>
            </div>
            <?php if ($type === 'rds'): ?>
                <label>
                    <span>최대 연결 수 (max_connections)</span>
                    <input type="number" name="max_connections" value="<?= e($values['max_connections']) ?>" min="1" placeholder="선택">
                    <span class="muted small">연결 사용률(%) 계산에 씁니다. 비우면 사용률을 표시하지 않습니다.</span>
                    <?= $error('max_connections') ?>
                </label>
            <?php endif ?>
        <?php endif ?>

        <?php if (!$isNew): ?>
            <label>
                <span>상태</span>
                <select name="active">
                    <option value="1"<?= $values['active'] ? ' selected' : '' ?>>사용</option>
                    <option value="0"<?= $values['active'] ? '' : ' selected' ?>>비활성 (화면에서 숨기고 수집·알림 중지)</option>
                </select>
                <?= $error('active') ?>
            </label>
        <?php endif ?>
        <div class="form-actions">
            <span class="muted small"><?= $isNew ? '' : '종류는 바꿀 수 없습니다. 다른 종류면 새로 추가하고 이 리소스는 비활성화하세요.' ?></span>
            <a class="btn" href="<?= $systemHref ?>">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '추가' : '저장' ?></button>
        </div>
    </form>
</section>
