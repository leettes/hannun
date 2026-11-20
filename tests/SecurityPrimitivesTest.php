<?php

declare(strict_types=1);

namespace Tests;

use App\Security\Crypto;
use App\Security\Totp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecurityPrimitivesTest extends TestCase
{
    /** RFC 6238 부록 B 의 SHA1 키 "12345678901234567890" */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * RFC 6238 테스트 벡터 (8자리 값의 뒤 6자리)
     */
    public function testTotpMatchesRfc6238Vectors(): void
    {
        $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time)), "T={$time}");
        }
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
    }

    public function testTotpWindowAndReuse(): void
    {
        $time = 1_800_000_000;
        $step = Totp::step($time);
        $now = Totp::code(self::RFC_SECRET, $step);

        $this->assertSame($step, Totp::verify(self::RFC_SECRET, $now, $time));
        $this->assertSame($step, Totp::verify(self::RFC_SECRET, substr($now, 0, 3) . ' ' . substr($now, 3), $time), '띄어 써도 된다');
        $this->assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $time), '30초 전 코드 허용');
        $this->assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $time), '30초 뒤 코드 허용');
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $time), '1분 전 코드 거부');
        $this->assertNull(Totp::verify(self::RFC_SECRET, $now, $time, $step), '이미 쓴 코드 거부');
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $time, $step), '쓴 단계보다 이전 코드도 거부');
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, '', $time));
    }

    public function testTotpUriAndSecret(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $uri = Totp::uri($secret, 'a@example.com', '한눈');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $crypto = new Crypto(random_bytes(32));
        $a = $crypto->encrypt('회의 결정 사항');
        $this->assertStringStartsWith('v1:', $a);
        $this->assertStringNotContainsString('회의', $a);
        $this->assertNotSame($a, $crypto->encrypt('회의 결정 사항'), '값마다 nonce 가 다르다');
        $this->assertSame('회의 결정 사항', $crypto->decrypt($a));
        $this->assertSame('', $crypto->decrypt($crypto->encrypt('')));

        $raw = base64_decode(substr($a, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(RuntimeException::class);
        $crypto->decrypt('v1:' . base64_encode($raw));
    }

    public function testCryptoWrongKeyAndKeyRotation(): void
    {
        $old = random_bytes(32);
        $new = random_bytes(32);
        $stored = (new Crypto($old))->encrypt('비밀');

        $this->assertSame('비밀', (new Crypto($new, $old))->decrypt($stored), '교체 중에는 이전 키로도 읽는다');
        $this->assertSame('비밀', (new Crypto($new, $old))->decrypt((new Crypto($new, $old))->encrypt('비밀')));
        try {
            (new Crypto($new))->decrypt($stored);
            $this->fail('다른 키로는 복호화되면 안 된다');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        try {
            (new Crypto($new))->decrypt('평문');
            $this->fail('형식이 아니면 거부');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCryptoFromEnvRefusesMissingOrInvalidKey(): void
    {
        $this->assertNull(Crypto::fromBase64(null));
        $this->assertNull(Crypto::fromBase64(''));
        $this->assertNull(@Crypto::fromBase64(base64_encode('short')));
        $this->assertNull(@Crypto::fromBase64('not base64!!'));
        $this->assertNull(@Crypto::fromBase64(base64_encode(random_bytes(32)), 'bad'), '이전 키가 잘못돼도 거부');
        $this->assertInstanceOf(Crypto::class, Crypto::fromBase64(base64_encode(random_bytes(32))));
    }
}
