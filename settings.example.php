<?php

declare(strict_types=1);

// Copy this file to a location outside the webroot (for example settings.php, which git
// ignores), fill in your own values and point the code at that copy. Never commit the copy:
// the node addresses in it are private.
//
// The addresses below are placeholders. `.example.invalid` can never resolve.

return [
    // Nodes in the order they are tried: own nodes first, in this order, then the fallback.
    // kind is "own" (a node you run, reached over a private network, restricted RPC port
    // only) or "fallback" (a public node, used when the own nodes fail). At least one own
    // node is required. A label is lowercase letters, digits and hyphens, at most 20 long.
    'nodes' => [
        ['label' => 'own-a', 'kind' => 'own', 'url' => 'http://node-a.example.invalid:17777'],
        ['label' => 'own-b', 'kind' => 'own', 'url' => 'http://node-b.example.invalid:17777'],
        ['label' => 'fallback', 'kind' => 'fallback', 'url' => 'https://node.lateo.dev'],
    ],

    // Absolute paths, outside the webroot.
    'database' => '/srv/explorer-data/explorer.sqlite',
    'cache_dir' => '/srv/explorer-data/cache',
    'lock_file' => '/srv/explorer-data/indexer.lock',

    // How much work the explorer may ask of the nodes. These are the defaults.
    'limits' => [
        'page_max_calls' => 4,              // RPC attempts per page request, over all nodes
        'fallback_calls_per_minute' => 30,  // calls to the fallback, over all processes
        'header_batch' => 1000,             // headers per get_block_headers_range call
        'backfill_pause_seconds' => 2.0,    // pause between two backfill calls
        'run_seconds' => 45,                // working time of one indexer run
        'reorg_max_depth' => 100,           // deepest reorganisation that is undone
        'unsettled_depth' => 10,            // tip blocks shown as not yet settled
    ],
];
