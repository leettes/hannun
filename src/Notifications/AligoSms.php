<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;

/**
 * 알리고 문자 API (https://smartsms.aligo.in/admin/api/spec.html)
 * 발신번호와 발송 서버 IP 를 알리고 관리 화면에 미리 등록해야 한다.
 */
final class AligoSms implements SmsSender
{
    private const ENDPOINT = 'https://apis.aligo.in/send/';

    /** 단문(SMS) 최대 길이 (EUC-KR 바이트). 넘으면 장문(LMS) */
    private const SMS_BYTES = 90;

    /**
     * @param Closure(string, array<string, string>): ?string $post URL 과 폼 필드로 POST, 실패하면 null
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $userId,
        private readonly string $sender,
        private readonly bool $testMode,
        private readonly Closure $post,
    ) {
    }

    public function send(array $to, string $message): void
    {
        $long = strlen((string) mb_convert_encoding($message, 'EUC-KR', 'UTF-8')) > self::SMS_BYTES;
        $fields = [
            'key' => $this->apiKey,
            'user_id' => $this->userId,
            'sender' => $this->sender,
            'receiver' => implode(',', $to),
            'msg' => $message,
            'msg_type' => $long ? 'LMS' : 'SMS',
        ];
        if ($long) {
            $fields['title'] = '한눈 모니터링';
        }
        if ($this->testMode) {
            $fields['testmode_yn'] = 'Y';
        }

        $body = ($this->post)(self::ENDPOINT, $fields);
        $result = $body === null ? null : json_decode($body, true);
        if (!is_array($result)) {
            throw new SmsFailed('알리고 API 응답 없음');
        }
        if ((int) ($result['result_code'] ?? 0) !== 1) {
            throw new SmsFailed(sprintf('알리고 오류 %s: %s', $result['result_code'] ?? '?', $result['message'] ?? ''));
        }
    }

    /**
     * 기본 HTTPS POST (10초 제한)
     *
     * @param array<string, string> $fields
     */
    public static function httpPost(string $url, array $fields): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => 10,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
