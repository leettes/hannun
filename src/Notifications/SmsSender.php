<?php

declare(strict_types=1);

namespace App\Notifications;

interface SmsSender
{
    /**
     * @param list<string> $to 숫자만 있는 휴대폰 번호
     * @throws SmsFailed
     */
    public function send(array $to, string $message): void;
}
