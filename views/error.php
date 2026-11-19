<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
?>
<section class="lt-state-page" aria-labelledby="state-title">
  <p class="lt-state-code"><?= lt_esc($v['code']) ?></p>
  <h1 id="state-title"><?= lt_esc($v['title']) ?></h1>
  <p class="lead"><?= lt_esc($v['lead']) ?></p>
  <p><?= lt_esc($v['body']) ?></p>
  <div class="lt-state-actions">
    <?= lt_partial('button', ['label' => 'Go to the home page', 'variant' => 'primary', 'href' => '/']) ?>
    <?= lt_partial('button', ['label' => 'Read how the explorer works', 'variant' => 'secondary', 'href' => '/about']) ?>
  </div>
</section>
<?= $view->render('search-form', ['q' => '']) ?>
