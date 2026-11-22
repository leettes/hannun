<?php
use App\Meetings\MeetingTaskRepository;

/**
 * 할 일 담당·상태 칸 (표·회의 상세 할 일 블록). 쓸 수 있으면 고르는 즉시 저장
 *
 * @var array<string, mixed> $t @var bool $canWrite @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var string $back @var string $csrfToken @var string $part 'person' | 'status' | 'promote'
 */
$id = (int) $t['id'];
?>
<?php if ($part === 'promote'): ?>
    <?php if (($t['buildup_id'] ?? null) === null && $canWrite && isset($currentUser) && $currentUser->can('operator')): ?>
        <a class="task-promote" href="/meetings/<?= (int) $t['meeting_id'] ?>/buildup/new?task=<?= $id ?>">빌드업에 올리기</a>
    <?php endif ?>
    <?php return ?>
<?php endif ?>
<?php if ($part === 'status' && ($t['buildup_id'] ?? null) !== null): ?>
    <a class="fstatus fstatus-<?= e((string) $t['buildup_status']) ?>" href="/buildup/<?= (int) $t['buildup_id'] ?>" title="빌드업에서 상태를 바꿉니다">빌드업 · <?= e(App\Features\FeatureRepository::statusLabel((string) $t['buildup_status'])) ?></a>
    <?php return ?>
<?php endif ?>
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
