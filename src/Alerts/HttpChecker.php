<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Monitoring\Status;
use App\Monitoring\Thresholds;
use Closure;
use PDO;

/**
 * Uptime Kuma 없이 대시보드가 직접 웹/API 를 점검한다 (cron 1분, UPTIME_KUMA_URL 이 비어 있을 때).
 * 예전 API 모니터링 배치와 같은 규칙:
 *
 * - HTTP 2xx/3xx(리다이렉트는 따라가지 않음) + 리소스 config 의 응답 확인
 *   check_keyword: 본문에 이 문자열이 있어야 함 / check_json_key, check_json_value: JSON 값이 같아야 함
 * - 실패하거나 응답이 느리면(Thresholds::HTTP_SLOW_MS) 1초 뒤 한 번 더 요청해서 그 결과로 판단
 * - 두 번 모두 실패하면 장애, 느리면 경고 (반영은 HttpStatusTracker)
 */
final class HttpChecker
{
    public const SOURCE = 'dashboard';

    public const TIMEOUT_SECONDS = 30;

    private readonly HttpStatusTracker $tracker;

    /**
     * @param Closure(): PDO $connection
     * @param Closure(string): array{status: int, body: string, ms: int, error: ?string, cert_days: ?int} $request
     * @param Closure(): void $pause 재시도 전 대기
     */
    public function __construct(
        private readonly Closure $connection,
        AlertIngestor $ingestor,
        private readonly Closure $request,
        private readonly Closure $pause,
    ) {
        $this->tracker = new HttpStatusTracker($connection, $ingestor);
    }

    /**
     * @return array{checked: int, down: int, slow: int, opened: int, resolved: int, failures: list<string>}
     */
    public function run(): array
    {
        $summary = ['checked' => 0, 'down' => 0, 'slow' => 0, 'opened' => 0, 'resolved' => 0, 'failures' => []];
        $statement = ($this->connection)()->query(
            "SELECT r.id, r.system_id, r.name, r.identifier, r.config FROM resources r
             JOIN systems s ON s.id = r.system_id AND s.is_active = 1
             WHERE r.type = 'http' AND r.is_active = 1
             ORDER BY r.id",
        );

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config = $row['config'] === null ? [] : (array) json_decode((string) $row['config'], true);
            $result = $this->check((string) $row['identifier'], $config);
            $resource = ['id' => (int) $row['id'], 'system_id' => (int) $row['system_id'], 'name' => (string) $row['name']];

            $summary['checked']++;
            if ($result['error'] !== null) {
                $summary['down']++;
                $summary['failures'][] = "{$row['name']} ({$row['identifier']}): {$result['error']}";
            } elseif ($result['ms'] >= Thresholds::HTTP_SLOW_MS) {
                $summary['slow']++;
                $summary['failures'][] = sprintf('%s (%s): 응답 지연 %.2f초', $row['name'], $row['identifier'], $result['ms'] / 1000);
            }

            $change = $this->tracker->apply(
                $resource,
                self::SOURCE,
                $result['error'] === null ? Status::UP : Status::DOWN,
                $result['error'] === null ? $result['ms'] : null,
                $result['cert_days'],
                $result['error'] ?? "HTTP {$result['status']} ({$result['ms']}ms)",
            );
            if ($change !== null) {
                $summary[$change]++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{status: int, ms: int, cert_days: ?int, error: ?string} error 가 null 이면 정상
     */
    public function check(string $url, array $config): array
    {
        $result = $this->attempt($url, $config);
        if ($result['error'] !== null || $result['ms'] >= Thresholds::HTTP_SLOW_MS) {
            ($this->pause)();
            $result = $this->attempt($url, $config);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{status: int, ms: int, cert_days: ?int, error: ?string}
     */
    private function attempt(string $url, array $config): array
    {
        $response = ($this->request)($url);

        return [
            'status' => $response['status'],
            'ms' => $response['ms'],
            'cert_days' => $response['cert_days'],
            'error' => $response['error'] ?? self::verify($response['status'], $response['body'], $config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return string|null 실패 이유
     */
    public static function verify(int $status, string $body, array $config): ?string
    {
        if ($status < 200 || $status >= 400) {
            return "HTTP {$status}";
        }

        $keyword = (string) ($config['check_keyword'] ?? '');
        if ($keyword !== '' && !str_contains($body, $keyword)) {
            return "응답에 '{$keyword}' 없음: " . self::excerpt($body);
        }

        $key = (string) ($config['check_json_key'] ?? '');
        if ($key !== '') {
            $expected = (string) ($config['check_json_value'] ?? '');
            $json = json_decode($body, true);
            if (!is_array($json) || !array_key_exists($key, $json) || !is_scalar($json[$key] ?? '')) {
                return "JSON {$key} 없음: " . self::excerpt($body);
            }
            $actual = is_bool($json[$key]) ? var_export($json[$key], true) : (string) $json[$key];
            if ($actual !== $expected) {
                return "JSON {$key}={$actual} (기대값 {$expected})";
            }
        }

        return null;
    }

    /**
     * curl 로 GET 한 번. 인증서는 검증하고, 리다이렉트는 따라가지 않는다.
     *
     * @return array{status: int, body: string, ms: int, error: ?string, cert_days: ?int}
     */
    public static function request(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CERTINFO => true,
            CURLOPT_USERAGENT => 'monitoring-dashboard/1.0',
        ]);
        $body = curl_exec($curl);
        $error = $body === false ? curl_error($curl) : null;
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $ms = (int) round((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);
        $certInfo = curl_getinfo($curl, CURLINFO_CERTINFO);

        // 첫 인증서가 서버 인증서
        $expires = is_array($certInfo) ? strtotime((string) ($certInfo[0]['Expire date'] ?? '')) : false;

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'ms' => $ms,
            'error' => $error === null ? null : "요청 실패: {$error}",
            'cert_days' => $expires === false ? null : (int) floor(($expires - time()) / 86400),
        ];
    }

    private static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return mb_strimwidth($text === '' ? '(빈 응답)' : $text, 0, 200, '…');
    }
}
