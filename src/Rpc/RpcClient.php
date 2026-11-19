<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

use Lateo\Explorer\Config\Config;
use Lateo\Explorer\Config\NodeSpec;
use Lateo\Explorer\Support\Clock;
use Lateo\Explorer\Support\SystemClock;

/**
 * Calls the daemon's restricted RPC on the configured nodes.
 *
 * - Only the calls of RpcMethod are possible; anything else throws before any I/O.
 * - Nodes are tried in this order: own nodes in settings order, then the fallback. Every
 *   result says which node answered.
 * - Web profile: one try per node, a budget of page_max_calls attempts per client (so one
 *   client is one page request), and the fallback also under the shared per-minute cap.
 * - Indexer profile: up to two more tries on the same node after a connect, timeout or decode
 *   error, no attempt budget, and the fallback only when the caller passes $allowFallback.
 * - Every attempt that reaches the fallback is counted by the limiter, whatever the profile.
 */
final class RpcClient
{
    /** The JSON-RPC id of every request; a response with another id is refused. */
    public const REQUEST_ID = '0';

    private readonly RequestLimits $requestLimits;
    private readonly FallbackLimiter $limiter;
    private ?int $remaining;
    private int $attempts = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly Profile $profile,
        ?FallbackLimiter $limiter = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->requestLimits = new RequestLimits(
            $profile->connectTimeout(),
            $profile->totalTimeout(),
            $profile->maxResponseBytes(),
        );
        $this->limiter = $limiter ?? new FallbackLimiter(
            $config->cacheDir,
            $config->limits->fallbackCallsPerMinute,
            $clock,
        );
        $this->remaining = $profile->hasCallBudget() ? $config->limits->pageMaxCalls : null;
    }

    /**
     * @param string|RpcMethod $method a case of RpcMethod or its value ("get_info", "/get_transactions")
     * @param array<string,mixed> $params
     * @param bool|null $allowFallback null follows the profile (web yes, indexer no)
     * @param string|null $node the label of one node to ask, instead of the usual order
     * @throws RpcException
     */
    public function call(
        string|RpcMethod $method,
        array $params = [],
        ?bool $allowFallback = null,
        ?string $node = null,
    ): RpcResult {
        $m = $method instanceof RpcMethod ? $method : RpcMethod::tryFrom($method);
        if ($m === null) {
            throw new RpcException(ErrorCategory::RefusedMethod, null, 'not on the allow-list');
        }
        $allow = $allowFallback ?? $this->profile->fallbackByDefault();
        $body = $this->encode($m, $params);

        $last = null;
        foreach ($this->candidates($node, $allow) as $spec) {
            $tries = 1 + $this->profile->sameNodeRetries();
            for ($i = 0; $i < $tries; $i++) {
                if ($i > 0) {
                    $this->clock->sleep($this->profile->retryDelay());
                }
                try {
                    return $this->attempt($m, $body, $spec);
                } catch (RpcException $e) {
                    if ($e->category === ErrorCategory::Budget) {
                        throw $e;
                    }
                    $last = $e;
                    if (!$e->category->isRetryable()) {
                        break;
                    }
                }
            }
        }
        throw $last ?? new RpcException(ErrorCategory::Missing, null, 'no node to ask');
    }

    /** @return list<string> the labels of the own nodes, in settings order */
    public function ownLabels(): array
    {
        return array_map(static fn (NodeSpec $n): string => $n->label, $this->config->ownNodes());
    }

    /** The label of the fallback node, or null when none is configured. */
    public function fallbackLabel(): ?string
    {
        $nodes = $this->config->fallbackNodes();
        return $nodes === [] ? null : $nodes[0]->label;
    }

    /** Number of attempts that went to a transport so far (all nodes). */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** @param array<string,mixed> $params */
    private function encode(RpcMethod $m, array $params): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        try {
            if ($m->isPlain()) {
                return json_encode($params === [] ? new \stdClass() : $params, $flags);
            }
            return json_encode([
                'jsonrpc' => '2.0',
                'id' => self::REQUEST_ID,
                'method' => $m->value,
                'params' => $params === [] ? new \stdClass() : $params,
            ], $flags);
        } catch (\JsonException) {
            throw new RpcException(ErrorCategory::RefusedMethod, null, 'parameters cannot be encoded');
        }
    }

    /** @return list<NodeSpec> */
    private function candidates(?string $node, bool $allowFallback): array
    {
        if ($node !== null) {
            $spec = $this->config->node($node);
            if ($spec === null) {
                throw new RpcException(ErrorCategory::Missing, null, 'unknown node label');
            }
            if ($spec->isFallback() && !$allowFallback) {
                throw new RpcException(ErrorCategory::RefusedMethod, $spec->label, 'fallback not allowed');
            }
            return [$spec];
        }
        return $allowFallback ? $this->config->orderedNodes() : $this->config->ownNodes();
    }

    private function attempt(RpcMethod $m, string $body, NodeSpec $spec): RpcResult
    {
        if ($this->remaining !== null && $this->remaining <= 0) {
            throw new RpcException(ErrorCategory::Budget, $spec->label, 'call budget used up');
        }
        if ($spec->isFallback()) {
            $this->limiter->acquire($spec->label);
        }
        if ($this->remaining !== null) {
            $this->remaining--;
        }
        $this->attempts++;

        try {
            $response = $this->transport->post($spec->url . $m->path(), $body, $this->requestLimits);
        } catch (TransportFailure $failure) {
            throw new RpcException($failure->category, $spec->label);
        }
        return new RpcResult(ResponseDecoder::decode($m, $response, self::REQUEST_ID, $spec->label), $spec->label);
    }
}
