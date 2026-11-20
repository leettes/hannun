<?php
use App\Meetings\MeetingRepository;

/**
 * 전체 회의 목록
 *
 * @var App\Auth\User $me
 * @var array{from: ?string, to: ?string, system_id: ?int, attendee_id: ?int, author_id: ?int, q: ?string} $filter
 * @var list<array<string, mixed>> $meetings @var int $total @var int $page @var int $pages
 * @var list<array{id: int, name: string}> $systems @var list<array{id: int, name: string, meeting_access: string}> $staff
 * @var ?string $flash
 */
$url = static function (array $change) use ($filter): string {
    $q = array_filter([
        'from' => $filter['from'], 'to' => $filter['to'], 'system' => $filter['system_id'],
        'attendee' => $filter['attendee_id'], 'author' => $filter['author_id'], 'q' => $filter['q'],
    ], static fn ($v): bool => $v !== null);
    $q = array_filter($change + $q, static fn ($v): bool => $v !== null && $v !== '');

    return '/meetings/list' . ($q === [] ? '' : '?' . http_build_query($q));
};
$filtered = array_filter($filter, static fn ($v): bool => $v !== null) !== [];
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '회의록',
    'crumbHref' => '/meetings',
    'heading' => '전체 회의 목록',
    'sub' => '볼 수 있는 회의록만 보입니다. 본문은 암호화되어 있어 제목·주제로만 검색합니다.',
    'aside' => '<a class="btn" href="/meetings/trash">휴지통</a>' . ($me->canWriteMeetings() ? ' <a class="btn btn-primary" href="/meetings/new">회의록 작성</a>' : ''),
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<section class="card">
    <form class="form" method="get" action="/meetings/list">
        <div class="form-row form-row-3">
            <label><span>제목·주제</span><input type="search" name="q" value="<?= e((string) $filter['q']) ?>" maxlength="100"></label>
            <label><span>시작일</span><input type="date" name="from" value="<?= e((string) $filter['from']) ?>"></label>
            <label><span>종료일</span><input type="date" name="to" value="<?= e((string) $filter['to']) ?>"></label>
            <label>
                <span>서비스</span>
                <select name="system">
                    <option value="">전체</option>
                    <?php foreach ($systems as $s): ?><option value="<?= $s['id'] ?>"<?= $filter['system_id'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?>
                </select>
            </label>
            <label>
                <span>참석자</span>
                <select name="attendee">
                    <option value="">전체</option>
                    <?php foreach ($staff as $u): ?><option value="<?= $u['id'] ?>"<?= $filter['attendee_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?>
                </select>
            </label>
            <label>
                <span>작성자</span>
                <select name="author">
                    <option value="">전체</option>
                    <?php foreach ($staff as $u): ?><option value="<?= $u['id'] ?>"<?= $filter['author_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?>
                </select>
            </label>
        </div>
        <div class="form-actions">
            <?php if ($filtered): ?><a class="btn" href="/meetings/list">초기화</a><?php endif ?>
            <button class="btn btn-primary" type="submit">조회</button>
        </div>
    </form>
</section>

<div class="card card-flush">
    <?php if ($meetings === []): ?>
        <div class="empty"><?= $filtered ? '조건에 맞는 회의록이 없습니다.' : '볼 수 있는 회의록이 없습니다.' ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-time">일시</th><th class="col-main">제목</th><th class="col-short">작성자</th><th class="col-tag">참석</th><th class="col-tag">할 일</th><th class="col-short">공개 범위</th></tr>
                </thead>
                <tbody>
                <?php foreach ($meetings as $m): ?>
                    <tr>
                        <td class="nowrap num"><?= e(meeting_time((string) $m['held_at'])) ?></td>
                        <td class="cell-main"><a href="/meetings/<?= (int) $m['id'] ?>"><?= e((string) $m['title']) ?></a><?php if ($m['subtitle']): ?> <span class="muted small">· <?= e((string) $m['subtitle']) ?></span><?php endif ?></td>
                        <td class="nowrap"><?= e((string) $m['author_name']) ?></td>
                        <td class="nowrap num"><?= (int) $m['attendee_count'] ?>명</td>
                        <td class="nowrap num"><?= (int) $m['task_count'] ?>건</td>
                        <td class="nowrap"><?= e(MeetingRepository::VISIBILITY_SHORT[$m['visibility']] ?? (string) $m['visibility']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="쪽">
                <?php if ($page > 1): ?><a class="btn btn-small" href="<?= e($url(['page' => $page - 1])) ?>">← 이전</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">← 이전</span><?php endif ?>
                <span class="muted small num"><?= $page ?> / <?= $pages ?> · <?= number_format($total) ?>건</span>
                <?php if ($page < $pages): ?><a class="btn btn-small" href="<?= e($url(['page' => $page + 1])) ?>">다음 →</a><?php else: ?><span class="btn btn-small is-disabled" aria-disabled="true">다음 →</span><?php endif ?>
            </nav>
        <?php endif ?>
    <?php endif ?>
</div>
