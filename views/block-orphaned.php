<?php

declare(strict_types=1);

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Html;

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
?>
<section class="lt-stack">
  <h1>Orphaned block at height <span class="lt-num"><?= lt_esc(Format::int($v['height'])) ?></span></h1>
  <p><?= Html::badge('offline', 'Orphaned') ?></p>
  <p>This block was on the chain the indexer followed. A reorganisation replaced it. It is not on the canonical chain, and its transactions are not confirmed by it.</p>
  <dl class="lt-kv">
    <div><dt>Height</dt><dd><?= Html::num($v['height']) ?></dd></div>
    <div><dt>State</dt><dd>Orphaned</dd></div>
    <div><dt>Hash</dt><dd><?= Html::hash($v['hash']) ?></dd></div>
    <div><dt>Replaced, seen by the indexer at</dt><dd><?= Html::time($v['detectedAt'], $v['now']) ?></dd></div>
  </dl>
<?php if ($v['canonicalHeight'] !== null): ?>
  <p>The canonical block at this height is block <?= Html::blockLink($v['canonicalHeight']) ?>.</p>
<?php endif; ?>
  <?= $view->render('search-form', ['q' => '']) ?>
</section>
