<?php
use App\Monitoring\Thresholds;

/**
 * @var array $system @var list<array> $incidents @var bool $showAwsResources
 * @var App\Monitoring\TimeRange $range @var list<array> $responseTimes @var list<array<string, mixed>> $apps
 */
$s = $system;
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '대시보드',
    'crumbHref' => '/',
    'heading' => e($s['name']) . ' ' . $this->partial('partials/status-badge', ['status' => $s['status']]),
    'sub' => $s['description'] === '' ? null : e($s['description']),
    'meta' => [
        ['html' => '<span class="env-tag env-' . e($s['environment']) . '">' . e(App\Monitoring\SystemRegistry::ENVIRONMENTS[$s['environment']] ?? $s['environment']) . '</span>', 'class' => 'is-plain'],
        ['icon' => 'user', 'html' => '담당 ' . e($s['owner'])],
        ['icon' => 'cloud', 'html' => e(cloud_label($s['cloud'] ?? 'aws', $s['aws_alias'] ?? null, $s['aws_account'] === '-' ? null : $s['aws_account']))],
        ...(empty($s['admin_url']) ? [] : [['html' => '<a href="' . e($s['admin_url']) . '" target="_blank" rel="noopener noreferrer">관리자 페이지 ↗</a>']]),
        ['html' => '<a href="/architecture?system=' . e(rawurlencode($s['code'])) . '">구성도 보기</a>'],
    ],
    'aside' => '<span class="muted small">마지막 점검 ' . e(time_ago($s['last_checked_at'])) . '</span>',
]) ?>

