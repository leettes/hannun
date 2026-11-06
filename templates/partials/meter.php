<?php
/** @var string $label @var ?float $value 비율(%), 없으면 null @var string $text @var bool $warn */
?>
<div class="meter-row<?= $warn ? ' is-warn' : '' ?>">
    <div class="meter-label"><span><?= e($label) ?></span><strong><?= e($text) ?></strong></div>
    <div class="meter"><span style="width: <?= max(0, min(100, (float) $value)) ?>%"></span></div>
</div>
