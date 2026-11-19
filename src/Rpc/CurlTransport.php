<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * POSTs JSON with curl. TLS verification is on, redirects are not followed, only http and
 * https are allowed, the connect and total timeouts come from the request limits, and the
 * response is cut off at the size cap inside the write callback. The HTTP status is kept.
 */
final class CurlTransport implements Transport
{
    public const USER_AGENT = 'lateo-explorer (+https://explorer.lateo.dev)';

    private const CURLE_OPERATION_TIMEDOUT = 28;

    public function post(string $url, string $jsonBody, RequestLimits $limits): TransportResponse
    {
        $collector = new BodyCollector($limits->maxBytes);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportFailure(ErrorCategory::Connect);
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($limits->connectTimeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($limits->totalTimeout * 1000)),
            CURLOPT_WRITEFUNCTION => static fn (\CurlHandle $handle, string $chunk): int => $collector->accept($chunk),
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($collector->overflowed()) {
            throw new TransportFailure(ErrorCategory::TooLarge);
        }
        if ($errno !== 0) {
            throw new TransportFailure(self::categoryFor($errno));
        }
        return new TransportResponse($status, $collector->body());
    }

    /** Map a curl error number to a category: timeouts are their own, all else is a connect failure. */
    public static function categoryFor(int $curlErrno): ErrorCategory
    {
        return $curlErrno === self::CURLE_OPERATION_TIMEDOUT ? ErrorCategory::Timeout : ErrorCategory::Connect;
    }
}
