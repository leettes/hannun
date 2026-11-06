<?php

declare(strict_types=1);

namespace App\Monitoring;

use RuntimeException;

/**
 * 장애 수정 요청을 반영할 수 없음. 메시지는 화면에 그대로 보여준다.
 * $conflict: 다른 사용자가 먼저 바꿔서 화면의 값이 오래된 경우
 */
final class IncidentUpdateRejected extends RuntimeException
{
    public function __construct(string $message, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }
}
