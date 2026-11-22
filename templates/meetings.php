<?php
use App\Meetings\MeetingRepository;
use App\Meetings\MeetingTaskRepository;

/**
 * 회의록 메인 (시안-메인.html)
 *
 * @var App\Auth\User $me
 * @var array{month: int, total: int, latest: ?string} $stats
 * @var list<array<string, mixed>> $recent
 * @var array{status: array<string, int>, total: int, unassigned: int, services: array<string, int>, people: array<string, int>} $summary
 * @var list<array<string, mixed>> $tasks @var array{status: ?string, service: ?string, q: ?string} $filter
 * @var list<string> $serviceTabs @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var int $idleSeconds @var string $backUrl @var ?string $flash @var string $csrfToken
 */
$canWrite = $me->canWriteMeetings();
$url = static function (array $change) use ($filter): string {
    $q = array_filter($change + ['status' => $filter['status'], 'service' => $filter['service'], 'q' => $filter['q']], static fn ($v): bool => $v !== null && $v !== '');

    return '/meetings' . ($q === [] ? '' : '?' . http_build_query($q)) . '#tasks';
};
$monthLabel = (int) date('n') . '월';
$latest = $stats['latest'] === null ? null : date('n/j', (int) strtotime(substr($stats['latest'], 0, 10)));
$serviceMax = max([1, ...array_values($summary['services'])]);
$taskServices = array_keys($summary['services']);
$aside = '<a class="btn" href="/meetings/list">전체 회의 목록</a>';
if ($canWrite) {
    $aside .= ' <a class="btn" href="/meetings/new/transcript">녹취 텍스트로 작성</a> <a class="btn btn-primary" href="/meetings/new">회의록 작성</a>';
}
?>
<header class="page-head">
    <div>
        <h1>회의록</h1>
        <p class="page-sub">사내 회의 기록과 회의에서 나온 할 일. 회의록 권한이 있는 직원만 볼 수 있습니다.</p>
        <?= $this->partial('partials/secure-chips', ['idleSeconds' => $idleSeconds, 'visibility' => null]) ?>
    </div>
    <div class="page-head-aside head-actions"><?= $aside ?></div>
</header>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="stats stats-4">
    <div class="stat"><div class="stat-label">회의</div><div class="stat-value"><?= $stats['month'] ?></div><div class="stat-sub"><?= e($monthLabel) ?><?= $latest !== null ? ' · 최근 ' . e($latest) : '' ?><?= $stats['month'] !== $stats['total'] ? ' · 전체 ' . $stats['total'] : '' ?></div></div>
    <div class="stat"><div class="stat-label"><span class="dot"></span>대기</div><div class="stat-value"><?= $summary['status']['todo'] ?></div><div class="stat-sub">할 일 <?= $summary['total'] ?>건 중</div></div>
    <div class="stat"><div class="stat-label"><span class="dot" style="background:var(--accent)"></span>진행</div><div class="stat-value"><?= $summary['status']['doing'] ?></div><div class="stat-sub"><?= $summary['status']['hold'] > 0 ? '보류 ' . $summary['status']['hold'] . '건' : '&nbsp;' ?></div></div>
    <div class="stat"><div class="stat-label"><span class="dot s-up"></span>완료</div><div class="stat-value"><?= $summary['status']['done'] ?></div><div class="stat-sub">&nbsp;</div></div>
    <div class="stat<?= $summary['unassigned'] > 0 ? ' s-warning' : '' ?>"><div class="stat-label"><span class="dot s-warning"></span>담당 미지정</div><div class="stat-value"><?= $summary['unassigned'] ?></div><div class="stat-sub">회의에서 담당이 안 나온 것</div></div>
</div>

