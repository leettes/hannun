<?php

declare(strict_types=1);

namespace Tests;

use App\App;
use App\Controller\HealthController;
use App\Http\Request;
use App\Router;
use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    public function testHealthReturnsOk(): void
    {
        $router = new Router();
        $router->get('/health', [HealthController::class, 'index']);

        $response = (new App($router))->handle(new Request('GET', '/health'));

        $this->assertSame(200, $response->status);
        $this->assertSame('ok', json_decode($response->body, true)['status']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $response = (new App(new Router()))->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->status);
    }

    public function testRouteParamsArePassedByName(): void
    {
        $router = new Router();
        $router->get('/systems/{code}', fn (Request $request, string $code): array => ['code' => $code]);

        $response = (new App($router))->handle(new Request('GET', '/systems/miri'));

        $this->assertSame(['code' => 'miri'], json_decode($response->body, true));
    }
}
