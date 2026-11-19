<?php
declare(strict_types=1);

/**
 * Renders a non-colour-alone status indicator badge.
 * Props: state (online|degraded|offline|unknown), label (string).
 */
$props = $props ?? [];
$allowed = ['online', 'degraded', 'offline', 'unknown'];
$state = in_array($props['state'] ?? '', $allowed, true) ? (string) $props['state'] : 'unknown';
$label = isset($props['label']) && $props['label'] !== '' ? (string) $props['label'] : ucfirst($state);
?>
<span class="lt-status lt-status-<?= lt_esc($state) ?>"><?= lt_esc($label) ?></span>
