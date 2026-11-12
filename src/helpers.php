<?php

declare(strict_types=1);

use App\Monitoring\Incident;
use App\Monitoring\Status;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 사이드바 서비스 타일 글자: 약칭이 있으면 약칭, 없으면 이름 앞 두 글자(공백 제외).
 */
function service_short(string $name, ?string $short = null): string
{
    $short = trim((string) $short);

    return $short !== '' ? $short : mb_substr((string) preg_replace('/\s+/u', '', $name), 0, 2);
}

/**
 * 사용자 아이콘 글자: 한글 세·네 글자 이름은 성을 뺀 두 글자(이승환 → 승환), 두 글자 이하는 그대로, 그 외는 첫 글자(대문자).
 */
function avatar_text(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $length = mb_strlen($name);
    if (preg_match('/^\p{Hangul}+$/u', $name) && $length <= 4) {
        return $length <= 2 ? $name : mb_substr($name, $length - 2);
    }

    return mb_strtoupper(mb_substr($name, 0, 1));
}

/**
 * public/assets/ 정적 파일 주소. 파일이 바뀌면 주소(?v=수정시각)도 바뀌어 브라우저 캐시를 피한다.
 */
function asset(string $file): string
{
    $path = '/assets/' . ltrim($file, '/');
    $mtime = @filemtime(dirname(__DIR__) . '/public' . $path);

    return e($mtime === false ? $path : $path . '?v=' . $mtime);
}

function status_label(string $status): string
{
    return Status::label($status);
}

function severity_label(string $severity): string
{
    return Incident::SEVERITY_LABELS[$severity] ?? $severity;
}

function incident_status_label(string $status): string
{
    return Incident::STATUS_LABELS[$status] ?? $status;
}

function pct(int|float|null $value): string
{
    return $value === null ? '-' : round($value, 1) . '%';
}

function fmt_time(?DateTimeInterface $time, string $format = 'm-d H:i'): string
{
    return $time === null ? '-' : $time->format($format);
}

function time_ago(?DateTimeInterface $time): string
{
    if ($time === null) {
        return '-';
    }

    $seconds = max(0, time() - $time->getTimestamp());

    return match (true) {
        $seconds < 60 => $seconds . '초 전',
        $seconds < 3600 => intdiv($seconds, 60) . '분 전',
        $seconds < 86400 => intdiv($seconds, 3600) . '시간 전',
        default => intdiv($seconds, 86400) . '일 전',
    };
}

function duration(DateTimeInterface $start, ?DateTimeInterface $end = null): string
{
    $minutes = intdiv(max(0, ($end?->getTimestamp() ?? time()) - $start->getTimestamp()), 60);

    if ($minutes < 60) {
        return $minutes . '분';
    }
    if ($minutes < 1440) {
        return intdiv($minutes, 60) . '시간 ' . ($minutes % 60) . '분';
    }

    return intdiv($minutes, 1440) . '일 ' . intdiv($minutes % 1440, 60) . '시간';
}

/**
 * 값 목록을 작은 꺾은선 SVG로 그린다. $max 가 null 이면 최댓값 기준.
 *
 * @param list<float|int> $values
 */
function sparkline(array $values, ?float $max = null, int $width = 160, int $height = 36): string
{
    $count = count($values);
    if ($count < 2) {
        return '';
    }

    $max ??= max($values) ?: 1;
    $points = [];
    foreach ($values as $i => $value) {
        $x = round($i / ($count - 1) * $width, 1);
        $y = round($height - 2 - (min($value, $max) / $max) * ($height - 4), 1);
        $points[] = $x . ',' . $y;
    }

    return sprintf(
        '<svg class="sparkline" viewBox="0 0 %d %d" preserveAspectRatio="none" aria-hidden="true">'
        . '<polyline points="%s" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>',
        $width,
        $height,
        implode(' ', $points),
    );
}

/**
 * 도메인 등록업체 표시 이름. 국내에서 많이 쓰는 곳은 한글 이름을 붙인다.
 */
