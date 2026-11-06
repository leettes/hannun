<?php

declare(strict_types=1);

namespace App\Alerts;

use RuntimeException;

/**
 * 형식이 잘못된 알림 요청 (400 으로 응답)
 */
final class InvalidAlert extends RuntimeException
{
}
