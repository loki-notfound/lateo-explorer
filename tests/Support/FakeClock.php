<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

use Lateo\Explorer\Support\Clock;

/** A clock that moves only when told to or when something sleeps. */
final class FakeClock implements Clock
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $time = 1_800_000_000.0)
    {
    }

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
