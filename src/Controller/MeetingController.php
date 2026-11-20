<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Features\FeatureRepository;
use App\Http\Request;
use App\Http\Response;
use App\Meetings\MeetingDocument;
use App\Meetings\MeetingImporter;
use App\Meetings\MeetingRepository;
use App\Meetings\MeetingTaskRepository;
use App\Monitoring\MonitoringRepository;
use App\Security\AuditLog;
use App\Security\TwoFactor;
use App\View;

/**
 * 회의록 (시안: __bak/회의/시안-메인·상세.html). 접근 조건(권한·2단계 인증·유휴 재인증)은 RequireMeetingAccess 가 먼저 확인한다.
 *
 * - 볼 수 없는 회의는 목록에 나오지 않고, 주소로 열어도 404 (있는지도 알리지 않는다)
 * - 내용 수정·삭제·공개 범위 변경·참석자 연결은 작성자 본인만 (관리자도 남의 회의록은 못 고친다)
 * - 내보내기·공개 범위 변경·녹취 내려받기·삭제는 6자리 코드 재확인 (TwoFactor::REAUTH_SECONDS 동안 유효)
 * - 열람·작성·수정·삭제·복구·공개 범위 변경·내보내기·내려받기는 감사 로그에 남긴다 (본문은 넣지 않음)
 */
