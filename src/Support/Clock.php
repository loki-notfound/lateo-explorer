<?php

declare(strict_types=1);

namespace Lateo\Explorer\Support;

/** Time source and sleeping, replaceable in tests. */
interface Clock
{
    /** Seconds since the Unix epoch, with a fraction. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
