<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\MonitoringRepository;
use App\Security\AuditLog;
use App\View;

/**
 * 감사 로그 조회 (관리자). 수정·삭제 기능은 없다.
 */
final class AuditLogController extends Controller
{
    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly AuditLog $audit,
        private readonly UserProvider $users,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $userId = $request->query('user');
        $action = $request->query('action');
        $from = $request->query('from');
        $to = $request->query('to');
        $filter = [
            'user_id' => $userId !== null && ctype_digit($userId) ? (int) $userId : null,
            'action' => $action !== null && $action !== '' ? $action : null,
            'from' => $from !== null && self::validDate($from) ? $from : null,
            'to' => $to !== null && self::validDate($to) ? $to : null,
        ];
        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $this->audit->search($filter, $page);

        return $this->page('audit-logs', [
            'title' => '감사 로그',
            'active' => 'audit-logs',
            'filter' => $filter,
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / AuditLog::PAGE_SIZE)),
            'users' => $this->users->all(),
        ]);
    }
}
