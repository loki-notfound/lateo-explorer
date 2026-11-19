<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

/**
 * Thrown by a test (or its setUp) that cannot run in this environment. tests/run.php counts
 * it as skipped, not as passed and not as failed. Use it only for a missing capability, with a
 * short reason; never to hide a failure.
 */
final class SkipTest extends \RuntimeException
{
}
