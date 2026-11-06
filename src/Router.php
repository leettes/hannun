<?php

declare(strict_types=1);

namespace App;

use Closure;

final class Router
{
    /** @var list<array{string, string, callable|array{class-string, string}, ?string}> */
    private array $routes = [];

    private Closure $resolver;

    /**
     * @param (Closure(class-string): object)|null $resolver 컨트롤러 인스턴스 생성 방법
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $class): object => new $class();
    }

    public function get(string $path, callable|array $handler, ?string $role = null): void
    {
        $this->add('GET', $path, $handler, $role);
    }

    public function post(string $path, callable|array $handler, ?string $role = null): void
    {
        $this->add('POST', $path, $handler, $role);
    }

    /**
     * 경로의 {name} 부분은 파라미터로 캡처되어 핸들러에 이름 인자로 전달된다.
     * $role 을 주면 그 역할 이상(User::can)인 로그인 사용자만 실행할 수 있다.
     */
    public function add(string $method, string $path, callable|array $handler, ?string $role = null): void
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $this->normalize($path));
        $this->routes[] = [strtoupper($method), '#^' . $pattern . '$#', $handler, $role];
    }

    /**
     * @return array{callable, array<string, string>, ?string}|null 핸들러, 경로 파라미터, 필요한 역할
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = $this->normalize($path);

        foreach ($this->routes as [$routeMethod, $pattern, $handler, $role]) {
            if ($routeMethod !== $method || !preg_match($pattern, $path, $matches)) {
                continue;
            }

            $params = array_map('rawurldecode', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            if (is_array($handler)) {
                [$class, $action] = $handler;
                $handler = [($this->resolver)($class), $action];
            }

            return [$handler, $params, $role];
        }

        return null;
    }

    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
