<?php

namespace Ernestdefoe\Chronicle\Feed;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A plain-text excerpt from a post's stored content.
 *
 * Flarum keeps a post as the formatter's XML, so the text is already there:
 * reading it straight from that XML costs nothing per post. Rendering the HTML
 * instead would run every extension's renderer, and some of those (mentions)
 * load relations one post at a time.
 */
class Excerpt
{
    public static function from(?string $xml, int $length = 200): string
    {
        if ($xml === null || $xml === '') {
            return '';
        }

        // Older or imported posts may hold plain text rather than XML.
        if ($xml[0] !== '<') {
            return self::trim(html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $length);
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return self::trim(strip_tags($xml), $length);
        }

        $xpath = new DOMXPath($doc);

        // A post mention keeps its text as `@"Name"#p12`; say it the way it
        // reads on the page instead.
        foreach (iterator_to_array($xpath->query('//POSTMENTION')) as $mention) {
            /** @var DOMElement $mention */
            $name = $mention->getAttribute('displayname') ?: $mention->getAttribute('username');
            $mention->parentNode->replaceChild($doc->createTextNode($name !== '' ? '@'.$name : ''), $mention);
        }

        // Markup characters (<s>, <e>), ignored text (<i>), quoted posts and
        // code blocks are not what the member wrote in their own words.
        foreach (iterator_to_array($xpath->query('//s | //e | //i | //QUOTE | //CODE | //SPOILER | //DETAILS')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Block ends become spaces so paragraphs don't run together.
        foreach (iterator_to_array($xpath->query('//p | //br | //LI | //H1 | //H2 | //H3 | //H4 | //H5 | //H6')) as $node) {
            $node->parentNode?->insertBefore($doc->createTextNode(' '), $node->nextSibling);
        }

        return self::trim($doc->documentElement?->textContent ?? '', $length);
    }

    private static function trim(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n.,;:!?-").'…';
    }
}
