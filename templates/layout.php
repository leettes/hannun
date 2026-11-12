<?php
/**
 * @var string $title @var string $active @var string $content @var array $navSystems @var int $activeIncidentCount
 * @var ?App\Auth\User $currentUser @var ?string $csrfToken @var ?int $refresh 자동 새로고침(초), null 이면 안 함
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($refresh !== null): ?>
        <?php /* JS 가 있으면 refresh.js 가 새로고침한다 (입력 중에는 멈춤) */ ?>
        <noscript><meta http-equiv="refresh" content="<?= (int) $refresh ?>"></noscript>
    <?php endif ?>
    <title><?= e($title) ?> · 한눈</title>
    <link rel="icon" href="<?= asset('logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('theme.js') ?>"></script>
    <script src="<?= asset('nav.js') ?>" defer></script>
    <script src="<?= asset('table.js') ?>" defer></script>
    <?php if ($refresh !== null): ?>
        <script src="<?= asset('refresh.js') ?>" defer></script>
    <?php endif ?>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-top">
            <a class="brand" href="/"><img class="brand-mark" src="<?= asset('logo.svg') ?>" alt="" width="24" height="24">한눈</a>
            <?php /* 모바일에서만 보인다 */ ?>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="sidebar-menu" aria-label="메뉴 열기">
                <?php if ($activeIncidentCount > 0): ?>
                    <span class="nav-count" title="복구되지 않은 장애"><?= $activeIncidentCount ?></span>
                <?php endif ?>
                <span class="nav-toggle-icon" aria-hidden="true"></span>
            </button>
        </div>

        <div class="sidebar-menu" id="sidebar-menu">
        <nav class="nav">
            <a class="nav-item<?= $active === 'dashboard' ? ' is-active' : '' ?>" href="/">대시보드</a>
            <a class="nav-item<?= $active === 'incidents' ? ' is-active' : '' ?>" href="/incidents">
                장애 센터
                <?php if ($activeIncidentCount > 0): ?>
                    <span class="nav-count"><?= $activeIncidentCount ?></span>
                <?php endif ?>
            </a>
            <a class="nav-item<?= $active === 'architecture' ? ' is-active' : '' ?>" href="/architecture">시스템 구성도</a>
            <?php if (isset($currentUser) && $currentUser->can('admin')): ?>
                <?php
                $settingsMenu = ['settings' => ['/settings/systems', '서비스 관리'], 'aws-accounts' => ['/settings/aws-accounts', 'AWS 계정'], 'companies' => ['/settings/companies', '관리회사'], 'apps' => ['/settings/apps', '앱'], 'app-accounts' => ['/settings/app-accounts', '개발자 계정'], 'users' => ['/users', '사용자 관리']];
                $inSettings = isset($settingsMenu[$active]);
                ?>
                <?php /* 설정 하위 메뉴. 지금 보고 있는 화면이 설정 안이면 펼쳐 둔다 */ ?>
                <details class="nav-group"<?= $inSettings ? ' open' : '' ?>>
                    <summary class="nav-item<?= $inSettings ? ' is-current' : '' ?>">설정</summary>
                    <div class="nav nav-sub">
                        <?php foreach ($settingsMenu as $key => [$href, $label]): ?>
                            <a class="nav-item<?= $active === $key ? ' is-active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
                        <?php endforeach ?>
                    </div>
                </details>
            <?php endif ?>
        </nav>

        <div class="nav-label">서비스</div>
        <?php /* 서비스: 이름 앞 두 글자 타일. 전체 이름과 상태는 툴팁 */ ?>
        <nav class="service-grid" aria-label="서비스">
            <?php foreach ($navSystems as $s): ?>
                <?php $label = $s['name'] . ($s['status'] === App\Monitoring\Status::UNKNOWN ? '' : ' · ' . status_label($s['status'])) ?>
                <a class="service-tile s-<?= e($s['status']) ?><?= $active === 'system:' . $s['code'] ? ' is-active' : '' ?>" href="/systems/<?= e($s['code']) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
                    <span aria-hidden="true"><?= e(service_short($s['name'], $s['short_name'] ?? null)) ?></span>
                    <span class="dot s-<?= e($s['status']) ?>" aria-hidden="true"></span>
                </a>
            <?php endforeach ?>
        </nav>

        <div class="sidebar-foot">
            <?php if (isset($currentUser)): ?>
                <?php /* 이름·역할은 툴팁과 내 정보 화면에서 */ ?>
                <a class="user<?= $active === 'profile' ? ' is-active' : '' ?>" href="/me"
                   title="<?= e($currentUser->name . ' · ' . $currentUser->roleLabel() . ' — 내 정보') ?>"
                   aria-label="<?= e('내 정보: ' . $currentUser->name . ' (' . $currentUser->roleLabel() . ')') ?>">
                    <span class="avatar" aria-hidden="true"><?= e(avatar_text($currentUser->name)) ?></span>
                </a>
                <div class="foot-actions">
                    <button class="theme-toggle" type="button" aria-label="테마 바꾸기">
                        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    </button>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
                        <button class="link-button" type="submit">로그아웃</button>
                    </form>
                </div>
            <?php endif ?>
        </div>
        </div>
    </aside>

    <main class="main">
        <?= $content ?>
        <footer class="site-footer">© <?= date('Y') ?> leetes. All rights reserved.</footer>
    </main>
</div>
<?php if ($refresh !== null): ?>
    <button type="button" class="refresh-timer" data-refresh-seconds="<?= (int) $refresh ?>" title="눌러서 지금 새로고침">
        <span class="refresh-bar" aria-hidden="true"></span>
        자동 새로고침 <span class="refresh-count" aria-live="off"><?= (int) $refresh ?>초</span>
    </button>
<?php endif ?>
</body>
</html>
