<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Rpc\CallLimiter;
use Lateo\Explorer\Rpc\Profile;
use Lateo\Explorer\Rpc\RpcClient;
use Lateo\Explorer\Rpc\Transport;
use Lateo\Explorer\Support\Clock;

/**
 * Makes the RPC client of one page request. It is the web profile: one try per node and a
 * budget of page_max_calls attempts, which lives in the client, so a client must not be
 * shared between requests.
 */
final class LiveClientFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly Clock $clock,
        /** Where the shared caps on calls to the fallback and to the own nodes keep their counts. */
        private readonly string $cacheDir,
    ) {
    }

    public function make(): RpcClient
    {
        $limits = $this->config->limits;
        return new RpcClient(
            $this->config,
            $this->transport,
            Profile::Web,
            CallLimiter::fallback($this->cacheDir, $limits->fallbackCallsPerMinute, $this->clock),
            $this->clock,
            CallLimiter::ownNodes($this->cacheDir, $limits->webOwnCallsPerMinute, $this->clock),
        );
    }
}