<section class="section">
    <div class="section-head">
        <h2>웹/API</h2>
        <span class="muted small">1분 점검 · 응답 <?= number_format(Thresholds::HTTP_SLOW_MS) ?>ms 이상 또는 SSL <?= Thresholds::SSL_WARN_DAYS ?>일 이내 시 경고</span>
    </div>
    <div class="card card-flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-tag">구분</th><th class="col-main">이름</th><th class="col-url">URL</th><th class="col-status">상태</th><th class="num col-num">응답</th><th class="num col-num" data-label="가용률(24h)">24시간 가용률</th><th class="col-date">SSL 만료</th><th class="col-ago">점검</th></tr>
                </thead>
                <tbody>
                <?php foreach ($s['http'] as $m): ?>
                    <tr>
                        <td><span class="kind-tag"><?= e(App\Monitoring\SystemRegistry::HTTP_KINDS[$m['kind']]) ?></span></td>
                        <td class="cell-main"><?= e($m['name']) ?></td>
                        <td class="mono small break"><?= e($m['url']) ?></td>
                        <td><?= $this->partial('partials/status-badge', ['status' => $m['status']]) ?></td>
                        <td class="num<?= ($m['response_ms'] ?? 0) >= Thresholds::HTTP_SLOW_MS ? ' t-warn' : '' ?>"><?= $m['response_ms'] === null ? '-' : number_format($m['response_ms']) . 'ms' ?></td>
                        <td class="num"><?= $m['uptime_24h'] === null ? '-' : number_format($m['uptime_24h'], 2) . '%' ?></td>
                        <td class="nowrap<?= $m['ssl_days_left'] !== null && $m['ssl_days_left'] < Thresholds::SSL_WARN_DAYS ? ' t-warn' : '' ?>">
                            <?= $m['ssl_days_left'] === null ? '-' : e($m['ssl_days_left'] . '일 남음') ?>
                            <div class="muted small"><?= e(fmt_time($m['ssl_expires_at'], 'Y-m-d')) ?></div>
                        </td>
                        <td class="nowrap muted"><?= e(time_ago($m['last_checked_at'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php if ($s['http'] !== []): ?>
    <section class="section">
        <div class="section-head">
            <h2>응답 시간</h2>
            <span class="muted small"><?= e($range->label()) ?> · <?= e($range->bucketLabel()) ?> 평균 · 빨간 구간은 장애</span>
        </div>
        <?= $this->partial('partials/response-chart', ['range' => $range, 'series' => $responseTimes]) ?>
    </section>
<?php endif ?>

<?php if ($s['http'] !== []): ?>
    <section class="section">
        <div class="section-head">
            <h2>도메인 · 연결 IP</h2>
            <span class="muted small">IP 는 1분마다, 등록업체·만료일은 하루 한 번 갱신</span>
        </div>
        <?= $this->partial('partials/domain-table', ['rows' => array_map(static fn (array $m): array => $m + [
            'label' => $m['name'],
            'href' => null,
            'identifier' => $m['url'],
        ], $s['http'])]) ?>
    </section>
<?php endif ?>

<?php if ($apps !== []): ?>
    <section class="section">
        <div class="section-head">
            <h2>앱</h2>
            <span class="section-head-aside">
                <span class="muted small">iOS 버전·업데이트일은 하루 한 번 App Store 자동 조회, Android 는 직접 입력</span>
                <?php if (isset($currentUser) && $currentUser->can('admin')): ?><a href="/settings/apps">앱 관리</a><?php endif ?>
            </span>
        </div>
        <?= $this->partial('partials/app-table', ['apps' => $apps, 'editable' => isset($currentUser) && $currentUser->can('admin'), 'showSystem' => false]) ?>
    </section>
<?php endif ?>

<?php if ($showAwsResources): ?>
<section class="section">
    <div class="section-head">
        <h2>EC2</h2>
        <span class="muted small">CloudWatch · 5분 평균</span>
    </div>
    <div class="resource-grid">
        <?php foreach ($s['ec2'] as $i): ?>
            <div class="card resource-card">
                <div class="resource-head">
                    <div>
                        <div class="resource-name"><?= e($i['name']) ?></div>
                        <div class="muted small mono"><?= e($i['instance_id']) ?> · <?= e($i['instance_type']) ?></div>
                    </div>
                    <?= $this->partial('partials/status-badge', ['status' => $i['status']]) ?>
                </div>
                <div class="muted small">상태 검사 <?= match ($i['status_check_ok']) { true => '통과', false => '실패', null => '-' } ?></div>
                <?= $this->partial('partials/meter', ['label' => 'CPU', 'value' => $i['cpu'], 'text' => pct($i['cpu']), 'warn' => $i['cpu'] >= Thresholds::EC2_CPU]) ?>
                <?= $this->partial('partials/meter', ['label' => '메모리', 'value' => $i['memory'], 'text' => pct($i['memory']), 'warn' => $i['memory'] >= Thresholds::EC2_MEMORY]) ?>
                <?= $this->partial('partials/meter', ['label' => '디스크', 'value' => $i['disk'], 'text' => pct($i['disk']), 'warn' => $i['disk'] >= Thresholds::EC2_DISK]) ?>
                <div class="trend">
                    <div class="trend-label muted small">CPU 최근 2시간</div>
                    <?= sparkline($i['series']['cpu'], 100) ?>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2>RDS</h2>
        <span class="muted small">CloudWatch · 5분 평균</span>
    </div>
    <?php if ($s['rds'] === []): ?>
        <div class="card empty">등록된 RDS가 없습니다.</div>
    <?php else: ?>
        <div class="resource-grid">
            <?php foreach ($s['rds'] as $d): ?>
                <div class="card resource-card">
                    <div class="resource-head">
                        <div>
                            <div class="resource-name"><?= e($d['name']) ?></div>
                            <div class="muted small mono"><?= e($d['identifier']) ?> · <?= e($d['engine']) ?></div>
                        </div>
                        <?= $this->partial('partials/status-badge', ['status' => $d['status']]) ?>
                    </div>
                    <div class="muted small"><?= $d['available'] ? 'available' : '사용 불가' ?></div>
                    <?= $this->partial('partials/meter', ['label' => 'CPU', 'value' => $d['cpu'], 'text' => pct($d['cpu']), 'warn' => $d['cpu'] >= Thresholds::RDS_CPU]) ?>
                    <?= $this->partial('partials/meter', [
                        'label' => '연결 수',
                        'value' => $d['connection_ratio'],
                        'text' => ($d['connections'] ?? '-') . ' / ' . ($d['max_connections'] ?: '-'),
                        'warn' => $d['connection_ratio'] >= Thresholds::RDS_CONNECTIONS_RATIO,
                    ]) ?>
                    <?= $this->partial('partials/meter', ['label' => '여유 메모리', 'value' => $d['free_memory'], 'text' => pct($d['free_memory']), 'warn' => $d['free_memory'] !== null && $d['free_memory'] < Thresholds::RDS_FREE_MEMORY_MIN]) ?>
                    <?= $this->partial('partials/meter', ['label' => '여유 스토리지', 'value' => $d['free_storage'], 'text' => pct($d['free_storage']), 'warn' => $d['free_storage'] !== null && $d['free_storage'] < Thresholds::RDS_FREE_STORAGE_MIN]) ?>
                    <div class="trend-pair">
                        <div class="trend">
                            <div class="trend-label muted small">CPU 최근 2시간</div>
                            <?= sparkline($d['series']['cpu'], 100) ?>
                        </div>
                        <div class="trend">
                            <div class="trend-label muted small">연결 수 최근 2시간</div>
                            <?= sparkline($d['series']['connections'], $d['max_connections'] > 0 ? (float) $d['max_connections'] : null) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</section>
<?php endif ?>

<section class="section">
    <div class="section-head">
        <h2>장애 이력</h2>
    </div>
    <div class="card card-flush">
        <?= $this->partial('partials/incident-table', ['incidents' => $incidents, 'showSystem' => false]) ?>
    </div>
</section>
