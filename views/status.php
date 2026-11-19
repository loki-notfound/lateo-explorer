<?php

declare(strict_types=1);

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Html;

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
$now = $v['now'];
?>
<section class="lt-stack">
  <h1>Status</h1>
  <p class="lead">Which nodes the explorer reads, whether they agree with the index, and what the indexer did last.</p>
</section>

<section class="lt-stack" aria-labelledby="nodes-title">
  <h2 id="nodes-title">Nodes</h2>
<?php if ($v['nodesMessage'] !== ''): ?>
  <p><?= lt_esc($v['nodesMessage']) ?>. The state of the nodes is read live and could not be loaded now. Reload the page in a minute.</p>
<?php else: ?>
  <div class="lt-table-scroll">
    <table class="lt-table">
      <caption>The top block of each node against the index<?= lt_esc($v['nodesStale'] ? ' (last known values)' : '') ?></caption>
      <thead>
        <tr>
          <th scope="col">Node</th>
          <th scope="col">State</th>
          <th scope="col" class="lt-num">Height</th>
          <th scope="col">Top block against the index</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($v['nodes'] as $n): ?>
        <tr>
          <th scope="row"><?= lt_esc($n['name']) ?></th>
<?php if ($n['reachable']): ?>
          <td><?= Html::badge('online', 'Reachable') ?></td>
          <td class="lt-num"><?= lt_esc(Format::int((int) $n['height'])) ?></td>
          <td><?= Html::badge($n['match']['state'], $n['match']['label']) ?></td>
<?php else: ?>
          <td><?= Html::badge('offline', 'Node not reachable') ?></td>
          <td class="lt-num">-</td>
          <td><?= lt_esc($n['skipped'] ? 'Not asked again for a short time after a failed try' : 'Not compared') ?></td>
<?php endif; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>

<section class="lt-stack" aria-labelledby="index-title">
  <h2 id="index-title">The index and the indexer</h2>
  <dl class="lt-kv">
<?php if ($v['top'] === null): ?>
    <div><dt>Indexed height</dt><dd>No block is indexed yet</dd></div>
<?php else: ?>
    <div><dt>Indexed height</dt><dd><?= Html::blockLink($v['top']['height']) ?></dd></div>
    <div><dt>Time of that block</dt><dd><?= Html::time($v['top']['timestamp'], $now) ?></dd></div>
<?php endif; ?>
<?php if ($v['run'] === null): ?>
    <div><dt>Last indexer run</dt><dd>No run is recorded yet</dd></div>
<?php else: ?>
    <div><dt>Last indexer run</dt><dd><?= Html::time((int) $v['run']['finished_at'], $now) ?></dd></div>
    <div><dt>Mode of that run</dt><dd><?= lt_esc($v['run']['mode']) ?></dd></div>
    <div><dt>Headers stored by it</dt><dd><?= Html::num((int) $v['run']['headers']) ?></dd></div>
    <div><dt>Outcome of that run</dt><dd><?= lt_esc($v['run']['outcome']) ?></dd></div>
<?php endif; ?>
<?php if ($v['reorg'] === null): ?>
    <div><dt>Last reorganisation</dt><dd>None recorded</dd></div>
<?php else: ?>
    <div><dt>Last reorganisation</dt><dd><?= Html::time((int) $v['reorg']['detected_at'], $now) ?>, <?= lt_esc(Format::int((int) $v['reorg']['depth'])) ?> blocks deep, above height <?= Html::blockLink((int) $v['reorg']['fork_height']) ?></dd></div>
<?php endif; ?>
<?php if ($v['snapshot'] === null): ?>
    <div><dt>Last supply snapshot</dt><dd>None taken yet</dd></div>
<?php else: ?>
    <div><dt>Last supply snapshot</dt><dd><?= Html::time($v['snapshot'], $now) ?></dd></div>
<?php endif; ?>
  </dl>
</section>
