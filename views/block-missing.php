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
  <h1>Block not indexed yet</h1>
<?php if ($v['byHeight']): ?>
  <p class="lead">Block <?= Html::num($v['key']) ?> is not in the index.</p>
<?php else: ?>
  <p class="lead">No block with this hash is in the index.</p>
  <p><?= Html::hash($v['key']) ?></p>
<?php endif; ?>
<?php if ($v['top'] !== null): ?>
  <p>The index holds blocks up to height <?= Html::blockLink($v['top']) ?>. A block above that may exist on the chain. It appears here when the indexer reaches it.</p>
<?php else: ?>
  <p>The index is empty. The indexer has not stored a block yet.</p>
<?php endif; ?>
  <?= $view->render('search-form', ['q' => '']) ?>
</section>
