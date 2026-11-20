<?php
use App\Controller\ArchitectureController as Arch;
use App\Monitoring\DomainInfo;
use App\Monitoring\Status;
use App\Monitoring\SystemRegistry;
use App\Monitoring\Thresholds;

/**
 * 시스템 구성도. 계층(사용자 → 도메인·보안 → 웹 서버 → 데이터 → 외부 연동)마다 띠 하나.
 *
 * @var array<string, array<string, mixed>> $systems @var ?array<string, mixed> $selected
 * @var array<string, list<array<string, mixed>>> $appsBySystem @var bool $showAwsResources
 */

/*
 * 노드 상태: Status::* (점검 결과) | ok (한눈에 등록된 값) | stack (공통 스택, 버전 미수집) | todo (확인 필요)
 */
$stateLabel = static fn (string $state, ?string $label = null): string => match ($state) {
    'ok', 'stack', 'todo' => '<span class="arch-st arch-st-' . $state . '"><i class="arch-mark"></i>' . e($label ?? ['ok' => '등록됨', 'stack' => '공통 스택', 'todo' => '확인 필요'][$state]) . '</span>',
    default => '<span class="arch-st"><span class="dot s-' . e($state) . '"></span>' . e($label ?? ($state === Status::UNKNOWN ? '점검 전' : status_label($state))) . '</span>',
};
$ico = static fn (string $text, string $color): string => '<span class="arch-ico" style="--c:' . $color . '" aria-hidden="true">' . e($text) . '</span>';
/** $sub, $extra 는 이스케이프된 HTML */
$node = static fn (string $title, string $sub, string $state, string $icon = '', string $extra = '', ?string $label = null): string =>
    '<div class="arch-node' . ($state === 'todo' ? ' is-todo' : '') . '"><div class="arch-node-t">' . $icon . '<span>' . e($title) . '</span></div>'
    . ($sub === '' ? '' : '<div class="arch-node-s">' . $sub . '</div>') . $extra . $stateLabel($state, $label) . '</div>';
$band = static fn (int $n, string $ko, string $en, string $body): string =>
    '<section class="arch-band arch-b' . $n . '"><div class="arch-band-h"><h2>' . e($ko) . '</h2><span>' . e($en) . '</span></div><div class="arch-band-b">' . $body . '</div></section>';
$iconImg = static fn (array $a, int $size): string => empty($a['icon_file'])
    ? '<span class="app-icon app-icon-empty" style="width:' . $size . 'px;height:' . $size . 'px" title="' . e($a['name']) . '">?</span>'
    : '<img class="app-icon" src="/app-icons/' . e(rawurlencode((string) $a['icon_file'])) . '" width="' . $size . '" height="' . $size . '" alt="" title="' . e($a['name']) . '">';
$appList = static fn (array $apps): string => '<ul class="arch-apps">' . implode('', array_map(static fn (array $a): string =>
    '<li><a href="/apps/' . (int) $a['id'] . '">' . $iconImg($a, 22) . '<span>' . e($a['name']) . '</span></a><span class="muted num">'
    . ($a['version'] ? 'v' . e($a['version']) : '버전 미수집') . '</span></li>', $apps)) . '</ul>';
$appIcons = static fn (array $apps): string => '<div class="arch-icons">' . implode('', array_map(static fn (array $a): string =>
    '<a href="/apps/' . (int) $a['id'] . '">' . $iconImg($a, 28) . '</a>', $apps)) . '</div>';
$byPlatform = static fn (array $apps, string $p): array => array_values(array_filter($apps, static fn (array $a): bool => $a['platform'] === $p));
$host = static fn (?string $url): string => (string) (parse_url((string) $url, PHP_URL_HOST) ?? '');
$sslDays = static function (array $http): ?int {
    $days = array_filter(array_column($http, 'ssl_days_left'), static fn ($d): bool => $d !== null);

    return $days === [] ? null : min($days);
};
$sslNode = static function (?int $days, string $sub) use ($node, $ico): string {
    if ($days === null) {
        return $node('SSL / TLS', e($sub), 'unknown', $ico('S', 'var(--series-3)'));
    }
    $state = $days < Thresholds::SSL_WARN_DAYS ? Status::WARNING : Status::UP;

    return $node('SSL / TLS', e($sub . ' · 가장 빠른 만료 ' . $days . '일 남음'), $state, $ico('S', 'var(--series-3)'), '', $state === Status::UP ? 'HTTPS 정상' : '만료 임박');
};
$accounts = static fn (array $apps): string => implode(', ', array_unique(array_filter(array_column($apps, 'account_name')))) ?: '개발자 계정 미지정';

