<?php
use App\Monitoring\AppRegistry;

/**
 * 앱 목록. 설정 > 앱, 서비스 상세 공용.
 *
 * @var list<array<string, mixed>> $apps
 * @var bool $editable 이름에 수정 링크
 * @var bool $showSystem 서비스 열
 */
?>
<div class="card card-flush">
    <?php if ($apps === []): ?>
        <div class="empty">등록된 앱이 없습니다.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <?php if ($showSystem): ?><th class="col-main">서비스</th><?php endif ?>
                    <th class="col-main">이름</th><th class="col-tag">플랫폼</th><th class="col-host">번들 ID / 패키지</th><th class="col-short">버전</th><th class="col-date">업데이트</th><th class="col-person">개발자 계정</th><th class="col-person">관리회사</th><th class="col-action">스토어</th>
                    <?php if ($showSystem): ?><th class="col-tag">상태</th><?php endif ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($apps as $a):
                    $expires = $a['account_id'] === null ? null : days_until($a['membership_expires_at']);
                    ?>
                    <tr<?= $a['is_active'] ? '' : ' class="muted"' ?>>
                        <?php if ($showSystem): ?><td><?= e($a['system_name']) ?></td><?php endif ?>
                        <td class="cell-main">
                            <span class="app-name">
                                <?php if ($a['icon_file'] !== null): ?>
                                    <img class="app-icon" src="/app-icons/<?= e(rawurlencode((string) $a['icon_file'])) ?>" width="28" height="28" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="app-icon app-icon-empty" title="<?= e($a['icon_error'] ?? '아이콘을 가져오는 중 (cron)') ?>" aria-hidden="true"><?= e(mb_substr((string) $a['name'], 0, 1)) ?></span>
                                <?php endif ?>
                                <?= $editable ? '<a href="/settings/apps/' . (int) $a['id'] . '">' . e($a['name']) . '</a>' : e($a['name']) ?>
                            </span>
                        </td>
                        <td class="nowrap"><?= e(AppRegistry::PLATFORMS[$a['platform']] ?? $a['platform']) ?></td>
                        <td class="mono break"><?= e($a['bundle_id'] ?? '-') ?></td>
                        <?php
                        // iOS 는 App Store 자동 조회, 그 외는 직접 입력
                        $versionTitle = $a['platform'] === 'ios'
                            ? 'App Store 자동 조회 · 확인 ' . ($a['checked_at'] ?? '아직 안 함') . ($a['store_error'] === null ? '' : ' · ' . $a['store_error'])
                            : '직접 입력';
                        ?>
                        <td class="mono" title="<?= e($versionTitle) ?>">
                            <?= e($a['version'] ?? '-') ?>
                            <?php if ($a['platform'] === 'ios' && $a['store_error'] !== null): ?><span class="t-warn" aria-label="자동 조회 실패">⚠</span><?php endif ?>
                        </td>
                        <td class="nowrap"><?= e($a['released_at'] ?? '-') ?></td>
                        <td class="nowrap">
                            <?= e($a['account_name'] ?? '-') ?>
                            <?php if ($expires !== null && $expires < 30): ?>
                                <span class="t-warn" title="멤버십 만료 <?= e((string) $a['membership_expires_at']) ?>">⚠</span>
                            <?php endif ?>
                        </td>
                        <td<?= $a['company_name'] === null ? ' class="muted"' : '' ?>><?= e($a['company_name'] ?? '미지정') ?></td>
                        <td class="nowrap">
                            <?php if ($a['store_url'] !== null): ?>
                                <a class="btn btn-small btn-store" href="<?= e($a['store_url']) ?>" target="_blank" rel="noopener noreferrer"
                                   title="<?= e($a['name'] . ' ' . ($a['platform'] === 'web' ? '앱 열기' : '스토어 상세 (새 창)')) ?>"><?= e(AppRegistry::STORE_LABELS[$a['platform']] ?? '열기') ?> <span aria-hidden="true">↗</span></a>
                            <?php else: ?>
                                <span class="muted small">주소 없음</span>
                            <?php endif ?>
                        </td>
                        <?php if ($showSystem): ?><td><?= $a['is_active'] ? '사용' : '비활성' ?></td><?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
