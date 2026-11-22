<?php
use App\Security\AuditLog;

/**
 * @var array{user_id: ?int, action: ?string, from: ?string, to: ?string} $filter
 * @var list<array<string, mixed>> $logs @var int $total @var int $page @var int $pages
 * @var list<App\Auth\User> $users
 */
$url = static function (array $change) use ($filter): string {
    $q = array_filter([
        'user' => $filter['user_id'],
        'action' => $filter['action'],
        'from' => $filter['from'],
        'to' => $filter['to'],
    ], static fn ($v): bool => $v !== null);
    $q = array_filter($change + $q, static fn ($v): bool => $v !== null && $v !== '');

    return '/settings/audit-logs' . ($q === [] ? '' : '?' . http_build_query($q));
};
$target = static fn (array $log): string => match ($log['target_type']) {
    null => '',
    'user' => '사용자 #' . $log['target_id'],
    'meeting' => '회의록 #' . $log['target_id'],
    'feature' => '빌드업 #' . $log['target_id'],
    default => $log['target_type'] . ' #' . $log['target_id'],
};
?>
<?= $this->partial('partials/page-head', [
    'heading' => '감사 로그',
    'sub' => '회의록 열람·작성, 회의록 권한 변경, 2단계 인증 기록. 화면에서 고치거나 지울 수 없고 1년 뒤 자동 정리됩니다.',
]) ?>

<section class="card">
    <form class="form" method="get" action="/settings/audit-logs">
        <div class="form-row">
            <label>
                <span>사용자</span>
                <select name="user">
                    <option value="">전체</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u->id ?>"<?= $filter['user_id'] === $u->id ? ' selected' : '' ?>><?= e($u->name) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label>
                <span>행위</span>
                <select name="action">
                    <option value="">전체</option>
                    <?php foreach (AuditLog::ACTIONS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $filter['action'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label>
                <span>시작일</span>
                <input type="date" name="from" value="<?= e((string) $filter['from']) ?>">
            </label>
            <label>
                <span>종료일</span>
                <input type="date" name="to" value="<?= e((string) $filter['to']) ?>">
            </label>
        </div>
        <div class="form-actions">
            <a class="btn" href="/settings/audit-logs">초기화</a>
            <button class="btn btn-primary" type="submit">조회</button>
        </div>
    </form>
</section>

<div class="card card-flush">
    <?php if ($logs === []): ?>
        <div class="empty">기록이 없습니다.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-time">시각</th><th class="col-main">사용자</th><th class="col-short">행위</th><th class="col-short">대상</th><th class="col-short">IP</th><th>내용</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="nowrap num"><?= e((string) $log['created_at']) ?></td>
                        <td><?= $log['user_id'] === null ? '<span class="muted">시스템</span>' : e((string) ($log['user_name'] ?? '#' . $log['user_id'])) ?></td>
                        <td class="nowrap"><?= e(AuditLog::actionLabel((string) $log['action'])) ?></td>
                        <td class="nowrap"><?= e($target($log)) ?></td>
                        <td class="nowrap num"><?= e((string) ($log['ip'] ?? '')) ?></td>
                        <td class="break small"><?= e((string) ($log['detail'] ?? '')) ?></td>
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
