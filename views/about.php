<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $v
 * @var \Lateo\Explorer\Web\View $view
 */
?>
<section class="lt-stack">
  <picture class="ex-lockup">
    <source srcset="/assets/lockup-host-explorer-reversed.svg" media="(prefers-color-scheme: dark)">
    <img src="/assets/lockup-host-explorer.svg" width="165" height="28" alt="explorer.lateo.dev">
  </picture>
  <h1>About this explorer</h1>
  <p class="lead">explorer.lateo.dev shows the blocks of Zephyr, the oracle's pricing record of each block and the reserve. It reads the chain and nothing else.</p>
  <?= lt_partial('callout', [
      'tone' => 'info',
      'title' => 'Independent project',
      'body' => 'Independent open-source project around Zephyr. Not affiliated with ZephyrProtocol.',
  ]) ?>
  <?= lt_partial('callout', [
      'tone' => 'warning',
      'title' => 'Never share your seed phrase',
      'body' => 'Security notice: node.lateo.dev, explorer.lateo.dev, and lateo support will never ask for your 25-word seed phrase or private keys. Never type or paste your seed phrase into any website, support form, or chat window.',
  ]) ?>
</section>

<section class="lt-stack" aria-labelledby="what-title">
  <h2 id="what-title">What this site shows</h2>
  <p>Blocks with their header and miner reward. The pricing record of each block. The state of the index and of the nodes it reads.</p>
  <p>Pages for single transactions, the mempool, supply and graphs are not built yet.</p>
</section>

<section class="lt-stack" aria-labelledby="data-title">
  <h2 id="data-title">Where the data comes from</h2>
  <p>The data comes from the Zephyr daemon. The explorer asks two Zephyr nodes of the lateo operator, over a private network. If neither answers, it asks <?= lt_esc($v['fallback']) ?> as a fallback.</p>
  <p>It uses the restricted, read-only RPC of those nodes. It never relays a transaction and never calls an administrative method. It contacts no other node and no other service.</p>
  <p>A background program, the indexer, copies block headers into a local database in small, paced steps. The pages read that database first. A few facts are read live and kept for a short time: the tip of the chain, the transaction hashes of a block and the state of the nodes. When no node answers, a page says "Node not reachable" and still shows what the database holds.</p>
  <p>Requests to the nodes carry the user agent <code><?= lt_esc($v['userAgent']) ?></code>.</p>
</section>

<section class="lt-stack" aria-labelledby="prices-title">
  <h2 id="prices-title">Prices</h2>
  <p>Every price comes from the oracle of the protocol: the pricing record that each block carries. The explorer shows these records as they are, also for old blocks. It uses no exchange data.</p>
  <p>Pricing records start at block 89,300 (2023-10-01). Earlier blocks have none. The moving average is the protocol's own, over 720 blocks.</p>
  <p>Amounts show 12 decimals in detail. Prices are in USD, as the oracle states them.</p>
</section>

<section class="lt-stack" aria-labelledby="names-title">
  <h2 id="names-title">Asset names</h2>
  <p>The four assets are called ZPH, ZSD, ZRS and ZYS. Hard fork 11, at block 536,000 (mined on 2025-06-15), renamed them from ZEPH, ZEPHUSD, ZEPHRSV and ZYIELD. This explorer shows the current names everywhere, also for blocks before the fork. This is the only place that names the old ones.</p>
</section>

<section class="lt-stack" aria-labelledby="stored-title">
  <h2 id="stored-title">What is stored</h2>
  <p>The database holds block headers, the pricing record of each block, supply figures and a record of what the indexer did. It holds no data about visitors.</p>
</section>

<section class="lt-stack" aria-labelledby="privacy-title">
  <h2 id="privacy-title">Privacy</h2>
  <ul>
    <li>No cookies, no scripts, no analytics and no requests to third parties. Styles and images come from this site.</li>
    <li>The application does not log what you search for.</li>
    <li>The web server's access log records each request with its page address. For a search, that address includes what you typed. The log is kept by size: ten files of 10 MB, then the oldest is deleted.</li>
    <li>The site runs behind Cloudflare, which sees each request as well.</li>
    <li>Your keys never reach this server. The explorer has no address pages. It does not guess which inputs of a transaction are real, and it does not match amounts between transactions.</li>
  </ul>
  <?= lt_partial('callout', [
      'tone' => 'warning',
      'title' => 'A public explorer is a trust trade-off',
      'body' => 'This site shows public chain data. The web server and Cloudflare can see your IP address and what you search for. They cannot see your keys, because the site never asks for any. To avoid the trade-off, run your own node and read the chain yourself.',
  ]) ?>
</section>

<section class="lt-stack" aria-labelledby="contact-title">
  <h2 id="contact-title">Contact, licence and marks</h2>
  <p>Report a problem or ask a question in the issues of <a href="<?= lt_esc($v['repository']) ?>">the public repository on GitHub</a>, or write to <code><?= lt_esc($v['contact']) ?></code>.</p>
  <p>The source code is under the BSD-3-Clause licence. The lateo name, logo and other marks are not covered by that licence. All rights to them are reserved.</p>
</section>
