<?php

declare(strict_types=1);

namespace Lateo\Explorer\Config;

/** One node the explorer may contact. The url is private: never print it. */
final readonly class NodeSpec
{
    public function __construct(
        public string $label,
        public NodeKind $kind,
        public string $url,
    ) {
    }

    public function isFallback(): bool
    {
        return $this->kind === NodeKind::Fallback;
    }
}
