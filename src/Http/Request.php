<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param string $content 요청 본문 원문 (JSON Webhook 등)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $server = [],
        public readonly string $content = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $_GET,
            $_POST,
            $_SERVER,
            (string) file_get_contents('php://input'),
        );
    }

    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function input(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * 같은 이름으로 여러 값을 보낸 폼 필드 (name="x[]"). 문자열 값만 돌려준다.
     *
     * @return list<string>
     */
    public function inputList(string $key): array
    {
        $value = $this->body[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    public function header(string $name): ?string
    {
        $value = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;

        return is_string($value) ? $value : null;
    }

    public function remoteAddr(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return $https !== '' && strtolower((string) $https) !== 'off';
    }

    /**
     * 경로 + 쿼리 (로그인 후 돌아갈 주소 등)
     */
    public function uri(): string
    {
        return $this->path . ($this->query === [] ? '' : '?' . http_build_query($this->query));
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }
}