$C = ['ios' => '#1c1c1e', 'android' => '#1baf7a', 'web' => 'var(--series-1)', 'admin' => 'var(--unknown)', 'aws' => '#ff9900',
    'nginx' => '#009639', 'php' => '#777bb3', 'mysql' => '#00758f', 'store' => '#0d96f6', 'play' => '#01875f',
    'push' => 'var(--series-2)', 'pay' => 'var(--series-7)', 'sms' => 'var(--series-8)', 'social' => 'var(--series-1)', 'muted' => 'var(--unknown)'];

$totalApps = array_sum(array_map('count', $appsBySystem));
$tabs = ['' => ['전체', count($systems)]];
foreach ($systems as $code => $s) {
    $tabs[$code] = [$s['name'], count($appsBySystem[$code] ?? [])];
}
$current = $selected['code'] ?? '';
?>
<?= $this->partial('partials/page-head', [
    'heading' => '시스템 구성도',
    'sub' => '서비스마다 사용자 접점부터 도메인, 서버(Nginx·PHP), MySQL, 외부 연동까지 계층별로 봅니다. 상태는 1분 점검 결과입니다.',
    'aside' => '<div class="arch-legend">' . $stateLabel(Status::UP, '점검 결과') . $stateLabel('ok', '한눈에 등록') . $stateLabel('stack', '공통 스택') . $stateLabel('todo', '확인 필요') . '</div>',
]) ?>

<nav class="tabs" aria-label="서비스">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
        <a class="tab<?= $key === $current ? ' is-active' : '' ?>" href="/architecture<?= $key === '' ? '' : '?system=' . e(rawurlencode((string) $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="tab-count"><?= $key === '' ? $count : ($count > 0 ? $count . '앱' : '웹') ?></span>
        </a>
    <?php endforeach ?>
</nav>

