<?php
/**
 * 웹/API 주소별 연결 IP 와 도메인 등록 정보 (DomainInspector 가 갱신). 서비스 관리·서비스 상세 공용.
 *
 * 관리자가 직접 입력한 항목(domain_info.manual)에는 '수동' 표시. $editable 이면 도메인에 수정 링크.
 *
 * @var list<array{label: string, href: ?string, host: string, identifier: string, resolved_ips: ?string, resolved_at: ?string, domain_info: ?array<string, mixed>, domain?: ?string}> $rows
 * @var bool $editable
 */
$editable ??= false;
?>
<div class="card card-flush">
    <?php if ($rows === []): ?>
        <div class="empty">등록된 웹/API 리소스가 없습니다.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="col-main">이름</th><th class="col-host">호스트</th><th class="col-ip">연결 IP</th><th class="col-domain">도메인</th><th class="col-text">등록업체</th><th class="col-short">DNS</th><th class="col-date">도메인 만료</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $d):
                    $info = $d['domain_info'];
                    $expires = isset($info['expires_at']) ? new DateTimeImmutable($info['expires_at']) : null;
                    $daysLeft = $expires === null ? null : (int) floor(($expires->getTimestamp() - time()) / 86400);
                    $manual = static fn (string $field): string => in_array($field, $info['manual'] ?? [], true)
                        ? ' <span class="badge badge-manual" title="관리자가 직접 입력한 값">수동</span>' : '';
                    $domain = $d['domain'] ?? $info['domain'] ?? null;
                    ?>
                    <tr>
                        <td class="cell-main"><?= $d['href'] === null ? e($d['label']) : '<a href="' . e($d['href']) . '">' . e($d['label']) . '</a>' ?></td>
                        <td class="mono break" title="<?= e($d['identifier']) ?>"><?= e($d['host']) ?></td>
                        <td class="mono small" title="<?= e('확인 ' . ($d['resolved_at'] ?? '-')) ?>"><?= e($d['resolved_ips'] === null ? '-' : str_replace(',', ', ', $d['resolved_ips'])) ?></td>
                        <td class="mono">
                            <?php if ($editable && $domain !== null): ?>
                                <a href="/settings/domains/<?= e(rawurlencode($domain)) ?>" title="도메인 정보 수정"><?= e($domain) ?></a>
                            <?php else: ?>
                                <?= e($domain ?? '-') ?>
                            <?php endif ?>
                            <?php if (!empty($info['memo'])): ?><div class="muted small break"><?= e($info['memo']) ?></div><?php endif ?>
                        </td>
                        <td><?= e(registrar_label($info['registrar'] ?? null)) ?><?= $manual('registrar') ?></td>
                        <td title="<?= e(implode(', ', $info['nameservers'] ?? [])) ?>"><?= e(dns_provider($info['nameservers'] ?? [])) ?><?= $manual('nameservers') ?></td>
                        <td class="nowrap<?= $daysLeft !== null && $daysLeft < 30 ? ' t-warn' : '' ?>">
                            <?= $expires === null ? '-' : e($expires->format('Y-m-d')) . ' <span class="muted small">(' . $daysLeft . '일)</span>' ?><?= $manual('expires_at') ?>
                            <?php if (!empty($info['error'])): ?><div class="field-error"><?= e($info['error']) ?></div><?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
