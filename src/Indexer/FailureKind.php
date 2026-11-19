<?php

declare(strict_types=1);

namespace Lateo\Explorer\Indexer;

/** Why the indexer stopped with an error. */
enum FailureKind: string
{
    /** A node's answer does not have the shape or the values the indexer will store. */
    case InvalidData = 'invalid-data';
    /** The chain differs from the stored one deeper than reorg_max_depth: a person looks. */
    case ReorgTooDeep = 'reorg-too-deep';
    /** A node disagrees with the stored chain but its chain has no more work than ours. */
    case LessWork = 'less-work';
    /** A batch of a node other than the run's tip node does not link to the stored chain. */
    case NotTipNode = 'not-tip-node';
    /** The nodes could not give what the run needs. */
    case Node = 'node';
    /** The run's rules do not allow going on (for example a backfill without an own node). */
    case Policy = 'policy';
    /** The chain kept changing during the run. */
    case Unstable = 'unstable';
}
