<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/** Sends one POST and returns the status and body, or throws TransportFailure. */
interface Transport
{
    /** @throws TransportFailure */
    public function post(string $url, string $jsonBody, RequestLimits $limits): TransportResponse;
}
