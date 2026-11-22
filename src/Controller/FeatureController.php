<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Features\FeatureRepository;
use App\Http\Request;
use App\Http\Response;
use App\Meetings\MeetingRepository;
use App\Meetings\MeetingTaskRepository;
use App\Monitoring\MonitoringRepository;
use App\Security\AuditLog;
use App\View;

/**
 * 빌드업: 서비스·앱에 들어갈 개발 건을 요청부터 배포까지 추적 (docs/MEETINGS.md 3단계. 코드 이름은 feature).
 *
 * - 목록·상세는 모든 역할, 등록·수정·상태 변경·메모는 운영자 이상 (경로에서 지정)
 * - 회의에서 등록(/meetings/{id}/buildup/…)은 회의록 관문(권한·2단계 인증)을 거친다
 * - 출처 회의 링크는 그 회의를 볼 수 있는 사람에게만. 아니면 '회의에서 등록됨'만 보인다
 */
final class FeatureController extends Controller
{
    private const FLASH_KEY = 'features_flash';
    private const VERSION = '/^[0-9A-Za-z][0-9A-Za-z.+\-]{0,29}$/';

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly FeatureRepository $features,
        private readonly MeetingRepository $meetings,
        private readonly UserProvider $users,
        private readonly AuditLog $audit,
        private readonly MeetingTaskRepository $tasks,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
    {
        $id = static fn (?string $v): ?int => $v !== null && ctype_digit($v) ? (int) $v : null;
        $status = $request->query('status') ?? 'open';
        $filter = [
            'status' => $status === 'all' ? null : ($status === 'open' || isset(FeatureRepository::STATUSES[$status]) ? $status : 'open'),
            'system_id' => $id($request->query('system')),
            'app_id' => $id($request->query('app')),
            'assignee_id' => $id($request->query('assignee')),
        ];
        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $this->features->search($filter, $page);

        return $this->page('features', [
            'title' => '빌드업',
            'active' => 'features',
            'filter' => $filter,
            'features' => $result['rows'],
            'counts' => $result['counts'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / FeatureRepository::PAGE_SIZE)),
            'systems' => $this->features->systems(),
            'apps' => $this->features->apps(),
            'staff' => $this->features->staff(),
            'canEdit' => $this->me($request)->can('operator'),
            'flash' => $this->flash($request),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }

        return $this->detail($request, $feature);
    }

    public function create(Request $request): Response
    {
        $system = $request->query('system');
        $app = $request->query('app');

        return $this->form(null, null, [
            'system_id' => $system !== null && ctype_digit($system) ? (int) $system : null,
            'app_id' => $app !== null && ctype_digit($app) ? (int) $app : null,
            'title' => '',
            'description' => '',
            'target_version' => '',
            'assignee_user_id' => null,
            'status' => 'requested',
        ]);
    }

    public function store(Request $request): Response
    {
        $values = $this->input($request);
        $errors = $this->validate($values, creating: true);
        if ($errors !== []) {
            return $this->form(null, null, $values, $errors);
        }
        $id = $this->features->create(self::stored($values) + ['status' => $values['status']], $this->me($request)->id);

        return Response::redirect('/buildup/' . $id);
    }

