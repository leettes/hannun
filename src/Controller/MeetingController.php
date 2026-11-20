<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\User;
use App\Http\Request;
use App\Http\Response;

/**
 * 회의록. 접근 조건(권한·2단계 인증·유휴 재인증)은 RequireMeetingAccess 미들웨어가 확인한다.
 * 목록·작성·상세는 2단계 (docs/MEETINGS.md 9장).
 */
final class MeetingController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $request->attribute('user');

        return $this->page('meetings', [
            'title' => '회의록',
            'active' => 'meetings',
            'canWrite' => $me instanceof User && $me->canWriteMeetings(),
        ]);
    }
}
