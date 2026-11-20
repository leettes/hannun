<?php
use App\Meetings\MeetingRepository;
use App\Security\AuditLog;

/**
 * 회의록 상세 (시안-상세.html)
 *
 * @var array<string, mixed> $meeting @var string $tab
 * @var array{html: string, toc: list<array{id: string, title: string, sub: list<array{id: string, title: string}>}>} $doc
 * @var list<array<string, mixed>> $tasks
 * @var ?array{speakers: list<string>, minutes: ?int, lines: list<array{speaker: string, time: string, text: string}>} $transcript
 * @var string $trQuery @var string $trWho
 * @var list<array<string, mixed>> $history @var list<array<string, mixed>> $recentLogs
 * @var bool $isAuthor @var list<array<string, mixed>> $features @var bool $canAddFeature
 * @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var int $idleSeconds @var bool $confirmDelete @var ?string $flash @var string $csrfToken
 */
$id = (int) $meeting['id'];
$deleted = $meeting['deleted_at'] !== null;
$date = substr((string) $meeting['held_at'], 0, 10) . ' (' . weekday_ko((string) $meeting['held_at']) . ')';
$time = substr((string) $meeting['held_at'], 11, 5);
$meta = [$date . ($time !== '00:00' ? ' ' . $time : '')];
if ($meeting['duration_minutes'] !== null) {
    $meta[] = '약 ' . (int) $meeting['duration_minutes'] . '분';
}
$meta[] = '참석 ' . count($meeting['participants']) . '명';
$meta[] = '할 일 ' . count($tasks) . '건';
$taskServices = array_values(array_unique(array_column($tasks, 'service')));
$tabUrl = static fn (string $t): string => '/meetings/' . $id . ($t === 'doc' ? '' : '?tab=' . $t);
$historyLabel = static function (array $log): string {
    $detail = json_decode((string) ($log['detail'] ?? ''), true) ?: [];

    return match ($log['action']) {
        'meeting.create' => '작성' . (($detail['source'] ?? '') === 'import' ? ' (파일 가져오기)' : (($detail['source'] ?? '') === 'transcript' ? ' (녹취로 작성)' : '')),
        'meeting.update' => isset($detail['version']) ? '정리본 수정 (버전 ' . (int) $detail['version'] . ')' : '정보 수정',
        'meeting.tasks' => '할 일 ' . (int) ($detail['count'] ?? 1) . '건 등록',
        default => AuditLog::actionLabel((string) $log['action']),
    };
};
$historyDetail = static function (array $log): string {
    $detail = json_decode((string) ($log['detail'] ?? ''), true) ?: [];
    $fieldNames = ['title' => '제목', 'subtitle' => '주제', 'held_at' => '일시', 'duration' => '시간', 'system' => '서비스', 'topics' => '태그', 'summary' => '요약', 'body' => '본문', 'decisions' => '결정 사항', 'attendees' => '참석자'];

    return match ($log['action']) {
        'meeting.update' => '바뀐 항목: ' . implode(', ', array_map(static fn (string $f): string => $fieldNames[$f] ?? $f, $detail['fields'] ?? [])) . ' (내용은 이력에 남기지 않음)',
        'meeting.visibility' => (MeetingRepository::VISIBILITY_SHORT[$detail['from'] ?? ''] ?? '') . ' → ' . (MeetingRepository::VISIBILITY_SHORT[$detail['to'] ?? ''] ?? ''),
        'meeting.create' => isset($detail['file']) ? (string) $detail['file'] : '',
        'meeting.tasks' => ($detail['source'] ?? '') === 'decisions' ? "'확인 / 할 일' 항목을 할 일로 등록" : '',
        default => '',
    };
};
$actions = '';
if (!$deleted) {
    $actions .= '<a class="btn" href="/meetings/' . $id . '/export">내보내기</a>';
    if ($isAuthor) {
        $actions .= ' <a class="btn" href="/meetings/reauth?' . e(http_build_query(['next' => '/meetings/' . $id . '/edit#visibility', 'action' => '공개 범위 변경'])) . '">공개 범위</a>'
            . ' <a class="btn btn-primary" href="/meetings/' . $id . '/edit">수정</a>';
    }
}
?>
<header class="page-head">
    <div>
        <a class="crumb" href="<?= $deleted ? '/meetings/trash' : '/meetings' ?>">← <?= $deleted ? '휴지통' : '회의록' ?></a>
        <h1><?= e((string) $meeting['title']) ?><?php if ($meeting['subtitle']): ?> <span class="muted" style="font-weight:500">· <?= e((string) $meeting['subtitle']) ?></span><?php endif ?></h1>
        <p class="page-sub"><?= e(implode(' · ', $meta)) ?></p>
        <?= $this->partial('partials/secure-chips', ['idleSeconds' => $idleSeconds, 'visibility' => (string) $meeting['visibility']]) ?>
    </div>
    <div class="head-actions"><?= $actions ?></div>
