<?php
use App\Monitoring\Status;
use App\Monitoring\SystemRegistry;
use App\Monitoring\Thresholds;

/**
 * 대시보드 서비스 카드. 전체 보기·관리회사별 보기 공용.
 *
 * @var string $code @var array<string, mixed> $s @var int $activeIncidents @var bool $showAwsResources
 * @var list<array<string, mixed>> $apps 이 서비스의 사용 중인 앱 @var bool $selected 아래 상세 패널에 열린 서비스
 * @var bool $canEdit 관리자 (설정 버튼)
 */
$statusText = [Status::UP => '정상 운영', Status::WARNING => '경고', Status::DOWN => '장애', Status::UNKNOWN => '점검 전'][$s['status']] ?? $s['status'];

// 대표 웹/API: 웹 먼저, 없으면 첫 번째
$main = null;
foreach ($s['http'] as $m) {
    if ($main === null || ($m['kind'] === 'web' && $main['kind'] !== 'web')) {
        $main = $m;
    }
}
$siteUrl = null;
if ($main !== null && ($parts = parse_url((string) $main['url'])) !== false && isset($parts['host'])) {
    $siteUrl = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/';
}

// 최근 응답 속도: 여러 개면 가장 느린 값, 각각은 툴팁으로
$responses = array_filter(array_column($s['http'], 'response_ms', 'name'), static fn ($ms): bool => $ms !== null);
$slowest = $responses === [] ? null : max($responses);
$responseTitle = implode(' · ', array_map(
    static fn (array $m): string => $m['name'] . ' ' . ($m['response_ms'] === null ? '-' : number_format($m['response_ms']) . 'ms'),
    $s['http'],
));
$uptimes = array_filter(array_column($s['http'], 'uptime_24h'), static fn ($v): bool => $v !== null);
$uptime = $uptimes === [] ? null : min($uptimes);

// 미리보기: 플랫폼별(iOS / Android, 웹앱은 있을 때만) 앱 아이콘. 아이콘마다 그 앱 상세(/apps/{id})로
$byPlatform = ['ios' => [], 'android' => []];
foreach ($apps as $a) {
    $byPlatform[$a['platform']][] = $a;
}
if (($byPlatform['web'] ?? []) === []) {
    unset($byPlatform['web']);
}

// 로고: 아이콘이 있는 첫 앱(→ 그 앱 상세), 없으면 이름 첫 글자 타일(→ 서비스 상세)
$logoApp = null;
foreach ($apps as $a) {
    if (!empty($a['icon_file'])) {
        $logoApp = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'icon' => (string) $a['icon_file']];
        break;
    }
}
$hue = crc32($code) % 360;
$letter = mb_substr(service_short($s['name'], $s['short_name'] ?? null), 0, 1);

