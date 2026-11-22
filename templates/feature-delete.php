<?php
use App\Features\FeatureRepository;

/** 빌드업 삭제 확인. @var array<string, mixed> $feature @var int $eventCount @var string $csrfToken */
$id = (int) $feature['id'];
?>
<?= $this->partial('partials/page-head', [
    'crumb' => e((string) $feature['title']),
    'crumbHref' => '/buildup/' . $id,
    'heading' => '빌드업 삭제',
]) ?>

<section class="card narrow">
    <p><strong><?= e((string) $feature['title']) ?></strong> <span class="muted">(<?= e((string) $feature['system_name']) ?> · <?= e(FeatureRepository::statusLabel((string) $feature['status'])) ?>)</span> 을(를) 삭제합니다.</p>
    <ul class="muted small">
        <li>타임라인 <?= $eventCount ?>건도 함께 지워지고 <strong>되돌릴 수 없습니다.</strong></li>
        <li>하지 않기로 한 건이라면 삭제 대신 상태를 <strong>취소</strong>로 바꾸면 기록이 남습니다.</li>
        <li>누가 무엇을 지웠는지는 감사 로그에 남습니다.</li>
    </ul>
    <form class="form" method="post" action="/buildup/<?= $id ?>/delete">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="confirm" value="delete">
        <div class="form-actions">
            <a class="btn" href="/buildup/<?= $id ?>">취소</a>
            <button class="btn btn-danger-ghost" type="submit">삭제</button>
        </div>
    </form>
</section>