final class MeetingController extends Controller
{
    private const FLASH_KEY = 'meetings_flash';
    private const MAX_TITLE = 200;
    private const MAX_BODY = 100_000;
    private const MAX_DECISIONS = 20_000;
    private const MAX_TRANSCRIPT = 500_000;

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly MeetingRepository $meetings,
        private readonly UserProvider $users,
        private readonly AuditLog $audit,
        private readonly FeatureRepository $features,
        private readonly MeetingTaskRepository $tasks,
        private readonly TwoFactor $twoFactor,
        private readonly int $idleSeconds,
    ) {
        parent::__construct($view, $data);
    }

    /**
     * 회의록 메인: 요약 숫자, 최근 회의, 할 일 표(상태·서비스·검색), 서비스별·담당별
     */
    public function index(Request $request): Response
    {
        $me = $this->me($request);
        $status = $request->query('status');
        $service = trim($request->query('service') ?? '');
        $q = trim($request->query('q') ?? '');
        $filter = [
            'status' => $status !== null && isset(MeetingTaskRepository::STATUSES[$status]) ? $status : null,
            'service' => $service === '' ? null : $service,
            'q' => $q === '' ? null : mb_substr($q, 0, 100),
        ];
        $summary = $this->tasks->summary($me->id);

        return $this->page('meetings', [
            'title' => '회의록',
            'active' => 'meetings',
            'me' => $me,
            'stats' => $this->meetings->stats($me->id, date('Y-m')),
            'recent' => $this->meetings->recent($me->id, 3),
            'summary' => $summary,
            'tasks' => $this->tasks->visibleTo($me->id, $filter),
            'filter' => $filter,
            'serviceTabs' => array_keys($summary['services'] + $this->tasks->usedServices($me->id)),
            'staff' => $this->meetings->staff(),
            'idleSeconds' => $this->idleSeconds,
            'backUrl' => $request->uri(),
            'flash' => $this->flash($request),
        ]);
    }

    /**
     * 전체 회의 목록 (표, 필터)
     */
    public function list(Request $request): Response
    {
        $me = $this->me($request);
        $id = static fn (?string $v): ?int => $v !== null && ctype_digit($v) ? (int) $v : null;
        $from = $request->query('from');
        $to = $request->query('to');
        $q = trim($request->query('q') ?? '');
        $filter = [
            'from' => $from !== null && self::validDate($from) ? $from : null,
            'to' => $to !== null && self::validDate($to) ? $to : null,
            'system_id' => $id($request->query('system')),
            'attendee_id' => $id($request->query('attendee')),
            'author_id' => $id($request->query('author')),
            'q' => $q === '' ? null : mb_substr($q, 0, 100),
        ];
        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $this->meetings->visibleTo($me->id, $filter, $page);

        return $this->page('meetings-list', [
            'title' => '전체 회의 목록',
            'active' => 'meetings',
            'me' => $me,
            'filter' => $filter,
            'meetings' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / MeetingRepository::PAGE_SIZE)),
            'systems' => $this->meetings->systems(),
            'staff' => $this->meetings->staff(),
            'flash' => $this->flash($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }

        return $this->form($request, null, $this->blankValues($me));
    }

    public function store(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $values = $this->input($request);
        $errors = $this->validate($values);
        if ($errors !== []) {
            return $this->form($request, null, $values, $errors);
        }
        $id = $this->meetings->create(self::toStored($values), $me->id);
        $this->audit->recordRequest($request, 'meeting.create', 'meeting', $id, ['visibility' => $values['visibility'], 'attendees' => count($values['attendees'])]);

        return Response::redirect('/meetings/' . $id);
    }

    /**
     * 녹취 텍스트로 작성: 녹취 원문(필수)과 정리본 마크다운(선택)을 붙여 넣는다.
     * 녹취에서 발언자('참석자 N')와 길이를, 정리본에서 제목·날짜·본문·할 일 체크리스트를 읽는다. 외부 요약 서비스는 쓰지 않는다
     */
    public function createFromTranscript(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }

        return $this->transcriptForm(['title' => '', 'held_at' => '', 'visibility' => 'attendees', 'transcript' => '', 'document' => '', 'transcript_name' => '']);
    }

    public function storeFromTranscript(Request $request): Response
    {
        $me = $this->me($request);
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $input = [
            'title' => trim($request->input('title') ?? ''),
            'held_at' => trim($request->input('held_at') ?? ''),
            'visibility' => $request->input('visibility') ?? '',
            'transcript' => str_replace("\r\n", "\n", $request->input('transcript') ?? ''),
            'document' => str_replace("\r\n", "\n", $request->input('document') ?? ''),
            'transcript_name' => trim($request->input('transcript_name') ?? ''),
        ];
        $errors = [];
        $parsed = MeetingRepository::parseTranscript($input['transcript']);
        if (trim($input['transcript']) === '' || mb_strlen($input['transcript']) > self::MAX_TRANSCRIPT) {
            $errors['transcript'] = '녹취 원문을 붙여 넣으세요 (' . number_format(self::MAX_TRANSCRIPT) . '자 이내).';
        } elseif ($parsed['lines'] === []) {
            $errors['transcript'] = "발언 줄을 찾지 못했습니다. '참석자 1 00:05' 처럼 발언자와 시각이 한 줄에 있어야 합니다.";
        }

        // 정리본: 날짜 줄이 없어도 받는다 (일시는 입력값으로)
        $doc = ['title' => '', 'held_at' => null, 'body' => '', 'decisions' => ''];
        if (trim($input['document']) !== '') {
            try {
                $doc = MeetingImporter::parse($input['document'], '');
            } catch (\RuntimeException) {
                $doc = MeetingImporter::parse("1970-01-01\n" . $input['document'], '');
                $doc['held_at'] = null;
            }
        }
        $title = $input['title'] !== '' ? $input['title'] : (string) $doc['title'];
        $heldAt = self::heldAt($input['held_at']) ?? $doc['held_at'];
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = '제목을 입력하세요 (정리본 첫 줄의 # 제목 으로도 됩니다).';
        }
        if ($heldAt === null) {
            $errors['held_at'] = '회의 일시를 입력하세요 (정리본의 날짜 줄로도 됩니다).';
        }
        if (!isset(MeetingRepository::VISIBILITY[$input['visibility']])) {
            $errors['visibility'] = '공개 범위를 고르세요.';
        }
        if ($errors !== []) {
            return $this->transcriptForm($input, $errors);
        }

        $id = $this->meetings->create([
            'title' => $title,
            'held_at' => (string) $heldAt,
            'system_id' => null,
            'visibility' => $input['visibility'],
            'body' => $doc['body'],
            'decisions' => $doc['decisions'],
            'attendees' => [$me->id],
            'summary' => MeetingDocument::leadParagraph($doc['body']),
            'duration_minutes' => $parsed['minutes'],
            'labels' => $parsed['speakers'],
            'transcript' => $input['transcript'],
            'transcript_name' => $input['transcript_name'] !== '' ? mb_substr($input['transcript_name'], 0, 100) : '녹취.txt',
        ], $me->id);
        $this->audit->recordRequest($request, 'meeting.create', 'meeting', $id, ['visibility' => $input['visibility'], 'source' => 'transcript', 'speakers' => count($parsed['speakers'])]);
        $this->session($request)->set(self::FLASH_KEY, '녹취로 회의록을 만들었습니다. 참석자를 직원 계정과 연결하고, 결정 사항을 할 일로 등록하세요.');

        return Response::redirect('/meetings/' . $id);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        $meetingId = (int) $meeting['id'];
        $this->audit->recordRequest($request, 'meeting.view', 'meeting', $meetingId);

        $tab = $request->query('tab') ?? 'doc';
        $tab = in_array($tab, ['doc', 'transcript', 'history'], true) ? $tab : 'doc';
        $tasks = $this->tasks->visibleTo($me->id, ['meeting_id' => $meetingId]);
        $canWrite = $me->canWriteMeetings() && $meeting['deleted_at'] === null;
        $decisionCount = count(MeetingTaskRepository::fromDecisions((string) $meeting['decisions']));

        // 할 일이 있거나 쓸 수 있으면 할 일 블록, 아니면 결정 사항을 그대로
        $taskBlock = $tasks === [] && !$canWrite ? null : $this->view->partial('partials/meeting-task-block', [
            'meeting' => $meeting,
            'tasks' => $tasks,
            'canWrite' => $canWrite,
            'services' => $this->tasks->services(),
            'staff' => $this->meetings->staff(),
            'decisionCount' => $tasks === [] ? $decisionCount : 0,
        ]);

        $transcript = null;
        if ($tab === 'transcript') {
            $text = $this->meetings->transcript($meetingId);
            $transcript = $text === null ? null : MeetingRepository::parseTranscript($text);
        }

        return $this->page('meeting', [
            'title' => $meeting['title'],
            'active' => 'meetings',
            'meeting' => $meeting,
            'tab' => $tab,
            'doc' => MeetingDocument::render((string) $meeting['body'], $taskBlock, (string) $meeting['decisions']),
            'tasks' => $tasks,
            'transcript' => $transcript,
            'trQuery' => trim($request->query('q') ?? ''),
            'trWho' => trim($request->query('who') ?? ''),
            'history' => $this->audit->forTarget('meeting', $meetingId, 100, ['meeting.view']),
            'recentLogs' => $this->audit->forTarget('meeting', $meetingId, 5),
            'isAuthor' => $this->canEdit($meeting, $me),
            'features' => $this->features->forMeeting($meetingId),
            'canAddFeature' => $me->can('operator') && $meeting['deleted_at'] === null,
            'staff' => $this->meetings->staff(),
            'idleSeconds' => $this->idleSeconds,
            'backUrl' => '/meetings/' . $meetingId . '#tasks',
            'confirmDelete' => $request->query('confirm') === 'delete' && $this->canEdit($meeting, $me) && $meeting['deleted_at'] === null
                && $this->twoFactor->recentlyReauthenticated($this->session($request), $me),
            'flash' => $this->flash($request),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$this->canEdit($meeting, $me)) {
            return $this->authorOnly();
        }
        $visibility = $request->query('visibility');

        return $this->form($request, $meeting, [
            'title' => (string) $meeting['title'],
            'subtitle' => (string) ($meeting['subtitle'] ?? ''),
            'held_at' => str_replace(' ', 'T', (string) $meeting['held_at']),
            'duration_minutes' => $meeting['duration_minutes'] === null ? '' : (string) $meeting['duration_minutes'],
            'system_id' => $meeting['system_id'] === null ? null : (int) $meeting['system_id'],
            'visibility' => $visibility !== null && isset(MeetingRepository::VISIBILITY[$visibility]) ? $visibility : (string) $meeting['visibility'],
            'topics' => implode(', ', $meeting['topics']),
            'summary' => (string) $meeting['summary'],
            'body' => (string) $meeting['body'],
            'decisions' => (string) $meeting['decisions'],
            'attendees' => array_column($meeting['attendees'], 'id'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$this->canEdit($meeting, $me)) {
            return $this->authorOnly();
        }
        $values = $this->input($request);
        $errors = $this->validate($values);
        $visibilityChanged = $values['visibility'] !== $meeting['visibility'];
        if ($visibilityChanged && !$this->twoFactor->recentlyReauthenticated($this->session($request), $me)) {
            $errors['visibility'] = '공개 범위를 바꾸려면 먼저 본인 확인(인증 앱 코드)을 하세요.';
        }
        if ($errors !== []) {
            return $this->form($request, $meeting, $values, $errors);
        }

        $stored = self::toStored($values);
        $changed = array_keys(array_filter([
            'title' => $stored['title'] !== $meeting['title'],
            'subtitle' => (string) $stored['subtitle'] !== (string) ($meeting['subtitle'] ?? ''),
            'held_at' => $stored['held_at'] !== $meeting['held_at'],
            'duration' => $stored['duration_minutes'] !== ($meeting['duration_minutes'] === null ? null : (int) $meeting['duration_minutes']),
            'system' => $stored['system_id'] !== ($meeting['system_id'] === null ? null : (int) $meeting['system_id']),
            'topics' => $stored['topics'] !== $meeting['topics'],
            'summary' => trim($stored['summary']) !== trim((string) $meeting['summary']),
            'body' => $stored['body'] !== $meeting['body'],
            'decisions' => $stored['decisions'] !== $meeting['decisions'],
            'attendees' => self::sorted($stored['attendees']) !== self::sorted(array_column($meeting['attendees'], 'id')),
        ]));
        $contentChanged = array_intersect($changed, ['body', 'decisions', 'summary']) !== [];
        $this->meetings->update((int) $meeting['id'], $stored, $contentChanged);
        if ($changed !== []) {
            $this->audit->recordRequest($request, 'meeting.update', 'meeting', (int) $meeting['id'], ['fields' => $changed] + ($contentChanged ? ['version' => (int) $meeting['version'] + 1] : []));
        }
        if ($visibilityChanged) {
            $this->audit->recordRequest($request, 'meeting.visibility', 'meeting', (int) $meeting['id'], ['from' => $meeting['visibility'], 'to' => $values['visibility']]);
        }
        $this->session($request)->set(self::FLASH_KEY, '회의록을 저장했습니다.');

        return Response::redirect('/meetings/' . $meeting['id']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$this->canEdit($meeting, $me)) {
            return $this->authorOnly();
        }
        if (!$this->twoFactor->recentlyReauthenticated($this->session($request), $me)) {
            return $this->reauthRedirect('/meetings/' . $meeting['id'] . '?confirm=delete', '휴지통으로 이동');
        }
        $this->meetings->trash((int) $meeting['id']);
        $this->audit->recordRequest($request, 'meeting.delete', 'meeting', (int) $meeting['id']);
        $this->session($request)->set(self::FLASH_KEY, "'{$meeting['title']}' 회의록을 휴지통으로 옮겼습니다.");

        return Response::redirect('/meetings');
    }

    public function trash(Request $request): Response
    {
        $me = $this->me($request);

        return $this->page('meetings-trash', [
            'title' => '회의록 휴지통',
            'active' => 'meetings',
            'meetings' => $this->meetings->trashOf($me->id),
            'flash' => $this->flash($request),
        ]);
    }

    public function restore(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] === null || (int) $meeting['author_user_id'] !== $me->id) {
            return $this->notFound('휴지통에 없는 회의록입니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->authorOnly();
        }
        $this->meetings->restore((int) $meeting['id']);
        $this->audit->recordRequest($request, 'meeting.restore', 'meeting', (int) $meeting['id']);
        $this->session($request)->set(self::FLASH_KEY, '회의록을 되살렸습니다.');

        return Response::redirect('/meetings/' . $meeting['id']);
    }

    /**
     * 마크다운으로 내보내기 (재확인 후)
     */
    public function export(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$this->twoFactor->recentlyReauthenticated($this->session($request), $me)) {
            return $this->reauthRedirect('/meetings/' . $meeting['id'] . '/export', '내보내기 (Markdown)');
        }
        $this->audit->recordRequest($request, 'meeting.export', 'meeting', (int) $meeting['id'], ['format' => 'markdown']);

        return self::download(self::toMarkdown($meeting, $this->tasks->visibleTo($me->id, ['meeting_id' => (int) $meeting['id']])), 'meeting-' . $meeting['id'] . '.md', 'text/markdown');
    }

    /**
     * 녹취 원문 내려받기 (재확인 후)
     */
    public function transcript(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        $text = $meeting === null ? null : $this->meetings->transcript((int) $meeting['id']);
        if ($meeting === null || $text === null) {
            return $this->notFound('녹취 원문이 없습니다.');
        }
        if (!$this->twoFactor->recentlyReauthenticated($this->session($request), $me)) {
            return $this->reauthRedirect('/meetings/' . $meeting['id'] . '/transcript', '녹취 원문 내려받기');
        }
        $this->audit->recordRequest($request, 'meeting.transcript', 'meeting', (int) $meeting['id']);

        return self::download($text, (string) ($meeting['transcript_name'] ?: 'transcript.txt'), 'text/plain');
    }

    /**
     * 참석자 라벨('참석자 3')을 직원 계정과 연결 (작성자만. 공개 범위 '참석자만' 에 영향)
     */
    public function linkParticipant(Request $request, string $id, string $participant): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$this->canEdit($meeting, $me)) {
            return $this->authorOnly();
        }
        $user = $request->input('user_id') ?? '';
        $userId = ctype_digit($user) ? (int) $user : null;
        if ($userId !== null && !in_array($userId, array_column($this->meetings->staff(), 'id'), true)) {
            return $this->notFound('사용 중인 계정이 아닙니다.');
        }
        if (!ctype_digit($participant) || !$this->meetings->linkParticipant((int) $meeting['id'], (int) $participant, $userId)) {
            return $this->notFound('참석자를 찾을 수 없습니다.');
        }
        $this->audit->recordRequest($request, 'meeting.participant', 'meeting', (int) $meeting['id'], ['participant' => (int) $participant, 'user' => $userId]);

        return Response::redirect('/meetings/' . $meeting['id'] . '#attendees');
    }

    /**
     * 결정 사항 체크리스트를 할 일로 등록
     */
    public function registerTasks(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        if ($this->tasks->visibleTo($me->id, ['meeting_id' => (int) $meeting['id']]) !== []) {
            return Response::redirect('/meetings/' . $meeting['id'] . '#tasks'); // 이미 등록함 (두 번 누름)
        }
        $service = mb_substr(trim($request->input('service') ?? '') ?: '공통·신규', 0, 30);
        $items = MeetingTaskRepository::fromDecisions((string) $meeting['decisions']);
        foreach ($items as $item) {
            $this->tasks->create((int) $meeting['id'], $item + ['service' => $service], $me->id);
        }
        if ($items !== []) {
            $this->audit->recordRequest($request, 'meeting.tasks', 'meeting', (int) $meeting['id'], ['count' => count($items), 'source' => 'decisions']);
        }
        $this->session($request)->set(self::FLASH_KEY, count($items) . '건을 할 일로 등록했습니다. 서비스와 담당을 확인하세요.');

        return Response::redirect('/meetings/' . $meeting['id'] . '#tasks');
    }

    /**
     * 회의 상세의 '할 일 추가' 한 줄 입력 ('항목: 할 일 (참석자 N)' 형식도 받는다)
     */
    public function addTask(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null || $meeting['deleted_at'] !== null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        if (!$me->canWriteMeetings()) {
            return $this->forbidden();
        }
        $text = trim($request->input('task') ?? '');
        if ($text === '' || mb_strlen($text) > 500) {
            $this->session($request)->set(self::FLASH_KEY, '할 일을 500자 이내로 입력하세요.');

            return Response::redirect('/meetings/' . $meeting['id'] . '#tasks');
        }
        $service = mb_substr(trim($request->input('service') ?? '') ?: '공통·신규', 0, 30);
        $taskId = $this->tasks->create((int) $meeting['id'], MeetingTaskRepository::fromDecisions('- ' . $text)[0] + ['service' => $service], $me->id);
        $this->audit->recordRequest($request, 'meeting.tasks', 'meeting', (int) $meeting['id'], ['count' => 1, 'task' => $taskId]);

        return Response::redirect('/meetings/' . $meeting['id'] . '#tasks');
    }

    /**
     * 본인 확인 (6자리 코드). 확인되면 원래 하려던 화면으로 돌아간다
     */
    public function reauthForm(Request $request): Response
    {
        return $this->reauthPage($request->query('next'), $request->query('action'));
    }

    public function reauth(Request $request): Response
    {
        $me = $this->me($request);
        $next = $request->input('next');
        $action = $request->input('action');

        return match ($this->twoFactor->reauthenticate($request, $this->session($request), $me, $request->input('code') ?? '')) {
            TwoFactor::OK => Response::redirect(self::safeNext($next ?: '/meetings')),
            TwoFactor::LOCKED => $this->reauthPage($next, $action, '실패가 많아 ' . intdiv(TwoFactor::LOCK_SECONDS, 60) . '분 동안 잠겼습니다.', 429),
            default => $this->reauthPage($next, $action, '코드가 맞지 않습니다.', 422),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function blankValues(User $me): array
    {
        return [
            'title' => '', 'subtitle' => '', 'held_at' => date('Y-m-d\TH:00'), 'duration_minutes' => '',
            'system_id' => null, 'visibility' => 'attendees', 'topics' => '', 'summary' => '',
            'body' => '', 'decisions' => '', 'attendees' => [$me->id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $system = $request->input('system_id') ?? '';

        return [
            'title' => trim($request->input('title') ?? ''),
            'subtitle' => trim($request->input('subtitle') ?? ''),
            'held_at' => trim($request->input('held_at') ?? ''),
            'duration_minutes' => trim($request->input('duration_minutes') ?? ''),
            'system_id' => ctype_digit($system) ? (int) $system : null,
            'visibility' => $request->input('visibility') ?? '',
            'topics' => trim($request->input('topics') ?? ''),
            'summary' => trim(str_replace("\r\n", "\n", $request->input('summary') ?? '')),
            'body' => str_replace("\r\n", "\n", $request->input('body') ?? ''),
            'decisions' => str_replace("\r\n", "\n", $request->input('decisions') ?? ''),
            'attendees' => array_values(array_unique(array_map('intval', array_filter($request->inputList('attendees'), 'ctype_digit')))),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validate(array $values): array
    {
        $errors = [];
        if ($values['title'] === '' || mb_strlen($values['title']) > self::MAX_TITLE) {
            $errors['title'] = '제목을 ' . self::MAX_TITLE . '자 이내로 입력하세요.';
        }
        if (mb_strlen($values['subtitle']) > self::MAX_TITLE) {
            $errors['subtitle'] = '주제는 ' . self::MAX_TITLE . '자 이내로 입력하세요.';
        }
        if (self::heldAt($values['held_at']) === null) {
            $errors['held_at'] = '회의 일시를 입력하세요.';
        }
        if ($values['duration_minutes'] !== '' && (!ctype_digit($values['duration_minutes']) || (int) $values['duration_minutes'] < 1 || (int) $values['duration_minutes'] > 1440)) {
            $errors['duration_minutes'] = '회의 시간은 분 단위 숫자로 입력하세요.';
        }
        if ($values['system_id'] !== null && !in_array($values['system_id'], array_column($this->meetings->systems(), 'id'), true)) {
            $errors['system_id'] = '서비스를 다시 고르세요.';
        }
        if (!isset(MeetingRepository::VISIBILITY[$values['visibility']])) {
            $errors['visibility'] = '공개 범위를 고르세요.';
        }
        if (array_diff($values['attendees'], array_column($this->meetings->staff(), 'id')) !== []) {
            $errors['attendees'] = '참석자를 다시 고르세요.';
        }
        if (mb_strlen($values['topics']) > 300) {
            $errors['topics'] = '태그는 300자 이내로 입력하세요.';
        }
        if (mb_strlen($values['summary']) > 2000) {
            $errors['summary'] = '요약은 2,000자 이내로 입력하세요.';
        }
        if (mb_strlen($values['body']) > self::MAX_BODY) {
            $errors['body'] = '본문은 ' . number_format(self::MAX_BODY) . '자 이내로 입력하세요.';
        }
        if (mb_strlen($values['decisions']) > self::MAX_DECISIONS) {
            $errors['decisions'] = '결정 사항은 ' . number_format(self::MAX_DECISIONS) . '자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * 폼 값을 저장 형식으로 (일시 Y-m-d H:i, 태그 목록, 요약이 비면 본문 첫 문단)
     *
     * @param array<string, mixed> $values
     * @return array{title: string, subtitle: ?string, held_at: string, duration_minutes: ?int, system_id: ?int, visibility: string, topics: list<string>, summary: string, body: string, decisions: string, attendees: list<int>}
     */
    private static function toStored(array $values): array
    {
        return [
            'title' => $values['title'],
            'subtitle' => $values['subtitle'] === '' ? null : $values['subtitle'],
            'held_at' => (string) self::heldAt($values['held_at']),
            'duration_minutes' => $values['duration_minutes'] === '' ? null : (int) $values['duration_minutes'],
            'system_id' => $values['system_id'],
            'visibility' => $values['visibility'],
            'topics' => array_values(array_unique(array_filter(array_map('trim', preg_split('/,/u', $values['topics']) ?: []), static fn (string $t): bool => $t !== ''))),
            'summary' => $values['summary'] !== '' ? $values['summary'] : MeetingDocument::leadParagraph($values['body']),
            'body' => $values['body'],
            'decisions' => $values['decisions'],
            'attendees' => $values['attendees'],
        ];
    }

    private static function heldAt(string $value): ?string
    {
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(str_replace('!', '', $format)) === $value) {
                return $date->format('Y-m-d H:i');
            }
        }

        return null;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $meeting
     * @param list<array<string, mixed>> $tasks
     */
    private static function toMarkdown(array $meeting, array $tasks): string
    {
        $lines = ['# ' . $meeting['title'] . ($meeting['subtitle'] ? ' · ' . $meeting['subtitle'] : ''), '', (string) $meeting['held_at'], '', trim((string) $meeting['body']), '', '## 확인 / 할 일', ''];
        if ($tasks === []) {
            $lines[] = trim((string) $meeting['decisions']);
        }
        foreach ($tasks as $t) {
            $lines[] = sprintf('- [%s] %s: %s%s (%s · %s)', $t['status'] === 'done' ? 'x' : ' ', $t['item'], $t['task'], $t['assignee_name'] !== null ? ' — ' . $t['assignee_name'] : '', $t['service'], MeetingTaskRepository::STATUSES[$t['status']]);
        }

        return implode("\n", $lines) . "\n";
    }

    private static function download(string $content, string $filename, string $type): Response
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';

        return new Response($content, 200, [
            'Content-Type' => $type . '; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
        ]);
    }

    /**
     * @param array<string, mixed>|null $meeting
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(Request $request, ?array $meeting, array $values, array $errors = []): Response
    {
        $me = $this->me($request);

        return $this->page('meeting-form', [
            'title' => $meeting === null ? '회의록 작성' : '회의록 수정',
            'active' => 'meetings',
            'meeting' => $meeting,
            'values' => $values,
            'errors' => $errors,
            'systems' => $this->meetings->systems(),
            'staff' => $this->meetings->staff(),
            'reauthed' => $this->twoFactor->recentlyReauthenticated($this->session($request), $me),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function transcriptForm(array $values, array $errors = []): Response
    {
        return $this->page('meeting-transcript-form', [
            'title' => '녹취 텍스트로 작성',
            'active' => 'meetings',
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function reauthPage(?string $next, ?string $action, ?string $error = null, int $status = 200): Response
    {
        return $this->page('meeting-reauth', [
            'title' => '본인 확인',
            'active' => 'meetings',
            'next' => self::safeNext($next ?: '/meetings'),
            'action' => mb_substr((string) ($action ?: '민감한 작업'), 0, 50),
            'error' => $error,
        ], $status);
    }

    private function reauthRedirect(string $next, string $action): Response
    {
        return Response::redirect('/meetings/reauth?' . http_build_query(['next' => $next, 'action' => $action]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id, User $me): ?array
    {
        return ctype_digit($id) ? $this->meetings->findFor((int) $id, $me->id) : null;
    }

    /**
     * @param array<string, mixed> $meeting
     */
    private function canEdit(array $meeting, User $me): bool
    {
        return (int) $meeting['author_user_id'] === $me->id && $me->canWriteMeetings();
    }

    private function authorOnly(): Response
    {
        return $this->page('error', [
            'title' => '권한 없음',
            'heading' => '작성자만 바꿀 수 있습니다',
            'message' => '회의록 수정·삭제·공개 범위 변경·참석자 연결은 작성자 본인만 할 수 있습니다 (관리자 포함).',
        ], 403);
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
