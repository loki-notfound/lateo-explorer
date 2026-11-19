<?php
declare(strict_types=1);

/**
 * Renders the scriptless Lateo family navigation bar from products.json.
 * Props: activeProduct (string), products (array|null), productsPath (string|null).
 */
$props = $props ?? [];
$activeProduct = (string) ($props['activeProduct'] ?? 'home');
$products = is_array($props['products'] ?? null)
    ? $props['products']
    : lt_load_products(isset($props['productsPath']) ? (string) $props['productsPath'] : null);

$home = null;
$links = [];
foreach ($products as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    if ($home === null && (($entry['id'] ?? '') === 'home')) {
        $home = $entry;
    } else {
        $links[] = $entry;
    }
}
if ($home === null && $links !== []) {
    $home = array_shift($links);
}
if ($home !== null):
    $homeId = (string) ($home['id'] ?? 'home');
    $homeUrl = (string) ($home['url'] ?? 'https://lateo.dev/');
    $homeName = (string) ($home['name'] ?? 'lateo');
    $homeCurrent = $activeProduct === $homeId;
?>
<nav class="lt-family-bar" aria-label="Lateo family">
  <div class="lt-family-bar-inner">
    <a class="lt-family-brand" href="<?= lt_esc($homeUrl) ?>" data-product="<?= lt_esc($homeId) ?>"<?= $homeCurrent ? ' aria-current="page"' : '' ?>>
      <svg class="lt-family-mark" viewBox="0 0 64 64" width="16" height="16" fill="none" aria-hidden="true">
        <path d="M 8 24 H 16 V 48 H 40 V 56 H 16 C 11.58 56 8 52.42 8 48 Z" fill="currentColor"/>
        <path d="M 24 8 H 48 C 52.42 8 56 11.58 56 16 V 40 H 48 V 16 H 24 Z" fill="currentColor"/>
        <rect x="24" y="24" width="16" height="16" rx="3" fill="currentColor"/>
      </svg>
      <span><?= lt_esc($homeName) ?></span>
    </a>
    <ul class="lt-family-links">
<?php foreach ($links as $item):
    $id = (string) ($item['id'] ?? '');
    $url = (string) ($item['url'] ?? '');
    $name = (string) ($item['name'] ?? $id);
    $isCurrent = $activeProduct === $id;
?>
      <li><a class="lt-family-link" href="<?= lt_esc($url) ?>" data-product="<?= lt_esc($id) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= lt_esc($name) ?></a></li>
<?php endforeach; ?>
    </ul>
  </div>
</nav>
<?php endif; ?>
