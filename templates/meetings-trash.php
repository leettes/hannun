<?php
/** @var list<array<string, mixed>> $meetings @var ?string $flash @var string $csrfToken */
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '회의록',
    'crumbHref' => '/meetings',
    'heading' => '휴지통',
    'sub' => '내가 작성하고 지운 회의록입니다. 다른 사람에게는 보이지 않고, 되살리면 원래 공개 범위로 돌아갑니다.',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <?php if ($meetings === []): ?>
        <div class="empty">휴지통이 비어 있습니다.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th class="col-time">일시</th><th class="col-main">제목</th><th class="col-time">삭제</th><th class="col-short"></th></tr></thead>
                <tbody>
                <?php foreach ($meetings as $m): ?>
                    <tr>
                        <td class="nowrap num"><?= e((string) $m['held_at']) ?></td>
                        <td class="cell-main"><a href="/meetings/<?= (int) $m['id'] ?>"><?= e((string) $m['title']) ?></a></td>
                        <td class="nowrap num"><?= e((string) $m['deleted_at']) ?></td>
                        <td>
                            <form method="post" action="/meetings/<?= (int) $m['id'] ?>/restore">
                                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                                <button class="btn btn-small" type="submit">되살리기</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
