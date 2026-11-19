<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\Request;
use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/**
 * /search?q=: a block height or a block hash that the index knows redirects to its page.
 * A transaction hash is told that transaction search comes later, an address is refused with
 * the reason, anything else is "not found". No RPC call is made and nothing is logged: the
 * query lives only in this request.
 */
final class SearchPage
{
    /** How much of the query is echoed back. */
    public const ECHO_LENGTH = 40;

    public function respond(Services $s, Request $request): Response
    {
        $q = trim($request->query ?? '');
        $index = $s->index();

        if ($q === '') {
            return $this->message($s, 200, 'empty', '', null);
        }
        if (preg_match('/\A[0-9]{1,10}\z/', $q) === 1) {
            $height = (int) $q;
            if ($index->hashAt($height) !== null) {
                return Response::redirect('/block/' . $height);
            }
            $top = $index->top();
            return $this->message($s, 404, 'not-found', $q, $top === null ? null : $top['height']);
        }
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $q) === 1) {
            $hash = strtolower($q);
            $block = $index->blockByHash($hash);
            if ($block !== null) {
                return Response::redirect('/block/' . (int) $block['height']);
            }
            if ($index->orphaned($hash) !== null) {
                return Response::redirect('/block/' . $hash);
            }
            return $this->message($s, 404, 'transaction', $q, null);
        }
        if (self::looksLikeAddress($q)) {
            return $this->message($s, 422, 'address', '', null);
        }
        $top = $index->top();
        return $this->message($s, 404, 'not-found', $q, $top === null ? null : $top['height']);
    }

    /** A string that has the shape of a Zephyr address: the prefix ZEPH and about a hundred letters and digits. */
    public static function looksLikeAddress(string $q): bool
    {
        return preg_match('/\A[Zz][Ee][Pp][Hh][A-Za-z0-9]{86,110}\z/', $q) === 1;
    }

    private function message(Services $s, int $status, string $kind, string $q, ?int $topHeight): Response
    {
        $shown = Format::shorten($q, self::ECHO_LENGTH);
        $html = $s->view->render('search', [
            'kind' => $kind,
            'shown' => $shown,
            'formValue' => $kind === 'address' ? '' : Format::shorten($q, 100),
            'topHeight' => $topHeight,
        ]);
        return $s->layout->page(
            $status,
            'Search',
            'Search the explorer by block height or block hash.',
            '/search',
            $html,
            'no-store',
            [['label' => 'Home', 'href' => '/'], ['label' => 'Search']],
        );
    }
}
