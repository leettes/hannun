<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Monitoring\MonitoringRepository;
use App\View;

abstract class Controller
{
    /** 상태를 보는 화면(입력 폼 없음)의 자동 새로고침 간격(초). cron 점검 주기와 같다 */
    protected const REFRESH_SECONDS = 60;

    public function __construct(
        protected readonly View $view,
        protected readonly MonitoringRepository $data,
    ) {
    }

    /**
     * 레이아웃(사이드바 등)에 필요한 공통 값을 채워 페이지를 렌더링한다.
     *
     * @param array<string, mixed> $vars
     */
    protected function page(string $template, array $vars = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $vars + [
            'title' => '',
            'active' => '',
            'refresh' => null,
            'navSystems' => $this->data->systems(),
            'activeIncidentCount' => $this->data->activeIncidentCount(),
        ]), $status);
    }

    protected function notFound(string $message = '요청한 페이지를 찾을 수 없습니다.'): Response
    {
        return $this->page('error', ['title' => '페이지 없음', 'message' => $message], 404);
    }

    protected function forbidden(): Response
    {
        return $this->page('error', [
            'title' => '권한 없음',
            'heading' => '접근 권한이 없습니다',
            'message' => '이 기능을 사용할 권한이 없습니다. 필요하면 관리자에게 역할 변경을 요청하세요.',
        ], 403);
    }

    /**
     * YYYY-MM-DD 이고 실제로 있는 날짜인지 (2027-02-30 은 거부)
     */
    protected static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    protected function session(Request $request): Session
    {
        $session = $request->attribute('session');

        return $session instanceof Session ? $session : new Session();
    }
}
