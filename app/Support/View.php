<?php

declare(strict_types=1);

namespace CloudPortal\Support;

final class View
{
    /** @param array<string,mixed> $shared */
    public function __construct(private readonly string $basePath, private readonly array $shared = [])
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $data = [...$this->shared, ...$data];
        $content = $this->renderFile($view, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, [...$data, 'content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $view, array $data): string
    {
        $path = $this->basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($path) || !str_starts_with(realpath($path) ?: '', realpath($this->basePath) ?: '')) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
