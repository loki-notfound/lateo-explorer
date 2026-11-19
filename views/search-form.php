<?php

declare(strict_types=1);

/** @var array<string,mixed> $v */
?>
<search>
  <form class="lt-search-bar" method="get" action="/search">
    <label class="lt-sr-only" for="q">Search by block height or block hash</label>
    <input class="lt-input" type="search" id="q" name="q" value="<?= lt_esc($v['q'] ?? '') ?>" placeholder="Block height or block hash" maxlength="200" autocomplete="off">
    <?= lt_partial('button', ['label' => 'Search', 'type' => 'submit', 'variant' => 'primary']) ?>
  </form>
</search>
