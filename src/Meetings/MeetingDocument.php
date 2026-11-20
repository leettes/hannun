<?php

declare(strict_types=1);

namespace App\Meetings;

/**
 * 회의록 정리본 렌더러 (시안-상세의 renderMd 와 같은 범위). 원문 HTML 은 모두 이스케이프한다.
 *
 * - '# 제목'·날짜 줄은 페이지 머리에 보이므로 건너뛴다
 * - ## / ### 에 id 를 붙이고 목차를 만든다. '(결론 없음)' 은 배지로
 * - 목록은 4칸 들여쓰기로 중첩. '**결론:**', '**방향:**', '**정리:**' 로 시작하면 결정 강조
 * - 첫 문단은 요약(리드) 문단
 * - '(…불명확)' 은 확인 필요 칩, '참석자 N' 은 발언자 칩
 * - 할 일 섹션('## … 할 일')은 넘겨받은 할 일 블록 HTML 로 바꾼다
 */
final class MeetingDocument
{
    /**
     * @param string $body 정리본 마크다운 (할 일 섹션은 빠져 있을 수 있다)
     * @param ?string $taskBlock 할 일 섹션 자리에 넣을 HTML (없으면 $decisions 를 목록으로)
     * @param string $decisions 결정 사항 마크다운 (할 일로 등록하기 전)
     * @return array{html: string, toc: list<array{id: string, title: string, sub: list<array{id: string, title: string}>}>}
     */
    public static function render(string $body, ?string $taskBlock = null, string $decisions = ''): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body));
        $hasTaskHeading = false;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $m) && self::isTaskHeading($m[1])) {
                $hasTaskHeading = true;
            }
        }
        if (!$hasTaskHeading && ($taskBlock !== null || trim($decisions) !== '')) {
            $lines[] = '';
            $lines[] = '## 확인 / 할 일';
        }

        $html = '';
        $toc = [];
        $depth = 0;
        $h2 = 0;
        $h3 = 0;
        $inTasks = false;
        $lead = true;
        $closeLists = static function (int $to) use (&$html, &$depth): void {
            while ($depth > $to) {
                $html .= '</ul>';
                $depth--;
            }
        };

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if (preg_match('/^#\s+/', $line) || preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $line)) {
                continue;
            }
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $closeLists(0);
                $h2++;
                $h3 = 0;
                $id = "s{$h2}";
                $inTasks = self::isTaskHeading($m[1]);
                $noConclusion = str_contains($m[1], '(결론 없음)');
                $heading = trim(str_replace('(결론 없음)', '', $m[1]));
                $html .= '<h2 id="' . $id . '">' . self::escape($heading) . ($noConclusion ? '<span class="no-conclusion">결론 없음</span>' : '') . '</h2>';
                $toc[] = ['id' => $id, 'title' => self::tocTitle($heading, all: false), 'sub' => []];
                if ($inTasks) {
                    $html .= $taskBlock ?? self::decisionList($decisions);
                }
                continue;
            }
            if ($inTasks) {
                continue; // 할 일 체크리스트는 할 일 블록으로 대체
            }
            if (preg_match('/^###\s+(.+)$/', $line, $m)) {
                $closeLists(0);
                $h3++;
                $id = "s{$h2}-{$h3}";
                $html .= '<h3 id="' . $id . '">' . self::inline($m[1]) . '</h3>';
                if ($toc !== []) {
                    $toc[count($toc) - 1]['sub'][] = ['id' => $id, 'title' => self::tocTitle($m[1], all: true)];
                }
                continue;
            }
            if (preg_match('/^( *)[-*]\s+(.+)$/', $line, $m)) {
                $level = intdiv(strlen($m[1]), 4) + 1;
                while ($depth < $level) {
                    $html .= '<ul>';
                    $depth++;
                }
                $closeLists($level);
                $decision = preg_match('/^\*\*(결론|방향|정리):?\*\*/u', $m[2]) === 1;
                $html .= '<li' . ($decision ? ' class="decision"' : '') . '>' . self::inline($m[2]) . '</li>';
                continue;
            }
            if ($line === '') {
                $closeLists(0);
                continue;
            }
            $closeLists(0);
            $html .= $lead ? '<p class="doc-lead">' . self::inline($line) . '</p>' : '<p>' . self::inline($line) . '</p>';
            $lead = false;
        }
        $closeLists(0);

        return ['html' => $html, 'toc' => $toc];
    }

    /**
     * 정리본 첫 문단 (요약). 가져오기·녹취 작성에서 요약이 비었을 때 쓴다
     */
    public static function leadParagraph(string $body): string
    {
        foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $body)) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '' && !preg_match('/^(#|[-*]\s|\d{4}-\d{2}-\d{2})/', $block)) {
                return $block;
            }
        }

        return '';
    }

    public static function isTaskHeading(string $heading): bool
    {
        return preg_match('/할\s*일/u', $heading) === 1;
    }

    private static function decisionList(string $decisions): string
    {
        if (trim($decisions) === '') {
            return '';
        }

        return '<div class="md-body">' . Markdown::render($decisions) . '</div>';
    }

    /**
     * 목차 글: 번호를 떼고 '(결론 없음)'·'(참석자 N)' 을 뺀다. 소제목은 괄호 설명을 모두 뺀다
     */
    private static function tocTitle(string $heading, bool $all): string
    {
        $title = (string) preg_replace('/^\d+\.\s*/', '', $heading);
        $title = (string) preg_replace('/\s*\((결론 없음|참석자 \d+)\)/u', '', $title);

        return trim($all ? (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $title) : $title);
    }

    private static function inline(string $text): string
    {
        $html = Markdown::inline($text);
        $html = (string) preg_replace_callback(
            '/\(([^()]*?)불명확\)/u',
            static function (array $m): string {
                $pre = trim(rtrim($m[1]), ', ');

                return ($pre !== '' ? '(' . $pre . ') ' : '') . '<span class="unclear" title="녹취에서 확실하지 않은 부분. 녹취 원문 탭에서 확인">불명확</span>';
            },
            $html,
        );

        // '참석자 7명' 같은 인원수는 그대로 둔다
        return (string) preg_replace('/참석자 (\d+)(?![\d명])/u', '<span class="who">참석자 $1</span>', $html);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
