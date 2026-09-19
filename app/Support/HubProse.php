<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * hub-xref r1 — the book hub's prose pipeline.
 *
 * One entry point for every markdown prose field on the hub (summary,
 * excerpt, authorship_note). Runs four passes IN THIS ORDER:
 *
 *   1. Str::markdown         — authors write markdown; "[text](ref:…)"
 *                              becomes a plain <a href="ref:…">, and
 *                              "{{grc|…}}" tokens ride through as text.
 *   2. SourceMarkers::tokens — "(a)" → superscript source markers. Runs
 *                              BEFORE the lang pass so a stray "(a)" inside
 *                              a definition breaks that token VISIBLY (the
 *                              lang regex refuses angle brackets) instead of
 *                              smuggling <sup> markup into an attribute.
 *   3. rewriteRefs           — <a href="ref:…"> → real reader/hub links
 *                              carrying data-xref-* (phase 2's popover hooks).
 *   4. langTokens            — {{lang|word|translit|definition}} → tappable
 *                              .orig-word spans carrying data-orig-*.
 *
 * REF TARGET GRAMMAR — no spaces. CommonMark ends a link destination at the
 * first whitespace, so "ref:Acts 18:25" would not even parse as a link; the
 * book is therefore glued to the reference with a dot (the OSIS convention):
 *
 *   ref:Mark              → the book's hub page
 *   ref:Mark.3            → chapter 3 (no verse selection, no popover)
 *   ref:Acts.18:25        → chapter 18, verse 25 selected in Focus mode
 *   ref:John.1:19-34      → a verse range
 *   ref:Acts.1:2,5,8      → a verse list (the reader's full ?v= grammar)
 *   ref:John.2:1-11:57    → cross-chapter range: links to 2:1 only — the
 *                           reader is single-chapter (ReferenceLinker's rule)
 *
 * "Book" is anything ReferenceLinker's map resolves: an OSIS id (Rev), a
 * slug (apocalypse-of-john), or a one-word name (John). Multi-word books
 * use the slug form, e.g. ref:song-of-solomon.2:4.
 *
 * THE GUARD, same philosophy as SourceMarkers: an unresolvable ref renders
 * its display text UNLINKED — the page never ships a dead link — and the
 * importer's lint pass (which calls lintTarget below, so the two can never
 * drift apart) shouts at import time. A malformed or unknown-language
 * {{…}} token is left literally visible on the page.
 *
 * Both entry points return HTML, so Blade must print with {!! !!}. Safe for
 * the same reasons SourceMarkers is: markdown escapes the text first, and
 * every author-supplied fragment lifted into an attribute is entity-decoded
 * and then re-escaped with e().
 */
class HubProse
{
    public static function render(?string $text, array $letters, string $translationSlug): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $html = Str::markdown($text);
        $html = SourceMarkers::tokens($html, $letters);
        $html = self::rewriteRefs($html, strtolower($translationSlug));
        $html = self::langTokens($html);

        return $html;
    }

    /* ================================================================
       Pass 3 — cross-reference links
       ================================================================ */

    private static function rewriteRefs(string $html, string $from): string
    {
        // CommonMark emits exactly this shape for [text](ref:…) — href is
        // the only attribute. The inner HTML may itself contain markdown
        // output (em, sup…), hence the non-greedy dot-all body.
        return preg_replace_callback(
            '/<a href="ref:([^"]*)">(.*?)<\/a>/su',
            function (array $m) use ($from) {
                $anchor = self::refAnchor($m[1], $from);

                return $anchor !== null
                    ? $anchor . $m[2] . '</a>'
                    : $m[2];                 // unresolvable → unlink, keep text
            },
            $html
        );
    }

    /**
     * The opening <a …> tag for one ref target — or null when the target is
     * malformed, names an unknown book, or no edition on the site carries
     * the passage (the three cases lintTarget() names at import time).
     */
    private static function refAnchor(string $target, string $from): ?string
    {
        $ref = self::parseTarget($target);
        if ($ref === null) {
            return null;
        }

        $slug = ReferenceLinker::bookSlug($ref['book']);
        if ($slug === null) {
            return null;
        }

        // Which edition can actually serve it? Hub links (no chapter) probe
        // chapter 1 — a book with any text at all has one. Same fallback
        // chain as the reader's heading links: current edition → remembered
        // cookie → globals → sort order.
        $edition = ReferenceLinker::editionSlug($slug, $ref['chapter'] ?? 1, $from);
        if ($edition === null) {
            return null;
        }

        $url = '/bible/' . rawurlencode($edition) . '/' . $slug;
        if ($ref['chapter'] !== null) {
            $url .= '/' . $ref['chapter'];
            if ($ref['v'] !== null) {
                $url .= '?v=' . $ref['v'];
            }
        }

        $attrs = 'class="xref-link" href="' . e($url) . '"';

        // Crossing editions is worth announcing — a hover title now, the
        // popover's edition badge in phase 2. Mirrors ReferenceLinker.
        if ($edition !== $from) {
            $attrs .= ' title="' . e(ReferenceLinker::editionName($edition) ?? $edition) . '"'
                    . ' data-xref-edition="' . e(ReferenceLinker::editionShort($edition) ?? $edition) . '"';
        }

        // Popover hooks: only refs with a verse selection get them.
        // data-xref-v is the FIRST contiguous run (the verse-translations
        // endpoint takes "n" / "n-n"); the href's ?v= keeps the full list.
        if ($ref['chapter'] !== null && $ref['v1'] !== null) {
            $attrs .= ' data-xref-book="' . e($slug) . '"'
                    . ' data-xref-chapter="' . $ref['chapter'] . '"'
                    . ' data-xref-v="' . $ref['v1'] . ($ref['v2'] !== null ? '-' . $ref['v2'] : '') . '"';
        }

        return '<a ' . $attrs . '>';
    }

    /**
     * "Book" / "Book.C" / "Book.C:spec" → parts, or null when malformed.
     *
     * Returns:
     *   book     the raw book token (resolution is the caller's job)
     *   chapter  int|null
     *   v        the full ?v= string (list allowed) or null
     *   v1, v2   the first contiguous run, for the popover fetch; v2 is
     *            null for a single verse or a cross-chapter range
     */
    private static function parseTarget(string $target): ?array
    {
        // Authors pasting from the excerpts sometimes carry en/em dashes in;
        // normalise them to the ASCII hyphen the ?v= grammar expects.
        $target = str_replace(["\u{2013}", "\u{2014}"], '-', trim($target));

        if (! preg_match('/^([A-Za-z0-9-]+?)(?:\.(\d+)(?::([\d,:-]+))?)?$/', $target, $m)) {
            return null;
        }

        $book    = $m[1];
        $chapter = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        $spec    = $m[3] ?? '';

        if ($spec === '') {
            return ['book' => $book, 'chapter' => $chapter, 'v' => null, 'v1' => null, 'v2' => null];
        }

        // Validate segment by segment: "5" or "5-8"; the FIRST segment may
        // also be a cross-chapter "1-11:57". Anything else is malformed.
        $segments = explode(',', $spec);
        $cross    = false;

        foreach ($segments as $i => $seg) {
            if (preg_match('/^\d+$/', $seg)) {
                continue;
            }
            if (preg_match('/^\d+-\d+$/', $seg)) {
                continue;
            }
            if ($i === 0 && preg_match('/^\d+-\d+:\d+$/', $seg)) {
                $cross = true;
                continue;
            }

            return null;
        }

        $first = $segments[0];
        $v1    = (int) $first;               // leading digits, so "1-11:57" → 1
        $v2    = null;

        if (! $cross && str_contains($first, '-')) {
            [$a, $b] = array_map('intval', explode('-', $first, 2));
            if ($b < $a) {
                [$a, $b] = [$b, $a];
            }
            $v1 = $a;
            $v2 = $b;
        }

        // Cross-chapter: the reader is single-chapter, so both the link and
        // the fetch carry only the start verse.
        $v = $cross ? (string) $v1 : $spec;

        return ['book' => $book, 'chapter' => $chapter, 'v' => $v, 'v1' => $v1, 'v2' => $v2];
    }

    /**
     * Import-time lint: WHY a target won't link, as a human sentence — or
     * null when it resolves. Lives here, on the same parseTarget/resolution
     * path the renderer uses, so the lint and the page can never disagree.
     */
    public static function lintTarget(string $target): ?string
    {
        $ref = self::parseTarget($target);
        if ($ref === null) {
            return 'malformed (expected Book, Book.C, or Book.C:V[-V2][,V…])';
        }

        $slug = ReferenceLinker::bookSlug($ref['book']);
        if ($slug === null) {
            return "unknown book '{$ref['book']}'";
        }

        $chapter = $ref['chapter'] ?? 1;
        if (ReferenceLinker::editionSlug($slug, $chapter, '') === null) {
            return "no edition on the site carries {$slug} {$chapter}";
        }

        return null;
    }

    /* ================================================================
       Pass 4 — original-language words
       ================================================================ */

    /**
     * {{lang|word|translit|definition}} → a tappable span. The word shows;
     * translit + definition ride in data attributes for phase 2's popover.
     * Language codes resolve against config/interlinear.php, which also
     * supplies the display name and the rtl flag — adding a language there
     * makes it work here, no code change.
     *
     * The field character class refuses | { } < > — so a nested token, an
     * unterminated token, or a token corrupted by an earlier pass (see the
     * ordering note in the class docblock) simply fails to match and stays
     * literally visible: fail visible, never corrupt.
     */
    private static function langTokens(string $html): string
    {
        $langs = config('interlinear.languages', []);

        return preg_replace_callback(
            '/\{\{([a-z]{2,3})\|([^|{}<>]+)\|([^|{}<>]*)\|([^|{}<>]+)\}\}/u',
            function (array $m) use ($langs) {
                [$all, $code, $word, $translit, $def] = $m;

                if (! isset($langs[$code])) {
                    return $all;             // unknown language → visible bug
                }

                // Markdown already entity-escaped the text (& → &amp; …);
                // decode, then e() once, so attributes are escaped exactly
                // one time whatever the author typed.
                $dec      = fn (string $s) => trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $word     = $dec($word);
                $translit = $dec($translit);
                $def      = $dec($def);

                if ($word === '' || $def === '') {
                    return $all;             // empty word/definition → visible bug
                }

                $meta = $langs[$code];
                $dir  = ! empty($meta['rtl']) ? ' dir="rtl"' : '';

                return '<span class="orig-word" lang="' . e($code) . '"' . $dir
                    . ' tabindex="0" role="button"'
                    . ' data-orig-langname="' . e($meta['name'] ?? $code) . '"'
                    . ($translit !== '' ? ' data-orig-translit="' . e($translit) . '"' : '')
                    . ' data-orig-def="' . e($def) . '">'
                    . e($word) . '</span>';
            },
            $html
        );
    }
}
