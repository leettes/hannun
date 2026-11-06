<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\Status;

final class DashboardController extends Controller
{
    /** 서비스 상태 보기 방식: 전체 / 관리회사별. 고른 방식은 세션에 기억한다 */
    public const VIEWS = ['all', 'company'];

    private const VIEW_KEY = 'dashboard_view';

    public function index(Request $request): Response
    {
        $systems = $this->data->systems();
        $counts = array_count_values(array_column($systems, 'status')) + ['up' => 0, 'warning' => 0, 'down' => 0];
        $activeIncidentCounts = array_map(
            fn (array $s): int => $this->data->activeIncidentCount($s['code']),
            $systems,
        );

        $session = $this->session($request);
        $view = $request->query('view');
        if (in_array($view, self::VIEWS, true)) {
            $session->set(self::VIEW_KEY, $view);
        } else {
            $view = $session->get(self::VIEW_KEY);
            $view = in_array($view, self::VIEWS, true) ? $view : 'all';
        }

        $flash = $session->get(IncidentController::DASHBOARD_FLASH_KEY);
        $session->remove(IncidentController::DASHBOARD_FLASH_KEY);

        return $this->page('dashboard', [
            'title' => '대시보드',
            'active' => 'dashboard',
            'refresh' => self::REFRESH_SECONDS,
            'systems' => $systems,
            'counts' => $counts,
            'activeIncidentCounts' => $activeIncidentCounts,
            'view' => $view,
            'groups' => $view === 'company' ? self::groupByCompany($systems, $activeIncidentCounts) : null,
            // 확인한 장애는 빼고 보여 준다 (장애 센터에는 그대로 남는다)
            'recentIncidents' => $this->data->incidents(limit: 5, hideDismissed: true),
            'lastCheckedAt' => $this->data->lastCheckedAt(),
            'flash' => is_string($flash) ? $flash : null,
        ]);
    }

    /**
     * 관리회사별로 묶는다. 서비스에 직접 지정한 회사, 없으면 AWS 계정의 회사. 회사 이름순, 관리회사가 없는 서비스는 마지막.
     *
     * @param array<string, array<string, mixed>> $systems code => 서비스
     * @param array<string, int> $activeIncidentCounts code => 처리 중 장애 수
     * @return list<array{label: string, company_id: ?int, accounts: list<string>, status: string, codes: list<string>, counts: array<string, int>, incidents: int}>
     */
    public static function groupByCompany(array $systems, array $activeIncidentCounts): array
    {
        $groups = [];
        foreach ($systems as $code => $s) {
            $companyId = $s['company_id'] ?? null;
            $key = $companyId === null ? 'none' : "company:{$companyId}";
            $groups[$key] ??= [
                'label' => $companyId === null ? '관리회사 미지정' : (string) $s['company_name'],
                'company_id' => $companyId,
                'accounts' => [],
                'codes' => [],
                'statuses' => [],
                'incidents' => 0,
            ];
            if ($companyId !== null && $s['cloud'] === 'aws' && $s['aws_alias'] !== null) {
                $groups[$key]['accounts'][$s['aws_alias']] = true;
            }
            $groups[$key]['codes'][] = (string) $code;
            $groups[$key]['statuses'][] = $s['status'];
            $groups[$key]['incidents'] += $activeIncidentCounts[$code] ?? 0;
        }
        uasort($groups, static fn (array $a, array $b): int => [$a['company_id'] === null, $a['label']] <=> [$b['company_id'] === null, $b['label']]);

        return array_values(array_map(static function (array $g): array {
            $accounts = array_keys($g['accounts']);
            sort($accounts);

            return [
                'label' => $g['label'],
                'company_id' => $g['company_id'],
                'accounts' => array_map(strval(...), $accounts),
                'status' => Status::worst($g['statuses']),
                'codes' => $g['codes'],
                'counts' => array_count_values($g['statuses']) + ['up' => 0, 'warning' => 0, 'down' => 0],
                'incidents' => $g['incidents'],
            ];
        }, $groups));
    }
}
