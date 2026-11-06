<?php
/**
 * @var array $systems @var array $counts @var array $activeIncidentCounts
 * @var list<array> $recentIncidents @var ?DateTimeImmutable $lastCheckedAt @var int $activeIncidentCount
 * @var ?string $flash @var ?App\Auth\User $currentUser @var ?string $csrfToken
 * @var string $view all|company @var ?list<array{label: string, company_id: ?int, accounts: list<string>, status: string, codes: list<string>, counts: array<string, int>, incidents: int}> $groups 관리회사별 보기일 때만
 */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '대시보드',
    'meta' => [
        ['icon' => 'live', 'html' => '마지막 점검 <strong>' . e(time_ago($lastCheckedAt)) . '</strong>', 'class' => 'is-live'],
        ['icon' => 'clock', 'html' => e(fmt_time($lastCheckedAt, 'Y-m-d H:i:s')), 'class' => 'num'],
        ['icon' => 'refresh', 'html' => '1분마다 자동 새로고침'],
    ],
]) ?>

<section class="stats">
    <div class="stat">
        <div class="stat-label">전체 서비스</div>
        <div class="stat-value"><?= count($systems) ?></div>
    </div>
    <div class="stat s-up">
        <div class="stat-label"><span class="dot s-up"></span>정상</div>
        <div class="stat-value"><?= $counts['up'] ?></div>
    </div>
    <div class="stat<?= $counts['warning'] > 0 ? ' s-warning' : '' ?>">
        <div class="stat-label"><span class="dot s-warning"></span>경고</div>
        <div class="stat-value"><?= $counts['warning'] ?></div>
    </div>
    <div class="stat<?= $counts['down'] > 0 ? ' s-down' : '' ?>">
        <div class="stat-label"><span class="dot s-down"></span>장애</div>
        <div class="stat-value"><?= $counts['down'] ?></div>
    </div>
    <a class="stat stat-link" href="/incidents?status=open">
        <div class="stat-label">처리 중인 장애</div>
        <div class="stat-value"><?= $activeIncidentCount ?></div>
    </a>
</section>

<section class="section">
    <div class="section-head">
        <h2>서비스 상태</h2>
        <nav class="tabs tabs-inline" aria-label="보기 방식">
            <?php foreach (['all' => '전체', 'company' => '관리회사별'] as $key => $label): ?>
                <a class="tab<?= $view === $key ? ' is-active' : '' ?>" href="/?view=<?= e($key) ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <?php if ($systems === []): ?>
        <div class="card empty">등록된 서비스가 없습니다.</div>
    <?php endif ?>
    <?php
    $card = fn (string $code, array $s): string => $this->partial('partials/system-card', [
        'code' => $code, 's' => $s, 'activeIncidents' => (int) $activeIncidentCounts[$code],
    ]);
    ?>
    <?php if ($groups === null): ?>
        <div class="system-grid">
            <?php foreach ($systems as $code => $s): ?><?= $card($code, $s) ?><?php endforeach ?>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $g): ?>
            <div class="company-group">
                <div class="company-group-head">
                    <h3>
                        <span class="dot s-<?= e($g['status']) ?>"></span>
                        <?= e($g['label']) ?>
                        <?php if ($g['accounts'] !== []): ?><span class="muted small" title="AWS 계정">AWS <?= e(implode(', ', $g['accounts'])) ?></span><?php endif ?>
                    </h3>
                    <span class="muted small">
                        서비스 <?= count($g['codes']) ?>
                        <?php foreach (['down' => '장애', 'warning' => '경고'] as $st => $stLabel): ?>
                            <?php if ($g['counts'][$st] > 0): ?> · <span class="t-<?= $st === 'down' ? 'down' : 'warn' ?>"><?= $stLabel ?> <?= $g['counts'][$st] ?></span><?php endif ?>
                        <?php endforeach ?>
                        <?php if ($g['incidents'] > 0): ?> · 처리 중 장애 <?= $g['incidents'] ?>건<?php endif ?>
                    </span>
                </div>
                <div class="system-grid">
                    <?php foreach ($g['codes'] as $code): ?><?= $card($code, $systems[$code]) ?><?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</section>

<section class="section" id="recent-incidents">
    <div class="section-head">
        <h2>최근 장애</h2>
        <span class="section-head-aside">
            <span class="muted small">확인한 장애는 여기서 빠지고 장애 센터에는 남습니다</span>
            <a href="/incidents">전체 보기</a>
        </span>
    </div>
    <?php if ($flash !== null): ?>
        <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
    <?php endif ?>
    <div class="card card-flush">
        <?= $this->partial('partials/incident-table', [
            'incidents' => $recentIncidents,
            'emptyText' => '확인하지 않은 최근 장애가 없습니다.',
            'dismissible' => isset($currentUser) && $currentUser->can('operator'),
        ]) ?>
    </div>
</section>
