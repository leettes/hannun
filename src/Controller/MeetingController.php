<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Auth\UserProvider;
use App\Http\Request;
use App\Http\Response;
use App\Meetings\Markdown;
use App\Meetings\MeetingRepository;
use App\Monitoring\MonitoringRepository;
use App\Security\AuditLog;
use App\View;

/**
 * 회의록. 접근 조건(권한·2단계 인증·유휴 재인증)은 RequireMeetingAccess 미들웨어가 먼저 확인한다.
 *
 * - 볼 수 없는 회의는 목록에 나오지 않고, 주소로 열어도 404 (있는지도 알리지 않는다)
 * - 수정·삭제·공개 범위 변경은 작성자 본인만 (관리자도 남의 회의록은 못 고친다)
 * - 공개 범위를 바꿀 때는 비밀번호를 다시 확인한다
 * - 열람·작성·수정·삭제·복구·공개 범위 변경은 감사 로그에 남긴다 (본문은 넣지 않음)
 */
final class MeetingController extends Controller
{
    private const FLASH_KEY = 'meetings_flash';
    private const MAX_TITLE = 200;
    private const MAX_BODY = 100_000;
    private const MAX_DECISIONS = 20_000;

    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly MeetingRepository $meetings,
        private readonly UserProvider $users,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view, $data);
    }

    public function index(Request $request): Response
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

        return $this->page('meetings', [
            'title' => '회의록',
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

        return $this->form(null, [
            'title' => '',
            'held_at' => date('Y-m-d\TH:00'),
            'system_id' => null,
            'visibility' => 'attendees',
            'body' => '',
            'decisions' => '',
            'attendees' => [$me->id],
        ]);
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
            return $this->form(null, $values, $errors);
        }
        $id = $this->meetings->create(self::toStored($values), $me->id);
        $this->audit->recordRequest($request, 'meeting.create', 'meeting', $id, ['visibility' => $values['visibility'], 'attendees' => count($values['attendees'])]);

        return Response::redirect('/meetings/' . $id);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->me($request);
        $meeting = $this->find($id, $me);
        if ($meeting === null) {
            return $this->notFound('회의록이 없거나 볼 수 있는 권한이 없습니다.');
        }
        $this->audit->recordRequest($request, 'meeting.view', 'meeting', (int) $meeting['id']);

        return $this->page('meeting', [
            'title' => $meeting['title'],
            'active' => 'meetings',
            'meeting' => $meeting,
            'bodyHtml' => Markdown::render((string) $meeting['body']),
            'decisionsHtml' => Markdown::render((string) $meeting['decisions']),
            'isAuthor' => $this->canEdit($meeting, $me),
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

        return $this->form($meeting, [
            'title' => (string) $meeting['title'],
            'held_at' => str_replace(' ', 'T', (string) $meeting['held_at']),
            'system_id' => $meeting['system_id'] === null ? null : (int) $meeting['system_id'],
            'visibility' => (string) $meeting['visibility'],
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
        if ($visibilityChanged && !password_verify($request->input('password') ?? '', $me->passwordHash)) {
            $errors['password'] = '공개 범위를 바꾸려면 비밀번호를 입력하세요.';
        }
        if ($errors !== []) {
            return $this->form($meeting, $values, $errors);
        }

        $stored = self::toStored($values);
        $changed = array_keys(array_filter([
            'title' => $stored['title'] !== $meeting['title'],
            'held_at' => $stored['held_at'] !== $meeting['held_at'],
            'system' => $stored['system_id'] !== ($meeting['system_id'] === null ? null : (int) $meeting['system_id']),
            'body' => $stored['body'] !== $meeting['body'],
            'decisions' => $stored['decisions'] !== $meeting['decisions'],
            'attendees' => self::sorted($stored['attendees']) !== self::sorted(array_column($meeting['attendees'], 'id')),
        ]));
        $this->meetings->update((int) $meeting['id'], $stored);
        if ($changed !== []) {
            $this->audit->recordRequest($request, 'meeting.update', 'meeting', (int) $meeting['id'], ['fields' => $changed]);
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
     * @return array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>}
     */
    private function input(Request $request): array
    {
        $system = $request->input('system_id') ?? '';

        return [
            'title' => trim($request->input('title') ?? ''),
            'held_at' => trim($request->input('held_at') ?? ''),
            'system_id' => ctype_digit($system) ? (int) $system : null,
            'visibility' => $request->input('visibility') ?? '',
            'body' => str_replace("\r\n", "\n", $request->input('body') ?? ''),
            'decisions' => str_replace("\r\n", "\n", $request->input('decisions') ?? ''),
            'attendees' => array_values(array_unique(array_map('intval', array_filter($request->inputList('attendees'), 'ctype_digit')))),
        ];
    }

    /**
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
     * @return array<string, string>
     */
    private function validate(array $values): array
    {
        $errors = [];
        if ($values['title'] === '' || mb_strlen($values['title']) > self::MAX_TITLE) {
            $errors['title'] = '제목을 ' . self::MAX_TITLE . '자 이내로 입력하세요.';
        }
        if (self::heldAt($values['held_at']) === null) {
            $errors['held_at'] = '회의 일시를 입력하세요.';
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
        if (mb_strlen($values['body']) > self::MAX_BODY) {
            $errors['body'] = '본문은 ' . number_format(self::MAX_BODY) . '자 이내로 입력하세요.';
        }
        if (mb_strlen($values['decisions']) > self::MAX_DECISIONS) {
            $errors['decisions'] = '결정 사항은 ' . number_format(self::MAX_DECISIONS) . '자 이내로 입력하세요.';
        }

        return $errors;
    }

    /**
     * 폼 값(Y-m-dTH:i)을 저장 형식(Y-m-d H:i)으로
     *
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
     * @return array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>}
     */
    private static function toStored(array $values): array
    {
        return ['held_at' => (string) self::heldAt($values['held_at'])] + $values;
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
     * @param array<string, mixed>|null $meeting 수정 중인 회의록 (새로 쓰면 null)
     * @param array{title: string, held_at: string, system_id: ?int, visibility: string, body: string, decisions: string, attendees: list<int>} $values
     * @param array<string, string> $errors
     */
    private function form(?array $meeting, array $values, array $errors = []): Response
    {
        return $this->page('meeting-form', [
            'title' => $meeting === null ? '회의록 작성' : '회의록 수정',
            'active' => 'meetings',
            'meeting' => $meeting,
            'values' => $values,
            'errors' => $errors,
            'systems' => $this->meetings->systems(),
            'staff' => $this->meetings->staff(),
        ], $errors === [] ? 200 : 422);
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
            'message' => '회의록 수정·삭제·공개 범위 변경은 작성자 본인만 할 수 있습니다 (관리자 포함).',
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