<?php if ($selected !== null):
    $s = $selected;
    $apps = $appsBySystem[$s['code']] ?? [];
    $ios = $byPlatform($apps, 'ios');
    $android = $byPlatform($apps, 'android');
    $webApps = $byPlatform($apps, 'web');
    $isAws = $s['cloud'] === 'aws';
    $webHttp = array_values(array_filter($s['http'], static fn (array $m): bool => $m['kind'] === 'web'));

    // 1. 사용자
    $client = '';
    if ($ios !== []) { $client .= $node('iOS 앱 ' . count($ios) . '개', 'App Store 배포', 'ok', $ico('i', $C['ios']), $appList($ios)); }
    if ($android !== []) { $client .= $node('Android 앱 ' . count($android) . '개', 'Google Play 배포', 'ok', $ico('A', $C['android']), $appList($android)); }
    if ($webApps !== []) { $client .= $node('웹앱 ' . count($webApps) . '개', '', 'ok', $ico('W', $C['web']), $appList($webApps)); }
    if ($webHttp !== []) { $client .= $node('웹 브라우저', 'PC / Mobile', 'ok', $ico('W', $C['web'])); }
    if (!empty($s['admin_url'])) {
        $client .= $node('관리자 페이지', '<a href="' . e($s['admin_url']) . '" target="_blank" rel="noopener noreferrer">' . e($host($s['admin_url'])) . ' ↗</a>', 'ok', $ico('M', $C['admin']));
    }
    if ($apps === []) { $client .= $node('스토어 앱 없음', '웹 서비스', 'ok', $ico('–', $C['muted']), '', '앱 미등록'); }

    // 2. 도메인·보안
    $domain = '';
    foreach ($s['http'] as $m) {
        $info = $m['domain_info'] ?? null;
        $parts = [e(SystemRegistry::HTTP_KINDS[$m['kind']] ?? '웹/API') . ' · <code>' . e((string) (parse_url((string) $m['url'], PHP_URL_PATH) ?: '/')) . '</code>'];
        if ($info !== null) {
            $parts[] = 'DNS ' . e(dns_provider($info['nameservers'] ?? [])) . ' · 등록 ' . e(registrar_label($info['registrar'] ?? null));
            if (!empty($info['expires_at'])) { $parts[] = '도메인 만료 ' . expiry_cell((string) $info['expires_at']); }
        }
        if ($m['resolved_ips'] !== null) { $parts[] = 'IP <code>' . e(str_replace(',', ', ', (string) $m['resolved_ips'])) . '</code>'; }
        $label = $m['status'] === Status::UNKNOWN ? '점검 전' : status_label($m['status']) . ($m['response_ms'] === null ? '' : ' · ' . number_format($m['response_ms']) . 'ms');
        $domain .= $node($m['host'], implode('<br>', $parts), $m['status'], $ico('D', $C['web']), '', $label);
    }
    if (!empty($s['admin_url'])) {
        $domain .= $node($host($s['admin_url']), '관리자 · 점검 대상 아님', 'ok', $ico('D', $C['admin']));
    }
    $domain .= $sslNode($sslDays($s['http']), 'HTTPS');
    $domain .= $isAws
        ? $node('DDoS 방어 · WAF', 'AWS Shield Standard 기본 적용 · WAF 사용 여부 미확인', 'todo', $ico('W', 'var(--series-7)'))
        : $node('DDoS 방어', e(SystemRegistry::CLOUD_PROVIDERS[$s['cloud']] ?? $s['cloud']) . ' 기본 방어 · 설정 미확인', 'todo', $ico('W', 'var(--series-7)'));

    // 3. 웹 서버
    if ($isAws) {
        $ec2 = '';
        if ($showAwsResources && $s['ec2'] !== []) {
            foreach ($s['ec2'] as $r) {
                $ec2 .= $node('EC2 ' . $r['name'], '<code>' . e($r['instance_id']) . '</code> · ' . e($r['instance_type']) . ($r['cpu'] === null ? '' : ' · CPU ' . e(pct($r['cpu']))), $r['status'], $ico('E', $C['aws']));
            }
        } else {
            $ec2 = $node('EC2', $s['code'] === Arch::HANNUN_HOST ? '인스턴스 미등록 · 한눈·Uptime Kuma 같이 실행' : '인스턴스 미등록', 'todo', $ico('E', $C['aws']), '', 'AWS 연동 전');
        }
        $web = '<div class="arch-group"><div class="arch-group-h"><b>' . e(cloud_label('aws', $s['aws_alias'], $s['aws_account'] === '-' ? null : $s['aws_account'])) . '</b><span class="muted">' . e($s['region']) . '</span></div><div class="arch-row">'
            . $ec2
            . $node(Arch::STACK['nginx'][0], e(Arch::STACK['nginx'][1]), 'stack', $ico('N', $C['nginx']), '', '공통 스택 · 버전 미수집')
            . $node(Arch::STACK['php'][0], e($s['http'] !== [] && array_filter($s['http'], static fn (array $m): bool => $m['kind'] === 'api') ? 'API 서버' : '웹 애플리케이션'), 'stack', $ico('P', $C['php']), '', '공통 스택 · 버전 미수집')
            . (empty($s['admin_url']) ? '' : $node('관리자 애플리케이션', e($host($s['admin_url'])), 'stack', $ico('P', $C['php'])))
            . '</div></div>';
    } else {
        $web = $node(SystemRegistry::CLOUD_PROVIDERS[$s['cloud']] ?? $s['cloud'], '호스팅' . ($s['company_name'] ? ' · ' . e($s['company_name']) : ''), 'ok', $ico('▲', '#111'))
            . $node('런타임', 'Nginx·PHP 해당 없음 · 프레임워크 미확인', 'todo', $ico('?', $C['muted']));
    }

    // 4. 데이터
    if ($isAws) {
        $data = '';
        if ($showAwsResources && $s['rds'] !== []) {
            foreach ($s['rds'] as $r) {
                $data .= $node('RDS ' . $r['name'], '<code>' . e($r['identifier']) . '</code> · ' . e($r['engine']), $r['status'], $ico('M', $C['mysql']));
            }
        } else {
            $data = $node(Arch::STACK['mysql'][0], e(Arch::STACK['mysql'][1]), 'stack', $ico('M', $C['mysql']), '', '공통 스택 · 위치 미확인');
        }
        $data .= $node('파일 스토리지', 'S3 사용 여부·버킷 미확인', 'todo', $ico('S3', '#569a31'))
            . $node('백업', 'RDS·EBS 스냅샷 정책 미확인', 'todo', $ico('B', $C['muted']));
    } else {
        $data = $node('데이터 저장소', '사용하는 DB 미확인', 'todo', $ico('?', $C['muted']));
    }

    // 5. 외부 연동
    $ext = '';
    if ($ios !== []) { $ext .= $node('App Store Connect', e($accounts($ios)), 'ok', $ico('A', $C['store']), '', '개발자 계정 등록'); }
    if ($android !== []) { $ext .= $node('Google Play Console', e($accounts($android)), 'ok', $ico('G', $C['play']), '', '개발자 계정 등록'); }
    foreach (Arch::EXTERNAL_TODO as [$title, $sub, $appsOnly]) {
        if (!$appsOnly || $apps !== []) {
            $ext .= $node($title, e($sub), 'todo', $ico(mb_substr($title, 0, 1), $C['muted']));
        }
    }
    ?>
    <ul class="arch-meta">
        <li>관리회사 <b><?= e($s['company_name'] ?? '미지정') ?></b></li>
        <li><?= e(cloud_label($s['cloud'], $s['aws_alias'], $s['aws_account'] === '-' ? null : $s['aws_account'])) ?></li>
        <li>담당 <b><?= e($s['owner']) ?></b></li>
        <li><a href="/systems/<?= e($s['code']) ?>">서비스 상세 →</a></li>
    </ul>
    <div class="arch">
        <?= $band(1, '사용자', 'CLIENT', $client) ?>
        <?= $band(2, '도메인 · 보안', 'DOMAIN / SECURITY', $domain) ?>
        <?= $band(3, '웹 서버', $isAws ? 'WEB · AWS EC2' : 'WEB · ' . strtoupper((string) $s['cloud']), $web) ?>
        <?= $band(4, '데이터', 'DATA', $data) ?>
        <?= $band(5, '외부 연동', 'EXTERNAL', $ext) ?>
    </div>

