<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * Renders the plain PHP views of views/. A view gets its data as $v and the renderer as $view
 * (to include another view). It prints through lt_esc(), Html or lt_partial() only.
 */
final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string,mixed> $v */
    public function render(string $name, array $v = []): string
    {
        if (preg_match('/\A[a-z0-9-]{1,40}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('bad view name');
        }
        $file = rtrim($this->dir, '/') . '/' . $name . '.php';
        $run = static function (string $__file, array $v, self $view): void {
            require $__file;
        };
        ob_start();
        try {
            $run($file, $v, $this);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
