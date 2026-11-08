<?php

declare(strict_types=1);

// iOS 앱 리뷰를 지금 바로 받는다 (평소에는 cron 이 앱마다 하루 한 번). docs/APPS.md 5단계
//   php bin/collect-reviews.php           받을 차례인 앱 (cron 과 같음)
//   php bin/collect-reviews.php --all     사용 중인 iOS 앱 전부
//   php bin/collect-reviews.php --app=11  이 앱만

use App\Database;
use App\Monitoring\AppReviewCollector;

require dirname(__DIR__) . '/config/env.php';

$options = getopt('', ['app:', 'all']);
$collector = new AppReviewCollector(Database::connection(...), AppReviewCollector::httpGet(...));

if (isset($options['all'])) {
    $ids = Database::connection()->query("SELECT id FROM apps WHERE platform = 'ios' AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
} elseif (isset($options['app'])) {
    if (!ctype_digit((string) $options['app'])) {
        fwrite(STDERR, "--app 은 앱 번호(숫자)\n");
        exit(2);
    }
    $ids = [(int) $options['app']];
} else {
    $ids = [null];
}

$exit = 0;
foreach ($ids as $id) {
    $result = $collector->run($id === null ? null : (int) $id);
    foreach ($result['collected'] as $line) {
        echo $line, PHP_EOL;
    }
    foreach ($result['errors'] as $line) {
        fwrite(STDERR, $line . PHP_EOL);
        $exit = 1;
    }
}
echo '완료 (' . Database::path() . ")\n";
exit($exit);
