<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\SmsFailed;
use App\Notifications\SmsSender;

final class FakeSms implements SmsSender
{
    /** @var list<array{to: list<string>, message: string}> */
    public array $sent = [];

    /** 다음 발송을 실패시킬 횟수 */
    public int $failNext = 0;

    public function send(array $to, string $message): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;
            throw new SmsFailed('fake failure');
        }
        $this->sent[] = ['to' => $to, 'message' => $message];
    }
}
