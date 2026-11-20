<?php
use App\Meetings\MeetingTaskRepository;

/**
 * 할 일 담당·상태 칸 (표·회의 상세 할 일 블록). 쓸 수 있으면 고르는 즉시 저장
 *
 * @var array<string, mixed> $t @var bool $canWrite @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var string $back @var string $csrfToken @var string $part 'person' | 'status'
 */
$id = (int) $t['id'];
?>
<?php if ($part === 'person'): ?>
    <?php if (!$canWrite): ?>
        <?= $t['assignee_name'] === null ? '<span class="muted">-</span>' : e((string) $t['assignee_name']) ?>
    <?php else: ?>
        <form class="inline-form" method="post" action="/meetings/tasks/<?= $id ?>/assignee">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <select name="assignee_user_id" class="person-select<?= $t['assignee_name'] !== null ? ' has-person' : '' ?>" data-autosubmit aria-label="담당">
                <option value=""><?= $t['assignee_name'] === null ? '+ 지정' : e((string) $t['assignee_name']) ?></option>
                <?php foreach ($staff as $u): ?>
                    <option value="<?= $u['id'] ?>"<?= $t['assignee_user_id'] !== null && (int) $t['assignee_user_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach ?>
            </select>
            <button class="btn btn-small js-hide" type="submit">저장</button>
        </form>
    <?php endif ?>
<?php else: ?>
    <?php if (!$canWrite): ?>
        <span class="tstatus tstatus-<?= e((string) $t['status']) ?>"><?= e(MeetingTaskRepository::STATUSES[$t['status']] ?? (string) $t['status']) ?></span>
    <?php else: ?>
        <form class="inline-form" method="post" action="/meetings/tasks/<?= $id ?>/status">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <select name="status" class="tstatus-select tstatus-<?= e((string) $t['status']) ?>" data-autosubmit aria-label="상태">
                <?php foreach (MeetingTaskRepository::STATUSES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $key === $t['status'] ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach ?>
            </select>
            <button class="btn btn-small js-hide" type="submit">저장</button>
        </form>
    <?php endif ?>
<?php endif ?>
