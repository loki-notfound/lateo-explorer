<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/** The database cannot be opened (not created yet, or not readable). */
final class IndexUnavailable extends \RuntimeException
{
}