    /**
     * 회의 결정 사항에서 등록 (/meetings/{id}/buildup/new?line=N)
     */
    public function createFromMeeting(Request $request, string $id): Response
    {
        $me = $this->me($request);
        if (!$me->can('operator')) {
            return $this->forbidden();
        }
        $meeting = $this->meeting($id, $me);
        if ($meeting === null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        // 회의 할 일에서 올리기 (?task=ID): 이름·내용·서비스를 채운다. 비고는 회의록 밖으로 옮기지 않는다
        $task = $this->meetingTask($request->query('task'), $meeting, $me);
        if ($task === false) {
            return $this->notFound('이 회의의 할 일이 아니거나 이미 빌드업에 올렸습니다.');
        }
        $lines = self::decisionLines((string) $meeting['decisions']);
        $line = $request->query('line');
        $title = $task !== null ? (string) $task['item'] : ($line !== null && ctype_digit($line) ? ($lines[(int) $line] ?? '') : '');
        $system = $task !== null ? $this->systemNamed((string) $task['service']) : null;

        return $this->form(null, $meeting, [
            'system_id' => $system ?? ($meeting['system_id'] === null ? null : (int) $meeting['system_id']),
            'app_id' => null,
            'title' => mb_substr($title, 0, 200),
            'description' => $task !== null ? mb_substr((string) $task['task'], 0, 5000) : '',
            'target_version' => '',
            'assignee_user_id' => $task !== null && $task['assignee_user_id'] !== null ? (int) $task['assignee_user_id'] : null,
            'status' => $task !== null && $task['status'] === 'doing' ? 'in_progress' : 'planned',
        ], task: $task);
    }

    public function storeFromMeeting(Request $request, string $id): Response
    {
        $me = $this->me($request);
        if (!$me->can('operator')) {
            return $this->forbidden();
        }
        $meeting = $this->meeting($id, $me);
        if ($meeting === null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        $task = $this->meetingTask($request->input('meeting_task_id'), $meeting, $me);
        if ($task === false) {
            return $this->notFound('이 회의의 할 일이 아니거나 이미 빌드업에 올렸습니다.');
        }
        $values = $this->input($request);
        $errors = $this->validate($values, creating: true);
        if ($errors !== []) {
            return $this->form(null, $meeting, $values, $errors, $task);
        }
        $featureId = $this->features->create(self::stored($values) + ['status' => $values['status']], $me->id, (int) $meeting['id'], null, $task === null ? null : (int) $task['id']);
        if ($task !== null) {
            $this->tasks->syncFromBuildup((int) $task['id'], FeatureRepository::TASK_STATUS[$values['status']], $me->id);
        }
        $this->session($request)->set(self::FLASH_KEY, $task !== null ? '회의 할 일을 빌드업에 올렸습니다. 이제 상태는 빌드업에서 바꿉니다.' : '회의 결정을 빌드업에 올렸습니다.');

        return Response::redirect('/buildup/' . $featureId);
    }

    public function edit(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }

        return $this->form($feature, null, [
            'system_id' => (int) $feature['system_id'],
            'app_id' => $feature['app_id'] === null ? null : (int) $feature['app_id'],
            'title' => (string) $feature['title'],
            'description' => (string) ($feature['description'] ?? ''),
            'target_version' => (string) ($feature['target_version'] ?? ''),
            'assignee_user_id' => $feature['assignee_user_id'] === null ? null : (int) $feature['assignee_user_id'],
            'status' => (string) $feature['status'],
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }
        $values = $this->input($request);
        $errors = $this->validate($values, creating: false);
        if ($errors !== []) {
            return $this->form($feature, null, $values + ['status' => $feature['status']], $errors);
        }
        $stored = self::stored($values);
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $changed = array_keys(array_filter([
            '서비스' => $stored['system_id'] !== (int) $feature['system_id'],
            '앱' => $stored['app_id'] !== $int($feature['app_id']),
            '이름' => $stored['title'] !== $feature['title'],
            '설명' => $stored['description'] !== $feature['description'],
            '목표 버전' => $stored['target_version'] !== $feature['target_version'],
            '담당자' => $stored['assignee_user_id'] !== $int($feature['assignee_user_id']),
        ]));
        $this->features->update((int) $feature['id'], $stored, $this->me($request)->id, $changed);
        $this->session($request)->set(self::FLASH_KEY, $changed === [] ? '바뀐 내용이 없습니다.' : '빌드업 항목을 저장했습니다.');

        return Response::redirect('/buildup/' . $feature['id']);
    }

    public function changeStatus(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }
        $to = $request->input('status') ?? '';
        $version = trim($request->input('released_version') ?? '');
        $note = trim($request->input('note') ?? '');
        $errors = [];
        if (!FeatureRepository::canMove((string) $feature['status'], $to)) {
            $errors['status'] = FeatureRepository::statusLabel((string) $feature['status']) . '에서 바꿀 수 있는 상태를 고르세요.';
        }
        if ($to === 'released' && preg_match(self::VERSION, $version) !== 1) {
            $errors['released_version'] = '배포한 버전을 입력하세요 (예: 1.4.0).';
        }
        if (mb_strlen($note) > 1000) {
            $errors['note'] = '메모는 1,000자 이내로 입력하세요.';
        }
        if ($errors !== []) {
            return $this->detail($request, $feature, $errors, ['status' => $to, 'released_version' => $version, 'note' => $note]);
        }
        $this->features->changeStatus((int) $feature['id'], (string) $feature['status'], $to, $this->me($request)->id, $to === 'released' ? $version : null, $note === '' ? null : $note);
        if ($feature['meeting_task_id'] !== null) {
            // 회의 할 일에서 올린 항목: 할 일 상태도 따라간다
            $this->tasks->syncFromBuildup((int) $feature['meeting_task_id'], FeatureRepository::TASK_STATUS[$to], $this->me($request)->id);
        }
        $this->session($request)->set(self::FLASH_KEY, '상태를 ' . FeatureRepository::statusLabel($to) . '(으)로 바꿨습니다.');

        return Response::redirect('/buildup/' . $feature['id']);
    }

    public function addNote(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }
        $note = trim($request->input('note') ?? '');
        if ($note === '' || mb_strlen($note) > 1000) {
            return $this->detail($request, $feature, ['memo' => '메모를 1,000자 이내로 입력하세요.'], ['memo' => $note]);
        }
        $this->features->addNote((int) $feature['id'], $this->me($request)->id, $note);

        return Response::redirect('/buildup/' . $feature['id'] . '#timeline');
    }

