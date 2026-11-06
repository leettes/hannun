<?php
use App\Monitoring\AppRegistry;

/**
 * @var ?array<string, mixed> $app 수정 대상 (null 이면 등록)
 * @var list<array<string, mixed>> $systems
 * @var list<array<string, mixed>> $accounts 이 플랫폼에 쓸 수 있는 개발자 계정
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string $csrfToken
 */
$isNew = $app === null;
$platform = (string) $values['platform'];
$web = $platform === 'web';
$error = static fn (string $field): string => isset($errors[$field])
    ? '<span class="field-error">' . e($errors[$field]) . '</span>'
    : '';
$accountPlatform = AppRegistry::ACCOUNT_FOR[$platform] ?? null;
?>
<?= $this->partial('partials/page-head', [
    'crumb' => '앱',
    'crumbHref' => '/settings/apps',
    'heading' => $isNew ? e(AppRegistry::PLATFORMS[$platform]) . ' 앱 등록' : e($app['name']),
    'sub' => $isNew ? null : e($app['system_name']) . ' · ' . e(AppRegistry::PLATFORMS[$platform]),
]) ?>

<?php if ($isNew): ?>
    <nav class="tabs" aria-label="플랫폼">
        <?php foreach (AppRegistry::PLATFORMS as $key => $label): ?>
            <a class="tab<?= $key === $platform ? ' is-active' : '' ?>" href="/settings/apps/new?platform=<?= e($key) ?><?= $values['system_id'] === '' ? '' : '&amp;system=' . e($values['system_id']) ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<section class="card narrow">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">입력값을 확인하세요.</div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $isNew ? '/settings/apps' : '/settings/apps/' . (int) $app['id'] ?>">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <?php if ($isNew): ?>
            <input type="hidden" name="platform" value="<?= e($platform) ?>">
            <?= $error('platform') ?>
        <?php endif ?>
        <div class="form-row">
            <label>
                <span>서비스</span>
                <select name="system_id" required>
                    <option value="">선택</option>
                    <?php foreach ($systems as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= (string) $s['id'] === $values['system_id'] ? ' selected' : '' ?>><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' (비활성)' ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('system_id') ?>
            </label>
            <label>
                <span>앱 이름</span>
                <input type="text" name="name" value="<?= e($values['name']) ?>" maxlength="50" placeholder="스토어에 보이는 이름" required>
                <?= $error('name') ?>
            </label>
        </div>
        <?php if (!$web): ?>
            <label>
                <span><?= $platform === 'ios' ? '번들 ID' : '패키지명' ?></span>
                <input type="text" name="bundle_id" value="<?= e($values['bundle_id']) ?>" maxlength="155" class="mono" placeholder="kr.example.app" autocomplete="off"<?= $platform === 'ios' ? '' : ' required' ?>>
                <span class="muted small"><?= $platform === 'ios'
                    ? 'App Store Connect → 앱 정보의 번들 ID. App Store 주소(…/id123)를 넣었으면 비워 두어도 자동 조회로 채웁니다.'
                    : 'Play Console 앱 목록 또는 스토어 주소의 id= 값' ?></span>
                <?= $error('bundle_id') ?>
            </label>
        <?php endif ?>
        <label>
            <span><?= $web ? '앱 주소' : '스토어 주소' ?></span>
            <input type="url" name="store_url" value="<?= e($values['store_url']) ?>" maxlength="500" placeholder="<?= $platform === 'ios' ? 'https://apps.apple.com/kr/app/...' : ($web ? 'https://app.example.com' : 'https://play.google.com/store/apps/details?id=...') ?>">
            <?= $error('store_url') ?>
        </label>
        <?php if ($accountPlatform !== null): ?>
            <label>
                <span>개발자 계정</span>
                <select name="account_id">
                    <option value="">미지정</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"<?= (string) $a['id'] === $values['account_id'] ? ' selected' : '' ?>><?= e($a['name']) ?><?= $a['company_name'] === null ? '' : ' · ' . e($a['company_name']) ?><?= $a['is_active'] ? '' : ' (비활성)' ?></option>
                    <?php endforeach ?>
                </select>
                <span class="muted small">앱을 올리는 <?= e(AppRegistry::ACCOUNT_PLATFORMS[$accountPlatform]) ?> 계정. 앱의 관리회사는 이 계정의 관리회사, 없으면 서비스의 관리회사입니다. <a href="/settings/app-accounts/new?platform=<?= e($accountPlatform) ?>">계정 등록</a></span>
                <?= $error('account_id') ?>
            </label>
        <?php endif ?>
        <?php if ($platform === 'ios'): ?>
            <div class="form-static">
                <span>버전 · 업데이트일 <span class="muted small">(App Store 자동 조회, 하루 한 번)</span></span>
                <?php if ($isNew): ?>
                    <p class="muted small">저장하면 다음 cron(1분 안)에서 App Store 에서 가져옵니다.</p>
                <?php else: ?>
                    <p>
                        <span class="mono"><?= e($app['version'] ?? '-') ?></span> · <?= e($app['released_at'] ?? '-') ?>
                        <span class="muted small">· 확인 <?= e($app['checked_at'] ?? '아직 안 함') ?></span>
                    </p>
                    <?php if ($app['store_error'] !== null): ?><p class="field-error"><?= e($app['store_error']) ?></p><?php endif ?>
                <?php endif ?>
            </div>
        <?php else: ?>
        <div class="form-row">
            <label>
                <span>현재 버전</span>
                <input type="text" name="version" value="<?= e($values['version']) ?>" maxlength="30" class="mono" placeholder="2.4.1">
                <?= $error('version') ?>
            </label>
            <label>
                <span>최근 업데이트일</span>
                <input type="date" name="released_at" value="<?= e($values['released_at']) ?>">
                <?= $error('released_at') ?>
            </label>
        </div>
        <?php endif ?>
        <label>
            <span>메모</span>
            <textarea name="notes" rows="3" maxlength="500" placeholder="예: 심사 담당 홍길동, 푸시는 Firebase"><?= e($values['notes']) ?></textarea>
            <?= $error('notes') ?>
        </label>
        <?php if (!$isNew): ?>
            <label>
                <span>상태</span>
                <select name="active">
                    <option value="1"<?= $values['active'] ? ' selected' : '' ?>>사용</option>
                    <option value="0"<?= $values['active'] ? '' : ' selected' ?>>비활성 (서비스 상세에서 숨김)</option>
                </select>
                <?= $error('active') ?>
            </label>
        <?php endif ?>
        <div class="form-actions">
            <span class="muted small"><?= $isNew ? '' : '플랫폼은 바꿀 수 없습니다. 다른 플랫폼이면 새로 등록하세요.' ?></span>
            <a class="btn" href="/settings/apps">취소</a>
            <button class="btn btn-primary" type="submit"><?= $isNew ? '등록' : '저장' ?></button>
        </div>
    </form>
</section>
