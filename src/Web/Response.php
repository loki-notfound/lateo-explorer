<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * What a page request answers with: a status, headers and a body. Pages return one and only
 * public/index.php sends it, so tests render pages without a web server.
 *
 * Every response carries the house style's security headers, with the same policy as
 * lt_send_security_headers() of style/helpers.php (form-action 'self' for the search form),
 * and never a Set-Cookie.
 */
final readonly class Response
{
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; "
        . "form-action 'self'; frame-ancestors 'none'";

    /** @param array<string,string> $headers by name */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /** @return array<string,string> */
    public static function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ];
    }

    /** @param array<string,string> $extra */
    public static function html(int $status, string $body, string $cacheControl, array $extra = []): self
    {
        return new self(
            $status,
            [...self::securityHeaders(), 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => $cacheControl, ...$extra],
            $body,
        );
    }

    /** A 303 See Other, never cached. */
    public static function redirect(string $location): self
    {
        return new self(
            303,
            [...self::securityHeaders(), 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Location' => $location],
            '',
        );
    }

    /** The value of a header, found without regard to case. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    public function withoutBody(): self
    {
        return new self($this->status, $this->headers, '');
    }
}