</header>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>
<?php if ($confirmDelete): ?>
    <div class="alert alert-error confirm-bar" role="alert">
        <span>본인 확인을 마쳤습니다. 이 회의록을 휴지통으로 옮길까요? 다른 사람에게는 보이지 않게 되고, 작성자는 휴지통에서 되살릴 수 있습니다.</span>
        <form class="inline-form" method="post" action="/meetings/<?= $id ?>/delete">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn-small btn-danger-ghost" type="submit">휴지통으로 이동</button>
        </form>
    </div>
<?php endif ?>
<?php if ($deleted): ?>
    <div class="alert alert-error confirm-bar" role="status">
        <span>휴지통에 있는 회의록입니다 (<?= e((string) $meeting['deleted_at']) ?> 삭제). 작성자에게만 보입니다.</span>
        <form class="inline-form" method="post" action="/meetings/<?= $id ?>/restore">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn-small" type="submit">되살리기</button>
        </form>
    </div>
<?php endif ?>

<div class="md-grid">
    <nav class="toc-wrap" aria-label="목차">
        <div class="toc">
            <p class="toc-title">목차</p>
            <ol>
                <?php foreach ($doc['toc'] as $t): ?>
                    <li><a href="<?= $tab === 'doc' ? '' : e($tabUrl('doc')) ?>#<?= e($t['id']) ?>"><?= e($t['title']) ?></a></li>
                    <?php foreach ($t['sub'] as $sub): ?>
                        <li class="toc-sub"><a href="<?= $tab === 'doc' ? '' : e($tabUrl('doc')) ?>#<?= e($sub['id']) ?>"><?= e($sub['title']) ?></a></li>
                    <?php endforeach ?>
                <?php endforeach ?>
            </ol>
        </div>
    </nav>

    <div style="min-width:0">
        <nav class="tabs doc-tabs" aria-label="회의록 보기">
            <a class="tab<?= $tab === 'doc' ? ' is-active' : '' ?>" href="<?= e($tabUrl('doc')) ?>">정리본</a>
            <a class="tab<?= $tab === 'transcript' ? ' is-active' : '' ?>" href="<?= e($tabUrl('transcript')) ?>">녹취 원문<?= $meeting['transcript_bytes'] === null ? ' <span class="tab-count">없음</span>' : '' ?></a>
            <a class="tab<?= $tab === 'history' ? ' is-active' : '' ?>" href="<?= e($tabUrl('history')) ?>">변경 이력 <span class="tab-count"><?= count($history) ?></span></a>
        </nav>

        <?php if ($tab === 'doc'): ?>
            <article class="card doc">
                <?php if (trim((string) $meeting['body']) === '' && $doc['toc'] === []): ?>
                    <p class="muted">정리본이 없습니다.<?= $isAuthor ? ' 수정에서 본문을 쓰거나 붙여 넣으세요.' : '' ?></p>
                <?php endif ?>
                <?= $doc['html'] /* MeetingDocument 가 이스케이프 */ ?>
            </article>
        <?php elseif ($tab === 'transcript'): ?>
            <section class="card doc transcript">
                <?php if ($transcript === null): ?>
                    <p class="muted">녹취 원문이 없습니다. '녹취 텍스트로 작성' 으로 만든 회의록이나 가져오기 때 녹취를 붙인 회의록에만 있습니다.</p>
                <?php else: ?>
                    <?php
                    $lines = array_values(array_filter($transcript['lines'], static fn (array $l): bool => ($trWho === '' || $l['speaker'] === $trWho)
                        && ($trQuery === '' || mb_stripos($l['text'], $trQuery) !== false)));
                    $mark = static fn (string $text): string => $trQuery === '' ? e($text) : (string) preg_replace('/(' . preg_quote(e($trQuery), '/') . ')/iu', '<mark>$1</mark>', e($text));
                    ?>
                    <div class="tr-tools">
                        <form method="get" action="/meetings/<?= $id ?>">
                            <input type="hidden" name="tab" value="transcript">
                            <input type="search" name="q" value="<?= e($trQuery) ?>" placeholder="녹취에서 찾기 (예: 미션, 리뷰)">
                            <select name="who" class="btn btn-small" data-autosubmit>
                                <option value="">모든 참석자</option>
                                <?php foreach ($transcript['speakers'] as $speaker): ?>
                                    <option value="<?= e($speaker) ?>"<?= $speaker === $trWho ? ' selected' : '' ?>><?= e($speaker) ?></option>
                                <?php endforeach ?>
                            </select>
                            <button class="btn btn-small" type="submit">찾기</button>
                        </form>
                        <span class="muted small"><?= count($lines) ?> / <?= count($transcript['lines']) ?>개 발언</span>
                    </div>
                    <?php foreach ($lines as $line): ?>
                        <div class="tr-line">
                            <span class="tr-speaker"><?= e($line['speaker']) ?></span>
                            <span class="tr-time"><?= e($line['time']) ?></span>
                            <span><?= nl2br($mark($line['text'])) ?></span>
                        </div>
                    <?php endforeach ?>
                <?php endif ?>
            </section>
        <?php else: ?>
            <section class="card">
                <?php if ($history === []): ?>
                    <p class="muted">기록이 없습니다.</p>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($history as $log): ?>
                            <li class="tl-item <?= $log['action'] === 'meeting.create' ? 'tl-opened' : 'tl-note' ?>">
                                <div class="tl-head"><strong><?= e($historyLabel($log)) ?></strong> <span class="muted small"><?= e((string) ($log['user_name'] ?? '시스템')) ?> · <?= e(substr((string) $log['created_at'], 5, 11)) ?></span></div>
                                <?php if (($text = $historyDetail($log)) !== ''): ?><div class="tl-body"><?= e($text) ?></div><?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ol>
                <?php endif ?>
            </section>
        <?php endif ?>

        <section class="section">
            <div class="section-head">
                <h2>이 회의에서 등록한 기능</h2>
                <?php if ($canAddFeature): ?>
                    <span class="section-head-aside"><a href="/meetings/<?= $id ?>/features/new">결정 사항을 기능으로 등록</a></span>
                <?php endif ?>
            </div>
            <div class="card card-flush">
                <?= $this->partial('partials/feature-list', ['features' => $features, 'showSystem' => true, 'empty' => '등록한 기능이 없습니다. 개발이 필요한 결정은 기능으로 등록해 배포까지 추적합니다.']) ?>
            </div>
        </section>
    </div>

    <aside class="side-stack">
        <section class="card">
            <dl class="props">
                <dt>일시</dt><dd><?= e($date) ?><?= $time !== '00:00' ? ' ' . e($time) : '' ?><?php if ($meeting['duration_minutes'] !== null): ?><br><span class="muted small">약 <?= (int) $meeting['duration_minutes'] ?>분</span><?php endif ?></dd>
                <?php if ($meeting['topics'] !== [] || $meeting['system_name'] !== null): ?>
                    <dt>서비스</dt>
                    <dd style="display:flex;gap:4px;flex-wrap:wrap">
                        <?php if ($meeting['system_name'] !== null): ?><a class="<?= e(service_class((string) $meeting['system_name'])) ?>" href="/systems/<?= e((string) $meeting['system_code']) ?>"><?= e((string) $meeting['system_name']) ?></a><?php endif ?>
                        <?php foreach ($meeting['topics'] as $topic): ?><?= topic_chip($topic, $taskServices) ?><?php endforeach ?>
                    </dd>
                <?php endif ?>
                <dt>참석</dt>
                <dd id="attendees">
                    <div class="attendees">
                        <?php foreach ($meeting['participants'] as $p): ?>
                            <span class="att<?= $p['user_id'] === null ? ' is-unlinked' : '' ?>" title="<?= $p['user_id'] === null ? '직원 계정과 연결 안 됨' : e((string) $p['user_name']) ?>">
                                <span class="avatar avatar-sm" aria-hidden="true"><?= e($p['user_name'] === null && preg_match('/^참석자 (\d+)$/u', $p['label'], $n) ? $n[1] : avatar_text((string) ($p['user_name'] ?? $p['label']))) ?></span><?= e($p['label']) ?><?= $p['user_id'] !== null && $p['user_name'] !== $p['label'] ? ' · ' . e((string) $p['user_name']) : '' ?>
                            </span>
                        <?php endforeach ?>
                        <?php if ($meeting['participants'] === []): ?><span class="muted small">지정한 참석자가 없습니다.</span><?php endif ?>
                    </div>
                    <?php if (array_filter($meeting['participants'], static fn (array $p): bool => $p['user_id'] === null) !== []): ?>
                        <div class="muted small" style="margin-top:6px">점선: 직원 계정과 아직 연결 안 됨</div>
                    <?php endif ?>
                    <?php $labelled = array_filter($meeting['participants'], static fn (array $p): bool => preg_match('/^참석자 \d+$/u', $p['label']) === 1); ?>
                    <?php if ($isAuthor && !$deleted && $labelled !== []): ?>
                        <details class="att-details">
                        <summary class="small">직원 계정 연결</summary>
                        <?php foreach ($labelled as $p): ?>
                            <form class="att-link" method="post" action="/meetings/<?= $id ?>/participants/<?= $p['id'] ?>">
                                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                                <span><?= e($p['label']) ?> =</span>
                                <select name="user_id" data-autosubmit aria-label="<?= e($p['label']) ?> 계정">
                                    <option value="">연결 안 함</option>
                                    <?php foreach ($staff as $u): ?>
                                        <option value="<?= $u['id'] ?>"<?= $p['user_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <button class="btn btn-small js-hide" type="submit">연결</button>
                            </form>
                        <?php endforeach ?>
                        </details>
                    <?php endif ?>
                </dd>
                <dt>작성</dt><dd><?= e((string) $meeting['author_name']) ?><br><span class="muted small"><?= e(substr((string) $meeting['created_at'], 5, 11)) ?><?= $meeting['updated_at'] !== $meeting['created_at'] ? ' · 수정 ' . e(substr((string) $meeting['updated_at'], 5, 11)) : '' ?> · 버전 <?= (int) $meeting['version'] ?></span></dd>
                <dt>공개</dt><dd><?= e(MeetingRepository::VISIBILITY_SHORT[$meeting['visibility']] ?? '') ?> <span class="muted small">(<?= e(MeetingRepository::VISIBILITY[$meeting['visibility']] ?? '') ?>)</span></dd>
            </dl>
        </section>
        <?php if ($meeting['transcript_bytes'] !== null): ?>
            <section class="card">
                <h3 class="card-title">원본</h3>
                <a class="file-row" href="/meetings/<?= $id ?>/transcript"><svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 1.5h5l3 3v10H4z"/><path d="M9 1.5v3h3"/></svg><?= e((string) $meeting['transcript_name']) ?><span class="muted small">녹취 · <?= e(number_format((int) $meeting['transcript_bytes'] / 1024)) ?>KB</span></a>
                <p class="muted small" style="margin:8px 0 0">본문과 같이 암호화해 저장. 내려받기는 본인 확인 후.</p>
            </section>
        <?php endif ?>
        <section class="card">
            <h3 class="card-title">최근 열람</h3>
            <ul class="log-list">
                <?php foreach ($recentLogs as $log): ?>
                    <li><span><span class="act"><?= e((string) ($log['user_name'] ?? '시스템')) ?></span> <?= e($log['action'] === 'meeting.view' ? '열람' : $historyLabel($log)) ?></span><span class="muted"><?= e(substr((string) $log['created_at'], 5, 11)) ?></span></li>
                <?php endforeach ?>
            </ul>
            <div class="audit-note"><svg class="ico" viewBox="0 0 16 16" style="width:15px;height:15px" aria-hidden="true"><path d="M8 1.5 2.5 3.8v3.7c0 3.2 2.3 6 5.5 7 3.2-1 5.5-3.8 5.5-7V3.8z"/><path d="M8 5v3.5M8 10.6v.1"/></svg><span>열람·수정·내보내기는 IP·시각과 함께 감사 로그에 남습니다. 전체 기록은 관리자 화면에서.</span></div>
        </section>
        <?php if ($isAuthor && !$deleted): ?>
            <form method="post" action="/meetings/<?= $id ?>/delete">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <button class="btn btn-small btn-danger-ghost" type="submit">휴지통으로 이동</button>
            </form>
        <?php endif ?>
    </aside>
</div>
