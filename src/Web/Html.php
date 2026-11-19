<?php

declare(strict_types=1);

namespace Lateo\Explorer\Web;

/**
 * Small HTML builders for the views. Every method escapes its input and returns HTML, so a
 * view may print the result without further escaping. Views print anything else only through
 * lt_esc() (a test enforces this).
 */
final class Html
{
    /** A time element with the absolute UTC time and the age beside it (never the age alone). */
    public static function time(int $unix, int $now): string
    {
        $age = Format::age($unix, $now);
        return '<time datetime="' . lt_esc(Format::isoTime($unix)) . '">' . lt_esc(Format::time($unix)) . '</time>'
            . ($age !== '' ? ' <span class="lt-muted">(' . lt_esc($age) . ')</span>' : '');
    }

    /** A full hash in the style's monospace class. */
    public static function hash(string $hash): string
    {
        return '<code class="lt-hash">' . lt_esc($hash) . '</code>';
    }

    /** A shortened hash that links to $href; the full hash is on the page it opens. */
    public static function hashLink(string $hash, string $href): string
    {
        return '<a href="' . lt_esc($href) . '"><code class="lt-hash-short">' . lt_esc(Format::hashShort($hash)) . '</code></a>';
    }

    /** A block height as a link to its page. */
    public static function blockLink(int $height): string
    {
        return '<a class="lt-num" href="/block/' . lt_esc($height) . '">' . lt_esc(Format::int($height)) . '</a>';
    }

    /** A number in the tabular style. */
    public static function num(int|string $n): string
    {
        return '<span class="lt-num">' . lt_esc(Format::int($n)) . '</span>';
    }

    /** A value with its unit, both tabular: "0.130016550000 USD". */
    public static function figure(string $text): string
    {
        return '<span class="lt-num">' . lt_esc($text) . '</span>';
    }

    /** A statistics tile whose value and time come as HTML built by this class. */
    public static function tile(string $label, string $valueHtml, string $noteHtml = ''): string
    {
        return '<div class="lt-stat-tile"><span class="lt-stat-label">' . lt_esc($label) . '</span>'
            . '<span class="lt-stat-value">' . $valueHtml . '</span>'
            . ($noteHtml !== '' ? '<span class="lt-stat-time">' . $noteHtml . '</span>' : '')
            . '</div>';
    }

    /** A state badge from the style (online, degraded, offline, unknown) with text. */
    public static function badge(string $state, string $label): string
    {
        return lt_partial('status-badge', ['state' => $state, 'label' => $label]);
    }
}
