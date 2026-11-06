<?php
use App\Monitoring\SystemRegistry;

/** @var list<array<string, mixed>> $systems @var list<array<string, mixed>> $domains @var ?string $flash */
?>
<?= $this->partial('partials/page-head', [
    'heading' => '서비스 관리',
    'sub' => '대시보드에 표시할 서비스와, 서비스별로 감시할 웹/API 를 등록합니다.',
    'aside' => '<a class="btn btn-primary" href="/settings/systems/new">서비스 등록</a>',
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<div class="card card-flush">
    <?php if ($systems === []): ?>
        <div class="empty">등록된 서비스가 없습니다. 오른쪽 위 '서비스 등록'으로 시작하세요.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-main">이름</th><th class="col-code">코드</th><th class="col-tag">환경</th><th class="col-cloud">클라우드</th><th class="col-person">관리회사</th><th class="col-person">담당자</th><th class="num col-num">리소스</th><th class="num col-num">진행 중 장애</th><th class="col-tag">상태</th></tr>
                </thead>
                <tbody>
                <?php foreach ($systems as $s): ?>
                    <tr<?= $s['is_active'] ? '' : ' class="muted"' ?>>
                        <td class="cell-main"><a href="/settings/systems/<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
                        <td class="mono"><?= e($s['code']) ?></td>
                        <td><?= e(SystemRegistry::ENVIRONMENTS[$s['environment']] ?? $s['environment']) ?></td>
                        <td class="small<?= $s['cloud_provider'] === 'aws' && $s['aws_account_id'] === null ? ' muted' : '' ?>"><?= e(cloud_label($s['cloud_provider'], $s['aws_alias'], $s['aws_account_id'])) ?></td>
                        <td<?= $s['company_name'] === null ? ' class="muted"' : '' ?> title="<?= $s['company_name'] === null ? '' : ($s['company_direct'] ? '서비스에 직접 지정' : 'AWS 계정의 관리회사') ?>"><?= e($s['company_name'] ?? '미지정') ?></td>
                        <td><?= e($s['owner'] ?? '미지정') ?></td>
                        <td class="num"><?= (int) $s['resource_count'] ?></td>
                        <td class="num"><?= (int) $s['active_incidents'] ?></td>
                        <td><?= $s['is_active'] ? '사용' : '비활성' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<section class="section">
    <div class="section-head">
        <h2>도메인 · 연결 IP</h2>
        <span class="muted small">웹/API 주소 기준. IP 는 1분마다, 등록업체·만료일은 하루 한 번 갱신 (bin/cron.php). 도메인을 누르면 직접 수정</span>
    </div>
    <?= $this->partial('partials/domain-table', ['rows' => array_map(static fn (array $d): array => $d + [
        'label' => $d['system_name'],
        'href' => '/settings/systems/' . (int) $d['system_id'],
    ], $domains), 'editable' => true]) ?>
</section>
