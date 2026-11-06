<?php

declare(strict_types=1);

namespace App\Controller;

final class HealthController
{
    /**
     * @return array<string, string>
     */
    public function index(): array
    {
        return [
            'status' => 'ok',
            'time' => date(DATE_ATOM),
        ];
    }
}
