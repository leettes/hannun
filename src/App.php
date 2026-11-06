<?php

declare(strict_types=1);

namespace App;

use App\Auth\User;
use App\Http\Middleware\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;
use Throwable;

final class App
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
            . "frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
    ];

    /**
     * @param list<Middleware> $middleware 순서대로 실행
     * @param (Closure(Request): Response)|null $notFound
     * @param (Closure(Request, Throwable): Response)|null $onError
     * @param (Closure(Request): Response)|null $forbidden 역할이 부족할 때
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $middleware = [],
        private readonly ?Closure $notFound = null,
        private readonly ?Closure $onError = null,
        private readonly ?Closure $forbidden = null,
    ) {
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->pipeline($request, 0);
        } catch (Throwable $e) {
            error_log((string) $e);
            $response = $this->onError !== null
                ? ($this->onError)($request, $e)
                : Response::json(['error' => 'Internal Server Error'], 500);
        }

        return $response->withDefaultHeaders(self::SECURITY_HEADERS);
    }

    private function pipeline(Request $request, int $index): Response
    {
        if (!isset($this->middleware[$index])) {
            return $this->dispatch($request);
        }

        return $this->middleware[$index]->process(
            $request,
            fn (Request $request): Response => $this->pipeline($request, $index + 1),
        );
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);

        if ($match === null) {
            return $this->notFound !== null
                ? ($this->notFound)($request)
                : Response::json(['error' => 'Not Found'], 404);
        }

        [$handler, $params, $role] = $match;

        $user = $request->attribute('user');
        if ($role !== null && !($user instanceof User && $user->can($role))) {
            return $this->forbidden !== null
                ? ($this->forbidden)($request)
                : Response::json(['error' => 'Forbidden'], 403);
        }

        return $this->toResponse($handler($request, ...$params));
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_string($result) => Response::html($result),
            default => Response::json($result),
        };
    }
}
