<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\AppRegistry;
use App\Monitoring\MonitoringRepository;
use App\View;

/**
 * 시스템 구성도 (/architecture). 모든 역할이 본다.
 *
 * 서비스마다 사용자 → 도메인·보안 → 웹 서버 → 데이터 → 외부 연동 계층으로 그린다.
 * 상태·도메인·앱·개발자 계정은 DB 값을 그대로 쓰고, 한눈이 수집하지 않는 항목
 * (Nginx·PHP·MySQL 버전, 결제·푸시 등 외부 연동)은 STACK·EXTERNAL_TODO 로 표시만 한다.
 */
final class ArchitectureController extends Controller
{
    /** AWS 서비스의 공통 스택. 서버 안 버전은 아직 수집하지 않는다 */
    public const STACK = [
        'nginx' => ['Nginx', '웹 서버 · 리버스 프록시'],
        'php' => ['PHP-FPM', '애플리케이션'],
        'mysql' => ['MySQL', 'RDS 인지 EC2 직접 설치인지 미확인'],
    ];

    /** 서비스별 기록이 없어 '확인 필요'로 보이는 외부 연동 */
    public const EXTERNAL_TODO = [
        ['푸시', 'APNs / FCM', true],
        ['결제', '인앱 결제 / PG', true],
        ['SMS', '본인인증 · 알림 문자', false],
        ['소셜 로그인', 'Apple / Google / Kakao', false],
    ];

    /** 한눈·Uptime Kuma 가 함께 올라가 있는 서비스 서버 (docs/DEPLOY.md, 2026-11-08 결정) */
    public const HANNUN_HOST = 'koi';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly AppRegistry $apps,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $systems = $this->data->systems();
        $code = (string) ($request->query('system') ?? '');
        if ($code !== '' && !isset($systems[$code])) {
            return $this->notFound('등록되지 않은 서비스입니다.');
        }

        $appsBySystem = [];
        foreach ($this->apps->apps() as $app) {
            if ((int) $app['is_active'] === 1) {
                $appsBySystem[(string) $app['system_code']][] = $app;
            }
        }

        return $this->page('architecture', [
            'title' => '시스템 구성도',
            'active' => 'architecture',
            'refresh' => self::REFRESH_SECONDS,
            'systems' => $systems,
            'selected' => $code === '' ? null : $systems[$code],
            'appsBySystem' => $appsBySystem,
        ]);
    }
}