    /**
     * 삭제 확인 화면
     */
    public function confirmDelete(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }

        return $this->page('feature-delete', [
            'title' => '빌드업 삭제',
            'active' => 'features',
            'feature' => $feature,
            'eventCount' => count($this->features->events((int) $feature['id'])),
        ]);
    }

    /**
     * 삭제 (운영자 이상). 타임라인도 지우므로 감사 로그에 무엇을 지웠는지 남긴다
     */
    public function destroy(Request $request, string $id): Response
    {
        $feature = $this->find($id);
        if ($feature === null) {
            return $this->notFound('등록되지 않은 빌드업 항목입니다.');
        }
        if (($request->input('confirm') ?? '') !== 'delete') {
            return Response::redirect('/buildup/' . $feature['id'] . '/delete');
        }
        $this->features->delete((int) $feature['id']);
        $this->audit->recordRequest($request, 'feature.delete', 'feature', (int) $feature['id'], [
            'title' => (string) $feature['title'],
            'system' => (string) $feature['system_name'],
            'status' => (string) $feature['status'],
            'meeting' => $feature['meeting_id'] === null ? null : (int) $feature['meeting_id'],
        ]);
        $this->session($request)->set(self::FLASH_KEY, "'{$feature['title']}' 을(를) 빌드업에서 삭제했습니다.");

        return Response::redirect('/buildup');
    }

    /**
     * 결정 사항에서 목록 항목만 뽑는다 (- 항목, 1. 항목, - [ ] 할 일)
     *
     * @return list<string>
     */
    public static function decisionLines(string $decisions): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $decisions)) as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?(.+?)\s*$/', $line, $m)) {
                $lines[] = $m[1];
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $feature
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function detail(Request $request, array $feature, array $errors = [], array $old = []): Response
    {
        $me = $this->me($request);
        $meetingId = $feature['meeting_id'] === null ? null : (int) $feature['meeting_id'];

        return $this->page('feature', [
            'title' => $feature['title'],
            'active' => 'features',
            'feature' => $feature,
            'events' => $this->features->events((int) $feature['id']),
            'next' => FeatureRepository::TRANSITIONS[$feature['status']] ?? [],
            'canEdit' => $me->can('operator'),
            // 출처 회의: 볼 수 있는 사람에게만 링크. 제목은 회의록 화면 밖에서 보이지 않게 넣지 않는다
            'meetingLink' => $meetingId !== null && $me->canReadMeetings() && $this->meetings->isVisibleTo($meetingId, $me->id) ? '/meetings/' . $meetingId : null,
            'errors' => $errors,
            'old' => $old,
            'flash' => $this->flash($request),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, mixed>|null $feature
     * @param array<string, mixed>|null $meeting 회의에서 등록할 때
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $feature, ?array $meeting, array $values, array $errors = [], ?array $task = null): Response
    {
        return $this->page('feature-form', [
            'task' => $task === null ? null : ['id' => (int) $task['id'], 'item' => (string) $task['item'], 'service' => (string) $task['service']],
            'title' => $feature === null ? '빌드업 등록' : '빌드업 수정',
            'active' => $meeting === null ? 'features' : 'meetings',
            'feature' => $feature,
            'meeting' => $meeting === null ? null : ['id' => (int) $meeting['id'], 'title' => (string) $meeting['title'], 'held_at' => (string) $meeting['held_at']],
            'decisionLines' => $meeting === null ? [] : self::decisionLines((string) $meeting['decisions']),
            'values' => $values,
            'errors' => $errors,
            'systems' => $this->features->systems(),
            'apps' => $this->features->apps(),
            'staff' => $this->features->staff(),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array{system_id: ?int, app_id: ?int, title: string, description: string, target_version: string, assignee_user_id: ?int, status: string}
     */
    private function input(Request $request): array
    {
        $id = static fn (?string $v): ?int => $v !== null && ctype_digit($v) ? (int) $v : null;

        return [
            'system_id' => $id($request->input('system_id')),
            'app_id' => $id($request->input('app_id')),
            'title' => trim($request->input('title') ?? ''),
            'description' => trim(str_replace("\r\n", "\n", $request->input('description') ?? '')),
            'target_version' => trim($request->input('target_version') ?? ''),
            'assignee_user_id' => $id($request->input('assignee_user_id')),
            'status' => $request->input('status') ?? 'requested',
        ];
    }

    /**
     * @param array{system_id: ?int, app_id: ?int, title: string, description: string, target_version: string, assignee_user_id: ?int, status: string} $values
     * @return array<string, string>
     */
    private function validate(array $values, bool $creating): array
    {
        $errors = [];
        if ($values['system_id'] === null || !in_array($values['system_id'], array_column($this->features->systems(), 'id'), true)) {
            $errors['system_id'] = '서비스를 고르세요.';
        }
        if ($values['app_id'] !== null) {
            $app = array_values(array_filter($this->features->apps(), static fn (array $a): bool => $a['id'] === $values['app_id']))[0] ?? null;
            if ($app === null || $app['system_id'] !== $values['system_id']) {
                $errors['app_id'] = '고른 서비스의 앱을 고르세요.';
            }
        }
        if ($values['title'] === '' || mb_strlen($values['title']) > 200) {
            $errors['title'] = '이름을 200자 이내로 입력하세요.';
        }
        if (mb_strlen($values['description']) > 5000) {
            $errors['description'] = '설명은 5,000자 이내로 입력하세요.';
        }
        if ($values['target_version'] !== '' && preg_match(self::VERSION, $values['target_version']) !== 1) {
            $errors['target_version'] = '버전 형식을 확인하세요 (예: 1.4.0).';
        }
        if ($values['assignee_user_id'] !== null && !in_array($values['assignee_user_id'], array_column($this->features->staff(), 'id'), true)) {
            $errors['assignee_user_id'] = '담당자를 다시 고르세요.';
        }
        if ($creating && !in_array($values['status'], FeatureRepository::INITIAL, true)) {
            $errors['status'] = '처음 상태를 고르세요.';
        }

        return $errors;
    }

    /**
     * @param array{system_id: ?int, app_id: ?int, title: string, description: string, target_version: string, assignee_user_id: ?int, status: string} $values
     * @return array{system_id: int, app_id: ?int, title: string, description: ?string, target_version: ?string, assignee_user_id: ?int}
     */
    private static function stored(array $values): array
    {
        return [
            'system_id' => (int) $values['system_id'],
            'app_id' => $values['app_id'],
            'title' => $values['title'],
            'description' => $values['description'] === '' ? null : $values['description'],
            'target_version' => $values['target_version'] === '' ? null : $values['target_version'],
            'assignee_user_id' => $values['assignee_user_id'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->features->find((int) $id) : null;
    }

    /**
     * @return array<string, mixed>|null 볼 수 있고 지우지 않은 회의
     */
    private function meeting(string $id, User $me): ?array
    {
        $meeting = ctype_digit($id) ? $this->meetings->findFor((int) $id, $me->id) : null;

        return $meeting === null || $meeting['deleted_at'] !== null ? null : $meeting;
    }

    /**
     * 회의 할 일 (올리기 대상). 값이 없으면 null, 이 회의의 볼 수 있는 할 일이 아니거나 이미 올렸으면 false
     *
     * @param array<string, mixed> $meeting
     * @return array<string, mixed>|false|null
     */
    private function meetingTask(?string $id, array $meeting, User $me): array|false|null
    {
        if ($id === null || $id === '') {
            return null;
        }
        $task = ctype_digit($id) ? $this->tasks->findFor((int) $id, $me->id) : null;
        if ($task === null || (int) $task['meeting_id'] !== (int) $meeting['id'] || $task['buildup_id'] !== null) {
            return false;
        }

        return $task;
    }

    /**
     * 할 일의 서비스 이름과 같은 시스템 (코이·미리 등). 마케팅·공통은 없으니 직접 고른다
     */
    private function systemNamed(string $name): ?int
    {
        foreach ($this->features->systems() as $system) {
            if ($system['name'] === $name) {
                return $system['id'];
            }
        }

        return null;
    }

    private function flash(Request $request): ?string
    {
        $session = $this->session($request);
        $flash = $session->get(self::FLASH_KEY);
        $session->remove(self::FLASH_KEY);

        return is_string($flash) ? $flash : null;
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
