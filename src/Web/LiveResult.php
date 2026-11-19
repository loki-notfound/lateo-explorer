<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

final readonly class LiveResult
{
    public function __construct(
        public LiveState $state,
        public mixed $value = null,
        /** True when the value is an expired one that stands in for a failed refresh. */
        public bool $stale = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->state === LiveState::Ok;
    }
}
