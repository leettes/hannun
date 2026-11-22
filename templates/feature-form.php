<?php
use App\Features\FeatureRepository;
use App\Monitoring\AppRegistry;

/**
 * @var ?array<string, mixed> $feature 수정 대상 (새로 등록하면 null)
 * @var ?array{id: int, title: string} $meeting 회의에서 등록할 때
 * @var ?array{id: int, item: string, service: string} $task 회의 할 일에서 올릴 때
 * @var list<string> $decisionLines
 * @var array{system_id: ?int, app_id: ?int, title: string, description: string, target_version: string, assignee_user_id: ?int, status: string} $values
 * @var array<string, string> $errors
 * @var list<array{id: int, name: string, code: string}> $systems @var list<array{id: int, system_id: int, name: string, platform: string}> $apps
 * @var list<array{id: int, name: string}> $staff @var string $csrfToken
 */
$isNew = $feature === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$systemNames = array_column($systems, 'name', 'id');
$action = $meeting !== null ? '/meetings/' . $meeting['id'] . '/buildup' : ($isNew ? '/buildup' : '/buildup/' . (int) $feature['id']);
$cancel = $meeting !== null ? '/meetings/' . $meeting['id'] : ($isNew ? '/buildup' : '/buildup/' . (int) $feature['id']);
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $meeting !== null ? e($meeting['title']) : ($isNew ? '빌드업' : e((string) $feature['title'])),
    'crumbHref' => $cancel,
    'heading' => $isNew ? ($meeting !== null ? '회의 결정을 빌드업에 올리기' : '빌드업 등록') : '빌드업 수정',
    'sub' => $meeting !== null ? '이 회의를 출처로 남깁니다. 출처 링크는 회의록을 볼 수 있는 사람에게만 보입니다.' . ($task !== null ? ' <strong>빌드업은 모든 역할이 봅니다.</strong> 회의록의 민감한 내용은 이름·설명에서 빼세요.' : '') : null,
]) ?>

<?php if ($task !== null): ?>
    <div class="alert alert-info narrow" role="status">회의 할 일 <strong><?= e($task['item']) ?></strong> <span class="muted">(<?= e($task['service']) ?>)</span> 을(를) 빌드업에 올립니다. 올린 뒤에는 할 일 상태가 빌드업 상태를 따라갑니다.</div>
<?php endif ?>
<?php if ($meeting !== null && $decisionLines !== [] && $task === null): ?>
    <section class="card">
        <h2 class="card-title">결정 사항에서 고르기</h2>
        <ul class="decision-picks">
            <?php foreach ($decisionLines as $i => $line): ?>
                <li><a href="/meetings/<?= (int) $meeting['id'] ?>/buildup/new?line=<?= $i ?>"><?= e($line) ?></a></li>
            <?php endforeach ?>
        </ul>
        <p class="muted small">누르면 이름에 채워집니다.</p>
    </section>
<?php endif ?>

<section class="card">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($task !== null): ?><input type="hidden" name="meeting_task_id" value="<?= $task['id'] ?>"><?php endif ?>
        <label>
            <span>이름</span>
            <input type="text" name="title" value="<?= e($values['title']) ?>" maxlength="200" required>
            <?= $error('title') ?>
        </label>
        <div class="form-row">
            <label>
                <span>서비스</span>
                <select name="system_id" required>
                    <option value="">선택</option>
                    <?php foreach ($systems as $s): ?>
                        <option value="<?= $s['id'] ?>"<?= $values['system_id'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('system_id') ?>
            </label>
            <label>
                <span>앱 <span class="muted small">(앱 단위일 때)</span></span>
                <select name="app_id">
                    <option value="">서비스 전체</option>
                    <?php foreach ($apps as $a): ?>
                        <option value="<?= $a['id'] ?>"<?= $values['app_id'] === $a['id'] ? ' selected' : '' ?>><?= e(($systemNames[$a['system_id']] ?? '') . ' · ' . $a['name'] . ' ' . (AppRegistry::PLATFORMS[$a['platform']] ?? $a['platform'])) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('app_id') ?>
            </label>
        </div>
        <div class="form-row form-row-3">
            <?php if ($isNew): ?>
                <label>
                    <span>상태</span>
                    <select name="status">
                        <?php foreach (FeatureRepository::INITIAL as $s): ?>
                            <option value="<?= e($s) ?>"<?= $values['status'] === $s ? ' selected' : '' ?>><?= e(FeatureRepository::statusLabel($s)) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= $error('status') ?>
                </label>
            <?php endif ?>
            <label>
                <span>목표 버전</span>
                <input type="text" name="target_version" value="<?= e($values['target_version']) ?>" maxlength="30" placeholder="1.4.0">
                <?= $error('target_version') ?>
            </label>
            <label>
                <span>담당자</span>
                <select name="assignee_user_id">
                    <option value="">없음</option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= $u['id'] ?>"<?= $values['assignee_user_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('assignee_user_id') ?>
            </label>
        </div>
        <label>
            <span>설명</span>
            <textarea name="description" rows="6" maxlength="5000"><?= e($values['description']) ?></textarea>
            <span class="muted small">모든 역할이 봅니다. 회의록의 민감한 내용은 옮기지 마세요.</span>
            <?= $error('description') ?>
        </label>
        <div class="form-actions">
            <?php if (!$isNew): ?><span class="muted small">상태는 상세 화면에서 바꿉니다.</span><?php endif ?>
            <a class="btn" href="<?= e($cancel) ?>">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>
