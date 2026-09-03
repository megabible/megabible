<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * hub-src r2 — inline source markers for the book hub.
 *
 * Authors write "(a)" inside any intro text field (dating, scholarly_view,
 * summary, excerpt…) and give the matching source a "letter": "a" in the
 * book's sources[] array. These helpers turn each token into the same
 * superscript-letter anchor the reader's footnotes use:
 *
 *     <sup class="fn-markers"><a class="fn-marker src-marker"
 *          href="#source-{slug}">a</a></sup>
 *
 * THE GUARD: a token is only transformed when its letter is actually
 * defined for this book. "(a)" in prose with no letter "a" registered — or
 * "(P)", "(J/E)", "(see ch. 3)" — passes through untouched. That makes an
 * undefined letter a visible authoring bug (the literal "(a)" shows on the
 * page) rather than a silent one.
 *
 * Both methods return HTML, so the Blade side must print with {!! !!}.
 * That is safe because inline() escapes the whole string FIRST and only
 * then injects marker markup built from e()'d slugs; markdown() feeds the
 * text through the same Str::markdown the hub already trusts.
 */
class SourceMarkers
{
    /** Plain one-line fields (the infobox): escape, then mark. */
    public static function inline(?string $text, array $letters): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return self::replaceTokens(e($text), $letters);
    }

    /** Markdown prose fields (summary, authorship, excerpt). */
    public static function markdown(?string $text, array $letters): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Markdown first: "(a)" is plain text to the parser, so tokens
        // survive into the HTML, where the replacement finds them.
        return self::replaceTokens(Str::markdown($text), $letters);
    }

    /**
     * Swap every defined "(x)" token for a marker anchor. Any whitespace
     * before the token is eaten so the superscript hugs the last word —
     * the marker's own padding-left supplies the breathing room, exactly
     * as the reader's footnote letters do.
     */
    private static function replaceTokens(string $html, array $letters): string
    {
        return preg_replace_callback(
            '/\s*\(([a-z])\)/',
            function (array $m) use ($letters) {
                $letter = $m[1];
                if (! isset($letters[$letter])) {
                    return $m[0];   // not a registered letter — leave it be
                }
                $slug = e($letters[$letter]);

                return '<sup class="fn-markers"><a class="fn-marker src-marker"'
                    . ' href="#source-' . $slug . '">' . $letter . '</a></sup>';
            },
            $html
        );
    }
}