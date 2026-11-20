<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\Security\AuditLog;
use App\Security\Totp;
use App\Security\TwoFactor;
use App\View;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;

/**
 * 2단계 인증 등록(/me/2fa)·해제와 코드 입력(/2fa). 모든 역할이 쓸 수 있다.
 * 회의록 권한이 있으면 필수이고, 그때는 스스로 해제할 수 없다 (관리자가 초기화).
 */
final class TwoFactorController extends Controller
{
    public const ISSUER = '한눈';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly UserProvider $users,
        private readonly TwoFactor $twoFactor,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view, $data);
    }

    public function setup(Request $request): Response
    {
        $me = $this->me($request);
        if (!$this->twoFactor->available()) {
            return $this->unavailable();
        }

        return $this->setupPage($request, $me);
    }

    public function enable(Request $request): Response
    {
        $me = $this->me($request);
        if (!$this->twoFactor->available()) {
            return $this->unavailable();
        }
        if ($me->hasTotp()) {
            return Response::redirect('/me/2fa');
        }
        if ($this->twoFactor->locked($me)) {
            return $this->setupPage($request, $me, '실패가 많아 ' . intdiv(TwoFactor::LOCK_SECONDS, 60) . '분 동안 잠겼습니다. 잠시 후 다시 시도하세요.', 429);
        }
        if (!$this->twoFactor->enable($request, $this->session($request), $me, $request->input('code') ?? '')) {
            return $this->setupPage($request, $me, '코드가 맞지 않습니다. 휴대폰 시각이 맞는지 확인하고 새 코드를 입력하세요.', 422);
        }
        $next = $request->input('next');
        if ($next !== null && $next !== '') {
            return Response::redirect(self::safeNext($next));
        }
        $this->session($request)->set('profile_flash', '2단계 인증을 등록했습니다.');

        return Response::redirect('/me');
    }

    public function disable(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->hasTotp()) {
            return Response::redirect('/me/2fa');
        }
        if ($me->canReadMeetings()) {
            return $this->setupPage($request, $me, '회의록 권한이 있으면 2단계 인증을 해제할 수 없습니다. 관리자에게 문의하세요.', 422);
        }
        if (!password_verify($request->input('password') ?? '', $me->passwordHash)) {
            return $this->setupPage($request, $me, '비밀번호가 올바르지 않습니다.', 422);
        }
        $this->users->resetTotp($me->id);
        $this->twoFactor->forget($this->session($request));
        $this->audit->recordRequest($request, 'auth.totp_disable', 'user', $me->id);
        $this->session($request)->set('profile_flash', '2단계 인증을 해제했습니다.');

        return Response::redirect('/me');
    }

    public function verifyForm(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->hasTotp()) {
            return Response::redirect('/me/2fa?' . http_build_query(['next' => $request->query('next') ?? '/meetings']));
        }

        return $this->verifyPage($request->query('next'), $this->twoFactor->locked($me) ? $this->lockedMessage() : null);
    }

    public function verify(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->hasTotp() || !$this->twoFactor->available()) {
            return Response::redirect('/me/2fa');
        }
        $next = $request->input('next');

        return match ($this->twoFactor->attempt($request, $this->session($request), $me, $request->input('code') ?? '')) {
            TwoFactor::OK => Response::redirect(self::safeNext($next ?: '/meetings')),
            TwoFactor::LOCKED => $this->verifyPage($next, $this->lockedMessage(), 429),
            default => $this->verifyPage($next, '코드가 맞지 않습니다.', 422),
        };
    }

    private function setupPage(Request $request, User $me, ?string $error = null, int $status = 200): Response
    {
        $secret = $me->hasTotp() ? null : $this->twoFactor->pendingSecret($this->session($request), $me);

        return $this->page('two-factor-setup', [
            'title' => '2단계 인증',
            'active' => 'profile',
            'me' => $me,
            'secret' => $secret === null ? null : Totp::format($secret),
            'qr' => $secret === null ? null : self::qrSvg(Totp::uri($secret, $me->email, self::ISSUER)),
            'next' => $request->query('next') ?? $request->input('next'),
            'error' => $error,
        ], $status);
    }

    private function verifyPage(?string $next, ?string $error = null, int $status = 200): Response
    {
        return $this->page('two-factor-verify', [
            'title' => '2단계 인증',
            'next' => $next === null ? null : self::safeNext($next),
            'error' => $error,
        ], $status);
    }

    private function unavailable(): Response
    {
        return $this->page('error', [
            'title' => '2단계 인증',
            'heading' => '2단계 인증을 쓸 수 없습니다',
            'message' => '서버에 암호화 키(APP_KEY)가 설정되지 않았습니다. 관리자에게 문의하세요.',
        ], 503);
    }

    private function lockedMessage(): string
    {
        return '실패가 많아 ' . intdiv(TwoFactor::LOCK_SECONDS, 60) . '분 동안 잠겼습니다. 잠시 후 다시 시도하세요.';
    }

    /**
     * 비밀값을 외부 서비스로 보내지 않도록 QR 코드를 서버에서 SVG 로 만든다
     */
    private static function qrSvg(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($text);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private function me(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('로그인한 사용자만 쓸 수 있다 (Authenticate 미들웨어).');
        }

        return $this->users->findById($user->id) ?? $user;
    }
}
