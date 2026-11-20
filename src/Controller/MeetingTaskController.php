<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Meetings\MeetingTaskRepository;
use App\Monitoring\MonitoringRepository;
use App\View;

/**
 * 회의 할 일: 상세(타임라인), 수정, 상태·담당 바로 바꾸기, 메모. 회의록 관문(/meetings/…) 안에 있다.
 * 보기는 그 회의를 볼 수 있는 사람, 바꾸기는 회의록 '열람·작성' 권한. 바꾼 기록은 할 일 타임라인에.
 */
final class MeetingTaskController extends Controller
{
    private const FLASH_KEY = 'meeting_task_flash';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly MeetingTaskRepository $tasks,
        private readonly UserProvider $users,
    ) {
        parent::__construct($view, $data);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $task = $this->find($id, $me);
        if ($task === null) {
            return $this->notFound('할 일이 없거나 볼 수 있는 권한이 없습니다.');
        }

        return $this->detail($request, $task, $me);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $task = $this->find($id, $me);
        if ($task === null) {
            return $this->notFound('할 일이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $values = [
            'service' => trim($request->input('service') ?? ''),
            'item' => trim($request->input('item') ?? ''),
            'task' => trim($request->input('task') ?? ''),
            'note' => trim(str_replace("\r\n", "\n", $request->input('note') ?? '')),
        ];
        $errors = [];
        if ($values['service'] === '' || mb_strlen($values['service']) > 30) {
            $errors['service'] = '서비스를 30자 이내로 입력하세요.';
        }
        if ($values['item'] === '' || mb_strlen($values['item']) > 100) {
            $errors['item'] = '항목을 100자 이내로 입력하세요.';
        }
        if ($values['task'] === '' || mb_strlen($values['task']) > 1000) {
            $errors['task'] = '할 일을 1,000자 이내로 입력하세요.';
        }
        if (mb_strlen($values['note']) > 1000) {
            $errors['note'] = '비고는 1,000자 이내로 입력하세요.';
        }
        if ($errors !== []) {
            return $this->detail($request, $task, $me, $errors, $values);
        }
        $changed = array_keys(array_filter([
            '서비스' => $values['service'] !== $task['service'],
            '항목' => $values['item'] !== $task['item'],
            '할 일' => $values['task'] !== $task['task'],
            '비고' => $values['note'] !== (string) ($task['note'] ?? ''),
        ]));
        $this->tasks->update((int) $task['id'], $values + ['note' => $values['note'] === '' ? null : $values['note']], $me->id, $changed);
        $this->session($request)->set(self::FLASH_KEY, $changed === [] ? '바뀐 내용이 없습니다.' : '할 일을 저장했습니다.');

        return Response::redirect('/meetings/tasks/' . $task['id']);
    }

    /**
     * 표에서 바로 상태 바꾸기
     */
    public function changeStatus(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $task = $this->find($id, $me);
        if ($task === null) {
            return $this->notFound('할 일이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $to = $request->input('status') ?? '';
        if (!isset(MeetingTaskRepository::STATUSES[$to])) {
            return $this->detail($request, $task, $me, ['status' => '상태를 고르세요.']);
        }
        if ($to !== $task['status']) {
            $this->tasks->changeStatus((int) $task['id'], (string) $task['status'], $to, $me->id);
        }

        return Response::redirect($this->back($request, $task));
    }

    /**
     * 표에서 바로 담당 지정 (빈 값이면 직원 지정 해제)
     */
    public function assign(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $task = $this->find($id, $me);
        if ($task === null) {
            return $this->notFound('할 일이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $raw = $request->input('assignee_user_id') ?? '';
        $assignee = ctype_digit($raw) ? $this->users->findById((int) $raw) : null;
        if ($raw !== '' && ($assignee === null || !$assignee->active)) {
            return $this->detail($request, $task, $me, ['assignee' => '담당자를 다시 고르세요.']);
        }
        $current = $task['assignee_user_id'] === null ? null : (int) $task['assignee_user_id'];
        if ($current !== $assignee?->id) {
            $this->tasks->assign((int) $task['id'], $assignee?->id, $task['assignee_name'], $assignee?->name, $me->id);
        }

        return Response::redirect($this->back($request, $task));
    }

    public function addNote(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $task = $this->find($id, $me);
        if ($task === null) {
            return $this->notFound('할 일이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $note = trim($request->input('memo') ?? '');
        if ($note === '' || mb_strlen($note) > 1000) {
            return $this->detail($request, $task, $me, ['memo' => '메모를 1,000자 이내로 입력하세요.']);
        }
        $this->tasks->addNote((int) $task['id'], $me->id, $note);

        return Response::redirect('/meetings/tasks/' . $task['id'] . '#timeline');
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function detail(Request $request, array $task, User $me, array $errors = [], array $old = []): Response
    {
        $events = array_map(fn (array $e): array => ['note_text' => $this->tasks->eventNote($e)] + $e, $this->tasks->events((int) $task['id']));
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return $this->page('meeting-task', [
            'title' => (string) $task['item'],
            'active' => 'meetings',
            'task' => $task,
            'events' => $events,
            'canWrite' => $me->canWriteMeetings(),
            'services' => $this->tasks->services(),
            'staff' => array_values(array_filter($this->users->all(), static fn (User $u): bool => $u->active)),
            'errors' => $errors,
            'old' => $old,
            'flash' => is_string($flash) ? $flash : null,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * 바꾼 뒤 돌아갈 곳: 폼이 보낸 back (같은 사이트 경로만), 없으면 할 일 상세
     *
     * @param array<string, mixed> $task
     */
    private function back(Request $request, array $task): string
    {
        $back = $request->input('back');

        return $back !== null && $back !== '' ? self::safeNext($back) : '/meetings/tasks/' . $task['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id, User $me): ?array
    {
        return ctype_digit($id) ? $this->tasks->findFor((int) $id, $me->id) : null;
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
