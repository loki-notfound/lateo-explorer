<?php
declare(strict_types=1);

/**
 * Renders a button or link styled with .lt-btn.
 * Props: label (string), variant (primary|secondary|quiet|danger), href (string|null),
 *        type (button|submit|reset), name (string|null), value (string|null), disabled (bool), attrs (array).
 */
$props = $props ?? [];
$label = (string) ($props['label'] ?? '');
$allowedVariants = ['primary', 'secondary', 'quiet', 'danger'];
$variant = in_array($props['variant'] ?? '', $allowedVariants, true) ? (string) $props['variant'] : 'primary';
$href = isset($props['href']) && $props['href'] !== '' ? (string) $props['href'] : null;
$allowedTypes = ['button', 'submit', 'reset'];
$type = in_array($props['type'] ?? '', $allowedTypes, true) ? (string) $props['type'] : 'button';
$disabled = !empty($props['disabled']);
$extraAttrs = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];
$class = lt_classes('lt-btn', 'lt-btn-' . $variant, isset($extraAttrs['class']) ? (string) $extraAttrs['class'] : '');
unset($extraAttrs['class']);

if ($href !== null):
    $linkAttrs = array_merge($extraAttrs, [
        'class' => $class,
        'href' => $disabled ? null : $href,
        'aria-disabled' => $disabled ? 'true' : null,
    ]);
?>
<a<?= lt_attrs($linkAttrs) ?>><?= lt_esc($label) ?></a>
<?php else:
    $btnAttrs = array_merge($extraAttrs, [
        'class' => $class,
        'type' => $type,
        'name' => isset($props['name']) ? (string) $props['name'] : null,
        'value' => isset($props['value']) ? (string) $props['value'] : null,
        'disabled' => $disabled,
    ]);
?>
<button<?= lt_attrs($btnAttrs) ?>><?= lt_esc($label) ?></button>
<?php endif; ?>
