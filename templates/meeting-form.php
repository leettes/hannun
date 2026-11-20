<?php
use App\Meetings\MeetingRepository;

/**
 * @var ?array<string, mixed> $meeting 수정 대상 (새로 쓰면 null)
 * @var array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
 * @var array<string, string> $errors
 * @var list<array{id: int, name: string}> $systems @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var string $csrfToken @var App\Auth\User $currentUser
 */
$isNew = $meeting === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $isNew ? '회의록' : e((string) $meeting['title']),
    'crumbHref' => $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'],
    'heading' => $isNew ? '회의록 작성' : '회의록 수정',
    'sub' => '본문과 결정 사항은 암호화해 저장합니다. 제목은 목록·검색에 쓰이므로 민감한 내용은 본문에 쓰세요.',
]) ?>

<section class="card">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <label>
            <span>제목</span>
            <input type="text" name="title" value="<?= e($values['title']) ?>" maxlength="200" required>
            <span class="muted small">목록에 그대로 보입니다. 금액·인명 등 민감한 내용은 넣지 마세요.</span>
            <?= $error('title') ?>
        </label>
        <div class="form-row form-row-3">
            <label>
                <span>회의 일시</span>
                <input type="datetime-local" name="held_at" value="<?= e($values['held_at']) ?>" required>
                <?= $error('held_at') ?>
            </label>
            <label>
                <span>관련 서비스</span>
                <select name="system_id">
                    <option value="">없음</option>
                    <?php foreach ($systems as $s): ?>
                        <option value="<?= $s['id'] ?>"<?= $values['system_id'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('system_id') ?>
            </label>
            <label>
                <span>공개 범위</span>
                <select name="visibility" data-visibility-original="<?= e($isNew ? '' : (string) $meeting['visibility']) ?>">
                    <?php foreach (MeetingRepository::VISIBILITY as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['visibility'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('visibility') ?>
            </label>
        </div>
        <fieldset class="field">
            <span>참석자</span>
            <div class="check-grid">
                <?php foreach ($staff as $u): ?>
                    <label class="check">
                        <input type="checkbox" name="attendees[]" value="<?= $u['id'] ?>"<?= in_array($u['id'], $values['attendees'], true) ? ' checked' : '' ?>>
                        <?= e($u['name']) ?><?= $u['meeting_access'] === 'none' ? ' <span class="muted small">(회의록 권한 없음)</span>' : '' ?>
                    </label>
                <?php endforeach ?>
            </div>
            <span class="muted small">'참석자만' 공개일 때 여기 고른 직원과 작성자가 봅니다. 회의록 권한이 없는 직원은 참석자여도 못 봅니다. 외부 참석자는 본문에 적으세요.</span>
            <?= $error('attendees') ?>
        </fieldset>
        <label>
            <span>본문</span>
            <textarea name="body" rows="16" class="mono-input"><?= e($values['body']) ?></textarea>
            <span class="muted small">마크다운: # 제목, - 목록, - [ ] 할 일, **굵게**, `코드`, [글](https://주소)</span>
            <?= $error('body') ?>
        </label>
        <label>
            <span>결정 사항</span>
            <textarea name="decisions" rows="6" class="mono-input"><?= e($values['decisions']) ?></textarea>
            <span class="muted small">한 줄에 하나씩 (- 로 시작). 3단계에서 결정 사항을 기능으로 등록할 수 있게 됩니다.</span>
            <?= $error('decisions') ?>
        </label>
        <?php if (!$isNew): ?>
            <label>
                <span>비밀번호 확인 <span class="muted small">(공개 범위를 바꿀 때만)</span></span>
                <input type="text" name="username" value="<?= e($currentUser->email) ?>" autocomplete="username" hidden>
                <input type="password" name="password" autocomplete="current-password">
                <?= $error('password') ?>
            </label>
        <?php endif ?>
        <div class="form-actions">
            <a class="btn" href="<?= $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'] ?>">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '저장' : '수정 저장' ?></button>
        </div>
    </form>
</section>
