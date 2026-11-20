<?php

declare(strict_types=1);

// 마크다운 회의록 파일을 회의록으로 가져온다 (본문·결정 사항은 APP_KEY 로 암호화)
//   php bin/import-meetings.php --author=me@example.com [--visibility=all|attendees|private] [--dry-run] 파일.md ...
// - 형식은 src/Meetings/MeetingImporter.php 참고 (# 제목, 날짜 줄, '## 확인 / 할 일' 은 결정 사항)
// - 같은 제목·일시의 회의록이 이미 있으면 건너뛴다 (여러 번 실행해도 됨)
// - 참석자는 비워 둔다. 공개 범위 기본값은 all (회의록 권한자 전체)
// - 감사 로그에 meeting.create (source: import, 파일 이름) 로 남는다

use App\Database;
use App\Env;
use App\Meetings\MeetingImporter;
use App\Meetings\MeetingRepository;
use App\Security\AuditLog;
use App\Security\Crypto;

require dirname(__DIR__) . '/config/env.php';

$options = getopt('', ['author:', 'visibility::', 'dry-run'], $rest);
$files = array_slice($argv, $rest);
$email = strtolower(trim((string) ($options['author'] ?? '')));
$visibility = (string) ($options['visibility'] ?? 'all');
$dryRun = isset($options['dry-run']);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($email === '' || $files === []) {
    $fail('사용법: php bin/import-meetings.php --author=me@example.com [--visibility=all|attendees|private] [--dry-run] 파일.md ...');
}
if (!isset(MeetingRepository::VISIBILITY[$visibility])) {
    $fail('visibility 는 ' . implode(', ', array_keys(MeetingRepository::VISIBILITY)) . ' 중 하나입니다.');
}
$crypto = Crypto::fromBase64(Env::get('APP_KEY'), Env::get('APP_KEY_PREVIOUS')) ?? $fail('.env 에 APP_KEY 가 없습니다. 키 없이 회의록을 저장하지 않습니다.');

$pdo = Database::connection();
$author = $pdo->prepare('SELECT id, name, meeting_access FROM users WHERE email = ? AND is_active = 1');
$author->execute([$email]);
$author = $author->fetch(PDO::FETCH_ASSOC) ?: $fail("사용 중인 계정이 아닙니다: {$email}");

$meetings = new MeetingRepository(Database::connection(...), $crypto);
$audit = new AuditLog(Database::connection(...));
$exists = $pdo->prepare('SELECT id FROM meetings WHERE title = ? AND held_at = ? AND deleted_at IS NULL');
$imported = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (!is_file($file)) {
        echo "건너뜀 {$name}: 파일 없음\n";
        continue;
    }
    try {
        $values = MeetingImporter::parse((string) file_get_contents($file), pathinfo($file, PATHINFO_FILENAME));
    } catch (RuntimeException $e) {
        echo "건너뜀 {$name}: {$e->getMessage()}\n";
        continue;
    }
    $exists->execute([$values['title'], $values['held_at']]);
    if (($id = $exists->fetchColumn()) !== false) {
        echo "건너뜀 {$name}: 이미 있음 (#{$id})\n";
        continue;
    }
    $summary = sprintf('%s · %s · 본문 %s자 · 결정 사항 %d줄', $values['title'], $values['held_at'], number_format(mb_strlen($values['body'])), count(array_filter(explode("\n", $values['decisions']), static fn (string $l): bool => trim($l) !== '')));
    if ($dryRun) {
        echo "확인 {$name}: {$summary}\n";
        continue;
    }
    $id = $meetings->create($values + ['system_id' => null, 'visibility' => $visibility, 'attendees' => []], (int) $author['id']);
    $audit->record(null, 'meeting.create', 'meeting', $id, detail: ['source' => 'import', 'file' => $name, 'author' => (int) $author['id'], 'visibility' => $visibility]);
    echo "가져옴 #{$id} {$name}: {$summary}\n";
    $imported++;
}

echo ($dryRun ? '확인만 했습니다 (--dry-run).' : "{$imported}건 가져왔습니다.") . PHP_EOL;
if ($author['meeting_access'] === 'none') {
    echo "참고: {$author['name']} 님은 회의록 권한이 없어 아직 볼 수 없습니다. 사용자 관리에서 회의록 권한을 켜세요.\n";
}
