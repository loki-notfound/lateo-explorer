<?php

declare(strict_types=1);

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Html;

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
$top = $v['top'];
$tip = $v['tip'];
$pricing = $v['pricing'];
$now = $v['now'];
?>
<section class="lt-stack">
  <h1>Zephyr explorer</h1>
  <p class="lead">Blocks, oracle prices and the reserve of Zephyr. Every price on this site comes from the protocol's oracle.</p>
  <?= $view->render('search-form', ['q' => '']) ?>
</section>

<section class="lt-stack" aria-labelledby="state-title">
  <h2 id="state-title">State of the data</h2>
<?php if ($top === null): ?>
  <p>No block is indexed yet. The indexer fills the index in the background. This page shows data as soon as there is some.</p>
<?php endif; ?>
  <div class="lt-stat-grid">
<?php if ($top !== null): ?>
    <?= Html::tile('Indexed height', Html::num($top['height']), Html::time($top['timestamp'], $now)) ?>
<?php endif; ?>
<?php if ($tip !== null): ?>
    <?= Html::tile('Chain height (node tip)', Html::num($tip['height']), Html::time($tip['timestamp'], $now) . ($v['tipStale'] ? ' (last known value)' : '')) ?>
<?php else: ?>
    <?= Html::tile('Chain height (node tip)', Html::figure($v['tipMessage'])) ?>
<?php endif; ?>
<?php if ($v['behind'] !== null): ?>
    <?= Html::tile('Index behind the chain', Html::figure($v['behind'])) ?>
<?php endif; ?>
  </div>
</section>

<section class="lt-stack" aria-labelledby="reserve-title">
  <h2 id="reserve-title">Oracle prices and reserve</h2>
<?php if ($pricing === null): ?>
  <p>No pricing record is indexed yet. The oracle's records start at block 89,300.</p>
<?php else: ?>
  <div class="lt-stat-grid">
    <?= Html::tile('Oracle price, spot', Html::figure(Format::price($pricing['spot'], false) . ' USD')) ?>
    <?= Html::tile('Oracle price, moving average', Html::figure(Format::price($pricing['moving_average'], false) . ' USD')) ?>
    <?= Html::tile('Reserve ratio, spot', Html::figure(Format::ratio($pricing['reserve_ratio']))) ?>
    <?= Html::tile('Reserve ratio, moving average', Html::figure(Format::ratio($pricing['reserve_ratio_ma']))) ?>
  </div>
  <p class="lt-muted">From the pricing record of block <?= Html::blockLink((int) $pricing['height']) ?>, recorded at <?= Html::time((int) $pricing['timestamp'], $now) ?>. The moving average covers 720 blocks.</p>
<?php endif; ?>
</section>

<section class="lt-stack" aria-labelledby="latest-title">
  <h2 id="latest-title">Latest blocks</h2>
<?php if ($v['blocks'] === []): ?>
  <p>0 blocks indexed so far. The latest blocks appear here once the indexer has stored some.</p>
<?php else: ?>
  <div class="lt-table-scroll">
    <table class="lt-table">
      <caption>The <?= lt_esc(count($v['blocks'])) ?> latest indexed blocks</caption>
      <thead>
        <tr>
          <th scope="col" class="lt-num">Height</th>
          <th scope="col">Time</th>
          <th scope="col">Hash</th>
          <th scope="col" class="lt-num">Transactions</th>
          <th scope="col" class="lt-num">Size</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($v['blocks'] as $b): ?>
        <tr>
          <td class="lt-num"><?= Html::blockLink((int) $b['height']) ?></td>
          <td><?= Html::time((int) $b['timestamp'], $now) ?></td>
          <td><?= Html::hashLink((string) $b['hash'], '/block/' . (int) $b['height']) ?></td>
          <td class="lt-num"><?= lt_esc(Format::int((int) $b['num_txes'])) ?></td>
          <td class="lt-num"><?= lt_esc(Format::bytes((int) $b['block_size'])) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
