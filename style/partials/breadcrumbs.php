<?php
declare(strict_types=1);

/**
 * Renders a breadcrumb trail.
 * Props: items (array<array{label:string,href?:string,current?:bool}>), label (string).
 */
$props = $props ?? [];
$items = is_array($props['items'] ?? null) ? $props['items'] : [];
$label = (string) ($props['label'] ?? 'Breadcrumb');
$count = count($items);
if ($count > 0):
?>
<nav class="lt-breadcrumbs" aria-label="<?= lt_esc($label) ?>">
  <ol>
<?php foreach ($items as $idx => $item):
    if (!is_array($item)) {
        continue;
    }
    $itemLabel = (string) ($item['label'] ?? '');
    $href = isset($item['href']) ? (string) $item['href'] : '';
    $isCurrent = (bool) ($item['current'] ?? ($idx === $count - 1));
?>
    <li><?php if ($href !== ''): ?><a href="<?= lt_esc($href) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= lt_esc($itemLabel) ?></a><?php else: ?><span<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= lt_esc($itemLabel) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ol>
</nav>
<?php endif; ?>
