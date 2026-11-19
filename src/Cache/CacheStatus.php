<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cache;

enum CacheStatus: string
{
    /** A fresh value was in the cache. */
    case Hit = 'hit';
    /** The value was computed now and stored. */
    case Computed = 'computed';
    /** An expired value is returned: the key was locked by another process, or computing failed. */
    case Stale = 'stale';
    /** The key was locked by another process and there is no value at all: render without it. */
    case Busy = 'busy';
}
