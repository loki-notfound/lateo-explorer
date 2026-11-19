<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/** Backfill: far behind the tip, own nodes only, paused between calls. Tip: close, fallback allowed. */
enum RunMode: string
{
    case Backfill = 'backfill';
    case Tip = 'tip';
}
