<?php
use App\Monitoring\Status;
use App\Monitoring\SystemRegistry;
use App\Monitoring\Thresholds;

/**
 * @var array $systems @var array $counts @var array $activeIncidentCounts
 * @var list<array> $recentIncidents @var ?DateTimeImmutable $lastCheckedAt @var int $activeIncidentCount
 * @var ?string $flash @var ?App\Auth\User $currentUser @var ?string $csrfToken @var bool $showAwsResources
 * @var string $view all|company @var ?list<array{label: string, company_id: ?int, accounts: list<string>, status: string, codes: list<string>, counts: array<string, int>, incidents: int}> $groups 관리회사별 보기일 때만
 * @var array<string, list<array<string, mixed>>> $appsBySystem 서비스 코드 => 사용 중인 앱
 * @var ?string $selected 상세 패널 서비스 코드 @var App\Monitoring\TimeRange $range
 * @var list<array> $selectedResponseTimes @var list<array> $selectedIncidents
 */
$canEdit = isset($currentUser) && $currentUser->can('admin');
?>
<header class="dash-head">
    <div>
        <h1>앱 시스템 통합 관리</h1>
        <p class="page-sub">여러 앱과 시스템을 한 곳에서 관리하세요.</p>
    </div>
    <div class="dash-head-aside">
        <span class="dash-updated" title="마지막 점검 <?= e(time_ago($lastCheckedAt)) ?> · 1분마다 자동 새로고침">
            <span class="meta-live" aria-hidden="true"></span>
            최근 업데이트: <span class="num"><?= e(fmt_time($lastCheckedAt, 'Y.m.d H:i')) ?></span>
        </span>
        <a class="icon-button" href="/" title="지금 새로고침" aria-label="지금 새로고침">
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9"/><path d="M13.5 2.5v3h-3"/></svg>
        </a>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary btn-add" href="/settings/systems/new">
                <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>서비스 추가
            </a>
        <?php endif ?>
    </div>
</header>

<section class="stats stats-compact" aria-label="상태 요약">
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
        'code' => $code,
        's' => $s,
        'activeIncidents' => (int) $activeIncidentCounts[$code],
        'apps' => $appsBySystem[$code] ?? [],
        'selected' => $code === $selected,
        'canEdit' => $canEdit,
    ]);
    ?>
    <?php if ($groups === null): ?>
        <div class="svc-grid">
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
                <div class="svc-grid">
                    <?php foreach ($g['codes'] as $code): ?><?= $card($code, $systems[$code]) ?><?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</section>

