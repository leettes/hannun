<?php

declare(strict_types=1);

namespace Tests;

use App\Monitoring\Status;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class StatusTest extends TestCase
{
    public function testUnknownNeverOutranksOtherStatuses(): void
    {
        $this->assertSame(Status::UP, Status::worst([Status::UP, Status::UNKNOWN]), '리소스 없는 그룹이 정상을 덮지 않는다');
        $this->assertSame(Status::WARNING, Status::worst([Status::UNKNOWN, Status::WARNING, Status::UP]));
        $this->assertSame(Status::DOWN, Status::worst([Status::UNKNOWN, Status::DOWN]));
        $this->assertSame(Status::UNKNOWN, Status::worst([Status::UNKNOWN]));
        $this->assertSame(Status::UNKNOWN, Status::worst([]));
    }

    public function testUnknownIsNotShown(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $client->pdo()->exec("UPDATE resources SET current_status = 'unknown'");

        foreach (['/', '/systems/miri', '/settings/systems'] as $uri) {
            $this->assertStringNotContainsString('알 수 없음', $client->get($uri)->body, $uri);
        }
    }
}