<?php else:
    $all = array_merge(...array_values($appsBySystem ?: [[]]));
    $ios = $byPlatform($all, 'ios');
    $android = $byPlatform($all, 'android');
    $names = static fn (callable $fn): string => implode(' · ', array_map(static fn (array $s): string => $s['name'], array_filter($systems, $fn))) ?: '-';

    $client = ($ios === [] ? '' : $node('iOS 앱 ' . count($ios) . '개', 'App Store', 'ok', $ico('i', $C['ios']), $appIcons($ios)))
        . ($android === [] ? '' : $node('Android 앱 ' . count($android) . '개', 'Google Play', 'ok', $ico('A', $C['android']), $appIcons($android)))
        . $node('웹 브라우저', e($names(static fn (array $s): bool => (bool) array_filter($s['http'], static fn (array $m): bool => $m['kind'] === 'web'))), 'ok', $ico('W', $C['web']))
        . $node('관리자 페이지', e($names(static fn (array $s): bool => !empty($s['admin_url']))), 'ok', $ico('M', $C['admin']));

    $domain = '';
    foreach ($systems as $s) {
        $rows = array_map(static fn (array $m): string => '<li><span class="dot s-' . e($m['status']) . '"></span><a class="mono" href="/architecture?system=' . e(rawurlencode($s['code'])) . '">' . e($m['host']) . '</a></li>', $s['http']);
        if (!empty($s['admin_url'])) { $rows[] = '<li><span class="arch-mark-ok"></span><span class="mono muted">' . e($host($s['admin_url'])) . '</span></li>'; }
        $domain .= '<div class="arch-group"><div class="arch-group-h"><b>' . e($s['name']) . '</b>' . $this->partial('partials/status-badge', ['status' => $s['http_status']]) . '</div><ul class="arch-hosts">' . implode('', $rows) . '</ul></div>';
    }
    $domain .= $sslNode($sslDays(array_merge(...array_values(array_map(static fn (array $s): array => $s['http'], $systems)) ?: [[]])), '전체 HTTPS');

    $groups = [];
    foreach ($systems as $s) {
        $key = $s['cloud'] === 'aws' ? 'aws:' . $s['aws_account'] : $s['cloud'];
        $groups[$key][] = $s;
    }
    $web = '';
    foreach ($groups as $key => $list) {
        $first = $list[0];
        $isAws = $first['cloud'] === 'aws';
        $links = implode('', array_map(static fn (array $s): string => '<a class="arch-chip" href="/architecture?system=' . e(rawurlencode($s['code'])) . '"><span class="dot s-' . e($s['status']) . '"></span>' . e($s['name']) . '</a>', $list));
        $hostsHannun = in_array(Arch::HANNUN_HOST, array_column($list, 'code'), true);
        $web .= '<div class="arch-group"><div class="arch-group-h"><b>' . e($isAws ? 'AWS · ' . ($first['aws_alias'] ?? '계정 미지정') : (SystemRegistry::CLOUD_PROVIDERS[$first['cloud']] ?? $first['cloud'])) . '</b><span class="muted">' . e($first['company_name'] ?? '') . '</span></div>'
            . '<div class="arch-chips">' . $links . '</div>'
            . ($isAws
                ? '<div class="arch-chain">' . $ico('E', $C['aws']) . 'EC2<span aria-hidden="true">→</span>' . $ico('N', $C['nginx']) . 'Nginx<span aria-hidden="true">→</span>' . $ico('P', $C['php']) . 'PHP-FPM</div>'
                : '<div class="arch-node-s">서버리스 호스팅 · Nginx·PHP 해당 없음</div>')
            . ($hostsHannun ? '<div class="arch-node-s"><b class="arch-accent">한눈 · Uptime Kuma</b> 같은 서버에서 실행</div>' : '')
            . '</div>';
    }

    $awsCount = count(array_filter($systems, static fn (array $s): bool => $s['cloud'] === 'aws'));
    $data = $node('MySQL', 'AWS 서비스 ' . $awsCount . '곳 · RDS / EC2 직접 설치 미확인', 'stack', $ico('M', $C['mysql']), '', '공통 스택 · 위치 미확인')
        . $node('파일 스토리지', 'S3 사용 여부 미확인', 'todo', $ico('S3', '#569a31'))
        . $node('백업', '스냅샷 정책 미확인', 'todo', $ico('B', $C['muted']));

    $countAccounts = static fn (array $apps): int => count(array_unique(array_filter(array_column($apps, 'account_name'))));
    $ext = $node('App Store Connect', '개발자 계정 ' . $countAccounts($ios) . '개', 'ok', $ico('A', $C['store']))
        . $node('Google Play Console', '개발자 계정 ' . $countAccounts($android) . '개', 'ok', $ico('G', $C['play']))
        . $node('알리고 SMS', '한눈 장애 알림 문자', 'ok', $ico('S', $C['sms']), '', '한눈에서 사용');
    foreach (Arch::EXTERNAL_TODO as [$title, $sub]) {
        if ($title !== 'SMS') { $ext .= $node($title, e($sub), 'todo', $ico(mb_substr($title, 0, 1), $C['muted'])); }
    }
    ?>
    <ul class="arch-meta">
        <li>서비스 <b><?= count($systems) ?></b></li>
        <li>클라우드 계정 <b><?= count($groups) ?></b></li>
        <li>스토어 앱 <b><?= $totalApps ?></b></li>
        <li>점검 주소 <b><?= array_sum(array_map(static fn (array $s): int => count($s['http']), $systems)) ?></b></li>
    </ul>
    <div class="arch">
        <?= $band(1, '사용자', 'CLIENT', $client) ?>
        <?= $band(2, '도메인 · 보안', 'DOMAIN / SECURITY', $domain) ?>
        <?= $band(3, '웹 서버', 'WEB', $web) ?>
        <?= $band(4, '데이터', 'DATA', $data) ?>
        <?= $band(5, '외부 연동', 'EXTERNAL', $ext) ?>
    </div>
<?php endif ?>

<div class="arch-hannun">
    <b>한눈 · Uptime Kuma</b>
    <span><?= e(($systems[Arch::HANNUN_HOST]['name'] ?? '코이') . ' 서버에 함께 설치') ?></span>
    <span>→ 서비스별 웹·API 주소 1분 점검</span>
    <span>→ 장애 시 알리고 문자</span>
</div>

<section class="section">
    <div class="section-head"><h2>채워야 할 정보</h2></div>
    <div class="card">
        <ul class="arch-todo">
            <?php if (!$showAwsResources): ?>
                <li>EC2·RDS 는 AWS 연동 전이라 비어 있습니다. 연동하면 인스턴스 유형, 상태, CPU 가 이 화면에 나옵니다 (<code>AWS_RESOURCES=true</code>).</li>
            <?php endif ?>
            <li>Nginx·PHP·MySQL 버전은 서버 안에서 확인해야 해서 아직 수집하지 않습니다.</li>
            <li>결제·푸시·SMS·소셜 로그인·S3 사용 여부는 서비스별 기록이 없어 “확인 필요”로 보입니다.</li>
        </ul>
    </div>
</section>