<?php if ($selected !== null):
    $s = $systems[$selected];
    $apps = $appsBySystem[$selected] ?? [];
    $statusText = [Status::UP => '정상 운영', Status::WARNING => '경고', Status::DOWN => '장애', Status::UNKNOWN => '점검 전'][$s['status']] ?? $s['status'];
    $icon = null;
    foreach ($apps as $a) {
        if (!empty($a['icon_file'])) {
            $icon = $a['icon_file'];
            break;
        }
    }

    // 시스템 상태 목록. 점검 기록이 없으면 '점검 전'
    $stateText = static fn (string $st): string => $st === Status::UNKNOWN ? '점검 전' : status_label($st);
    $checks = [];
    foreach ($s['http'] as $m) {
        $kind = SystemRegistry::HTTP_KINDS[$m['kind']] ?? '웹/API';
        $checks[] = [
            'status' => $m['status'],
            'label' => count($s['http']) > 1 ? $m['name'] : $kind . ' 서버',
            'text' => $stateText($m['status']) . ($m['response_ms'] !== null ? ' (응답 ' . number_format($m['response_ms']) . 'ms)' : ''),
        ];
    }
    $ssl = array_filter($s['http'], static fn (array $m): bool => $m['ssl_days_left'] !== null);
    if ($ssl !== []) {
        usort($ssl, static fn (array $a, array $b): int => $a['ssl_days_left'] <=> $b['ssl_days_left']);
        $first = $ssl[0];
        $checks[] = [
            'status' => $first['ssl_days_left'] < 0 ? Status::DOWN : ($first['ssl_days_left'] < Thresholds::SSL_WARN_DAYS ? Status::WARNING : Status::UP),
            'label' => 'SSL 인증서',
            'text' => ($first['ssl_days_left'] < Thresholds::SSL_WARN_DAYS ? '곧 만료' : '정상') . ' (만료일: ' . fmt_time($first['ssl_expires_at'], 'Y.m.d') . ')',
        ];
    }
    $domainExpiries = array_filter(array_map(static fn (array $m): ?string => $m['domain_info']['expires_at'] ?? null, $s['http']));
    if ($domainExpiries !== []) {
        $soonest = min($domainExpiries);
        $days = days_until($soonest);
        $checks[] = [
            'status' => $days === null ? Status::UNKNOWN : ($days < 0 ? Status::DOWN : ($days < 30 ? Status::WARNING : Status::UP)),
            'label' => '도메인',
            'text' => ($days !== null && $days < 30 ? '곧 만료' : '정상') . ' (만료일: ' . str_replace('-', '.', substr($soonest, 0, 10)) . ')',
        ];
    }
    if ($showAwsResources) {
        foreach (['ec2' => 'EC2', 'rds' => 'DB 연결'] as $type => $label) {
            if ($s[$type] !== []) {
                $checks[] = ['status' => $s[$type . '_status'], 'label' => $label, 'text' => $stateText($s[$type . '_status']) . ' (' . count($s[$type]) . '대)'];
            }
        }
    }
    $checkIcon = [
        Status::UP => '<path d="m5 8.2 2 2 4-4.2"/>',
        Status::WARNING => '<path d="M8 4.8v3.6M8 10.8v.1"/>',
        Status::DOWN => '<path d="m5.6 5.6 4.8 4.8M10.4 5.6l-4.8 4.8"/>',
        Status::UNKNOWN => '<path d="M5 8h6"/>',
    ];
    ?>
