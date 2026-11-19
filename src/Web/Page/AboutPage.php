<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web\Page;

use Lateo\Explorer\Web\Layout;
use Lateo\Explorer\Web\LiveData;
use Lateo\Explorer\Web\Response;
use Lateo\Explorer\Web\Services;

/** /about: the method page. Static text. */
final class AboutPage
{
    public const USER_AGENT = 'lateo-explorer (+https://explorer.lateo.dev)';
    public const CONTACT = 'loki-notfound@proton.me';

    public function respond(Services $s): Response
    {
        $html = $s->view->render('about', [
            'userAgent' => self::USER_AGENT,
            'contact' => self::CONTACT,
            'repository' => Layout::REPOSITORY_URL,
            'fallback' => LiveData::FALLBACK_NAME,
        ]);
        return $s->layout->page(
            200,
            'About this explorer',
            'What the explorer is, where its data comes from, what it stores and what it never does.',
            '/about',
            $html,
            'public, max-age=3600',
            [['label' => 'Home', 'href' => '/'], ['label' => 'About']],
        );
    }
}
