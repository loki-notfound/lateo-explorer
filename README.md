# lateo-explorer

Source of [explorer.lateo.dev](https://explorer.lateo.dev), a block, transaction
and mempool explorer with protocol analytics for Zephyr. It is to hold the site,
which PHP renders, and the indexer, a PHP command-line program that cron runs.

Independent open-source project around Zephyr. Not affiliated with
ZephyrProtocol.

## Status

In development, not deployed. What exists: the settings loader, the RPC client with its fixed
list of calls, a shared file cache, the mapping of asset names, the header indexer, and the first
pages: home, block, status, search and about. Pages for transactions, the mempool, supply and
graphs are not built yet.

## Principles

- Data comes from the Zephyr daemon's restricted RPC only: the operator's own
  nodes first, then a public node as fallback. The RPC client has a fixed
  allow-list of read-only calls; anything else is refused before any request is
  made. It never relays a transaction and never calls an administrative method.
- Load on the nodes is bounded: a fixed number of calls per page, a shared cache,
  a cap on calls to the fallback per minute, and small, paced runs of the indexer.
- All prices come from the protocol's oracle (the pricing record of each block).
- Everything that comes from the chain is untrusted input: asset names are mapped
  through a fixed list and anything else is shown as "unknown".
- A visitor's keys never reach the server.

## Requirements

PHP 8.4 with the `curl`, `gmp`, `json` and `pdo_sqlite` extensions (`gmp` compares
cumulative difficulties). No Composer and no other packages: `src/autoload.php` is
a small autoloader for the namespace `Lateo\Explorer`.

## Running the site

`public/index.php` is the front controller. A deploy stub may define `LATEO_EXPLORER_ROOT` (the
repository) and `LATEO_EXPLORER_SETTINGS` (the settings file) and then require it; otherwise the
root is the directory above `public/` and the settings path comes from the environment variable
`LATEO_EXPLORER_SETTINGS`. The pages read the database that the indexer fills; a few facts are
read live from a node through the shared cache (see the method page of the site). The site sets
no cookie and loads no script.

## Settings

Copy [settings.example.php](settings.example.php) to a place outside the webroot,
fill in your own nodes and paths, and keep that copy out of version control
(`settings.php` is in `.gitignore`). The nodes' addresses are private to whoever
runs the site.

## Development

    php tests/run.php

The tests read recorded RPC responses from `tests/fixtures/rpc/` and contact
nothing. The runner prints one line per failure and a summary, and exits 0 or 1.

The layout:

- `src/Config/`: the validated settings.
- `src/Rpc/`: the allow-list, the client, the transports and the decoding of
  responses.
- `src/Cache/`: the file cache with per-key locks.
- `src/Chain/`: the asset names of the chain.
- `src/Db/`: the SQLite schema with its migrations, and the stores for the chain
  tables, supply snapshots and runs.
- `src/Indexer/`: the header indexer, the supply snapshot and the cron entry
  point's application class.
- `src/Support/`: the clock.
- `src/Web/`: the router (`Application`), the request and response objects, the text formats
  (`Format`), the live reads through the cache (`LiveData`) and one class per page (`Page/`).
- `views/`: the pages as plain PHP; every printed value goes through `lt_esc()` or `Html`.
- `style/`: the PHP files of the lateo house style, unchanged copies (version 0.2.0).
- `public/`: the webroot's files: `index.php` (the front controller, the only place that sends
  output), `.htaccess`, `robots.txt` and `assets/` (the style's CSS and marks, and a small
  stylesheet of its own; see `public/assets/README.md`).
- `bin/indexer.php`: the indexer, run by cron with `--settings=<path>`.
- `tests/`: the tests and the recorded fixtures.

## Security

For security problems, see [SECURITY.md](SECURITY.md).

## Contributing

Issues and pull requests are welcome. Every commit on `main` is signed by the
maintainer, so an accepted pull request is not merged with GitHub's button: the
maintainer applies the change as a signed commit that credits you, and closes
the pull request with a reference to it. Contributions are accepted under the
repository's licence.

## Licence

BSD-3-Clause, see [LICENSE](LICENSE). The lateo name and marks are not covered
by that licence; see [TRADEMARKS.md](TRADEMARKS.md).
