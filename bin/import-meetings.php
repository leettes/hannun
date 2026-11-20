<?php

declare(strict_types=1);

// 마크다운 회의록 파일을 회의록으로 가져온다 (본문·결정 사항·요약·녹취는 APP_KEY 로 암호화)
//   php bin/import-meetings.php --author=me@example.com [--visibility=all|attendees|private] [--transcripts] [--dry-run] 파일.md ...
// - 형식은 src/Meetings/MeetingImporter.php 참고 (# 제목, 날짜 줄, '## 확인 / 할 일' 은 결정 사항)
// - --transcripts: 같은 폴더에서 파일 이름 앞 4자리(예: 1111)가 같은 .txt 를 녹취 원문으로 붙이고, 발언자(참석자 N)·회의 시간을 읽는다
// - 같은 제목·일시의 회의록이 이미 있으면 새로 만들지 않고 비어 있는 칸(요약·시간·참석자·녹취)만 채운다 (여러 번 실행해도 됨)
// - 공개 범위 기본값은 all (회의록 권한자 전체). 감사 로그에 meeting.create (source: import) 로 남는다

use App\Database;
use App\Env;
use App\Meetings\MeetingDocument;
use App\Meetings\MeetingImporter;
use App\Meetings\MeetingRepository;
use App\Security\AuditLog;
use App\Security\Crypto;

require dirname(__DIR__) . '/config/env.php';

$options = getopt('', ['author:', 'visibility::', 'transcripts', 'dry-run'], $rest);
$files = array_slice($argv, $rest);
$email = strtolower(trim((string) ($options['author'] ?? '')));
$visibility = (string) ($options['visibility'] ?? 'all');
$withTranscripts = isset($options['transcripts']);
$dryRun = isset($options['dry-run']);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($email === '' || $files === []) {
    $fail('사용법: php bin/import-meetings.php --author=me@example.com [--visibility=all|attendees|private] [--transcripts] [--dry-run] 파일.md ...');
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
$created = 0;
$filled = 0;

/** 같은 폴더에서 이름 앞 4자리가 같은 .txt (녹취) */
$findTranscript = static function (string $file): ?string {
    if (!preg_match('/^(\d{4})/', basename($file), $m)) {
        return null;
    }
    foreach (glob(dirname($file) . '/' . $m[1] . '*.txt') ?: [] as $candidate) {
        return $candidate;
    }

    return null;
};

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
    $extra = ['summary' => MeetingDocument::leadParagraph($values['body'])];
    $transcriptFile = $withTranscripts ? $findTranscript($file) : null;
    if ($transcriptFile !== null) {
        $text = (string) file_get_contents($transcriptFile);
        $parsed = MeetingRepository::parseTranscript($text);
        $extra += ['transcript' => $text, 'transcript_name' => basename($transcriptFile), 'labels' => $parsed['speakers'], 'duration_minutes' => $parsed['minutes']];
    }
    $summary = sprintf('%s · %s · 본문 %s자%s', $values['title'], $values['held_at'], number_format(mb_strlen($values['body'])),
        $transcriptFile !== null ? sprintf(' · 녹취 %s (%d명, 약 %d분)', basename($transcriptFile), count($extra['labels']), (int) $extra['duration_minutes']) : '');

    $exists->execute([$values['title'], $values['held_at']]);
    $id = $exists->fetchColumn();
    if ($dryRun) {
        echo ($id === false ? '새로 만듦' : "채움 #{$id}") . " {$name}: {$summary}\n";
        continue;
    }
    if ($id !== false) {
        $meetings->fillMissing((int) $id, $extra);
        echo "채움 #{$id} {$name}: {$summary}\n";
        $filled++;
        continue;
    }
    $id = $meetings->create($values + $extra + ['system_id' => null, 'visibility' => $visibility, 'attendees' => []], (int) $author['id']);
    $audit->record(null, 'meeting.create', 'meeting', $id, detail: ['source' => 'import', 'file' => $name, 'author' => (int) $author['id'], 'visibility' => $visibility]);
    echo "가져옴 #{$id} {$name}: {$summary}\n";
    $created++;
}

echo ($dryRun ? '확인만 했습니다 (--dry-run).' : "새로 {$created}건, 채움 {$filled}건.") . PHP_EOL;
if ($author['meeting_access'] === 'none') {
    echo "참고: {$author['name']} 님은 회의록 권한이 없어 아직 볼 수 없습니다. 사용자 관리에서 회의록 권한을 켜세요.\n";
}