<section class="section" id="overview">
    <div class="card card-flush detail-panel" data-detail-panel>
        <div class="detail-tabs-bar">
            <div class="detail-tabs" role="tablist" aria-label="<?= e($s['name']) ?> 상세">
                <?php foreach (['overview' => '시스템 개요', 'domains' => '도메인 & SSL', 'apps' => '앱', 'events' => '이벤트 로그'] as $key => $label): ?>
                    <button type="button" role="tab" class="detail-tab<?= $key === 'overview' ? ' is-active' : '' ?>" id="detail-tab-<?= $key ?>" aria-controls="detail-<?= $key ?>" aria-selected="<?= $key === 'overview' ? 'true' : 'false' ?>" data-tab="<?= $key ?>">
                        <?= e($label) ?>
                        <?php if ($key === 'apps' && $apps !== []): ?><span class="tab-count"><?= count($apps) ?></span><?php endif ?>
                        <?php if ($key === 'events' && $selectedIncidents !== []): ?><span class="tab-count"><?= count($selectedIncidents) ?></span><?php endif ?>
                    </button>
                <?php endforeach ?>
            </div>
            <form class="detail-picker" method="get" action="/#overview">
                <label class="visually-hidden" for="detail-system">서비스 선택</label>
                <select id="detail-system" name="system" data-autosubmit>
                    <?php foreach ($systems as $code => $item): ?>
                        <option value="<?= e($code) ?>"<?= $code === $selected ? ' selected' : '' ?>><?= e($item['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <noscript><button class="btn btn-small" type="submit">보기</button></noscript>
            </form>
        </div>

        <div class="detail-body" role="tabpanel" id="detail-overview" aria-labelledby="detail-tab-overview" data-panel="overview">
            <div class="overview-grid">
                <div class="overview-box">
                    <h3>시스템 정보</h3>
                    <div class="overview-id" style="--hue: <?= (int) (crc32($selected) % 360) ?>">
                        <span class="svc-logo svc-logo-lg">
                            <?php if ($icon !== null): ?>
                                <img src="/app-icons/<?= e(rawurlencode((string) $icon)) ?>" width="72" height="72" alt="">
                            <?php else: ?>
                                <span><?= e(mb_substr(service_short($s['name'], $s['short_name'] ?? null), 0, 1)) ?></span>
                            <?php endif ?>
                        </span>
                        <div>
                            <div class="overview-name">
                                <a href="/systems/<?= e($selected) ?>"><?= e($s['name']) ?></a>
                                <span class="svc-status s-<?= e($s['status']) ?>"><span class="dot s-<?= e($s['status']) ?>"></span><?= e($statusText) ?></span>
                            </div>
                            <p class="muted"><?= e($s['description'] !== '' ? $s['description'] : '서비스 설명이 없습니다.') ?></p>
                        </div>
                    </div>
                    <dl class="overview-dl">
                        <dt>운영 도메인</dt>
                        <dd>
                            <?php $hosts = array_values(array_unique(array_column($s['http'], 'host'))) ?>
                            <?php if ($hosts === []): ?>-<?php endif ?>
                            <?php foreach ($hosts as $h): ?>
                                <a href="https://<?= e($h) ?>/" target="_blank" rel="noopener noreferrer" class="break"><?= e($h) ?></a><br>
                            <?php endforeach ?>
                        </dd>
                        <dt>클라우드</dt>
                        <dd><?= e(cloud_label($s['cloud'] ?? 'aws', $s['aws_alias'] ?? null, $s['aws_account'] === '-' ? null : $s['aws_account'])) ?></dd>
                        <dt>DB</dt>
                        <dd><?= e($s['rds'] === [] ? '-' : implode(', ', array_map(static fn (array $r): string => $r['identifier'] . ' (RDS ' . $r['engine'] . ')', $s['rds']))) ?></dd>
                        <dt>관리회사</dt>
                        <dd><?= e($s['company_name'] ?? '미지정') ?></dd>
                    </dl>
                </div>

                <div class="overview-box overview-chart">
                    <h3>응답 시간 <span class="muted">(최근 7일 · 1시간 평균)</span></h3>
                    <?php if ($s['http'] === []): ?>
                        <div class="empty">등록된 웹/API 리소스가 없습니다.</div>
                    <?php else: ?>
                        <?= $this->partial('partials/response-chart', ['range' => $range, 'series' => $selectedResponseTimes, 'compact' => true]) ?>
                    <?php endif ?>
                </div>

                <div class="overview-box">
                    <h3>시스템 상태</h3>
                    <?php if ($checks === []): ?>
                        <div class="empty">점검 항목이 없습니다.</div>
                    <?php else: ?>
                        <ul class="health-list">
                            <?php foreach ($checks as $c): ?>
                                <li class="s-<?= e($c['status']) ?>">
                                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="7"/><?= $checkIcon[$c['status']] ?? $checkIcon[Status::UNKNOWN] ?></svg>
                                    <span class="health-label"><?= e($c['label']) ?></span>
                                    <span class="health-text"><?= e($c['text']) ?></span>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                    <a class="small overview-more" href="/systems/<?= e($selected) ?>">상세 지표 보기 →</a>
                </div>
            </div>
        </div>

        <div class="detail-body" role="tabpanel" id="detail-domains" aria-labelledby="detail-tab-domains" data-panel="domains">
            <?= $this->partial('partials/domain-table', ['editable' => $canEdit, 'rows' => array_map(static fn (array $m): array => $m + [
                'label' => $m['name'],
                'href' => null,
                'identifier' => $m['url'],
            ], $s['http'])]) ?>
        </div>

        <div class="detail-body" role="tabpanel" id="detail-apps" aria-labelledby="detail-tab-apps" data-panel="apps">
            <?= $this->partial('partials/app-table', ['apps' => $apps, 'editable' => $canEdit, 'showSystem' => false]) ?>
        </div>

        <div class="detail-body" role="tabpanel" id="detail-events" aria-labelledby="detail-tab-events" data-panel="events">
            <?= $this->partial('partials/incident-table', [
                'incidents' => $selectedIncidents,
                'emptyText' => '장애 이력이 없습니다.',
                'showSystem' => false,
            ]) ?>
        </div>
    </div>
    <script src="<?= asset('dashboard.js') ?>" defer></script>
</section>
<?php endif ?>

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
