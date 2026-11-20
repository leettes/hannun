<?php

declare(strict_types=1);

namespace App\Meetings;

/**
 * 회의록용 작은 마크다운 변환기. 원문의 HTML 은 모두 이스케이프하고, 링크는 http(s)·mailto 만 만든다.
 *
 * 지원: # 제목(3단계까지), - · 1. 목록, - [ ] / - [x] 체크, > 인용, ``` 코드 블록, ---,
 *       **굵게**, *기울임*, ~~취소선~~, `코드`, [글](주소), 맨 주소 자동 링크
 */
final class Markdown
{
    private const SAFE_URL = '#^(https?://|mailto:)#i';

    public static function render(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text));
        $html = [];
        $paragraph = [];
        $flush = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . implode('<br>', array_map(self::inline(...), $paragraph)) . '</p>';
                $paragraph = [];
            }
        };

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*```/', $line)) {
                $flush();
                $code = [];
                for ($i++; $i < $n && !preg_match('/^\s*```\s*$/', $lines[$i]); $i++) {
                    $code[] = $lines[$i];
                }
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flush();
                $level = strlen($m[1]) + 2; // 화면 제목(h1·h2) 아래 단계
                $html[] = "<h{$level}>" . self::inline(rtrim($m[2], " #")) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $flush();
                $html[] = '<hr>';
                continue;
            }
            if (preg_match('/^\s*>/', $line)) {
                $flush();
                $quote = [];
                for (; $i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m); $i++) {
                    $quote[] = $m[1];
                }
                $i--;
                $html[] = '<blockquote>' . implode('<br>', array_map(self::inline(...), $quote)) . '</blockquote>';
                continue;
            }
            foreach (['ul' => '/^\s*[-*+]\s+(.*)$/', 'ol' => '/^\s*\d+[.)]\s+(.*)$/'] as $tag => $pattern) {
                if (preg_match($pattern, $line)) {
                    $flush();
                    $items = [];
                    for (; $i < $n && preg_match($pattern, $lines[$i], $m); $i++) {
                        $items[] = '<li>' . self::listItem($m[1]) . '</li>';
                    }
                    $i--;
                    $html[] = "<{$tag}>" . implode('', $items) . "</{$tag}>";
                    continue 2;
                }
            }
            $paragraph[] = $line;
        }
        $flush();

        return implode("\n", $html);
    }

    private static function listItem(string $text): string
    {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $m)) {
            $done = $m[1] !== ' ';

            return '<span class="md-check' . ($done ? ' is-done' : '') . '" aria-label="' . ($done ? '완료' : '미완료') . '">' . ($done ? '☑' : '☐') . '</span> ' . self::inline($m[2]);
        }

        return self::inline($text);
    }

    /**
     * 한 줄 안의 서식. 먼저 이스케이프하고, 코드·링크는 자리표시로 빼 두었다가 마지막에 되돌린다
     */
    public static function inline(string $text): string
    {
        $tokens = [];
        $hold = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\0" . (count($tokens) - 1) . "\0";
        };

        $text = self::escape($text);
        $text = (string) preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => $hold('<code>' . $m[1] . '</code>'), $text);
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/',
            static fn (array $m): string => self::isSafeUrl($m[2]) ? $hold(self::link($m[2], $m[1])) : $m[1],
            $text,
        );
        $text = (string) preg_replace_callback(
            '#https?://[^\s<>"\x00]+#i',
            static function (array $m) use ($hold): string {
                $url = rtrim($m[0], '.,;:!?)');
                $rest = substr($m[0], strlen($url));

                return $hold(self::link($url, $url)) . $rest;
            },
            $text,
        );
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $text);

        return (string) preg_replace_callback('/\0(\d+)\0/', static fn (array $m): string => $tokens[(int) $m[1]], $text);
    }

    /**
     * @param string $url 이미 이스케이프된 주소
     * @param string $label 이미 이스케이프된 글
     */
    private static function link(string $url, string $label): string
    {
        return '<a href="' . $url . '" rel="noopener noreferrer nofollow" target="_blank">' . $label . '</a>';
    }

    private static function isSafeUrl(string $escapedUrl): bool
    {
        return preg_match(self::SAFE_URL, html_entity_decode($escapedUrl, ENT_QUOTES | ENT_HTML5)) === 1;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
