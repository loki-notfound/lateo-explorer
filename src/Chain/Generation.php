<?php

declare(strict_types=1);

namespace Lateo\Explorer\Chain;

/** Which spelling of an asset name the chain used. */
enum Generation: string
{
    /** ZEPH, ZEPHUSD, ZEPHRSV, ZYIELD: valid on chain up to hard fork 11 (height 536000). */
    case Old = 'old';
    /** ZPH, ZSD, ZRS, ZYS: valid from the audit hard fork 8 on. */
    case New = 'new';
    /** Not a name the chain knows. */
    case Unknown = 'unknown';
}