<section class="section">
    <div class="section-head">
        <h2>최근 회의</h2>
        <a class="small" href="/meetings/list">전체 회의 목록 →</a>
    </div>
    <?php if ($recent === []): ?>
        <div class="card empty">볼 수 있는 회의록이 없습니다.<?= $canWrite ? ' 회의록 작성이나 녹취 텍스트로 시작하세요.' : '' ?></div>
    <?php else: ?>
        <div class="meeting-cards">
            <?php foreach ($recent as $i => $m): ?>
                <?php $total = (int) $m['task_count']; $done = (int) $m['task_done']; ?>
                <a class="card meeting-card<?= $i === 0 ? ' is-latest' : '' ?>" href="/meetings/<?= (int) $m['id'] ?>">
                    <div class="mc-head">
                        <div class="mc-date">
                            <span class="mc-day"><?= e(date('m/d', (int) strtotime(substr((string) $m['held_at'], 0, 10)))) ?></span>
                            <span class="muted small"><?= e(weekday_ko((string) $m['held_at'])) ?><?= $m['duration_minutes'] !== null ? ' · 약 ' . (int) $m['duration_minutes'] . '분' : '' ?></span>
                        </div>
                        <span class="vis<?= $m['visibility'] === 'attendees' ? ' vis-attendees' : '' ?>"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="6" cy="5.5" r="2.3"/><path d="M1.8 13a4.2 4.2 0 0 1 8.4 0M11 3.5a2.2 2.2 0 0 1 0 4.2M12.5 13a4 4 0 0 0-1.7-3.3"/></svg><?= e(MeetingRepository::VISIBILITY_SHORT[$m['visibility']] ?? '') ?></span>
                    </div>
                    <div class="mc-title"><?= e((string) ($m['subtitle'] ?: $m['title'])) ?></div>
                    <?php if ($m['summary'] !== ''): ?><p class="mc-summary"><?= e((string) $m['summary']) ?></p><?php endif ?>
                    <?php if ($m['topics'] !== []): ?>
                        <div class="mc-topics"><?php foreach ($m['topics'] as $topic): ?><?= topic_chip($topic, $taskServices) ?><?php endforeach ?></div>
                    <?php endif ?>
                    <div class="mc-foot">
                        <span>참석 <?= (int) $m['participant_count'] ?>명</span>
                        <span>할 일 <?= $total ?></span>
                        <span class="mc-progress"><span class="mini-bar"><span style="width:<?= $total === 0 ? 0 : (int) round($done / $total * 100) ?>%"></span></span><?= $done ?>/<?= $total ?></span>
                    </div>
                </a>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</section>

