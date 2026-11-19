<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/** The outcome of a live read: what the page may say about data that comes from a node. */
enum LiveState: string
{
    /** The value is there (fresh, or stale when LiveResult::$stale). */
    case Ok = 'ok';
    /** No node answered: the page says "Node not reachable". */
    case Unreachable = 'unreachable';
    /** The shared cache is locked by another request, or cannot be used: "temporarily unavailable". */
    case Busy = 'busy';
    /** A node answered, but not about the block the index holds. Not shown. */
    case Mismatch = 'mismatch';

    /** What a page says in place of the data. Empty when the data is there. */
    public function message(): string
    {
        return match ($this) {
            self::Ok => '',
            self::Unreachable => 'Node not reachable',
            self::Busy => 'Temporarily unavailable',
            self::Mismatch => 'Not shown, because the node answered about another block',
        };
    }
}
