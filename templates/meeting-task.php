<?php
use App\Meetings\MeetingTaskRepository;

/**
 * 할 일 상세: 내용, 수정, 타임라인
 *
 * @var array<string, mixed> $task @var list<array<string, mixed>> $events @var bool $canWrite
 * @var list<string> $services @var list<App\Auth\User> $staff
 * @var array<string, string> $errors @var array<string, string> $old @var ?string $flash @var string $csrfToken
 */
$t = $task;
$id = (int) $t['id'];
$value = static fn (string $key) => $old[$key] ?? (string) ($t[$key] ?? '');
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$staffOptions = array_map(static fn (App\Auth\User $u): array => ['id' => $u->id, 'name' => $u->name, 'meeting_access' => $u->meetingAccess], $staff);
$eventLabel = static fn (array $ev): string => match ($ev['type']) {
    'created' => '등록',
    'status' => (MeetingTaskRepository::STATUSES[$ev['from_value']] ?? $ev['from_value']) . ' → ' . (MeetingTaskRepository::STATUSES[$ev['to_value']] ?? $ev['to_value']),
    'assignee' => '담당 ' . ($ev['from_value'] ?? '미지정') . ' → ' . ($ev['to_value'] ?? '미지정'),
    'edit' => '수정',
    'note' => '메모',
    default => (string) $ev['type'],
};
$back = '/meetings/tasks/' . $id;
?>
<?= $this->partial('partials/page-head', [
    'crumb' => date('m/d', (int) strtotime(substr((string) $t['meeting_held_at'], 0, 10))) . ' 회의 · ' . e((string) $t['meeting_title']),
    'crumbHref' => '/meetings/' . (int) $t['meeting_id'] . '#tasks',
    'heading' => e((string) $t['item']),
    'sub' => '<span class="' . e(service_class((string) $t['service'])) . '">' . e((string) $t['service']) . '</span> ' . e((string) $t['task']),
    'aside' => '<span class="tstatus tstatus-' . e((string) $t['status']) . '">' . e(MeetingTaskRepository::STATUSES[$t['status']] ?? '') . '</span>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="detail-grid">
    <div class="detail-main">
        <?php if ($canWrite): ?>
            <section class="card">
                <h2 class="card-title">수정</h2>
                <form class="form" method="post" action="/meetings/tasks/<?= $id ?>">
                    <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                    <div class="form-row">
                        <label>
                            <span>서비스</span>
                            <input type="text" name="service" value="<?= e($value('service')) ?>" maxlength="30" list="task-services" required>
                            <datalist id="task-services"><?php foreach ($services as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
                            <?= $error('service') ?>
                        </label>
                        <label>
                            <span>항목</span>
                            <input type="text" name="item" value="<?= e($value('item')) ?>" maxlength="100" required>
                            <?= $error('item') ?>
                        </label>
                    </div>
                    <label>
                        <span>할 일</span>
                        <textarea name="task" rows="3" maxlength="1000" required><?= e($value('task')) ?></textarea>
                        <?= $error('task') ?>
                    </label>
                    <label>
                        <span>비고</span>
                        <textarea name="note" rows="2" maxlength="1000"><?= e($value('note')) ?></textarea>
                        <?= $error('note') ?>
                    </label>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">저장</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="card">
                <h2 class="card-title">내용</h2>
                <p><?= nl2br(e((string) $t['task'])) ?></p>
                <?php if (($t['note'] ?? '') !== ''): ?><p class="muted"><?= nl2br(e((string) $t['note'])) ?></p><?php endif ?>
            </section>
        <?php endif ?>

        <section class="card" id="timeline">
            <h2 class="card-title">타임라인</h2>
            <ol class="timeline">
                <?php foreach ($events as $ev): ?>
                    <li class="tl-item <?= $ev['type'] === 'created' ? 'tl-opened' : 'tl-note' ?>">
                        <div class="tl-head"><strong><?= e($eventLabel($ev)) ?></strong> <span class="muted small"><?= e((string) ($ev['user_name'] ?? '시스템')) ?> · <?= e(substr((string) $ev['created_at'], 5, 11)) ?></span></div>
                        <?php if ($ev['note_text'] !== null): ?><div class="tl-body"><?= $ev['type'] === 'edit' ? '바뀐 항목: ' . e($ev['note_text']) : nl2br(e($ev['note_text'])) ?></div><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
            <?php if ($canWrite): ?>
                <form class="form tl-note-form" method="post" action="/meetings/tasks/<?= $id ?>/notes">
                    <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                    <label>
                        <span>메모 남기기 <span class="muted small">(암호화 저장)</span></span>
                        <textarea name="memo" rows="2" maxlength="1000" required></textarea>
                        <?= $error('memo') ?>
                    </label>
                    <div class="form-actions"><button class="btn" type="submit">메모 추가</button></div>
                </form>
            <?php endif ?>
        </section>
    </div>

    <aside class="card detail-side">
        <dl class="props">
            <dt>상태</dt><dd><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staffOptions, 'back' => $back, 'part' => 'status']) ?><?= $error('status') ?></dd>
            <dt>담당</dt><dd><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staffOptions, 'back' => $back, 'part' => 'person']) ?><?= $error('assignee') ?>
                <?php if ($t['assignee_label'] !== null): ?><div class="muted small">회의에서: <?= e((string) $t['assignee_label']) ?></div><?php endif ?></dd>
            <dt>회의</dt><dd><a class="plain" href="/meetings/<?= (int) $t['meeting_id'] ?>#tasks"><?= e(meeting_time((string) $t['meeting_held_at'])) ?></a></dd>
            <dt>완료</dt><dd><?= e((string) ($t['done_at'] ?? '-')) ?></dd>
            <dt>등록</dt><dd><?= e(substr((string) $t['created_at'], 0, 16)) ?></dd>
        </dl>
    </aside>
</div>
