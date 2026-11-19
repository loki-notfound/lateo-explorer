<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\Format;
use Lateo\Explorer\Web\LiveState;
use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/**
 * The home page: the state of the data, the latest blocks and the reserve. Everything comes
 * from the database except the tip of the chain, which is one cached call to a node.
 */
final class HomePage
{
    public const LATEST_BLOCKS = 20;

    public function respond(Services $s): Response
    {
        $index = $s->index();
        $top = $index->top();
        $tip = $s->live()->tip();

        $behind = null;
        if ($top !== null && $tip->ok()) {
            $behind = max(0, (int) $tip->value['height'] - $top['height']);
        }
        $pricing = $index->latestPricing();

        $html = $s->view->render('home', [
            'now' => $s->now(),
            'top' => $top,
            'tip' => $tip->ok() ? $tip->value : null,
            'tipMessage' => $tip->state === LiveState::Ok ? '' : $tip->state->message(),
            'tipStale' => $tip->stale,
            'behind' => $behind === null ? null : ($behind === 0 ? 'In sync' : Format::int($behind) . ($behind === 1 ? ' block' : ' blocks')),
            'blocks' => $top === null ? [] : $index->latestBlocks(self::LATEST_BLOCKS),
            'pricing' => $pricing,
        ]);
        return $s->layout->page(
            200,
            'Zephyr blocks, oracle prices and reserve',
            'Blocks, pricing records and the reserve of Zephyr, read from the chain and the protocol\'s oracle.',
            '/',
            $html,
            'public, max-age=5',
        );
    }
}
