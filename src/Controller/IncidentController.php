<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Monitoring\Incident;
use App\Monitoring\IncidentActions;
use App\Monitoring\IncidentUpdateRejected;
use App\Monitoring\MonitoringRepository;
use App\Notifications\Notifier;
use App\View;
use Throwable;

final class IncidentController extends Controller
{
    private const FLASH_KEY = 'incident_flash';

    /** 대시보드에 한 번 보여 줄 메시지 (DashboardController 가 읽는다) */
    public const DASHBOARD_FLASH_KEY = 'dashboard_flash';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly IncidentActions $actions,
        private readonly UserProvider $users,
        private readonly Notifier $notifier,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        if ($status === null || !isset(Incident::STATUS_LABELS[$status])) {
            $status = null;
        }

        $statusCounts = array_count_values(array_column($this->data->incidents(), 'status'));

        return $this->page('incidents', [
            'title' => '장애 센터',
            'active' => 'incidents',
            'refresh' => self::REFRESH_SECONDS,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'total' => array_sum($statusCounts),
            'incidents' => $this->data->incidents($status),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $incident = $this->find($id);
        if ($incident === null) {
            return $this->notFound('존재하지 않는 장애입니다.');
        }

        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);
        $values = array_combine(IncidentActions::FIELDS, [
            $incident['status'], $incident['assignee_id'], $incident['root_cause'], $incident['action_taken'],
        ]);

        return $this->detail($incident, $values, $values, '', is_string($flash) ? $flash : null);
    }

    /**
     * 운영자 이상 (라우트에서 제한)
     */
    public function update(Request $request, string $id): Response
    {
        $incident = $this->find($id);
        if ($incident === null) {
            return $this->notFound('존재하지 않는 장애입니다.');
        }

        $read = static fn (string $prefix): array => array_combine(IncidentActions::FIELDS, [
            $request->input($prefix . 'status') ?? '',
            $request->input($prefix . 'assignee') ?? '',
            $request->input($prefix . 'root_cause') ?? '',
            $request->input($prefix . 'action_taken') ?? '',
        ]);
        $seen = $read('seen_');
        $wanted = $read('');
        $note = $request->input('note') ?? '';
        $user = $request->attribute('user');

        try {
            $this->actions->update($incident['id'], $user instanceof User ? $user->id : 0, $seen, $wanted, $note);
        } catch (IncidentUpdateRejected $e) {
            return $this->detail($incident, $seen, $wanted, $note, error: $e->getMessage(), status: $e->conflict ? 409 : 422);
        }

        try {
            $this->notifier->dispatch();
        } catch (Throwable $e) {
            error_log('[notifications] dispatch failed: ' . $e->getMessage());
        }

        $this->session($request)->set(self::FLASH_KEY, '저장했습니다.');

        return Response::redirect('/incidents/' . $incident['id']);
    }

    /**
     * 대시보드 '최근 장애'에서 확인 처리 (운영자 이상, 라우트에서 제한). 사유는 선택.
     */
    public function dismiss(Request $request, string $id): Response
    {
        $incident = $this->find($id);
        if ($incident === null) {
            return $this->notFound('존재하지 않는 장애입니다.');
        }

        $user = $request->attribute('user');
        try {
            $this->actions->dismiss($incident['id'], $user instanceof User ? $user->id : 0, $request->input('reason'));
            $message = "'{$incident['title']}' 장애를 확인했습니다.";
        } catch (IncidentUpdateRejected $e) {
            $message = $e->getMessage();
        }
        $this->session($request)->set(self::DASHBOARD_FLASH_KEY, $message);

        return Response::redirect('/#recent-incidents');
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $seen 화면에 처음 표시한 값 (충돌 확인용으로 다시 보낸다)
     * @param array<string, mixed> $values 입력란에 채울 값
     */
    private function detail(
        array $incident,
        array $seen,
        array $values,
        string $note,
        ?string $flash = null,
        ?string $error = null,
        int $status = 200,
    ): Response {
        return $this->page('incident', [
            'title' => '장애 #' . $incident['id'],
            'active' => 'incidents',
            'incident' => $incident,
            'seen' => $seen,
            'values' => $values,
            'note' => $note,
            'assignees' => $this->assignees($incident),
            'flash' => $flash,
            'error' => $error,
        ], $status);
    }

    /**
     * 담당자 후보: 운영자 이상 활성 계정. 지금 담당자가 후보가 아니어도(비활성 등) 목록에는 남긴다.
     *
     * @param array<string, mixed> $incident
     * @return array<int, string> id => 이름
     */
    private function assignees(array $incident): array
    {
        $options = [];
        foreach ($this->users->all() as $u) {
            if ($u->active && $u->can('operator')) {
                $options[$u->id] = $u->name;
            }
        }
        if ($incident['assignee_id'] !== null && !isset($options[$incident['assignee_id']])) {
            $options[$incident['assignee_id']] = $incident['assignee'] . ' (지정 불가)';
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->data->incident((int) $id) : null;
    }
}
