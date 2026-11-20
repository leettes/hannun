<?php
use App\Meetings\MeetingRepository;

/**
 * @var array<string, mixed> $meeting @var string $bodyHtml @var string $decisionsHtml
 * @var bool $isAuthor @var ?string $flash @var string $csrfToken
 * @var list<array<string, mixed>> $features 이 회의에서 등록한 기능 @var list<string> $decisionLines @var bool $canAddFeature
 */
$id = (int) $meeting['id'];
$deleted = $meeting['deleted_at'] !== null;
$meta = [
    e(meeting_time((string) $meeting['held_at'])),
    e((string) $meeting['author_name']) . ' 작성',
    e(MeetingRepository::VISIBILITY[$meeting['visibility']] ?? (string) $meeting['visibility']) . ' 공개',
];
if ($meeting['system_name'] !== null) {
    array_splice($meta, 1, 0, ['<a href="/systems/' . e((string) $meeting['system_code']) . '">' . e((string) $meeting['system_name']) . '</a>']);
}
$aside = '';
if ($isAuthor && !$deleted) {
    $aside = '<a class="btn" href="/meetings/' . $id . '/edit">수정</a> '
        . '<button class="btn" type="submit" form="meeting-delete">휴지통으로</button>';
}
?>
<?= $this->partial('partials/page-head', [
    'crumb' => $deleted ? '휴지통' : '회의록',
    'crumbHref' => $deleted ? '/meetings/trash' : '/meetings',
    'heading' => e((string) $meeting['title']),
    'sub' => implode(' · ', $meta),
    'aside' => $aside,
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>
<?php if ($deleted): ?>
    <div class="alert alert-error" role="status">
        휴지통에 있는 회의록입니다 (<?= e((string) $meeting['deleted_at']) ?> 삭제). 작성자에게만 보입니다.
        <form method="post" action="/meetings/<?= $id ?>/restore" class="inline-form">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn-small" type="submit">되살리기</button>
        </form>
    </div>
<?php endif ?>

<section class="section">
    <div class="section-head"><h2>참석자</h2></div>
    <div class="card">
        <?php if ($meeting['attendees'] === []): ?>
            <span class="muted">지정한 참석자가 없습니다.</span>
        <?php else: ?>
            <?= implode(', ', array_map(static fn (array $a): string => e($a['name']), $meeting['attendees'])) ?>
        <?php endif ?>
    </div>
</section>

<section class="section">
    <div class="section-head"><h2>결정 사항</h2></div>
    <div class="card md-body">
        <?= trim((string) $meeting['decisions']) === '' ? '<span class="muted">적은 결정 사항이 없습니다.</span>' : $decisionsHtml /* Markdown::render 가 이스케이프 */ ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2>이 회의에서 등록한 기능</h2>
        <?php if ($canAddFeature): ?>
            <span class="section-head-aside"><a href="/meetings/<?= $id ?>/features/new">결정 사항을 기능으로 등록</a></span>
        <?php endif ?>
    </div>
    <div class="card card-flush">
        <?= $this->partial('partials/feature-list', ['features' => $features, 'showSystem' => true, 'empty' => $decisionLines === [] ? '등록한 기능이 없습니다.' : '등록한 기능이 없습니다. 결정 사항 ' . count($decisionLines) . '개를 기능으로 등록할 수 있습니다.']) ?>
    </div>
</section>

<section class="section">
    <div class="section-head"><h2>본문</h2></div>
    <div class="card md-body">
        <?= trim((string) $meeting['body']) === '' ? '<span class="muted">본문이 없습니다.</span>' : $bodyHtml /* Markdown::render 가 이스케이프 */ ?>
    </div>
    <p class="muted small">작성 <?= e((string) $meeting['created_at']) ?><?= $meeting['updated_at'] !== $meeting['created_at'] ? ' · 수정 ' . e((string) $meeting['updated_at']) : '' ?></p>
</section>

<?php if ($isAuthor && !$deleted): ?>
    <form id="meeting-delete" method="post" action="/meetings/<?= $id ?>/delete" hidden>
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
    </form>
<?php endif ?>
