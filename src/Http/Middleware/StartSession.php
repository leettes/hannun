<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use Closure;

final class StartSession implements Middleware
{
    /**
     * @param Closure(Request): Session $factory
     * @param list<string> $exemptPaths 세션(쿠키)을 만들지 않는 경로
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly array $exemptPaths = [],
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        if (in_array($request->path, $this->exemptPaths, true)) {
            return $next($request);
        }

        return $next($request->withAttribute('session', ($this->factory)($request)));
    }
}
