<?php

namespace App\Support;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;

/**
 * Turns the plain text of a parallel-reference heading (kinds r / mr / sr) into
 * HTML where each scripture reference links to that passage in the reader, with
 * the verses pre-selected via the focus-mode ?v= param.
 *
 *   "(Ruth 4:18–22; Luke 3:23–38)"
 *     → (<a href="/bible/kjv/ruth/4?v=18-22">Ruth 4:18–22</a>;
 *        <a href="/bible/kjv/luke/3?v=23-38">Luke 3:23–38</a>)
 *
 * EDITIONS: a link stays in the edition you're reading WHENEVER THAT EDITION
 * HAS THE TARGET — so a reference in the KJV column points at KJV, the WEB
 * column at WEB. But cross-references now routinely cross edition boundaries:
 * Jude 1:14 (KJV/WEB) cites 1 Enoch 1:9, which only exists in Charles; 1 Enoch
 * 1:9 cites back to Jude, which Charles doesn't have. Stamping the rendering
 * translation onto those links produced /bible/web/1-enoch/… and
 * /bible/charles/jude/… — both 404s. editionFor() picks the right edition per
 * link instead; see its docblock for the priority chain.
 *
 * MODE: 'reader' (default) builds /bible/… links with a ?v= selection. 'vigil'
 * builds /extras/vigil/… links WITHOUT ?v= — the vigil chapter view has no
 * verse-selection concept, so it just lands on the chapter. Both modes resolve
 * the same references; only the URL shape differs.
 *
 * Everything that isn't a resolvable reference — parentheses, semicolons, an
 * unknown book name, a book no edition on the site carries — is passed through
 * as escaped plain text, so the heading never renders worse than before.
 */
class ReferenceLinker
{
    /** normalized book name/short/slug → slug. Built once per request. */
    private static ?array $bookMap = null;

    /** slug → Book model, so we can reach the id for the verse probes. */
    private static ?array $books = null;

    /** Every edition, globals first then sort_order, keyed by lowercase slug. */
    private static ?array $editions = null;

    /** Memo for editionFor(): "from|book|chapter" → edition slug, or null. */
    private static array $resolved = [];

    /** URL prefix per mode; the vigil lives under the typing easter-egg path. */
    private const PREFIXES = [
        'reader' => '/bible/',
        'vigil'  => '/extras/vigil/',
    ];

    /**
     * A reference token: an optional leading 1–4, a capitalized book name
     * (possibly multi-word, e.g. "Song of Solomon"), then chapter:verse with an
     * optional range. The range dash may be hyphen, en dash (–) or em dash (—).
     * A trailing ":verse" catches the rare cross-chapter range (C:V–C:V).
     */
    private const REF_RE =
        '/((?:[1-4]\s+)?[A-Z][A-Za-z]+(?:\s+(?:of\s+)?[A-Z][A-Za-z]+)*?)\s+(\d+):(\d+)(?:\s*[\x{2013}\x{2014}-]\s*(\d+)(?::(\d+))?)?/u';

