<?php
use App\Meetings\MeetingRepository;

/** @var array<string, string> $values @var array<string, string> $errors @var string $csrfToken */
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '회의록',
    'crumbHref' => '/meetings',
    'heading' => '녹취 텍스트로 작성',
    'sub' => '음성 인식 녹취를 붙여 넣으면 발언자(참석자 N)와 회의 시간을 읽어 회의록을 만듭니다. 녹취와 정리본은 암호화해 저장하고, 외부 요약 서비스로 보내지 않습니다.',
]) ?>

<section class="card">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="/meetings/transcript">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="form-row form-row-3">
            <label>
                <span>제목 <span class="muted small">(비우면 정리본의 # 제목)</span></span>
                <input type="text" name="title" value="<?= e($values['title']) ?>" maxlength="200">
                <?= $error('title') ?>
            </label>
            <label>
                <span>회의 일시 <span class="muted small">(비우면 정리본의 날짜)</span></span>
                <input type="datetime-local" name="held_at" value="<?= e($values['held_at']) ?>">
                <?= $error('held_at') ?>
            </label>
            <label>
                <span>공개 범위</span>
                <select name="visibility">
                    <?php foreach (MeetingRepository::VISIBILITY as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['visibility'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('visibility') ?>
            </label>
        </div>
        <label>
            <span>녹취 원문</span>
            <textarea name="transcript" rows="14" class="mono-input" required placeholder="참석자 1 00:05&#10;말한 내용&#10;&#10;참석자 2 00:12&#10;말한 내용"><?= e($values['transcript']) ?></textarea>
            <span class="muted small">'참석자 1 00:05' 처럼 발언자와 시각이 한 줄에 있는 형식 (클로바노트 등 내보내기)</span>
            <?= $error('transcript') ?>
        </label>
        <label>
            <span>녹취 파일 이름 <span class="muted small">(선택, 원본 표시용)</span></span>
            <input type="text" name="transcript_name" value="<?= e($values['transcript_name']) ?>" maxlength="100" placeholder="1120_회의록.txt">
        </label>
        <label>
            <span>정리본 <span class="muted small">(선택. # 제목, 날짜 줄, ## 섹션, ## 확인 / 할 일)</span></span>
            <textarea name="document" rows="12" class="mono-input"><?= e($values['document']) ?></textarea>
            <span class="muted small">정리본은 나중에 수정 화면에서 써도 됩니다. '확인 / 할 일' 체크리스트는 회의 상세에서 할 일로 등록합니다.</span>
        </label>
        <div class="form-actions">
            <a class="btn" href="/meetings">취소</a>
            <button class="btn btn-primary" type="submit">회의록 만들기</button>
        </div>
    </form>
</section>
