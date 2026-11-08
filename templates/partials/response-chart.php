<?php
use App\Monitoring\Thresholds;
use App\Monitoring\TimeRange;

/**
 * 웹/API 응답 시간 차트. 그리기와 툴팁은 /assets/chart.js 가 하고, JS 가 없어도 표로 볼 수 있다.
 *
 * @var TimeRange $range
 * @var list<array{name: string, url: string, points: list<array{t: int, avg: ?int, max: ?int, down: int, n: int}>}> $series
 * @var bool $compact 기간 선택·표 없이 차트만 (대시보드 상세 패널)
 */
$compact ??= false;
$hasData = array_filter($series, static fn (array $s): bool => $s['points'] !== []) !== [];
$ms = static fn (?int $v): string => $v === null ? '-' : number_format($v) . 'ms';

// 표: 최근 묶음부터, 행 = 묶음 시각
$rows = [];
foreach ($series as $i => $s) {
    foreach ($s['points'] as $p) {
        $rows[$p['t']][$i] = $p;
    }
}
krsort($rows);

$chartData = [
    'from' => $range->from->getTimestamp(),
    'to' => $range->to->getTimestamp(),
    'bucket' => $range->bucketSeconds,
    // 눈금·시각 표시를 서버 시간대(KST)에 맞춘다
    'tzOffset' => (int) $range->to->format('Z'),
    'slowMs' => Thresholds::HTTP_SLOW_MS,
    'series' => array_map(static fn (array $s): array => [
        'name' => $s['name'],
        'points' => array_map(static fn (array $p): array => [$p['t'], $p['avg'], $p['max'], $p['down'], $p['n']], $s['points']),
    ], $series),
];
$customOpen = $range->isCustom() || $range->error !== null;
?>
<?php if (!$compact): ?>
<div class="range-bar">
    <nav class="tabs range-tabs" aria-label="조회 기간">
        <?php foreach (TimeRange::PRESETS as $key => [$label]): ?>
            <a class="tab<?= $range->key === $key && $range->error === null ? ' is-active' : '' ?>" href="?range=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
    <details class="range-custom"<?= $customOpen ? ' open' : '' ?>>
        <summary class="tab<?= $range->isCustom() ? ' is-active' : '' ?>">기타 기간</summary>
        <form class="range-form" method="get" action="">
            <input type="hidden" name="range" value="<?= TimeRange::CUSTOM ?>">
            <label>시작 <input type="datetime-local" name="from" required value="<?= e($range->from->format(TimeRange::INPUT_FORMAT)) ?>"></label>
            <label>끝 <input type="datetime-local" name="to" required value="<?= e($range->to->format(TimeRange::INPUT_FORMAT)) ?>"></label>
            <button class="btn btn-small btn-primary" type="submit">조회</button>
        </form>
    </details>
</div>
<?php if ($range->error !== null): ?>
    <p class="field-error range-error"><?= e($range->error) ?> 최근 1시간을 보여 줍니다.</p>
<?php endif ?>
<?php endif ?>

<div class="card chart-card<?= $compact ? ' chart-compact' : '' ?>">
    <?php if (!$hasData): ?>
        <div class="empty">이 기간에 응답 시간 기록이 없습니다.</div>
    <?php else: ?>
        <div class="chart-legend" hidden></div>
        <div class="chart-plot" role="img" aria-label="<?= e($range->label()) ?> 응답 시간 추이"></div>
        <script src="<?= asset('chart.js') ?>" defer></script>
        <script type="application/json" class="chart-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>

        <?php if (!$compact): ?>
        <details class="chart-table">
            <summary class="muted small">표로 보기</summary>
            <div class="table-wrap">
                <table class="table table-grid">
                    <thead>
                    <tr>
                        <th>시각</th>
                        <?php foreach ($series as $s): ?>
                            <th class="num"><?= e($s['name']) ?> 평균</th>
                            <th class="num">최대</th>
                            <th class="num">장애</th>
                        <?php endforeach ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $t => $cells): ?>
                        <tr>
                            <td class="nowrap mono small"><?= e(date('m-d H:i', $t)) ?></td>
                            <?php foreach ($series as $i => $s): $p = $cells[$i] ?? null ?>
                                <td class="num<?= ($p['avg'] ?? 0) >= Thresholds::HTTP_SLOW_MS ? ' t-warn' : '' ?>"><?= $ms($p['avg'] ?? null) ?></td>
                                <td class="num muted"><?= $ms($p['max'] ?? null) ?></td>
                                <td class="num<?= ($p['down'] ?? 0) > 0 ? ' t-down' : ' muted' ?>"><?= $p === null ? '-' : ($p['down'] > 0 ? $p['down'] . '회' : '0') ?></td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif ?>
    <?php endif ?>
</div>
