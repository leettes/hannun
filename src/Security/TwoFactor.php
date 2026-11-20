<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Session;
use RuntimeException;

/**
 * 회의록용 2단계 인증 (TOTP).
 *
 * - 통과 기록은 세션에 사용자 ID 와 함께 둔다 (로그인한 사용자가 바뀌면 무효)
 * - 회의록 화면에서 MEETING_IDLE_MINUTES 동안 활동이 없으면 다시 입력
 * - 실패 5회면 15분 잠금 (실패·통과는 감사 로그로 센다)
 */
final class TwoFactor
{
    public const SESSION_KEY = 'totp_verified';
    public const PENDING_KEY = 'totp_pending';
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 900;

    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';

    public function __construct(
        private readonly UserProvider $users,
        private readonly AuditLog $audit,
        private readonly ?Crypto $crypto,
        private readonly int $idleSeconds,
    ) {
    }

    /**
     * APP_KEY 가 있어야 비밀값을 저장·확인할 수 있다
     */
    public function available(): bool
    {
        return $this->crypto !== null;
    }

    public function isVerified(Session $session, User $user, ?int $now = null): bool
    {
        $state = $session->get(self::SESSION_KEY);
        if (!is_array($state) || ($state['user'] ?? null) !== $user->id || !$user->hasTotp()) {
            return false;
        }

        return ($now ?? time()) - (int) ($state['activity'] ?? 0) <= $this->idleSeconds;
    }

    /**
     * 회의록 화면 활동 시각 갱신 (유휴 재인증 기준)
     */
    public function touch(Session $session, User $user): void
    {
        $session->set(self::SESSION_KEY, ['user' => $user->id, 'activity' => time()]);
    }

    public function forget(Session $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    public function locked(User $user): bool
    {
        return $this->audit->recentTotpFailures($user->id, self::LOCK_SECONDS) >= self::MAX_FAILURES;
    }

    /**
     * 등록된 비밀값으로 코드를 확인한다. 통과하면 세션에 기록
     *
     * @return self::OK|self::INVALID|self::LOCKED
     */
    public function attempt(Request $request, Session $session, User $user, string $code): string
    {
        if ($this->locked($user)) {
            return self::LOCKED;
        }
        $state = $this->users->totpState($user->id);
        $step = $state['secret'] === null ? null : Totp::verify($this->secret($state['secret']), $code, time(), $state['last_step']);
        if ($step === null || !$this->users->advanceTotpStep($user->id, $step)) {
            $this->audit->recordRequest($request, 'auth.totp_fail', 'user', $user->id);

            return $this->locked($user) ? self::LOCKED : self::INVALID;
        }

        $session->regenerate();
        $this->touch($session, $user);
        $this->audit->recordRequest($request, 'auth.totp_ok', 'user', $user->id);

        return self::OK;
    }

    /**
     * 등록 중인 비밀값 (세션에만 있고 코드 확인 전에는 DB 에 넣지 않는다)
     */
    public function pendingSecret(Session $session, User $user): string
    {
        $pending = $session->get(self::PENDING_KEY);
        if (is_array($pending) && ($pending['user'] ?? null) === $user->id && is_string($pending['secret'] ?? null)) {
            return $pending['secret'];
        }
        $secret = Totp::generateSecret();
        $session->set(self::PENDING_KEY, ['user' => $user->id, 'secret' => $secret]);

        return $secret;
    }

    /**
     * 등록 확인: 코드가 맞으면 비밀값을 암호화해 저장하고 바로 통과 처리
     */
    public function enable(Request $request, Session $session, User $user, string $code): bool
    {
        if ($this->locked($user)) {
            return false;
        }
        $secret = $this->pendingSecret($session, $user);
        $step = Totp::verify($secret, $code, time());
        if ($step === null) {
            $this->audit->recordRequest($request, 'auth.totp_fail', 'user', $user->id, ['during' => 'enable']);

            return false;
        }
        $this->users->enableTotp($user->id, $this->crypto()->encrypt($secret), $step);
        $session->remove(self::PENDING_KEY);
        $session->regenerate();
        $this->touch($session, $user);
        $this->audit->recordRequest($request, 'auth.totp_enable', 'user', $user->id);

        return true;
    }

    private function secret(string $encrypted): string
    {
        return $this->crypto()->decrypt($encrypted);
    }

    private function crypto(): Crypto
    {
        return $this->crypto ?? throw new RuntimeException('APP_KEY 가 설정되지 않았습니다.');
    }
}
