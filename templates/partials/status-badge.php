<?php /** @var string $status 알 수 없음(unknown)은 이상이 아니므로 표시하지 않는다 */ ?>
<?php if ($status !== App\Monitoring\Status::UNKNOWN): ?>
<span class="badge s-<?= e($status) ?>"><span class="dot s-<?= e($status) ?>"></span><?= e(status_label($status)) ?></span>
<?php endif ?>
