<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * 회의록 본문·TOTP 비밀값 같은 민감한 값을 APP_KEY 로 암호화한다 (libsodium secretbox).
 * 저장 형식: "v1:" + base64(nonce + 암호문). 값마다 무작위 nonce.
 *
 * - 키는 .env 에만 둔다. DB·백업·git 에 넣지 않는다. 키를 잃으면 복호화할 수 없다
 * - 키 교체: 새 키를 APP_KEY, 이전 키를 APP_KEY_PREVIOUS 에 두면 둘 다로 복호화한다 (재암호화 명령은 4단계)
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    /** @var list<string> */
    private readonly array $keys;

    public function __construct(string $key, ?string $previousKey = null)
    {
        foreach (array_filter([$key, $previousKey], static fn (?string $k): bool => $k !== null) as $k) {
            if (strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('APP_KEY 는 32바이트여야 합니다 (base64). 생성: php -r \'echo base64_encode(random_bytes(32));\'');
            }
        }
        $this->keys = array_values(array_filter([$key, $previousKey], static fn (?string $k): bool => $k !== null));
    }

    /**
     * .env 값(base64)으로 만든다. 키가 없거나 형식이 틀리면 null: 암호화가 필요한 기능은 열지 않는다 (평문으로 넘어가지 않음)
     */
    public static function fromBase64(?string $key, ?string $previousKey = null): ?self
    {
        $decode = static function (?string $value): ?string {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            $raw = base64_decode($value, true);

            return $raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? '' : $raw;
        };
        $current = $decode($key);
        $previous = $decode($previousKey);
        if ($current === null || $current === '' || $previous === '') {
            if ($current === '' || $previous === '') {
                error_log('[crypto] APP_KEY 또는 APP_KEY_PREVIOUS 형식이 올바르지 않습니다 (base64 32바이트).');
            }

            return null;
        }

        return new self($current, $previous);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->keys[0]));
    }

    /**
     * @throws RuntimeException 형식이 틀리거나 변조됐거나 키가 다를 때
     */
    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new RuntimeException('암호화 형식이 아닙니다.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1) {
            throw new RuntimeException('암호문이 손상되었습니다.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        foreach ($this->keys as $key) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain !== false) {
                return $plain;
            }
        }

        throw new RuntimeException('복호화 실패: 변조되었거나 APP_KEY 가 다릅니다.');
    }
}
