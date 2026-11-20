<?php
use App\Meetings\MeetingRepository;

/**
 * @var ?array<string, mixed> $meeting 수정 대상 (새로 쓰면 null)
 * @var array<string, mixed> $values @var array<string, string> $errors
 * @var list<array{id: int, name: string}> $systems @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var bool $reauthed 최근에 본인 확인함 (공개 범위 변경 가능) @var string $csrfToken
 */
$isNew = $meeting === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$canChangeVisibility = $isNew || $reauthed;
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $isNew ? '회의록' : e((string) $meeting['title']),
    'crumbHref' => $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'],
    'heading' => $isNew ? '회의록 작성' : '회의록 수정',
    'sub' => '본문·결정 사항·요약은 암호화해 저장합니다. 제목·주제·태그는 목록과 검색에 쓰이므로 민감한 내용은 본문에 쓰세요.' . ($isNew ? ' 녹취 원문이 있으면 <a href="/meetings/new/transcript">녹취 텍스트로 작성</a>.' : ''),
]) ?>

<section class="card">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="form-row">
            <label>
                <span>제목</span>
                <input type="text" name="title" value="<?= e($values['title']) ?>" maxlength="200" required placeholder="11/20 회의록">
                <?= $error('title') ?>
            </label>
            <label>
                <span>주제 <span class="muted small">(카드·제목 옆에 보이는 한 줄)</span></span>
                <input type="text" name="subtitle" value="<?= e($values['subtitle']) ?>" maxlength="200" placeholder="코이 미션 · 민 커넥트 개편 초안">
                <?= $error('subtitle') ?>
            </label>
        </div>
        <div class="form-row form-row-3">
            <label>
                <span>회의 일시</span>
                <input type="datetime-local" name="held_at" value="<?= e($values['held_at']) ?>" required>
                <?= $error('held_at') ?>
            </label>
            <label>
                <span>회의 시간 <span class="muted small">(분)</span></span>
                <input type="number" name="duration_minutes" value="<?= e($values['duration_minutes']) ?>" min="1" max="1440" placeholder="60">
                <?= $error('duration_minutes') ?>
            </label>
            <label id="visibility">
                <span>공개 범위</span>
                <?php if ($canChangeVisibility): ?>
                    <select name="visibility">
                        <?php foreach (MeetingRepository::VISIBILITY as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $values['visibility'] ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if (!$isNew): ?><span class="muted small">본인 확인됨. 바꾸면 감사 로그에 남습니다.</span><?php endif ?>
                <?php else: ?>
                    <input type="hidden" name="visibility" value="<?= e((string) $meeting['visibility']) ?>">
                    <span><?= e(MeetingRepository::VISIBILITY[$meeting['visibility']] ?? '') ?> · <a href="/meetings/reauth?<?= e(http_build_query(['next' => '/meetings/' . (int) $meeting['id'] . '/edit#visibility', 'action' => '공개 범위 변경'])) ?>">바꾸기 (본인 확인)</a></span>
                <?php endif ?>
                <?= $error('visibility') ?>
            </label>
        </div>
        <div class="form-row">
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
                <span>태그 <span class="muted small">(쉼표로 구분)</span></span>
                <input type="text" name="topics" value="<?= e($values['topics']) ?>" maxlength="300" placeholder="코이, 민 커넥트, 앱 리뷰">
                <?= $error('topics') ?>
            </label>
        </div>
        <fieldset class="field">
            <span>참석자 (직원)</span>
            <div class="check-grid">
                <?php foreach ($staff as $u): ?>
                    <label class="check">
                        <input type="checkbox" name="attendees[]" value="<?= $u['id'] ?>"<?= in_array($u['id'], $values['attendees'], true) ? ' checked' : '' ?>>
                        <?= e($u['name']) ?><?= $u['meeting_access'] === 'none' ? ' <span class="muted small">(회의록 권한 없음)</span>' : '' ?>
                    </label>
                <?php endforeach ?>
            </div>
            <span class="muted small">'참석자만' 공개일 때 여기 고른 직원과 작성자가 봅니다. 녹취의 '참석자 N' 은 회의 상세에서 계정과 연결합니다.</span>
            <?= $error('attendees') ?>
        </fieldset>
        <label>
            <span>요약 <span class="muted small">(카드에 보임. 비우면 본문 첫 문단)</span></span>
            <textarea name="summary" rows="3" maxlength="2000"><?= e($values['summary']) ?></textarea>
            <?= $error('summary') ?>
        </label>
        <label>
            <span>정리본</span>
            <textarea name="body" rows="18" class="mono-input"><?= e($values['body']) ?></textarea>
            <span class="muted small">마크다운: ## 섹션, ### 소제목, - 목록 (4칸 들여쓰기로 하위), **결론:** 강조, (불명확), 참석자 N</span>
            <?= $error('body') ?>
        </label>
        <label>
            <span>확인 / 할 일</span>
            <textarea name="decisions" rows="6" class="mono-input"><?= e($values['decisions']) ?></textarea>
            <span class="muted small">'- [ ] 항목: 할 일 (참석자 N)' 한 줄에 하나. 저장 후 회의 상세에서 할 일로 등록합니다.</span>
            <?= $error('decisions') ?>
        </label>
        <div class="form-actions">
            <a class="btn" href="<?= $isNew ? '/meetings' : '/meetings/' . (int) $meeting['id'] ?>">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '저장' : '수정 저장' ?></button>
        </div>
    </form>
</section>
