<?php

declare(strict_types=1);

// 회의 할 일 CSV 를 가져온다 (항목·할 일·비고는 APP_KEY 로 암호화)
//   php bin/import-meeting-tasks.php [--dry-run] 할일.csv
// CSV 열 (첫 줄 머리글): 회의일,서비스,항목,할 일,담당,상태,비고
// - 회의일(YYYY-MM-DD)이 같은 회의록에 붙인다 (그날 회의가 없으면 건너뜀)
// - 담당: 직원 이름과 같으면 그 계정, 아니면 라벨로 ('참석자 5' 등. 회의 상세에서 계정과 연결)
// - 상태: 대기 / 진행 / 완료 / 보류 (비우면 대기)
// - 같은 회의에 같은 항목·할 일이 이미 있으면 건너뛴다 (여러 번 실행해도 됨)
// - 감사 로그에 meeting.tasks (source: import) 로 남는다

use App\Database;
use App\Env;
use App\Meetings\MeetingTaskRepository;
use App\Security\AuditLog;
use App\Security\Crypto;

require dirname(__DIR__) . '/config/env.php';

$options = getopt('', ['dry-run'], $rest);
$file = $argv[$rest] ?? '';
$dryRun = isset($options['dry-run']);
$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};
if ($file === '' || !is_file($file)) {
    $fail('사용법: php bin/import-meeting-tasks.php [--dry-run] 할일.csv');
}
$crypto = Crypto::fromBase64(Env::get('APP_KEY'), Env::get('APP_KEY_PREVIOUS')) ?? $fail('.env 에 APP_KEY 가 없습니다.');

$pdo = Database::connection();
$tasks = new MeetingTaskRepository(Database::connection(...), $crypto);
$audit = new AuditLog(Database::connection(...));
$statuses = array_flip(MeetingTaskRepository::STATUSES);
$staff = array_column($pdo->query('SELECT id, name FROM users WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC), 'id', 'name');
$meetingOn = $pdo->prepare("SELECT id FROM meetings WHERE substr(held_at, 1, 10) = ? AND deleted_at IS NULL ORDER BY held_at, id LIMIT 1");
$existing = $pdo->prepare('SELECT item_enc, task_enc FROM meeting_tasks WHERE meeting_id = ?');

$handle = fopen($file, 'r') ?: $fail('파일을 열 수 없습니다.');
$header = fgetcsv($handle, escape: '') ?: [];
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
$need = ['회의일', '서비스', '항목', '할 일', '담당', '상태', '비고'];
if (array_diff($need, $header) !== []) {
    $fail('머리글이 필요합니다: ' . implode(',', $need));
}
$added = [];
$skipped = 0;
$line = 1;
while (($row = fgetcsv($handle, escape: '')) !== false) {
    $line++;
    if ($row === [null] || count($row) < count($header)) {
        continue;
    }
    $r = array_combine($header, array_map(static fn ($v): string => trim((string) $v), $row));
    $meetingOn->execute([$r['회의일']]);
    $meetingId = $meetingOn->fetchColumn();
    if ($meetingId === false) {
        echo "건너뜀 {$line}행: {$r['회의일']} 회의록 없음\n";
        $skipped++;
        continue;
    }
    $existing->execute([$meetingId]);
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if ($crypto->decrypt($e['item_enc']) === $r['항목'] && $crypto->decrypt($e['task_enc']) === $r['할 일']) {
            echo "건너뜀 {$line}행: 이미 있음 ({$r['항목']})\n";
            $skipped++;
            continue 2;
        }
    }
    $who = $r['담당'];
    $values = [
        'service' => $r['서비스'] !== '' ? mb_substr($r['서비스'], 0, 30) : '공통·신규',
        'item' => mb_substr($r['항목'], 0, 100),
        'task' => $r['할 일'],
        'note' => $r['비고'] !== '' ? $r['비고'] : null,
        'assignee_user_id' => $who !== '' && isset($staff[$who]) ? (int) $staff[$who] : null,
        'assignee_label' => $who !== '' && !isset($staff[$who]) ? $who : null,
        'status' => $statuses[$r['상태']] ?? 'todo',
    ];
    if ($dryRun) {
        echo "확인 {$line}행: #{$meetingId} {$values['service']} · {$values['item']}\n";
        continue;
    }
    $tasks->create((int) $meetingId, $values, null);
    $added[(int) $meetingId] = ($added[(int) $meetingId] ?? 0) + 1;
}
fclose($handle);

foreach ($added as $meetingId => $count) {
    $audit->record(null, 'meeting.tasks', 'meeting', $meetingId, detail: ['count' => $count, 'source' => 'import', 'file' => basename($file)]);
}
echo ($dryRun ? '확인만 했습니다 (--dry-run).' : array_sum($added) . "건 가져옴, {$skipped}건 건너뜀.") . PHP_EOL;
