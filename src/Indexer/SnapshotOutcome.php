<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/** What the supply snapshot of a run did. */
enum SnapshotOutcome: string
{
    /** Stored: both headers had the same hash. */
    case Stored = 'stored';
    /** The top block is the one of the last snapshot; nothing to do. */
    case Unchanged = 'unchanged';
    /** The top block changed while the three calls ran; skipped for this run. */
    case Changed = 'changed';
    /** A call failed or an answer was unusable; skipped for this run. */
    case Failed = 'failed';
}
