<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The text a person formats in the rich text editor (a task's description, say), made safe before it is kept: only the formatting the editor makes survives,
 * with no scripts, event handlers, forms, frames or odd addresses. Text without any tags is returned as it is.
 */
class SafeHtml
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'hr', 'span', 'div', 'font', 'sub', 'sup'];

    /** Removed with everything inside them (any other tag that is not allowed is removed but its text is kept). */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'base', 'noscript', 'template'];

    private const STYLES = ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'font-family', 'font-size'];

    /** The words of formatted text as plain lines (for an e-mail without formatting, or a one-line preview). */
    public static function toText(?string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h[1-3]|blockquote|pre)>/i'], "\n", (string) $html);

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)));
    }

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        if (! preg_match('/<[a-z!\/][^>]*>/i', $html)) {
            return $html;                                                      // plain text: left as written (its line breaks are shown as line breaks)
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return null;
        }
        self::walk($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private static function walk(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_COMMENT_NODE || $node->nodeType === XML_PI_NODE) {
                $parent->removeChild($node);

                continue;
            }
            if (! $node instanceof DOMElement) {
                continue;                                                      // text stays
            }
            $name = strtolower($node->nodeName);
            if (in_array($name, self::DROP, true)) {
                $parent->removeChild($node);

                continue;
            }
            self::walk($node);
            if (! in_array($name, self::TAGS, true)) {
                while ($node->firstChild) {                                    // an unknown tag: its content stays, the tag goes
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }
            self::attributes($node, $name);
        }
    }

    private static function attributes(DOMElement $el, string $name): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $key = strtolower($attr->name);
            $value = trim($attr->value);
            $keep = match (true) {
                $key === 'href' && $name === 'a' => (bool) preg_match('#^(https?://|mailto:)\S+$#i', $value),
                $key === 'style' => true,
                $name === 'font' && in_array($key, ['color', 'face', 'size'], true) => (bool) preg_match('/^[#\w\s,\-]+$/', $value),
                default => false,
            };
            if (! $keep) {
                $el->removeAttribute($attr->name);
            } elseif ($key === 'style') {
                $style = self::style($value);
                $style === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $style);
            }
        }
        if ($name === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function style(string $css): string
    {
        $keep = [];
        foreach (explode(';', $css) as $rule) {
            [$prop, $val] = array_pad(explode(':', $rule, 2), 2, '');
            $prop = strtolower(trim($prop));
            $val = trim($val);
            if (in_array($prop, self::STYLES, true) && $val !== '' && preg_match('/^[#\w\s%.,()\-"\']+$/', $val) && ! preg_match('/url\s*\(|expression|javascript/i', $val)) {
                $keep[] = "$prop: $val";
            }
        }

        return implode('; ', $keep);
    }
}