function registrar_label(?string $registrar): string
{
    if ($registrar === null || $registrar === '') {
        return '-';
    }
    $known = ['gabia' => '가비아', 'whois corp' => '후이즈', 'iteasy' => '아이티이지', 'amazon registrar' => 'AWS',
        'cafe24' => '카페24', 'hosting.kr' => '호스팅케이알', 'megazone' => '메가존', 'godaddy' => 'GoDaddy', 'cloudflare' => 'Cloudflare'];
    foreach ($known as $needle => $label) {
        if (str_contains(strtolower($registrar), $needle)) {
            return "{$label} ({$registrar})";
        }
    }

    return $registrar;
}

/**
 * 네임서버 이름으로 DNS 관리 서비스를 추정한다.
 *
 * @param list<string> $nameservers
 */
function dns_provider(array $nameservers): string
{
    $all = implode(' ', $nameservers);
    $known = ['awsdns' => 'AWS Route 53', 'cloudflare' => 'Cloudflare', 'gabia' => '가비아', 'cafe24' => '카페24',
        'whois' => '후이즈', 'ksdom' => 'ksdom (아이티이지)', 'hosting.kr' => '호스팅케이알', 'domaincontrol' => 'GoDaddy', 'googledomains' => 'Google'];
    foreach ($known as $needle => $label) {
        if (str_contains($all, $needle)) {
            return $label;
        }
    }

    return $nameservers === [] ? '-' : $nameservers[0];
}

/**
 * AWS 계정 ID 를 콘솔과 같은 1234-5678-9012 형식으로.
 */
function aws_account_label(?string $accountId): string
{
    return $accountId === null || strlen($accountId) !== 12 ? (string) ($accountId ?? '-') : implode('-', str_split($accountId, 4));
}

/**
 * 날짜(Y-m-d 또는 Y-m-d H:i:s)까지 남은 날. 지났으면 음수, 값이 없으면 null
 */
function days_until(?string $date): ?int
{
    if ($date === null || $date === '' || ($time = strtotime(substr($date, 0, 10))) === false) {
        return null;
    }

    return (int) floor(($time - strtotime('today')) / 86400);
}

/**
 * 만료일 표시: 2027-03-02 (116일). 30일 안이면 경고 표시를 붙인다
 */
function expiry_cell(?string $date, string $empty = '-'): string
{
    $days = days_until($date);
    if ($days === null) {
        return '<span class="muted">' . e($empty) . '</span>';
    }
    $text = e(substr((string) $date, 0, 10)) . ' <span class="muted small">(' . ($days < 0 ? '만료 ' . -$days . '일 지남' : $days . '일') . ')</span>';

    return $days < 30 ? '<span class="t-warn" title="만료가 30일 안으로 다가왔습니다">' . $text . ' ⚠</span>' : $text;
}

/**
 * 사업자등록번호 1234567890 → 123-45-67890
 */
function business_no_label(?string $number): string
{
    return $number === null || strlen($number) !== 10 ? (string) ($number ?? '-') : substr($number, 0, 3) . '-' . substr($number, 3, 2) . '-' . substr($number, 5);
}

function mfa_label(?int $enabled): string
{
    return match ($enabled) {
        1 => '설정됨',
        0 => '안 됨',
        default => '모름',
    };
}

/**
 * 서비스의 클라우드 표시. AWS 는 연결된 계정 별칭·ID 를 붙인다.
 */
function cloud_label(string $provider, ?string $awsAlias = null, ?string $awsAccountId = null): string
{
    $label = App\Monitoring\SystemRegistry::CLOUD_PROVIDERS[$provider] ?? $provider;
    if ($provider !== 'aws') {
        return $label;
    }

    return $awsAccountId === null ? $label . ' (계정 미지정)' : "{$label} · {$awsAlias} (" . aws_account_label($awsAccountId) . ')';
}

/**
 * 별점 표시 (소수점은 별을 부분만 채운다). 앱 상세·리뷰 목록 공용
 */
function stars(float $rating): string
{
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $fill = max(0, min(1, $rating - $i + 1));
        $out .= '<span class="star" style="--fill: ' . round($fill * 100) . '%" aria-hidden="true">★</span>';
    }

    return '<span class="stars" title="' . e(number_format($rating, 1)) . '점">' . $out . '</span>';
}
