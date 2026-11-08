<?php
use App\Monitoring\SystemRegistry;

/**
 * @var ?array<string, mixed> $system 수정 대상 (null 이면 등록)
 * @var list<array<string, mixed>> $resources
 * @var list<App\Auth\User> $owners
 * @var list<array<string, mixed>> $awsAccounts
 * @var bool $showAwsResources
 * @var list<array<string, mixed>> $companies
 * @var array{code: string, name: string, environment: string, cloud_provider: string, aws_account_id: string, company_id: string, owner_user_ids: list<string>, short_name: string, description: string, admin_url: string, active: bool} $values
 * @var array<string, string> $errors
 * @var ?string $flash
 * @var string $csrfToken
 */
$isNew = $system === null;
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '서비스 관리',
    'crumbHref' => '/settings/systems',
    'heading' => $isNew ? '서비스 등록' : e($system['name']),
    'sub' => $isNew ? '대시보드에 표시할 서비스를 만듭니다.' : '코드 <span class="mono">' . e($system['code']) . '</span> · 화면 주소 /systems/' . e($system['code']),
]) ?>

<?php if ($flash !== null): ?>
    <div class="alert alert-info flash" role="status"><?= e($flash) ?></div>
<?php endif ?>

<section class="card narrow">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/settings/systems' : '/settings/systems/' . (int) $system['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="form-row">
            <label>
                <span>이름</span>
                <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="50" placeholder="미리" required>
                <?= $error('name') ?>
            </label>
            <?php if ($isNew): ?>
                <label>
                    <span>코드</span>
                    <input type="text" name="code" value="<?= e($values['code']) ?>" maxlength="30" placeholder="miri" pattern="[a-z][a-z0-9\-]{1,29}" autocomplete="off" required>
                    <span class="muted small">화면 주소에 쓰이며 등록 후 바꿀 수 없습니다.</span>
                    <?= $error('code') ?>
                </label>
            <?php endif ?>
        </div>
        <div class="form-row">
            <label>
                <span>환경</span>
                <select name="environment">
                    <?php foreach (SystemRegistry::ENVIRONMENTS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['environment'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('environment') ?>
            </label>
            <label>
                <span>약칭 (사이드바)</span>
                <input type="text" name="short_name" value="<?= e($values['short_name']) ?>" maxlength="2" placeholder="<?= e(service_short($values['name'] !== '' ? $values['name'] : '미리')) ?>" autocomplete="off">
                <span class="muted small">사이드바 타일에 2글자로 표시됩니다. 비우면 이름 앞 두 글자.</span>
                <?= $error('short_name') ?>
            </label>
        </div>
        <fieldset class="owner-picker">
            <legend>담당자 <span class="muted small">(여러 명 선택 가능)</span></legend>
            <div class="owner-options">
                <?php foreach ($owners as $u): ?>
                    <label class="owner-option">
                        <input type="checkbox" name="owner_user_ids[]" value="<?= $u->id ?>"<?= in_array((string) $u->id, $values['owner_user_ids'], true) ? ' checked' : '' ?>>
                        <span class="avatar avatar-sm" aria-hidden="true"><?= e(avatar_text($u->name)) ?></span>
                        <span class="owner-text">
                            <span class="owner-name"><?= e($u->name) ?></span>
                            <span class="muted small"><?= e($u->roleLabel()) ?><?= $u->phone === null ? ' · 휴대폰 없음' : '' ?><?= $u->active ? '' : ' · 비활성' ?></span>
                        </span>
                    </label>
                <?php endforeach ?>
            </div>
            <span class="muted small">담당자는 모두 이 서비스의 장애 문자를 받습니다. 아무도 고르지 않으면 '미지정'입니다. 휴대폰 번호는 사용자 관리 또는 각자 내 정보에서 입력합니다.</span>
            <?= $error('owner_user_ids') ?>
        </fieldset>
        <div class="form-row">
            <label>
                <span>클라우드</span>
                <select name="cloud_provider">
                    <?php foreach (SystemRegistry::CLOUD_PROVIDERS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $key === $values['cloud_provider'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('cloud_provider') ?>
            </label>
            <label>
                <span>AWS 계정</span>
                <select name="aws_account_id">
                    <option value="">미지정</option>
                    <?php foreach ($awsAccounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"<?= (string) $a['id'] === $values['aws_account_id'] ? ' selected' : '' ?>><?= e($a['alias'] . ' (' . aws_account_label($a['account_id']) . ')') ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">클라우드가 AWS 일 때만 저장됩니다. 계정은 <a href="/settings/aws-accounts">AWS 계정</a>에서 등록합니다.</span>
                <?= $error('aws_account_id') ?>
            </label>
        </div>
        <label>
            <span>관리회사</span>
            <select name="company_id">
                <option value="">AWS 계정의 관리회사 사용</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $values['company_id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= $c['business_no'] === null ? '' : ' (' . e(business_no_label($c['business_no'])) . ')' ?></option>
                <?php endforeach ?>
            </select>
            <span class="muted small">비우면 연결한 AWS 계정의 관리회사를 씁니다. Vercel 등 AWS 계정이 없는 서비스는 여기서 지정하세요. <a href="/settings/companies">관리회사</a></span>
            <?= $error('company_id') ?>
        </label>
        <label>
            <span>설명</span>
            <input type="text" name="description" value="<?= e($values['description']) ?>" maxlength="200" placeholder="선택">
            <?= $error('description') ?>
        </label>
        <label>
            <span>관리자 페이지</span>
            <input type="url" name="admin_url" value="<?= e($values['admin_url']) ?>" maxlength="500" placeholder="https://admin.example.com (선택)" inputmode="url">
            <span class="muted small">서비스 관리자(백오피스) 주소. 앱 상세·서비스 상세에 바로가기로 나옵니다. 계정·비밀번호는 넣지 마세요.</span>
            <?= $error('admin_url') ?>
        </label>
        <?php if (!$isNew): ?>
            <label>
                <span>상태</span>
                <select name="active">
                    <option value="1"<?= $values['active'] ? ' selected' : '' ?>>사용</option>
                    <option value="0"<?= $values['active'] ? '' : ' selected' ?>>비활성 (대시보드에서 숨기고 수집·알림 중지)</option>
                </select>
                <?= $error('active') ?>
            </label>
        <?php endif ?>
        <div class="form-actions">
            <a class="btn" href="/settings/systems">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>

<?php if (!$isNew): ?>
    <section class="section">
        <div class="section-head">
            <h2>감시 리소스</h2>
            <div class="section-actions">
                <?php foreach (SystemRegistry::RESOURCE_TYPES as $type => $label): ?>
                    <?php if (!$showAwsResources && in_array($type, SystemRegistry::AWS_RESOURCE_TYPES, true)) continue ?>
                    <a class="btn btn-small" href="/settings/systems/<?= (int) $system['id'] ?>/resources/new?type=<?= e($type) ?>"><?= e($label) ?> 추가</a>
                <?php endforeach ?>
            </div>
        </div>
        <div class="card card-flush">
            <?php if ($resources === []): ?>
                <div class="empty">등록된 리소스가 없습니다.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th class="col-tag">종류</th><th class="col-main">이름</th><th class="col-url">식별자</th><th class="col-short">연결</th><th class="col-ip">IP</th><th class="col-status">현재 상태</th><th class="col-tag">사용</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($resources as $r): ?>
                            <tr<?= $r['is_active'] ? '' : ' class="muted"' ?>>
                                <td><?= e($r['type'] === 'http'
                                    ? SystemRegistry::HTTP_KINDS[SystemRegistry::httpKind($r['config'] ?? [], (string) $r['identifier'])]
                                    : (SystemRegistry::RESOURCE_TYPES[$r['type']] ?? $r['type'])) ?></td>
                                <td class="cell-main"><a href="/settings/resources/<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                                <td class="mono break"><?= e($r['identifier']) ?></td>
                                <td class="small"><?php
                                    if ($r['type'] === 'http') {
                                        echo $r['external_ref'] === null ? '주소로 연결' : '모니터 ID ' . e($r['external_ref']);
                                    } else {
                                        echo e($r['region'] ?? '기본 리전');
                                    }
                                ?></td>
                                <td class="mono small"><?= e(str_replace(',', ', ', (string) ($r['resolved_ips'] ?? '-'))) ?></td>
                                <td><?= $this->partial('partials/status-badge', ['status' => $r['current_status']]) ?></td>
                                <td><?= $r['is_active'] ? '사용' : '비활성' ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
    </section>
<?php endif ?>
