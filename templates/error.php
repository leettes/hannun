<?php /** @var string $message @var ?string $heading @var ?bool $home */ ?>
<div class="card empty-page">
    <h1><?= e($heading ?? '페이지를 찾을 수 없습니다') ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <?php if ($home ?? true): ?>
        <a class="btn" href="/">대시보드로</a>
    <?php endif ?>
</div>
