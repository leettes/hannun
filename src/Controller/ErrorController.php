<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;

final class ErrorController extends Controller
{
    public function notFoundPage(): Response
    {
        return $this->notFound();
    }

    public function forbiddenPage(): Response
    {
        return $this->forbidden();
    }

    /**
     * 회의록 권한 없음. 회의록은 역할과 별개로 관리자가 사용자마다 켠다
     */
    public function meetingForbiddenPage(): Response
    {
        return $this->page('error', [
            'title' => '권한 없음',
            'heading' => '회의록 권한이 없습니다',
            'message' => '회의록은 관리자가 사용자 관리에서 권한을 켠 직원만 볼 수 있습니다.',
        ], 403);
    }
}
