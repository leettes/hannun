<?php
use App\Features\FeatureRepository;
use App\Monitoring\AppRegistry;

/**
 * @var array<string, mixed> $feature @var list<array<string, mixed>> $events @var list<string> $next
 * @var bool $canEdit @var ?string $meetingLink @var array<string, string> $errors @var array<string, string> $old
 * @var ?string $flash @var string $csrfToken
 */
$f = $feature;
$id = (int) $f['id'];
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$eventLabel = static fn (array $ev): string => match ($ev['type']) {
    'created' => '등록 (' . FeatureRepository::statusLabel((string) $ev['to_value']) . ')',
    'status' => FeatureRepository::statusLabel((string) $ev['from_value']) . ' → ' . FeatureRepository::statusLabel((string) $ev['to_value']),
    'edit' => '수정',
    'note' => '메모',
    default => (string) $ev['type'],
};
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '빌드업',
    'crumbHref' => '/buildup',
    'heading' => e((string) $f['title']),
    'sub' => e((string) $f['system_name']) . ($f['app_name'] !== null ? ' · ' . e($f['app_name'] . ' ' . (AppRegistry::PLATFORMS[$f['app_platform']] ?? '')) : ''),
    'aside' => '<span class="fstatus fstatus-' . e((string) $f['status']) . '">' . e(FeatureRepository::statusLabel((string) $f['status'])) . '</span>'
        . ($canEdit ? ' <a class="btn" href="/buildup/' . $id . '/edit">수정</a> <a class="btn btn-danger-ghost" href="/buildup/' . $id . '/delete">삭제</a>' : ''),
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="detail-grid">
    <div class="detail-main">
        <section class="card">
            <h2 class="card-title">설명</h2>
            <?php if (trim((string) ($f['description'] ?? '')) === ''): ?>
                <p class="muted">설명이 없습니다.</p>
            <?php else: ?>
                <p class="pre-line"><?= nl2br(e((string) $f['description'])) ?></p>
            <?php endif ?>
        </section>

        <?php if ($canEdit): ?>
            <section class="card">
                <h2 class="card-title">상태 변경</h2>
                <?php if ($next === []): ?>
                    <p class="muted">바꿀 수 있는 상태가 없습니다.</p>
                <?php else: ?>
                    <form class="form" method="post" action="/buildup/<?= $id ?>/status">
                        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                        <div class="form-row">
                            <label>
                                <span>새 상태</span>
                                <select name="status">
                                    <?php foreach ($next as $s): ?>
                                        <option value="<?= e($s) ?>"<?= ($old['status'] ?? '') === $s ? ' selected' : '' ?>><?= e(FeatureRepository::statusLabel($s)) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <?= $error('status') ?>
                            </label>
                            <?php if (in_array('released', $next, true)): ?>
                                <label>
                                    <span>배포 버전 <span class="muted small">(배포됨일 때)</span></span>
                                    <input type="text" name="released_version" value="<?= e($old['released_version'] ?? (string) ($f['target_version'] ?? '')) ?>" maxlength="30" placeholder="1.4.0">
                                    <?= $error('released_version') ?>
                                </label>
                            <?php endif ?>
                        </div>
                        <label>
                            <span>메모 <span class="muted small">(선택)</span></span>
                            <input type="text" name="note" value="<?= e($old['note'] ?? '') ?>" maxlength="1000">
                            <?= $error('note') ?>
                        </label>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">상태 변경</button>
                        </div>
                    </form>
                <?php endif ?>
            </section>
        <?php endif ?>

        <section class="card" id="timeline">
            <h2 class="card-title">타임라인</h2>
            <ol class="timeline">
                <?php foreach ($events as $ev): ?>
                    <li class="tl-item tl-feature-<?= e((string) $ev['type']) ?>">
                        <div class="tl-head">
                            <strong><?= e($eventLabel($ev)) ?></strong>
                            <span class="muted small"><?= e((string) ($ev['user_name'] ?? '시스템')) ?> · <?= e(substr((string) $ev['created_at'], 0, 16)) ?></span>
                        </div>
                        <?php if ($ev['note'] !== null): ?>
                            <div class="tl-body"><?= $ev['type'] === 'edit' ? '바뀐 항목: ' . e((string) $ev['note']) : nl2br(e((string) $ev['note'])) ?></div>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
            <?php if ($canEdit): ?>
                <form class="form tl-note-form" method="post" action="/buildup/<?= $id ?>/notes">
                    <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                    <label>
                        <span>메모 남기기</span>
                        <textarea name="note" rows="2" maxlength="1000" required><?= e($old['memo'] ?? '') ?></textarea>
                        <?= $error('memo') ?>
                    </label>
                    <div class="form-actions">
                        <button class="btn" type="submit">메모 추가</button>
                    </div>
                </form>
            <?php endif ?>
        </section>
    </div>

    <aside class="card detail-side">
        <dl class="props">
            <dt>상태</dt><dd><?= e(FeatureRepository::statusLabel((string) $f['status'])) ?></dd>
            <dt>서비스</dt><dd><a class="plain" href="/systems/<?= e((string) $f['system_code']) ?>"><?= e((string) $f['system_name']) ?></a></dd>
            <dt>앱</dt><dd><?= $f['app_id'] === null ? '-' : '<a class="plain" href="/apps/' . (int) $f['app_id'] . '">' . e($f['app_name'] . ' ' . (AppRegistry::PLATFORMS[$f['app_platform']] ?? '')) . '</a>' ?></dd>
            <dt>목표 버전</dt><dd><?= e((string) ($f['target_version'] ?? '-')) ?></dd>
            <dt>배포</dt><dd><?= $f['released_at'] === null ? '-' : e('v' . $f['released_version'] . ' · ' . substr((string) $f['released_at'], 0, 10)) ?></dd>
            <dt>담당자</dt><dd><?= e((string) ($f['assignee_name'] ?? '-')) ?></dd>
            <dt>등록</dt><dd><?= e((string) ($f['creator_name'] ?? '-')) ?> · <?= e(substr((string) $f['created_at'], 0, 10)) ?></dd>
            <?php if ($f['meeting_id'] !== null): ?>
                <dt>출처</dt><dd><?= $meetingLink !== null ? '<a class="plain" href="' . e($meetingLink) . '">회의록 보기</a>' : '회의에서 등록됨' ?></dd>
            <?php endif ?>
            <?php if ($f['incident_id'] !== null): ?>
                <dt>관련 장애</dt><dd><a class="plain" href="/incidents/<?= (int) $f['incident_id'] ?>">#<?= (int) $f['incident_id'] ?></a></dd>
            <?php endif ?>
        </dl>
    </aside>
</div>
