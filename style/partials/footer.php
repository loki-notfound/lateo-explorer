<?php
declare(strict_types=1);

/**
 * Renders the site footer.
 * Props: lines (array<string>), links (array<array{label:string,href:string}>), text (string), wide (bool).
 */
$props = $props ?? [];
$lines = is_array($props['lines'] ?? null) ? $props['lines'] : [];
$links = is_array($props['links'] ?? null) ? $props['links'] : [];
$text = isset($props['text']) ? (string) $props['text'] : '';
if ($lines === [] && $text !== '') {
    $lines = [$text];
}
$wrapClass = !empty($props['wide']) ? 'lt-wrap lt-wrap-wide' : 'lt-wrap';
?>
<footer class="lt-footer">
  <div class="<?= lt_esc($wrapClass) ?>">
<?php foreach ($lines as $line): ?>
    <p><?= lt_esc((string) $line) ?></p>
<?php endforeach; ?>
<?php if ($links !== []): ?>
    <p class="lt-cluster">
<?php foreach ($links as $link):
    if (!is_array($link)) {
        continue;
    }
    $href = (string) ($link['href'] ?? '#');
    $label = (string) ($link['label'] ?? '');
?>
      <a href="<?= lt_esc($href) ?>"><?= lt_esc($label) ?></a>
<?php endforeach; ?>
    </p>
<?php endif; ?>
  </div>
</footer>
