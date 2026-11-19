<?php
declare(strict_types=1);

/**
 * Renders a callout box with semantic tone, explicit title, and body paragraphs.
 * Props: tone (info|success|warning|danger), title (string), body (string), paragraphs (array<string>), role (string).
 */
$props = $props ?? [];
$allowedTones = ['info', 'success', 'warning', 'danger'];
$tone = in_array($props['tone'] ?? '', $allowedTones, true) ? (string) $props['tone'] : 'info';
$role = (string) ($props['role'] ?? 'note');
$defaultTitles = [
    'info' => 'Note',
    'success' => 'Success',
    'warning' => 'Warning',
    'danger' => 'Caution',
];
$title = array_key_exists('title', $props) ? (string) $props['title'] : $defaultTitles[$tone];
$paragraphs = is_array($props['paragraphs'] ?? null) ? $props['paragraphs'] : [];
if ($paragraphs === [] && isset($props['body']) && (string) $props['body'] !== '') {
    $paragraphs = [(string) $props['body']];
}
?>
<div class="lt-callout lt-callout-<?= lt_esc($tone) ?>" role="<?= lt_esc($role) ?>">
<?php if ($title !== ''): ?>
  <p class="lt-callout-title"><?= lt_esc($title) ?></p>
<?php endif; ?>
<?php foreach ($paragraphs as $paragraph): ?>
  <p><?= lt_esc((string) $paragraph) ?></p>
<?php endforeach; ?>
</div>
