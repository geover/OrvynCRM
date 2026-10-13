<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;

/**
 * Makes an uploaded SVG safe to show. An SVG is a program as much as a picture (scripts, event handlers, links to other
 * sites), so only a short list of drawing elements and attributes is kept, and anything that could run code or load
 * something from elsewhere is dropped. A file that cannot be read as plain SVG is refused.
 */
class SafeSvg
{
    /** @var list<string> */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'style',
    ];

    private const MAX_BYTES = 524288;

    /** The cleaned SVG, or null when it is not a readable plain SVG. */
    public static function clean(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES || preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[/i', $svg)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);        // no entity expansion, no network
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (! $loaded || ! $root || strtolower($root->localName) !== 'svg') {
            return null;
        }

        self::scrub($root);

        return $doc->saveXML($root) ?: null;
    }

    private static function scrub(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $element->removeChild($child);

                    continue;
                }
                self::scrub($child);
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                if (strtolower($element->localName) === 'style' && self::unsafeCss($child->nodeValue)) {
                    $element->removeChild($child);
                }
            } else {
                $element->removeChild($child);                                  // comments, processing instructions
            }
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (self::unsafeAttribute($attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private static function unsafeAttribute(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->nodeName);
        $value = trim($attribute->value);

        if (str_starts_with($name, 'on')) {
            return true;
        }
        if (in_array($name, ['href', 'xlink:href'], true)) {
            return ! str_starts_with($value, '#');                              // only links to something inside the same file
        }

        return $name === 'style' ? self::unsafeCss($value) : self::unsafeCss($value) || preg_match('/javascript:|data:/i', $value) === 1;
    }

    /** CSS that loads something from outside the file, or tries to run code. */
    private static function unsafeCss(string $css): bool
    {
        return preg_match('/@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|url\(\s*[\'"]?\s*(?!#)/i', $css) === 1;
    }
}
