<?php
use App\Monitoring\IncidentActions;

/**
 * @var list<array> $incidents @var bool $showSystem @var ?string $emptyText
 * @var bool $dismissible 대시보드 '최근 장애'의 확인 버튼 (운영자 이상) @var ?string $csrfToken
 */
$showSystem ??= true;
$dismissible ??= false;
?>
<?php if ($incidents === []): ?>
    <div class="empty"><?= e($emptyText ?? '해당하는 장애가 없습니다.') ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="col-tag">심각도</th>
                <th class="col-title">내용</th>
                <?php if ($showSystem): ?><th class="col-short">서비스</th><?php endif ?>
                <th class="col-time">발생</th>
                <th class="col-dur">지속 시간</th>
                <th class="col-person">담당자</th>
                <th class="col-status">상태</th>
                <?php if ($dismissible): ?><th class="num col-action">확인</th><?php endif ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($incidents as $i): ?>
                <tr>
                    <td><span class="sev sev-<?= e($i['severity']) ?>"><?= e(severity_label($i['severity'])) ?></span></td>
                    <td class="cell-main">
                        <a href="/incidents/<?= (int) $i['id'] ?>"><?= e($i['title']) ?></a>
                        <div class="muted small"><?= e($i['resource']) ?></div>
                    </td>
                    <?php if ($showSystem): ?>
                        <td><a class="plain" href="/systems/<?= e($i['system_code']) ?>"><?= e($i['system_name']) ?></a></td>
                    <?php endif ?>
                    <td class="nowrap" title="<?= e(fmt_time($i['started_at'], 'Y-m-d H:i:s')) ?>"><?= e(fmt_time($i['started_at'])) ?></td>
                    <td class="nowrap"><?= e(duration($i['started_at'], $i['resolved_at'])) ?></td>
                    <td><?= e($i['assignee'] ?? '미지정') ?></td>
                    <td><span class="istatus istatus-<?= e($i['status']) ?>"><?= e(incident_status_label($i['status'])) ?></span></td>
                    <?php if ($dismissible): ?>
                        <td class="num">
                            <details class="dismiss" data-pause-refresh>
                                <summary class="btn btn-small">확인</summary>
                                <form class="dismiss-form" method="post" action="/incidents/<?= (int) $i['id'] ?>/dismiss">
                                    <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
                                    <label class="small muted" for="dismiss-reason-<?= (int) $i['id'] ?>">사유 (선택)</label>
                                    <textarea id="dismiss-reason-<?= (int) $i['id'] ?>" name="reason" rows="2" maxlength="<?= IncidentActions::DISMISS_REASON_MAX ?>" placeholder="예: 배포 중 일시 오류, 조치 완료"></textarea>
                                    <button class="btn btn-small btn-primary" type="submit">확인 처리</button>
                                </form>
                            </details>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
