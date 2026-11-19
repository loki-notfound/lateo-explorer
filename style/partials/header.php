<?php
declare(strict_types=1);

/**
 * Renders the product site header and primary navigation.
 * Props: siteName (string), homeHref (string), nav (array), navLabel (string), currentPath (string), wide (bool).
 */
$props = $props ?? [];
$siteName = (string) ($props['siteName'] ?? 'lateo');
$homeHref = (string) ($props['homeHref'] ?? '/');
$nav = is_array($props['nav'] ?? null) ? $props['nav'] : [];
$navLabel = (string) ($props['navLabel'] ?? 'Site navigation');
$currentPath = (string) ($props['currentPath'] ?? '');
$wrapClass = !empty($props['wide']) ? 'lt-wrap lt-wrap-wide lt-header-inner' : 'lt-wrap lt-header-inner';
?>
<header class="lt-header">
  <div class="<?= lt_esc($wrapClass) ?>">
    <a class="lt-brand" href="<?= lt_esc($homeHref) ?>"><span><?= lt_esc($siteName) ?></span></a>
<?php if ($nav !== []): ?>
    <nav class="lt-nav" aria-label="<?= lt_esc($navLabel) ?>">
<?php foreach ($nav as $item):
    if (!is_array($item)) {
        continue;
    }
    $href = (string) ($item['href'] ?? '#');
    $label = (string) ($item['label'] ?? '');
    $isCurrent = array_key_exists('current', $item)
        ? (bool) $item['current']
        : lt_is_current($href, $currentPath);
?>
      <a href="<?= lt_esc($href) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= lt_esc($label) ?></a>
<?php endforeach; ?>
    </nav>
<?php endif; ?>
  </div>
</header>
