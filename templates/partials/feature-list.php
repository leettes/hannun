<?php
use App\Features\FeatureRepository;
use App\Monitoring\AppRegistry;

/**
 * 빌드업 표. 서비스 상세·앱 상세·회의록 상세·빌드업 목록에서 쓴다
 *
 * @var list<array<string, mixed>> $features @var bool $showSystem @var ?string $empty
 */
?>
<?php if ($features === []): ?>
    <div class="empty"><?= e($empty ?? '빌드업이 없습니다.') ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="col-tag">상태</th><th class="col-main">항목</th>
                <?php if ($showSystem): ?><th class="col-short">서비스</th><?php endif ?>
                <th class="col-short">앱</th><th class="col-short">목표 버전</th><th class="col-short">담당자</th><th class="col-time">수정</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($features as $f): ?>
                <tr<?= in_array($f['status'], ['released', 'dropped'], true) ? ' class="muted"' : '' ?>>
                    <td><span class="fstatus fstatus-<?= e((string) $f['status']) ?>"><?= e(FeatureRepository::statusLabel((string) $f['status'])) ?></span></td>
                    <td class="cell-main"><a href="/buildup/<?= (int) $f['id'] ?>"><?= e((string) $f['title']) ?></a><?php if (($f['meeting_held_at'] ?? null) !== null): ?><div class="muted small"><?= e(meeting_day((string) $f['meeting_held_at'])) ?> 회의</div><?php endif ?></td>
                    <?php if ($showSystem): ?><td class="nowrap"><?= e((string) $f['system_name']) ?></td><?php endif ?>
                    <td class="nowrap"><?= $f['app_name'] === null ? '<span class="muted">-</span>' : e($f['app_name'] . ' ' . (AppRegistry::PLATFORMS[$f['app_platform']] ?? '')) ?></td>
                    <td class="nowrap num"><?= $f['status'] === 'released' ? e('v' . $f['released_version']) : e((string) ($f['target_version'] ?? '-')) ?></td>
                    <td class="nowrap"><?= e((string) ($f['assignee_name'] ?? '-')) ?></td>
                    <td class="nowrap num"><?= e(substr((string) $f['updated_at'], 0, 10)) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
