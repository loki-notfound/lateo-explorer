# Security

## Reporting
Report a vulnerability privately through
[GitHub's private vulnerability reporting](https://github.com/loki-notfound/lateo-explorer/security/advisories/new)
for this repository. Please do not open a public issue for it. Expect a first
answer within a week; this is a one-person project.

## What belongs here
- The explorer's RPC client: something that makes it contact a node it should
  not, send a call that is not on its allow-list, change anything on a node, or
  exceed its stated load.
- The site of explorer.lateo.dev: something that shows more than the README says,
  that runs code in a visitor's browser it should not, or that lets a visitor's
  input reach a node, the database or another visitor.
- Anything that lets a visitor's private key, view key or transaction key reach
  the server.
- The handling of data the indexer stores.

## What belongs elsewhere
- A flaw in the Zephyr daemon or wallet:
  [ZephyrProtocol](https://github.com/ZephyrProtocol/zephyr). A daemon flaw
  inherited from Monero is best reported to Monero's vulnerability response
  process first.

## Supported versions
The current `main`. Once the site is deployed, that is what explorer.lateo.dev
runs.
