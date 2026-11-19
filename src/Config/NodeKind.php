<?php

declare(strict_types=1);

namespace Lateo\Explorer\Config;

enum NodeKind: string
{
    /** A node of the operator, reached over the private network. */
    case Own = 'own';

    /** The public node, used when the own nodes fail. */
    case Fallback = 'fallback';
}
