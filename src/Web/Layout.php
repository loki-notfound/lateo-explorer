<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * The frame of every page: the house style's lt_render_page() with this site's stylesheets,
 * navigation and footer, captured into a Response. The house style's files in style/ are
 * copies and stay as they are; what this site adds is only data passed to them.
 *
 * Nothing here starts a session or sets a cookie.
 */
final class Layout
{
    public const SITE_NAME = 'explorer.lateo.dev';
    public const REPOSITORY_URL = 'https://github.com/loki-notfound/lateo-explorer';

    public const INDEPENDENCE = 'Independent open-source project around Zephyr. Not affiliated with ZephyrProtocol.';
    public const SEED_NOTICE = 'Security notice: node.lateo.dev, explorer.lateo.dev, and lateo support will never ask for your '
        . '25-word seed phrase or private keys. Never type or paste your seed phrase into any website, support form, or chat window.';

    /** @var array<string,string> marks of the asset files, by file name */
    private static array $marks = [];

    private readonly string $root;

    /** @param string $root the repository root: style/ and public/assets/ are read from it */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        if (!function_exists('lt_render_page')) {
            require $this->root . '/style/layout.php';
        }
    }

    /**
     * A full page as a response.
     *
     * @param string $path the request path, for the current mark in the navigation
     * @param list<array{label:string,href?:string}> $breadcrumbs
     */
    public function page(
        int $status,
        string $title,
        string $description,
        string $path,
        string $contentHtml,
        string $cacheControl,
        array $breadcrumbs = [],
    ): Response {
        $options = [
            'title' => $title . ' - ' . self::SITE_NAME,
            'description' => $description,
            'stylesheets' => [
                $this->asset('content.css'),
                $this->asset('forms.css'),
                $this->asset('data.css'),
                $this->asset('explorer.css'),
            ],
            'printStylesheet' => $this->asset('print.css'),
            'iconHref' => $this->asset('favicon.svg'),
            'activeProduct' => 'explorer',
            'productsPath' => $this->root . '/style/products.json',
            'siteName' => self::SITE_NAME,
            'homeHref' => '/',
            'nav' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Status', 'href' => '/status'],
                ['label' => 'About', 'href' => '/about'],
            ],
            'navLabel' => 'Explorer',
            'currentPath' => $path,
            'breadcrumbs' => $breadcrumbs,
            'wide' => true,
            'footerLines' => [self::INDEPENDENCE, self::SEED_NOTICE],
            'footerLinks' => [
                ['label' => 'About this explorer', 'href' => '/about'],
                ['label' => 'Source code on GitHub', 'href' => self::REPOSITORY_URL],
            ],
            'content' => $contentHtml,
        ];
        ob_start();
        try {
            \lt_render_page($options);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return Response::html($status, $html, $cacheControl);
    }

    /**
     * The URL of a file under /assets/ with a mark of its content, so that a changed file is
     * fetched anew while an unchanged one stays in caches.
     */
    public function asset(string $file): string
    {
        $url = '/assets/' . $file;
        if (!isset(self::$marks[$this->root . '/' . $file])) {
            $content = @file_get_contents($this->root . '/public/assets/' . $file);
            self::$marks[$this->root . '/' . $file] = $content === false ? '' : substr(hash('sha256', $content), 0, 12);
        }
        $mark = self::$marks[$this->root . '/' . $file];
        return $mark === '' ? $url : $url . '?v=' . $mark;
    }
}