<section class="section" id="tasks">
    <div class="mt-grid">
        <div class="mt-main">
            <div class="section-head">
                <h2>할 일</h2>
                <span class="muted small"><?= $canWrite ? '상태·담당은 표에서 바로 바꿉니다 · 바꾼 기록은 할 일 상세 타임라인에' : '상태 변경은 회의록 열람·작성 권한이 있어야 합니다' ?></span>
            </div>
            <div class="task-toolbar">
                <nav class="tabs" aria-label="상태">
                    <a class="tab<?= $filter['status'] === null ? ' is-active' : '' ?>" href="<?= e($url(['status' => null])) ?>">전체 <span class="tab-count"><?= $summary['total'] ?></span></a>
                    <?php foreach (MeetingTaskRepository::STATUSES as $key => $label): ?>
                        <a class="tab<?= $filter['status'] === $key ? ' is-active' : '' ?>" href="<?= e($url(['status' => $key])) ?>"><?= e($label) ?> <span class="tab-count"><?= $summary['status'][$key] ?></span></a>
                    <?php endforeach ?>
                </nav>
                <form method="get" action="/meetings#tasks" role="search">
                    <?php if ($filter['status'] !== null): ?><input type="hidden" name="status" value="<?= e($filter['status']) ?>"><?php endif ?>
                    <?php if ($filter['service'] !== null): ?><input type="hidden" name="service" value="<?= e($filter['service']) ?>"><?php endif ?>
                    <input class="task-search" type="search" name="q" value="<?= e((string) $filter['q']) ?>" placeholder="항목·할 일 검색" maxlength="100">
                </form>
            </div>
            <?php if ($serviceTabs !== []): ?>
                <nav class="tabs tabs-filter" aria-label="서비스">
                    <span class="tabs-label">서비스</span>
                    <a class="tab<?= $filter['service'] === null ? ' is-active' : '' ?>" href="<?= e($url(['service' => null])) ?>">전체</a>
                    <?php foreach ($serviceTabs as $service): ?>
                        <a class="tab<?= $filter['service'] === $service ? ' is-active' : '' ?>" href="<?= e($url(['service' => $service])) ?>"><?= e($service) ?></a>
                    <?php endforeach ?>
                </nav>
            <?php endif ?>
            <div class="card card-flush">
                <?php if ($tasks === []): ?>
                    <div class="empty"><?= $summary['total'] === 0 ? '아직 할 일이 없습니다. 회의록의 결정 사항을 할 일로 등록하세요.' : '조건에 맞는 할 일이 없습니다.' ?></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th class="col-short">서비스</th>
                                <th class="col-title">할 일</th>
                                <th class="col-person" style="min-width:96px">담당</th>
                                <th class="col-status">상태</th>
                                <th class="col-src">회의</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $group = null; ?>
                            <?php foreach ($tasks as $t): ?>
                                <?php if ($group !== $t['meeting_id']): $group = $t['meeting_id']; ?>
                                    <tr class="group-row"><td colspan="5"><?= e(date('m/d', (int) strtotime(substr((string) $t['meeting_held_at'], 0, 10)))) ?> 회의<?= $t['meeting_subtitle'] ? ' · ' . e((string) $t['meeting_subtitle']) : '' ?></td></tr>
                                <?php endif ?>
                                <tr>
                                    <td><span class="<?= e(service_class((string) $t['service'])) ?>"><?= e((string) $t['service']) ?></span></td>
                                    <td>
                                        <div class="task-item"><a href="/meetings/tasks/<?= (int) $t['id'] ?>"><?= e((string) $t['item']) ?></a></div>
                                        <div class="task-do"><?= e((string) $t['task']) ?></div>
                                        <?php if (($t['note'] ?? '') !== ''): ?><div class="task-note"><?= e((string) $t['note']) ?></div><?php endif ?>
                                        <?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $backUrl, 'part' => 'promote']) ?>
                                    </td>
                                    <td><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $backUrl, 'part' => 'person']) ?></td>
                                    <td><?= $this->partial('partials/task-controls', ['t' => $t, 'canWrite' => $canWrite, 'staff' => $staff, 'back' => $backUrl, 'part' => 'status']) ?></td>
                                    <td class="task-src col-src"><a href="/meetings/<?= (int) $t['meeting_id'] ?>#tasks"><?= e(date('m/d', (int) strtotime(substr((string) $t['meeting_held_at'], 0, 10)))) ?></a></td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>
        <aside class="side-stack">
            <section class="card">
                <h3 class="card-title">서비스별 남은 할 일</h3>
                <?php if ($summary['services'] === []): ?>
                    <p class="muted small">남은 할 일이 없습니다.</p>
                <?php else: ?>
                    <ul class="hbar-list">
                        <?php foreach ($summary['services'] as $service => $n): ?>
                            <li>
                                <a href="<?= e($url(['service' => $service])) ?>">
                                    <span><?= e($service) ?></span>
                                    <span class="hbar"><span style="width:<?= (int) round($n / $serviceMax * 100) ?>%;background:<?= e(service_color($service)) ?>"></span></span>
                                    <span class="num"><?= $n ?></span>
                                </a>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>
            <section class="card">
                <h3 class="card-title">담당별</h3>
                <ul class="side-list">
                    <?php foreach ($summary['people'] as $who => $n): ?>
                        <li><span<?= $who === '미지정' ? ' class="muted"' : '' ?>><?= e($who) ?></span><span class="num"><?= $n ?>건</span></li>
                    <?php endforeach ?>
                </ul>
                <p class="muted small" style="margin:12px 0 0">녹취 정리본은 이름 대신 '참석자 N' 으로 남아 있습니다. 회의 상세에서 참석자를 직원 계정과 연결하면 담당이 실제 이름으로 바뀝니다.</p>
            </section>
            <section class="card">
                <div class="audit-note" style="margin-top:0"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 2.5 3.8v3.7c0 3.2 2.3 6 5.5 7 3.2-1 5.5-3.8 5.5-7V3.8z"/><path d="M8 5v3.5M8 10.6v.1"/></svg><span>회의록 열람·수정·내보내기는 사용자·IP·시각과 함께 감사 로그에 남습니다. 공개 범위가 '참석자만'인 회의는 참석자가 아니면 목록에도 나오지 않습니다.</span></div>
            </section>
        </aside>
    </div>
</section>
