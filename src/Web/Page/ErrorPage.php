<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/** The error pages: 404, 405, 503 and 500, in the house style, with what to do next. */
final class ErrorPage
{
    public static function notFound(Services $s, string $path): Response
    {
        return self::build(
            $s,
            404,
            'Page not found',
            'The page does not exist.',
            'This address is not a page of the explorer. Check the address, or search for a block height or block hash.',
            $path,
            'public, max-age=60',
        );
    }

    public static function methodNotAllowed(Services $s, string $path): Response
    {
        $r = self::build(
            $s,
            405,
            'Method not allowed',
            'This address answers GET and HEAD requests only.',
            'The explorer only shows pages. It takes no data from a form other than the search, and the search uses GET.',
            $path,
            'no-store',
        );
        return new Response($r->status, [...$r->headers, 'Allow' => 'GET, HEAD'], $r->body);
    }

    public static function unavailable(Services $s, string $path): Response
    {
        return self::build(
            $s,
            503,
            'Index not available',
            'The index of blocks cannot be read right now.',
            'The indexer may not have started yet, or the database is being replaced. Wait a few minutes and reload the page.',
            $path,
            'no-store',
        );
    }

    public static function serverError(Services $s, string $path): Response
    {
        return self::build(
            $s,
            500,
            'Something broke on the server',
            'The page could not be made.',
            'Nothing you did caused this. Reload the page in a minute. If it keeps happening, report it in the issues of the public repository.',
            $path,
            'no-store',
        );
    }

    private static function build(Services $s, int $code, string $title, string $lead, string $body, string $path, string $cache): Response
    {
        $html = $s->view->render('error', ['code' => $code, 'title' => $title, 'lead' => $lead, 'body' => $body]);
        return $s->layout->page($code, $title, $lead, $path, $html, $cache);
    }
}
