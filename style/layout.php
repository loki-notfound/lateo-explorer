<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Renders a complete Lateo HTML5 page using the shared Tier 2 partials.
 *
 * @param array{
 *   title: string,
 *   description?: string,
 *   lang?: string,
 *   stylesheets?: array<int, string>,
 *   printStylesheet?: string,
 *   iconHref?: string,
 *   activeProduct?: string,
 *   productsPath?: string,
 *   siteName?: string,
 *   homeHref?: string,
 *   nav?: array<int, array{label: string, href: string, current?: bool}>,
 *   navLabel?: string,
 *   currentPath?: string,
 *   breadcrumbs?: array<int, array{label: string, href?: string, current?: bool}>,
 *   flash?: array{tone: string, message: string}|null,
 *   wide?: bool,
 *   footerLines?: array<int, string>,
 *   footerLinks?: array<int, array{label: string, href: string}>,
 *   content: string|callable
 * } $options
 */
function lt_render_page(array $options): void
{
    $lang = (string) ($options['lang'] ?? 'en');
    $title = (string) ($options['title'] ?? 'lateo');
    $description = isset($options['description']) ? (string) $options['description'] : '';
    $stylesheets = is_array($options['stylesheets'] ?? null) ? $options['stylesheets'] : [];
    $printStylesheet = isset($options['printStylesheet']) ? (string) $options['printStylesheet'] : '';
    $iconHref = isset($options['iconHref']) ? (string) $options['iconHref'] : '';
    $activeProduct = (string) ($options['activeProduct'] ?? 'node');
    $productsPath = isset($options['productsPath']) ? (string) $options['productsPath'] : null;
    $siteName = (string) ($options['siteName'] ?? 'lateo');
    $homeHref = (string) ($options['homeHref'] ?? '/');
    $nav = is_array($options['nav'] ?? null) ? $options['nav'] : [];
    $navLabel = (string) ($options['navLabel'] ?? 'Site navigation');
    $currentPath = (string) ($options['currentPath'] ?? '');
    $breadcrumbs = is_array($options['breadcrumbs'] ?? null) ? $options['breadcrumbs'] : [];
    $flash = is_array($options['flash'] ?? null) ? $options['flash'] : null;
    $wide = (bool) ($options['wide'] ?? false);
    $footerLines = is_array($options['footerLines'] ?? null) ? $options['footerLines'] : [];
    $footerLinks = is_array($options['footerLinks'] ?? null) ? $options['footerLinks'] : [];

    $content = $options['content'] ?? '';
    if (is_callable($content)) {
        ob_start();
        $content();
        $bodyHtml = (string) ob_get_clean();
    } else {
        $bodyHtml = (string) $content;
    }

    $mainClass = $wide ? 'lt-wrap lt-wrap-wide lt-stack' : 'lt-wrap lt-stack';
    ?>
<!doctype html>
<html lang="<?= lt_esc($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= lt_esc($title) ?></title>
<?php if ($description !== ''): ?>
<meta name="description" content="<?= lt_esc($description) ?>">
<?php endif; ?>
<?php if ($iconHref !== ''): ?>
<link rel="icon" href="<?= lt_esc($iconHref) ?>" type="image/svg+xml">
<?php endif; ?>
<?php foreach ($stylesheets as $href): ?>
<link rel="stylesheet" href="<?= lt_esc((string) $href) ?>">
<?php endforeach; ?>
<?php if ($printStylesheet !== ''): ?>
<link rel="stylesheet" href="<?= lt_esc($printStylesheet) ?>" media="print">
<?php endif; ?>
</head>
<body>
<a class="lt-skip-link" href="#main">Skip to main content</a>
<?= lt_partial('family-bar', [
    'activeProduct' => $activeProduct,
    'productsPath' => $productsPath,
]) ?>
<?= lt_partial('header', [
    'siteName' => $siteName,
    'homeHref' => $homeHref,
    'nav' => $nav,
    'navLabel' => $navLabel,
    'currentPath' => $currentPath,
    'wide' => $wide,
]) ?>
<main id="main" class="<?= lt_esc($mainClass) ?>" tabindex="-1">
<?php if ($breadcrumbs !== []): ?>
<?= lt_partial('breadcrumbs', ['items' => $breadcrumbs]) ?>
<?php endif; ?>
<?php if ($flash !== null && ($flash['message'] ?? '') !== ''): ?>
<?= lt_partial('flash', $flash) ?>
<?php endif; ?>
<?php echo $bodyHtml; ?>
</main>
<?= lt_partial('footer', [
    'lines' => $footerLines,
    'links' => $footerLinks,
    'wide' => $wide,
]) ?>
</body>
</html>
<?php
}
