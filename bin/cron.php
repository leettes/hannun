<?php

declare(strict_types=1);

// 1분마다 실행: * * * * * cd /var/www/html && php bin/cron.php >> storage/logs/cron.log 2>&1
// 1) 웹/API 점검: UPTIME_KUMA_URL 이 있으면 Uptime Kuma /metrics 수집, 없으면 대시보드가 직접 점검
// 2) 웹/API 주소의 연결 IP·도메인 등록 정보 갱신  3) 웹/API 구분이 없는 리소스 채우기
// 4) iOS 앱 버전 조회·리뷰 수집·앱 아이콘 내려받기  5) 못 보낸 문자 알림 재발송  6) 기본 설정 SQL(database/config.sql) 갱신

use App\Alerts\AlertIngestor;
use App\Alerts\DomainInspector;
use App\Alerts\HttpChecker;
use App\Alerts\UptimeKumaCollector;
use App\ConfigSnapshot;
use App\Database;
use App\Env;
use App\Monitoring\AppIconFetcher;
use App\Monitoring\AppReviewCollector;
use App\Monitoring\AppStoreLookup;
use App\Monitoring\HttpKindDetector;
use App\Notifications\Notifier;

require dirname(__DIR__) . '/config/env.php';

$log = static fn (string $message) => print(date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL);
$notifier = Notifier::fromEnv(Database::connection(...));
$ingestor = new AlertIngestor(Database::connection(...), $notifier);
$exit = 0;

$kumaUrl = rtrim(Env::get('UPTIME_KUMA_URL', '') ?? '', '/');
if ($kumaUrl !== '') {
    $apiKey = Env::get('UPTIME_KUMA_API_KEY', '') ?? '';
    $fetch = static function () use ($kumaUrl, $apiKey): ?string {
        $context = stream_context_create(['http' => [
            'timeout' => 10,
            // Uptime Kuma API 키: 사용자 이름은 비우고 비밀번호에 키를 넣는 Basic 인증
            'header' => $apiKey === '' ? '' : 'Authorization: Basic ' . base64_encode(':' . $apiKey) . "\r\n",
        ]]);
        $body = @file_get_contents($kumaUrl . '/metrics', false, $context);

        return $body === false ? null : $body;
    };

    try {
        $result = (new UptimeKumaCollector(Database::connection(...), $ingestor, $fetch))->run();
        $log(sprintf('uptime-kuma: 모니터 %d개, 연결 %d개, 장애 %d건, 복구 %d건', $result['monitors'], $result['matched'], $result['opened'], $result['resolved']));
        foreach ($result['unmatched'] as $monitor) {
            $log('uptime-kuma: 대시보드 리소스와 연결되지 않은 모니터 ' . $monitor);
        }
    } catch (Throwable $e) {
        $log('uptime-kuma: ' . $e->getMessage());
        $exit = 1;
    }
} else {
    $checker = new HttpChecker(Database::connection(...), $ingestor, HttpChecker::request(...), static fn () => sleep(1));
    $result = $checker->run();
    $log(sprintf('http: 점검 %d개, 실패 %d, 지연 %d, 장애 %d건, 복구 %d건', $result['checked'], $result['down'], $result['slow'], $result['opened'], $result['resolved']));
    foreach ($result['failures'] as $failure) {
        $log('http: ' . $failure);
    }
}

try {
    $domains = (new DomainInspector(Database::connection(...), DomainInspector::resolve(...), DomainInspector::rdap(...), DomainInspector::whois(...)))->run();
    if ($domains['domains'] > 0) {
        $log(sprintf('domain: 도메인 정보 %d건 갱신', $domains['domains']));
    }
    foreach ([...$domains['changed'], ...$domains['errors']] as $message) {
        $log('domain: ' . $message);
    }
} catch (Throwable $e) {
    $log('domain: ' . $e->getMessage());
}

// 웹/API 구분이 없는 리소스: JSON 응답이면 API, 아니면 웹
try {
    foreach (HttpKindDetector::fillMissing(Database::connection(...), HttpKindDetector::detect(...)) as $filled) {
        $log('kind: ' . $filled);
    }
} catch (Throwable $e) {
    $log('kind: ' . $e->getMessage());
}

// iOS 앱 버전·업데이트일: 새 앱·주소가 바뀐 앱은 바로, 나머지는 하루 한 번 (한 번에 10개)
try {
    $store = (new AppStoreLookup(Database::connection(...), AppIconFetcher::httpGet(...)))->run();
    if ($store['updated'] !== []) {
        $log('app-store: ' . implode(', ', $store['updated']));
    }
    foreach ($store['errors'] as $message) {
        $log('app-store: ' . $message);
    }
} catch (Throwable $e) {
    $log('app-store: ' . $e->getMessage());
}

// iOS 리뷰: App Store 공개 리뷰 피드. 앱마다 하루 한 번, 실패하면 1시간 뒤 (한 번에 3개)
try {
    $reviews = (new AppReviewCollector(Database::connection(...), AppReviewCollector::httpGet(...)))->run();
    if ($reviews['collected'] !== []) {
        $log('app-review: ' . implode(', ', $reviews['collected']));
    }
    foreach ($reviews['errors'] as $message) {
        $log('app-review: ' . $message);
    }
} catch (Throwable $e) {
    $log('app-review: ' . $e->getMessage());
}

// 앱 아이콘: 새 앱·주소가 바뀐 앱은 바로, 나머지는 7일마다 (한 번에 5개)
try {
    $icons = (new AppIconFetcher(Database::connection(...), AppIconFetcher::httpGet(...), dirname(__DIR__) . '/' . AppIconFetcher::DIRECTORY))->run();
    if ($icons['fetched'] !== []) {
        $log('app-icon: ' . implode(', ', $icons['fetched']));
    }
    foreach ($icons['errors'] as $message) {
        $log('app-icon: ' . $message);
    }
} catch (Throwable $e) {
    $log('app-icon: ' . $e->getMessage());
}

// 기본 설정 SQL: 설정이 바뀌었을 때만 다시 쓴다 (git 으로 관리하는 최종 설정)
try {
    if (ConfigSnapshot::write(Database::connection(), dirname(__DIR__) . '/' . ConfigSnapshot::PATH)) {
        $log('config: ' . ConfigSnapshot::PATH . ' 갱신');
    }
} catch (Throwable $e) {
    $log('config: ' . $e->getMessage());
}

$sent = $notifier->dispatch();
if (array_sum($sent) > 0) {
    $log(sprintf('notifications: 발송 %d, 실패 %d, 건너뜀 %d', $sent['sent'], $sent['failed'], $sent['skipped']));
}

exit($exit);
