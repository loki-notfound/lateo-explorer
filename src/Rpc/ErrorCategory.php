<?php

declare(strict_types=1);

namespace Lateo\Explorer\Rpc;

/** Why an RPC call failed. Callers decide on the category, never on message text. */
enum ErrorCategory: string
{
    /** Refused by policy before any I/O: method not on the allow-list, fallback not allowed. */
    case RefusedMethod = 'refused-method';
    /** The page's call budget or one of the shared per-minute caps (fallback, web calls to own nodes) is used up. */
    case Budget = 'budget';
    /** The node could not be reached or the transfer broke off (DNS, TCP, TLS, reset, short body). */
    case Connect = 'connect';
    /** The connect or total timeout ran out. */
    case Timeout = 'timeout';
    /** The node answered with an HTTP status other than 200. */
    case Http = 'http';
    /** The response grew past the size cap. */
    case TooLarge = 'too-large';
    /** The body is not the JSON the call expects (not JSON, not an object, wrong id). */
    case Decode = 'decode';
    /** The node answered with a JSON-RPC error object. */
    case RpcError = 'rpc-error';
    /** The result's status is not OK. */
    case Status = 'status';
    /** The node says its answer is untrusted. */
    case Untrusted = 'untrusted';
    /** Something the call needs is absent: no status field, or missed transactions. */
    case Missing = 'missing';

    /** Failures of the transfer or of reading it, which one more try on the same node may cure. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Connect, self::Timeout, self::Decode => true,
            default => false,
        };
    }
}
