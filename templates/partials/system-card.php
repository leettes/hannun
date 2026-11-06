<?php
/**
 * 대시보드 서비스 카드. 전체 보기·계정별 보기 공용.
 *
 * @var string $code @var array<string, mixed> $s @var int $activeIncidents @var bool $showAwsResources
 */
?>
<a class="card system-card s-<?= e($s['status']) ?>" href="/systems/<?= e($code) ?>">
    <div class="system-card-head">
        <div>
            <div class="system-name"><?= e($s['name']) ?></div>
            <span class="env-tag env-<?= e($s['environment']) ?>"><?= e(App\Monitoring\SystemRegistry::ENVIRONMENTS[$s['environment']] ?? $s['environment']) ?></span>
        </div>
        <?= $this->partial('partials/status-badge', ['status' => $s['status']]) ?>
    </div>
    <ul class="checks">
        <?php
        // 최근 응답 속도: 여러 개면 가장 느린 값, 각각은 툴팁으로
        $responses = array_filter(array_column($s['http'], 'response_ms', 'name'), static fn ($ms): bool => $ms !== null);
        $slowest = $responses === [] ? null : max($responses);
        $responseText = match (true) {
            $slowest !== null => (count($s['http']) > 1 ? '최대 ' : '') . number_format($slowest) . 'ms',
            $s['http_status'] === App\Monitoring\Status::DOWN => '응답 없음',
            default => '-',
        };
        // 등록한 구분(웹/API)만 표시. 둘 다 있으면 '웹·API'
        $kinds = array_unique(array_column($s['http'], 'kind'));
        $kindLabel = $kinds === [] ? '웹/API' : implode('·', array_map(
            static fn (string $k): string => App\Monitoring\SystemRegistry::HTTP_KINDS[$k],
            array_values(array_intersect(array_keys(App\Monitoring\SystemRegistry::HTTP_KINDS), $kinds)),
        ));
        $responseTitle = implode(' · ', array_map(
            static fn (array $m): string => $m['name'] . ' ' . ($m['response_ms'] === null ? '-' : number_format($m['response_ms']) . 'ms'),
            $s['http'],
        ));
        ?>
        <li title="<?= e($responseTitle) ?>">
            <span class="dot s-<?= e($s['http_status']) ?>"></span><?= e($kindLabel) ?>
            <span class="<?= $slowest !== null && $slowest >= App\Monitoring\Thresholds::HTTP_SLOW_MS ? 't-warn' : 'muted' ?> num"><?= e($responseText) ?></span>
        </li>
        <?php if ($showAwsResources): ?>
            <li><span class="dot s-<?= e($s['ec2_status']) ?>"></span>EC2<span class="muted"><?= count($s['ec2']) ?></span></li>
            <li><span class="dot s-<?= e($s['rds_status']) ?>"></span>RDS<span class="muted"><?= count($s['rds']) ?: '-' ?></span></li>
        <?php endif ?>
    </ul>
    <div class="system-card-foot small">
        <?php if ($activeIncidents > 0): ?>
            <span class="foot-incident is-open">
                <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 15 14H1L8 1.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 6v3.5M8 11.6v.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                처리 중 장애 <?= (int) $activeIncidents ?>건
            </span>
        <?php else: ?>
            <span class="foot-incident">
                <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m5.2 8.2 1.9 1.9 3.7-3.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                처리 중 장애 없음
            </span>
        <?php endif ?>
        <span class="foot-time" title="마지막 점검 <?= e(fmt_time($s['last_checked_at'], 'Y-m-d H:i:s')) ?>">
            <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 4.5V8l2.3 1.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?= e(time_ago($s['last_checked_at'])) ?>
        </span>
    </div>
</a>
