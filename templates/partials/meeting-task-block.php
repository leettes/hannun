<?php
/**
 * 회의 상세 정리본의 '할 일' 섹션 (시안-상세 taskBlock)
 *
 * @var array<string, mixed> $meeting @var list<array<string, mixed>> $tasks @var bool $canWrite
 * @var list<string> $services @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var int $decisionCount 아직 할 일로 등록하지 않은 결정 사항 수 @var string $csrfToken
 */
$id = (int) $meeting['id'];
$back = '/meetings/' . $id . '#tasks';
$serviceSelect = static function (string $name, string $selected) use ($services): string {
    $html = '<select name="' . e($name) . '" aria-label="서비스">';
    foreach ($services as $s) {
        $html .= '<option value="' . e($s) . '"' . ($s === $selected ? ' selected' : '') . '>' . e($s) . '</option>';
    }

    return $html . '</select>';
};
?>
<div id="tasks"></div>
<?php if ($tasks === [] && $decisionCount > 0 && $canWrite): ?>
    <div class="task-block">
        <form class="task-add" method="post" action="/meetings/<?= $id ?>/tasks/from-decisions">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <span class="small" style="flex:1;align-self:center">결정 사항 <?= $decisionCount ?>건을 할 일로 등록합니다. 서비스는 등록 후 하나씩 바꿀 수 있습니다.</span>
            <?= $serviceSelect('service', '공통·신규') ?>
            <button class="btn btn-small btn-primary" type="submit">할 일로 등록</button>
        </form>
    </div>
    <div class="md-body" style="margin-top:12px"><?= App\Meetings\Markdown::render((string) $meeting['decisions']) ?></div>
<?php else: ?>
    <div class="task-block">
        <?php foreach ($tasks as $t): ?>
            <div class="task-row">
                <span class="t-svc"><span class="<?= e(service_class((string) $t['service'])) ?>"><?= e((string) $t['service']) ?></span></span>
                <div>
                    <div class="t-item"><a href="/meetings/tasks/<?= (int) $t['id'] ?>"><?= e((string) $t['item']) ?></a></div>
                    <div class="t-do"><?= e((string) $t['task']) ?></div>
                    <?php if (($t['note'] ?? '') !== ''): ?><div class="task-note"><?= e((string) $t['note']) ?></div><?php endif ?>
                    <?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $back, 'part' => 'promote', 'csrfToken' => $csrfToken]) ?>
                </div>
                <span class="t-who"><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $back, 'part' => 'person', 'csrfToken' => $csrfToken]) ?></span>
                <span><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $back, 'part' => 'status', 'csrfToken' => $csrfToken]) ?></span>
            </div>
        <?php endforeach ?>
        <?php if ($canWrite): ?>
            <form class="task-add" method="post" action="/meetings/<?= $id ?>/tasks">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <?= $serviceSelect('service', $tasks === [] ? '공통·신규' : (string) end($tasks)['service']) ?>
                <input type="text" name="task" maxlength="500" placeholder="할 일 추가 (예: 코이 미션: 완료율 쿼리 작성)" required>
                <button class="btn btn-small" type="submit">추가</button>
            </form>
        <?php endif ?>
    </div>
    <p class="muted small" style="margin:8px 0 0"><?= $tasks === [] ? '이 회의에서 나온 할 일을 추가하세요.' : '상태·담당을 바꾸면 회의록 메인의 할 일 목록에 바로 반영됩니다. 항목을 누르면 타임라인과 수정.' ?></p>
<?php endif ?>
