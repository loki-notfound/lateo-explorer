<?php

declare(strict_types=1);

use Lateo\Explorer\Web\Html;

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
?>
<section class="lt-stack">
  <h1>Search</h1>
<?php if ($v['kind'] === 'empty'): ?>
  <p class="lead">Enter a block height or a block hash.</p>
<?php elseif ($v['kind'] === 'transaction'): ?>
  <p class="lead">Transaction search comes later.</p>
  <p>The text you entered has the shape of a transaction hash, and it is not the hash of a block in the index. This explorer does not show single transactions yet. For now, search for a block height or a block hash.</p>
<?php elseif ($v['kind'] === 'address'): ?>
  <p class="lead">The explorer has no address pages, by design.</p>
  <p>The text you entered looks like an address. An address page would show what one person holds and spends. This explorer does not build that, and an address is not looked up. Search for a block height or a block hash instead.</p>
<?php else: ?>
  <p class="lead">Nothing found for <code><?= lt_esc($v['shown']) ?></code>.</p>
  <p>Search for a block height, for example <code>89300</code>, or for the 64-character hash of a block.</p>
<?php if ($v['topHeight'] !== null): ?>
  <p>The index holds blocks up to height <?= Html::blockLink($v['topHeight']) ?>.</p>
<?php endif; ?>
<?php endif; ?>
  <?= $view->render('search-form', ['q' => $v['formValue']]) ?>
</section>
