<?php
/**
 * @var string $heading @var ?string $sub @var ?string $crumb @var ?string $crumbHref @var ?string $aside
 * @var ?list<array{icon?: string, html: string, class?: string, title?: string}> $meta 제목 아래 정보 칩 (html 은 이스케이프된 값)
 */
$icons = [
    'live' => '<span class="meta-live" aria-hidden="true"></span>',
    'clock' => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.3 1.5"/></svg>',
    'refresh' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9"/><path d="M13.5 2.5v3h-3"/></svg>',
    'user' => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="5.5" r="2.8"/><path d="M2.8 14a5.2 5.2 0 0 1 10.4 0"/></svg>',
    'cloud' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 12.5h7a3 3 0 0 0 .3-6 4 4 0 0 0-7.6 1A2.5 2.5 0 0 0 4.5 12.5Z"/></svg>',
];
?>
<header class="page-head">
    <div>
        <?php if (!empty($crumb)): ?>
            <a class="crumb" href="<?= e($crumbHref ?? '/') ?>">← <?= e($crumb) ?></a>
        <?php endif ?>
        <h1><?= $heading ?></h1>
        <?php if (!empty($sub)): ?>
            <p class="page-sub"><?= $sub ?></p>
        <?php endif ?>
        <?php if (!empty($meta)): ?>
            <ul class="page-meta">
                <?php foreach ($meta as $item): ?>
                    <li class="meta-item<?= isset($item['class']) ? ' ' . e($item['class']) : '' ?>"<?= isset($item['title']) ? ' title="' . e($item['title']) . '"' : '' ?>>
                        <?= $icons[$item['icon'] ?? ''] ?? '' ?><span><?= $item['html'] ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
    <?php if (!empty($aside)): ?>
        <div class="page-head-aside"><?= $aside ?></div>
    <?php endif ?>
</header>
