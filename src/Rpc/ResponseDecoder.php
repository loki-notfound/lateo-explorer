<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/**
 * Turns a transport response into a result array, or throws an RpcException.
 *
 * JSON is decoded with JSON_BIGINT_AS_STRING: an integer that does not fit PHP's signed
 * 64-bit integer (the daemon's uint64 values) stays an exact decimal string. Integers that do
 * fit are PHP integers, so a caller that wants a uint64 handles both int and string.
 */
final class ResponseDecoder
{
    /** @return array<string,mixed> */
    public static function decode(RpcMethod $method, TransportResponse $response, string $expectedId, string $node): array
    {
        if ($response->status !== 200) {
            throw new RpcException(ErrorCategory::Http, $node);
        }
        if (!str_starts_with(ltrim($response->body), '{')) {
            throw new RpcException(ErrorCategory::Decode, $node, 'not a JSON object');
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RpcException(ErrorCategory::Decode, $node, 'not valid JSON');
        }
        if (!self::isObject($data)) {
            throw new RpcException(ErrorCategory::Decode, $node, 'not a JSON object');
        }

        if ($method->isPlain()) {
            $result = $data;
        } else {
            $id = $data['id'] ?? null;
            if (!is_scalar($id) || (string) $id !== $expectedId) {
                throw new RpcException(ErrorCategory::Decode, $node, 'id does not match');
            }
            if (array_key_exists('error', $data)) {
                throw new RpcException(ErrorCategory::RpcError, $node);
            }
            $result = $data['result'] ?? null;
            if (!self::isObject($result)) {
                throw new RpcException(ErrorCategory::Decode, $node, 'result is not an object');
            }
        }

        if (!array_key_exists('status', $result)) {
            throw new RpcException(ErrorCategory::Missing, $node, 'no status');
        }
        if ($result['status'] !== 'OK') {
            throw new RpcException(ErrorCategory::Status, $node);
        }
        if (($result['untrusted'] ?? false) !== false) {
            throw new RpcException(ErrorCategory::Untrusted, $node);
        }
        if ($method === RpcMethod::GetTransactions && !empty($result['missed_tx'])) {
            throw new RpcException(ErrorCategory::Missing, $node, 'transactions missed');
        }
        return $result;
    }

    /** A decoded JSON object (an empty array is accepted: it cannot carry a status anyway). */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
