# lateo-explorer

Source of [explorer.lateo.dev](https://explorer.lateo.dev), a block, transaction
and mempool explorer with protocol analytics for Zephyr. It is to hold the site,
which PHP renders, and the indexer, a PHP command-line program that cron runs.

Independent open-source project around Zephyr. Not affiliated with
ZephyrProtocol.

## Status

In development, not deployed. What exists so far is the groundwork: the settings
loader, the RPC client with its fixed list of calls, a shared file cache, the
mapping of asset names, and their tests. There are no pages and no indexer yet.

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

PHP 8.4 with the `curl` and `json` extensions. No Composer and no other
packages: `src/autoload.php` is a small autoloader for the namespace
`Lateo\Explorer`.

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
- `src/Support/`: the clock.
- `bin/`: command-line programs (none yet).
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
