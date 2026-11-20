<?php

declare(strict_types=1);

namespace App\Meetings;

use RuntimeException;

/**
 * 마크다운 회의록 파일을 회의록 값으로 나눈다 (bin/import-meetings.php).
 *
 * 형식:
 *   # 제목                      → 제목
 *   2026-11-05 [14:00]          → 회의 일시 (시각이 없으면 00:00)
 *   ...                         → 본문
 *   ## n. 확인 / 할 일 (또는 결정) → 결정 사항 (다음 ## 까지)
 */
final class MeetingImporter
{
    /**
     * @return array{title: string, held_at: string, body: string, decisions: string}
     */
    public static function parse(string $markdown, string $fallbackTitle): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $title = null;
        $heldAt = null;
        $body = [];
        $decisions = [];
        $inDecisions = false;

        foreach ($lines as $line) {
            if ($title === null && preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
                $title = $m[1];
                continue;
            }
            if ($heldAt === null && preg_match('/^\s*(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?\s*$/', $line, $m)) {
                $heldAt = $m[1] . ' ' . ($m[2] ?? '00:00');
                continue;
            }
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $inDecisions = preg_match('/할\s*일|결정/u', $m[1]) === 1;
                if ($inDecisions) {
                    continue; // 결정 사항 칸에 따로 보이므로 제목 줄은 뺀다
                }
            }
            if ($inDecisions) {
                $decisions[] = $line;
            } else {
                $body[] = $line;
            }
        }

        if ($heldAt === null || \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $heldAt) === false) {
            throw new RuntimeException('회의 날짜(YYYY-MM-DD 줄)를 찾지 못했습니다.');
        }

        return [
            'title' => mb_substr($title ?? $fallbackTitle, 0, 200),
            'held_at' => $heldAt,
            'body' => trim(implode("\n", $body)),
            'decisions' => trim(implode("\n", $decisions)),
        ];
    }
}