    /**
     * @param string $text            the heading's plain text
     * @param string $translationSlug the reader's translation slug (e.g. "kjv")
     * @param string $mode            'reader' (default) | 'vigil'
     * @return string HTML with references linked; safe to echo unescaped.
     */
    public static function linkify(string $text, string $translationSlug, string $mode = 'reader'): string
    {
        self::boot();

        $prefix = self::PREFIXES[$mode] ?? self::PREFIXES['reader'];
        $vigil  = ($mode === 'vigil');
        $from   = strtolower($translationSlug);

        if (! preg_match_all(self::REF_RE, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return e($text);                       // no references found → plain text
        }

        $out  = '';
        $last = 0;

        foreach ($matches as $m) {
            $whole  = $m[0][0];
            $offset = $m[0][1];

            // Escaped text between the previous match and this one.
            $out .= e(substr($text, $last, $offset - $last));
            $last = $offset + strlen($whole);

            $bookName = $m[1][0];
            $chapter  = (int) $m[2][0];
            $vStart   = (int) $m[3][0];
            $vEnd     = (isset($m[4]) && $m[4][0] !== '') ? (int) $m[4][0] : null;
            $isCross  = (isset($m[5]) && $m[5][0] !== '');     // C:V–C:V present

            $slug = self::$bookMap[self::normalize($bookName)] ?? null;

            if ($slug === null) {                  // unknown book → leave as text
                $out .= e($whole);
                continue;
            }

            // WHICH EDITION can actually serve this passage? Null = nothing on
            // the site has it, so don't build a link we know is a 404.
            $edition = self::editionFor($slug, $chapter, $from);

            if ($edition === null) {
                $out .= e($whole);
                continue;
            }

            $url = $prefix . rawurlencode($edition) . '/' . $slug . '/' . $chapter;

            // The vigil has no verse selection, so it stops at the chapter.
            // The reader appends ?v= (single-chapter focus, so a cross-chapter
            // range links to its start verse; a normal range → "start-end").
            if (! $vigil) {
                if ($isCross || $vEnd === null) {
                    $vParam = (string) $vStart;
                } else {
                    $vParam = $vStart . '-' . $vEnd;
                }
                $url .= '?v=' . $vParam;
            }

            // Crossing editions is worth announcing on hover — a reader in WEB
            // clicking through to Charles' 1 Enoch shouldn't wonder why the
            // masthead changed. Same-edition links carry no title, as before.
            $title = $edition !== $from
                ? ' title="' . e(self::$editions[$edition]->name) . '"'
                : '';

            $out .= '<a class="xref-link" href="' . e($url) . '"' . $title . '>' . e($whole) . '</a>';
        }

        $out .= e(substr($text, $last));           // trailing text after last match
        return $out;
    }

    /**
     * Pick the edition a single cross-reference link should point at, in
     * priority order:
     *
     *   1. The edition being rendered, if it holds that book AND chapter. This
     *      is the overwhelmingly common case, and it's what keeps each parallel
     *      column linking within itself.
     *   2. The reader's remembered edition (the reader_translation cookie, set
     *      by RememberTranslation). So a WEB reader who wanders into Charles'
     *      1 Enoch is sent back to WEB for Jude, not to the site default.
     *   3. Any edition that holds it — globals (full-canon) first, then
     *      sort_order. Identical ordering to SearchController::translationFor(),
     *      so search and cross-references never disagree about the "best"
     *      edition for a book.
     *   4. None → null, and the caller renders plain text.
     *
     * Tested at CHAPTER granularity because the chapter is the URL segment that
     * 404s; a ?v= pointing past the end of a chapter merely fails to highlight.
     *
     * Memoized on "from|book|chapter", so a heading citing the same book twice
     * (or a page full of Psalms references) probes the database once.
     */
    private static function editionFor(string $bookSlug, int $chapter, string $from): ?string
    {
        $memo = $from . '|' . $bookSlug . '|' . $chapter;

        if (array_key_exists($memo, self::$resolved)) {
            return self::$resolved[$memo];
        }

        $book = self::$books[$bookSlug] ?? null;
        if ($book === null) {
            return self::$resolved[$memo] = null;
        }

        $editions = self::editions();

        // Build the candidate queue. Array keys dedupe, and ??= means an
        // edition already queued at a higher priority is never demoted.
        $queue = [];
        if (isset($editions[$from])) {
            $queue[$from] = $editions[$from];
        }

        $cookie = strtolower((string) request()->cookie('reader_translation', ''));
        if ($cookie !== '' && isset($editions[$cookie])) {
            $queue[$cookie] ??= $editions[$cookie];
        }

        foreach ($editions as $slug => $t) {
            $queue[$slug] ??= $t;
        }

        foreach ($queue as $slug => $t) {
            $has = Verse::where('translation_id', $t->id)
                ->where('book_id', $book->id)
                ->where('chapter', $chapter)
                ->exists();

            if ($has) {
                return self::$resolved[$memo] = $slug;
            }
        }

        return self::$resolved[$memo] = null;
    }

    /** Every edition, globals first then sort_order, keyed by lowercase slug. */
    private static function editions(): array
    {
        if (self::$editions !== null) {
            return self::$editions;
        }

        self::$editions = Translation::query()
            ->orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get()
            ->keyBy(fn (Translation $t) => strtolower($t->abbreviation))
            ->all();

        return self::$editions;
    }

    /** Build the book lookup once: name, short and slug all resolve to the slug. */
    private static function boot(): void
    {
        if (self::$bookMap !== null) {
            return;
        }

        $map   = [];
        $books = [];

        foreach (Book::all() as $b) {
            $books[$b->slug] = $b;

            // short_name is the seeded column (short is kept in case a model
            // accessor exposes it under that name); nulls are skipped below.
            foreach ([$b->name, $b->short_name, $b->short, $b->slug] as $key) {
                if (! empty($key)) {
                    $map[self::normalize($key)] = $b->slug;
                }
            }
        }

        // Spellings the reference data uses that aren't a book's canonical
        // name/short. Only fill if not already resolved, so real books win.
        $aliases = [
            'song'          => 'song-of-solomon',
            'song of songs' => 'song-of-solomon',
            'canticles'     => 'song-of-solomon',
            'psalm'         => 'psalms',
        ];
        foreach ($aliases as $name => $slug) {
            $key = self::normalize($name);
            if (! isset($map[$key])) {
                $map[$key] = $slug;
            }
        }

        self::$bookMap = $map;
        self::$books   = $books;
    }

    /** lowercase, drop periods, collapse whitespace. */
    private static function normalize(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('.', '', mb_strtolower($s))));
    }
}