<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * 모든 템플릿에서 쓸 값 (로그인 사용자, CSRF 토큰 등)
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * 템플릿을 렌더링한 뒤 레이아웃의 $content 로 감싼다.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->partial($template, $data);

        return $this->partial($layout, ['content' => $content] + $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        extract($data + $this->shared, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
