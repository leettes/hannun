<?php

declare(strict_types=1);

namespace App\Alerts;

use Closure;

/**
 * Amazon SNS HTTP(S) 구독 메시지 검증과 구독 확인.
 * https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
 */
final class SnsMessage
{
    /** 서명 대상 필드 (이 순서대로 "키\n값\n") */
    private const SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** 인증서·구독 확인 URL 은 SNS 도메인만 허용 (서명 위조용 인증서 차단) */
    private const SNS_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/';

    /** @var array<string, string> 인증서 URL => PEM */
    private array $certificates = [];

    /**
     * @param list<string> $topicArns 받을 SNS 토픽 ARN
     * @param Closure(string): ?string $fetch HTTPS GET (실패하면 null)
     */
    public function __construct(
        private readonly array $topicArns,
        private readonly Closure $fetch,
    ) {
    }

    public function configured(): bool
    {
        return $this->topicArns !== [];
    }

    /**
     * @return array<string, mixed> 서명과 토픽까지 확인된 메시지
     * @throws InvalidAlert
     */
    public function parse(string $content): array
    {
        $message = json_decode($content, true);
        $type = is_array($message) ? ($message['Type'] ?? null) : null;
        if (!is_string($type) || !isset(self::SIGNED_FIELDS[$type])) {
            throw new InvalidAlert('SNS 메시지가 아닙니다.');
        }
        if (!in_array($message['TopicArn'] ?? null, $this->topicArns, true)) {
            throw new InvalidAlert('허용되지 않은 SNS 토픽입니다.');
        }
        if (!$this->verify($message, self::SIGNED_FIELDS[$type])) {
            throw new InvalidAlert('SNS 서명 검증에 실패했습니다.');
        }

        return $message;
    }

    /**
     * 구독 확인 URL 을 호출해 HTTPS 구독을 활성화한다.
     *
     * @param array<string, mixed> $message SubscriptionConfirmation
     */
    public function confirmSubscription(array $message): bool
    {
        $url = (string) ($message['SubscribeURL'] ?? '');

        return self::isSnsUrl($url) && ($this->fetch)($url) !== null;
    }

    /**
     * @param array<string, mixed> $message
     * @param list<string> $fields
     */
    private function verify(array $message, array $fields): bool
    {
        $algorithm = match ($message['SignatureVersion'] ?? null) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $certUrl = (string) ($message['SigningCertURL'] ?? '');
        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        if ($algorithm === null || $signature === false || !self::isSnsUrl($certUrl)) {
            return false;
        }

        $pem = $this->certificates[$certUrl] ??= (string) ($this->fetch)($certUrl);
        $key = $pem === '' ? false : openssl_pkey_get_public($pem);
        if ($key === false) {
            unset($this->certificates[$certUrl]);

            return false;
        }

        $data = '';
        foreach ($fields as $field) {
            if (isset($message[$field])) {
                $data .= $field . "\n" . $message[$field] . "\n";
            }
        }

        return openssl_verify($data, $signature, $key, $algorithm) === 1;
    }

    private static function isSnsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['port'])
            && preg_match(self::SNS_HOST, $parts['host'] ?? '') === 1;
    }

    /**
     * 기본 HTTPS GET (5초 제한, 인증서 검증)
     */
    public static function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
