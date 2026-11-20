<?php

declare(strict_types=1);

namespace Tests;

use App\Meetings\MeetingImporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MeetingImporterTest extends TestCase
{
    public function testSplitsTitleDateBodyAndDecisions(): void
    {
        $parsed = MeetingImporter::parse(implode("\n", [
            '# 11/05 회의록',
            '',
            '2026-11-05',
            '',
            '요약 문단',
            '',
            '## 1. 앱 이슈',
            '- 내용',
            '',
            '## 2. 확인 / 할 일',
            '',
            '- [ ] 번역 언어값 확인',
            '- [ ] 약관 날짜 확인',
            '',
            '녹음본 받으면 대조',
        ]), 'fallback');

        $this->assertSame('11/05 회의록', $parsed['title']);
        $this->assertSame('2026-11-05 00:00', $parsed['held_at'], '시각이 없으면 00:00');
        $this->assertSame("요약 문단\n\n## 1. 앱 이슈\n- 내용", $parsed['body']);
        $this->assertSame("- [ ] 번역 언어값 확인\n- [ ] 약관 날짜 확인\n\n녹음본 받으면 대조", $parsed['decisions']);
        $this->assertSame('2026-11-05', meeting_time('2026-11-05 00:00'), '화면에는 날짜만');
        $this->assertSame('2026-11-05 14:00', meeting_time('2026-11-05 14:00'));
    }

    public function testTimeAndFallbackTitle(): void
    {
        $parsed = MeetingImporter::parse("2026-11-20 14:30\n본문만", '1120_회의록');
        $this->assertSame('1120_회의록', $parsed['title']);
        $this->assertSame('2026-11-20 14:30', $parsed['held_at']);
        $this->assertSame('본문만', $parsed['body']);
        $this->assertSame('', $parsed['decisions']);
    }

    public function testRequiresDate(): void
    {
        $this->expectException(RuntimeException::class);
        MeetingImporter::parse("# 날짜 없는 회의\n본문", 'x');
    }
}
