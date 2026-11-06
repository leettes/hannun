<?php
use App\Monitoring\Incident;

/**
 * @var array $incident @var array $seen @var array $values @var string $note
 * @var array<int, string> $assignees @var ?string $flash @var ?string $error
 */
$i = $incident;
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '장애 센터',
    'crumbHref' => '/incidents',
    'heading' => '<span class="sev sev-' . e($i['severity']) . '">' . e(severity_label($i['severity'])) . '</span> ' . e($i['title']),
    'sub' => '#' . (int) $i['id'] . ' · <a class="plain" href="/systems/' . e($i['system_code']) . '">' . e($i['system_name']) . '</a> · ' . e($i['resource']),
    'aside' => '<span class="istatus istatus-' . e($i['status']) . '">' . e(incident_status_label($i['status'])) . '</span>',
]) ?>

<div class="detail-grid">
    <div class="detail-main">
        <section class="card">
            <h2 class="card-title">타임라인</h2>
            <ol class="timeline">
                <?php foreach (array_reverse($i['events']) as $ev): ?>
                    <li class="tl-item tl-<?= e($ev['type']) ?>">
                        <div class="tl-head">
                            <strong><?= e(Incident::EVENT_LABELS[$ev['type']] ?? $ev['type']) ?></strong>
                            <span class="muted small"><?= e($ev['user'] ?? '시스템') ?> · <?= e(fmt_time($ev['at'], 'm-d H:i:s')) ?></span>
                        </div>
                        <?php if ($ev['message'] !== null): ?>
                            <div class="tl-body"><?= e($ev['message']) ?></div>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>

        <section class="card">
            <h2 class="card-title">조치 기록</h2>
            <?php if ($flash !== null): ?>
                <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
            <?php endif ?>
            <?php if ($error !== null): ?>
                <div class="alert alert-error flash" role="alert"><?= e($error) ?></div>
            <?php endif ?>
            <?php if (isset($currentUser) && $currentUser->can('operator')): ?>
                <form class="form" method="post" action="/incidents/<?= (int) $i['id'] ?>">
                    <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
                    <input type="hidden" name="seen_status" value="<?= e($seen['status']) ?>">
                    <input type="hidden" name="seen_assignee" value="<?= e($seen['assignee_user_id'] ?? '') ?>">
                    <input type="hidden" name="seen_root_cause" value="<?= e($seen['root_cause'] ?? '') ?>">
                    <input type="hidden" name="seen_action_taken" value="<?= e($seen['action_taken'] ?? '') ?>">
                    <div class="form-row">
                        <label>
                            <span>상태</span>
                            <select name="status">
                                <?php foreach ([$seen['status'], ...Incident::TRANSITIONS[$seen['status']] ?? []] as $key): ?>
                                    <option value="<?= e($key) ?>"<?= $key === $values['status'] ? ' selected' : '' ?>>
                                        <?= e($key === $seen['status'] ? incident_status_label($key) . ' (현재)' : ($key === 'open' ? '다시 열기' : incident_status_label($key))) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </label>
                        <label>
                            <span>담당자</span>
                            <select name="assignee">
                                <option value="">미지정</option>
                                <?php foreach ($assignees as $userId => $name): ?>
                                    <option value="<?= (int) $userId ?>"<?= (string) $userId === (string) $values['assignee_user_id'] ? ' selected' : '' ?>><?= e($name) ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                    </div>
                    <label>
                        <span>원인</span>
                        <input type="text" name="root_cause" value="<?= e($values['root_cause'] ?? '') ?>" placeholder="확인된 원인">
                    </label>
                    <label>
                        <span>조치 내용 <span class="muted small">(복구 처리 시 필수)</span></span>
                        <textarea name="action_taken" rows="3" placeholder="복구를 위해 한 조치"><?= e($values['action_taken'] ?? '') ?></textarea>
                    </label>
                    <label>
                        <span>메모 <span class="muted small">(타임라인에 추가)</span></span>
                        <textarea name="note" rows="2" placeholder="진행 상황이나 공유할 내용"><?= e($note) ?></textarea>
                    </label>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">저장</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted">조회 권한 계정은 조치 기록을 남길 수 없습니다.</p>
            <?php endif ?>
        </section>
    </div>

    <aside class="card detail-side">
        <dl class="props">
            <dt>상태</dt><dd><?= e(incident_status_label($i['status'])) ?></dd>
            <dt>심각도</dt><dd><?= e(severity_label($i['severity'])) ?></dd>
            <dt>서비스</dt><dd><a class="plain" href="/systems/<?= e($i['system_code']) ?>"><?= e($i['system_name']) ?></a></dd>
            <dt>리소스</dt><dd class="break"><?= e($i['resource']) ?></dd>
            <dt>감지</dt><dd><?= e(Incident::SOURCE_LABELS[$i['source']] ?? $i['source']) ?></dd>
            <dt>발생</dt><dd><?= e(fmt_time($i['started_at'], 'Y-m-d H:i:s')) ?></dd>
            <dt>확인</dt><dd><?= e(fmt_time($i['acknowledged_at'], 'Y-m-d H:i:s')) ?></dd>
            <dt>복구</dt><dd><?= e(fmt_time($i['resolved_at'], 'Y-m-d H:i:s')) ?></dd>
            <dt>지속 시간</dt><dd><?= e(duration($i['started_at'], $i['resolved_at'])) ?><?= $i['resolved_at'] === null ? ' (진행 중)' : '' ?></dd>
            <dt>담당자</dt><dd><?= e($i['assignee'] ?? '미지정') ?></dd>
            <dt>원인</dt><dd><?= e($i['root_cause'] ?? '-') ?></dd>
            <dt>조치</dt><dd><?= e($i['action_taken'] ?? '-') ?></dd>
        </dl>
    </aside>
</div>
