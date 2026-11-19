<?php

declare(strict_types=1);

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Html;

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
$b = $v['block'];
$p = $v['pricing'];
$now = $v['now'];
$height = (int) $b['height'];
?>
<section class="lt-stack">
  <h1>Block <span class="lt-num"><?= lt_esc(Format::int($height)) ?></span></h1>
  <p><?= Html::badge('online', 'Canonical') ?></p>
<?php if ($v['unsettled']): ?>
  <?= lt_partial('callout', [
      'tone' => 'info',
      'title' => 'This block may still change',
      'body' => 'It is one of the last ' . $v['depth'] . ' blocks of the index. A reorganisation of the chain can still replace it.',
  ]) ?>
<?php endif; ?>
  <nav class="lt-cluster ex-block-nav" aria-label="Neighbouring blocks">
<?php if ($v['prev'] !== null): ?>
    <a href="/block/<?= lt_esc($v['prev']) ?>">Previous block <?= lt_esc(Format::int($v['prev'])) ?></a>
<?php else: ?>
    <span class="lt-muted">No previous block in the index</span>
<?php endif; ?>
<?php if ($v['next'] !== null): ?>
    <a href="/block/<?= lt_esc($v['next']) ?>">Next block <?= lt_esc(Format::int($v['next'])) ?></a>
<?php else: ?>
    <span class="lt-muted">No next block in the index yet</span>
<?php endif; ?>
  </nav>
  <?= $view->render('search-form', ['q' => '']) ?>
</section>

<section class="lt-stack" aria-labelledby="header-title">
  <h2 id="header-title">Block header</h2>
  <dl class="lt-kv">
    <div><dt>Height</dt><dd><?= Html::num($height) ?></dd></div>
    <div><dt>Time</dt><dd><?= Html::time((int) $b['timestamp'], $now) ?></dd></div>
    <div><dt>State</dt><dd>Canonical</dd></div>
    <div><dt>Hash</dt><dd><?= Html::hash((string) $b['hash']) ?></dd></div>
    <div><dt>Previous block hash</dt><dd><?= Html::hash((string) $b['prev_hash']) ?></dd></div>
    <div><dt>Miner reward</dt><dd><?= Html::figure(Format::amount((int) $b['reward'], 'ZPH')) ?> <span class="lt-muted">(the sum of the outputs of the miner transaction)</span></dd></div>
    <div><dt>Miner transaction</dt><dd><?= Html::hash((string) $b['miner_tx_hash']) ?></dd></div>
    <div><dt>Transactions</dt><dd><?= Html::num((int) $b['num_txes']) ?> <span class="lt-muted">(besides the miner transaction)</span></dd></div>
    <div><dt>Size</dt><dd><?= Html::figure(Format::bytes((int) $b['block_size'])) ?></dd></div>
    <div><dt>Weight</dt><dd><?= Html::figure(Format::int((int) $b['block_weight'])) ?></dd></div>
    <div><dt>Long-term weight</dt><dd><?= Html::figure(Format::int((int) $b['long_term_weight'])) ?></dd></div>
    <div><dt>Block version</dt><dd><?= Html::figure($b['major_version'] . '.' . $b['minor_version']) ?></dd></div>
    <div><dt>Nonce</dt><dd><?= Html::figure((string) $b['nonce']) ?></dd></div>
    <div><dt>Difficulty</dt><dd><?= Html::figure(Format::int((string) $b['difficulty'])) ?></dd></div>
    <div><dt>Cumulative difficulty</dt><dd><?= Html::figure(Format::int((string) $b['cumulative_difficulty'])) ?></dd></div>
  </dl>
<?php if ($v['hardFork11']): ?>
  <?= lt_partial('callout', [
      'tone' => 'info',
      'title' => 'The first block of hard fork 11',
      'body' => 'Its miner transaction includes a one-time mint of 1,921,650 ZPH, so the reward above is far larger than in other blocks.',
  ]) ?>
<?php endif; ?>
</section>

<section class="lt-stack" aria-labelledby="txs-title">
  <h2 id="txs-title">Transactions</h2>
<?php if ((int) $b['num_txes'] === 0): ?>
  <p>This block has no transactions besides the miner transaction.</p>
<?php elseif ($v['txs'] !== null): ?>
  <p>The hashes of the <?= lt_esc(Format::int((int) $b['num_txes'])) ?> transactions, as a node lists them<?= lt_esc($v['txsStale'] ? ' (last known list)' : '') ?>. Pages for single transactions come later.</p>
  <ul class="ex-list">
<?php foreach ($v['txs'] as $tx): ?>
    <li><?= Html::hash((string) $tx) ?></li>
<?php endforeach; ?>
  </ul>
<?php else: ?>
  <p>The transaction hashes are read live from a node. <?= lt_esc($v['txsMessage']) ?>. Reload the page in a minute.</p>
<?php endif; ?>
</section>

<section class="lt-stack" aria-labelledby="pricing-title">
  <h2 id="pricing-title">Pricing record</h2>
<?php if ($p === null && $height < $v['firstPricingHeight']): ?>
  <p>Pricing records start at block 89,300 (2023-10-01). This block is before that and has none.</p>
<?php elseif ($p === null): ?>
  <p>No pricing record is stored for this block.</p>
<?php else: ?>
  <p>The oracle's record that this block carries. Conversions between ZPH and ZSD or ZRS use the less favourable of the spot and the moving-average rate; conversions between ZSD and ZYS use the yield price.</p>
  <dl class="lt-kv">
    <div><dt>Record time</dt><dd><?= Html::time((int) $p['timestamp'], $now) ?></dd></div>
    <div><dt>Oracle price, spot</dt><dd><?= Html::figure(Format::price($p['spot']) . ' USD per ZPH') ?></dd></div>
    <div><dt>Oracle price, moving average</dt><dd><?= Html::figure(Format::price($p['moving_average']) . ' USD per ZPH') ?></dd></div>
    <div><dt>Stable Dollar, spot</dt><dd><?= Html::figure(Format::decimal($p['stable']) . ' ZPH per ZSD') ?></dd></div>
    <div><dt>Stable Dollar, moving average</dt><dd><?= Html::figure(Format::decimal($p['stable_ma']) . ' ZPH per ZSD') ?></dd></div>
    <div><dt>Reserve Share, spot</dt><dd><?= Html::figure(Format::decimal($p['reserve']) . ' ZPH per ZRS') ?></dd></div>
    <div><dt>Reserve Share, moving average</dt><dd><?= Html::figure(Format::decimal($p['reserve_ma']) . ' ZPH per ZRS') ?></dd></div>
    <div><dt>Yield Share</dt><dd><?= Html::figure(Format::decimal($p['yield_price']) . ' ZSD per ZYS') ?></dd></div>
    <div><dt>Reserve ratio, spot</dt><dd><?= Html::figure(Format::ratio($p['reserve_ratio'])) ?></dd></div>
    <div><dt>Reserve ratio, moving average</dt><dd><?= Html::figure(Format::ratio($p['reserve_ratio_ma'])) ?><?= lt_esc((int) $p['reserve_ratio_ma'] === 0 ? ' (not defined yet: the average needs 720 records)' : '') ?></dd></div>
    <div><dt>Oracle signature</dt><dd><?= Html::hash((string) $p['signature']) ?></dd></div>
  </dl>
<?php endif; ?>
</section>