$hosts = array_values(array_unique(array_column($s['http'], 'host')));
$db = array_column($s['rds'], 'identifier');
?>
<article class="svc-card s-<?= e($s['status']) ?><?= $selected ? ' is-selected' : '' ?>" style="--hue: <?= (int) $hue ?>">
    <header class="svc-head">
        <a class="svc-logo" href="<?= $logoApp !== null ? '/apps/' . $logoApp['id'] : '/systems/' . e($code) ?>"<?= $logoApp !== null ? ' title="' . e($logoApp['name']) . ' 앱 상세"' : '' ?> aria-hidden="true" tabindex="-1">
            <?php if ($logoApp !== null): ?>
                <img src="/app-icons/<?= e(rawurlencode($logoApp['icon'])) ?>" width="52" height="52" alt="" loading="lazy">
            <?php else: ?>
                <span><?= e($letter) ?></span>
            <?php endif ?>
        </a>
        <div class="svc-title">
            <a class="svc-name" href="/systems/<?= e($code) ?>"><?= e($s['name']) ?></a>
            <?php if ($siteUrl !== null): ?>
                <a class="svc-domain" href="<?= e($siteUrl) ?>" target="_blank" rel="noopener noreferrer" title="사이트 새 창으로 열기">
                    <?= e($main['host']) ?>
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9 2.5h4.5V7M13.5 2.5 7.5 8.5M12 9.5V13a.5.5 0 0 1-.5.5h-8A.5.5 0 0 1 3 13V5a.5.5 0 0 1 .5-.5H7"/></svg>
                </a>
            <?php else: ?>
                <span class="svc-domain muted">등록된 주소 없음</span>
            <?php endif ?>
        </div>
        <span class="svc-status s-<?= e($s['status']) ?>"><span class="dot s-<?= e($s['status']) ?>"></span><?= e($statusText) ?></span>
    </header>

    <div class="svc-body">
        <?php if ($apps !== []): ?>
            <?php /* 영역 자체는 링크가 아니다 (링크 안에 링크를 둘 수 없음). 아이콘마다 앱 상세로 */ ?>
            <div class="svc-preview svc-preview-split">
                <?php foreach ($byPlatform as $platform => $list): ?>
                    <div class="svc-plat">
                        <span class="svc-plat-label"><?= e(App\Monitoring\AppRegistry::PLATFORMS[$platform] ?? $platform) ?></span>
                        <?php if ($list === []): ?>
                            <span class="svc-plat-empty">없음</span>
                        <?php else: ?>
                            <span class="svc-apps">
                                <?php foreach (array_slice($list, 0, 3) as $a): ?>
                                    <?php $appLabel = $a['name'] . ' (' . (App\Monitoring\AppRegistry::PLATFORMS[$platform] ?? $platform) . ') 앱 상세' ?>
                                    <a class="svc-app" href="/apps/<?= (int) $a['id'] ?>" title="<?= e($appLabel) ?>" aria-label="<?= e($appLabel) ?>">
                                        <?php if (!empty($a['icon_file'])): ?>
                                            <img src="/app-icons/<?= e(rawurlencode((string) $a['icon_file'])) ?>" width="36" height="36" alt="" loading="lazy">
                                        <?php else: ?>
                                            <span aria-hidden="true"><?= e(mb_substr((string) $a['name'], 0, 1)) ?></span>
                                        <?php endif ?>
                                    </a>
                                <?php endforeach ?>
                            </span>
                            <span class="svc-plat-count"><?= count($list) ?>개<?= count($list) > 3 ? ' (+' . (count($list) - 3) . ')' : '' ?></span>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        <?php else: ?>
            <a class="svc-preview" href="/systems/<?= e($code) ?>" aria-label="<?= e($s['name']) ?> 상세">
                <span class="svc-preview-mark"><?= e($s['name']) ?></span>
                <span class="svc-preview-label"><?= e($s['description'] !== '' ? $s['description'] : (SystemRegistry::ENVIRONMENTS[$s['environment']] ?? $s['environment'])) ?></span>
            </a>
        <?php endif ?>

        <dl class="svc-info">
            <div>
                <dt><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2 2 10 0 12M8 2c-2 2-2 10 0 12"/></svg>서버</dt>
                <dd class="break" title="<?= e(implode(', ', $hosts)) ?>"><?= e($hosts[0] ?? '-') ?><?= count($hosts) > 1 ? ' <span class="muted">외 ' . (count($hosts) - 1) . '</span>' : '' ?></dd>
            </div>
            <div>
                <dt><svg viewBox="0 0 16 16" aria-hidden="true"><rect x="2.5" y="2.5" width="11" height="4.5" rx="1"/><rect x="2.5" y="9" width="11" height="4.5" rx="1"/></svg>클라우드</dt>
                <dd><?= e($s['cloud'] === 'aws' ? ($s['aws_alias'] ?? 'AWS') : (SystemRegistry::CLOUD_PROVIDERS[$s['cloud']] ?? $s['cloud'])) ?></dd>
            </div>
            <div>
                <dt><svg viewBox="0 0 16 16" aria-hidden="true"><ellipse cx="8" cy="4" rx="5" ry="2"/><path d="M3 4v8c0 1.1 2.2 2 5 2s5-.9 5-2V4M3 8c0 1.1 2.2 2 5 2s5-.9 5-2"/></svg>DB</dt>
                <dd class="break"><?= e($db === [] ? '-' : implode(', ', $db) . ' (RDS)') ?></dd>
            </div>
            <?php if ($showAwsResources): ?>
                <div>
                    <dt><svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="1.5"/><path d="M6 1.5v1.5M10 1.5v1.5M6 13v1.5M10 13v1.5M1.5 6h1.5M1.5 10h1.5M13 6h1.5M13 10h1.5"/></svg>인스턴스</dt>
                    <dd class="svc-res">
                        <span><span class="dot s-<?= e($s['ec2_status']) ?>"></span>EC2<span class="muted"> <?= count($s['ec2']) ?></span></span>
                        <span><span class="dot s-<?= e($s['rds_status']) ?>"></span>RDS<span class="muted"> <?= count($s['rds']) ?: '-' ?></span></span>
                    </dd>
                </div>
            <?php endif ?>
        </dl>

        <div class="svc-metrics">
            <div title="<?= e($responseTitle) ?>">
                <div class="svc-metric-label">응답 시간</div>
                <div class="svc-metric-value<?= $slowest !== null && $slowest >= Thresholds::HTTP_SLOW_MS ? ' t-warn' : '' ?>">
                    <?php if ($slowest !== null): ?>
                        <?= count($s['http']) > 1 ? '<small>최대 </small>' : '' ?><?= number_format($slowest) ?><small>ms</small>
                        <span class="visually-hidden"><?= count($s['http']) > 1 ? '최대 ' : '' ?><?= number_format($slowest) ?>ms</span>
                    <?php else: ?>
                        <span class="muted"><?= $s['http_status'] === Status::DOWN ? '응답 없음' : '-' ?></span>
                    <?php endif ?>
                </div>
            </div>
            <div>
                <div class="svc-metric-label">24시간 가용률</div>
                <div class="svc-metric-value<?= $uptime !== null && $uptime < 99 ? ' t-warn' : '' ?>">
                    <?= $uptime === null ? '<span class="muted">-</span>' : number_format($uptime, $uptime >= 99.995 ? 0 : 2) . '<small>%</small>' ?>
                </div>
            </div>
        </div>
    </div>

    <footer class="svc-foot">
        <span class="svc-foot-meta small">
            <?php if ($activeIncidents > 0): ?>
                <a class="t-down" href="/incidents?status=open">처리 중 장애 <?= (int) $activeIncidents ?>건</a>
            <?php else: ?>
                <span class="muted">처리 중 장애 없음</span>
            <?php endif ?>
            <span class="muted" title="마지막 점검 <?= e(fmt_time($s['last_checked_at'], 'Y-m-d H:i:s')) ?>">· <?= e(time_ago($s['last_checked_at'])) ?></span>
        </span>
        <span class="svc-actions">
            <a class="btn btn-small btn-icon" href="/?system=<?= e($code) ?>#overview"<?= $selected ? ' aria-current="true"' : '' ?>>
                <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 13V8M8 13V3M13 13V6"/></svg>통계
            </a>
            <?php if ($canEdit && isset($s['id'])): ?>
                <a class="btn btn-small btn-icon" href="/settings/systems/<?= (int) $s['id'] ?>">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="2.2"/><path d="M8 1.8v1.8M8 12.4v1.8M1.8 8h1.8M12.4 8h1.8M3.6 3.6l1.3 1.3M11.1 11.1l1.3 1.3M3.6 12.4l1.3-1.3M11.1 4.9l1.3-1.3"/></svg>설정
                </a>
            <?php endif ?>
            <a class="btn btn-small btn-icon btn-go" href="/systems/<?= e($code) ?>">
                상세 페이지<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
            </a>
        </span>
    </footer>
</article>
