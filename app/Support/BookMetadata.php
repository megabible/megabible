<?php

namespace App\Support;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;
use Illuminate\Support\Facades\DB;

/**
 * BOOK METADATA  ·  one source of truth for canon display rules + verse counts
 * ---------------------------------------------------------------------------
 * Extracted verbatim from ActsController::show() so more than one page can
 * lean on the same rules without the logic drifting apart. The Acts feed and
 * the Pericope hub/board/feed all need to answer two questions:
 *
 *   1. "How many verses does each chapter of each book hold, per translation?"
 *      — the denominators a client needs to detect chapter/book completions
 *      and to size things. Keyed by OSIS id because that's what the client's
 *      localStorage (mbVigil.v1) keys by.
 *
 *   2. "How do I PRINT a reference for this book?" — the reader-label rules
 *      (BibleController::readerRef()) precomputed once per book so a client
 *      can rebuild "Psalm 151:3" or "Jude 5" without a round-trip, from a raw
 *      (osis, chapter, verse) it stored at write time.
 *
 * Both derive from ONE grouped query, memoized for the lifetime of the
 * request (see $memo). Web requests each get a fresh process under
 * php-fpm / mod_php / `artisan serve`, so the memo is effectively per-request
 * and can't go stale between a re-import and a page load. If this ever runs
 * under a long-lived worker (Octane, a queue), call flush() after an import.
 *
 * The output of chapterCounts() and displayMeta() is byte-for-byte identical
 * to the arrays ActsController used to build inline — same iteration order
 * (no orderBy added), same keys, same casts — so @json() renders the exact
 * same payload the Acts page shipped before this extraction.
 */
class BookMetadata
{
    /**
     * Request-lifetime memo of the single grouped query's derivations:
     *   ['counts' => …, 'maxCh' => …, 'books' => Collection]
     * Null until first computed. Cleared by flush().
     *
     * @var array<string,mixed>|null
     */
    private static ?array $memo = null;

    /**
     * Per-(book, translation, chapter) verse counts, keyed by OSIS id:
     *
     *   [ 'Rom' => [ 'kjv' => [ 1 => 32, 2 => 29, … ], 'web' => [ … ] ], … ]
     *
     * OSIS keys (not slugs) because the client's mbVigil.v1 keys by OSIS.
     *
     * @return array<string, array<string, array<int,int>>>
     */
    public static function chapterCounts(): array
    {
        return self::compute()['counts'];
    }

    /**
     * Per-book display metadata — BibleController::readerRef()'s rules,
     * precomputed once per book so a client never re-derives them:
     *
     *   name    the reader-level name. Override books (canon reader_labels,
     *           e.g. Five Psalms of David) use the override name ("Psalm");
     *           everyone else their own.
     *   slug    for building links.
     *   off     chapter display offset (Psalm 151..155 => +150). 0 for most.
     *   single  true for single-chapter books WITHOUT an override (Jude,
     *           Obadiah…) → the client prints "Jude 5", no chapter number.
     *           Override books always show their computed number.
     *   short   mobile short label (homepage's home_short_names). Null for
     *           reader-label override books, whose short form is a whole-book
     *           tile label ("Ps 151–155") that would be nonsense mid-reference.
     *
     * Only books that actually have verses imported appear (the same
     * `isset($counts[...])` gate the Acts feed used), so the map never lists a
     * book the client can't reach.
     *
     * @return array<string, array{name:string, slug:string, off:int, single:bool, short:?string}>
     */
    public static function displayMeta(): array
    {
        $c      = self::compute();
        $counts = $c['counts'];
        $maxCh  = $c['maxCh'];
        $books  = $c['books'];

        $short  = config('canon.home_short_names', []);    // slug => short label
        $colors = config('canon.section_colors', []);      // section => --tl- name
        $bySlug = self::sectionBySlug();                   // slug => canon section key

        $meta = [];
        foreach ($books as $bk) {
            if (! isset($counts[$bk->osis_id])) {
                continue;
            }
            $o = config("canon.reader_labels.{$bk->osis_id}");
            $meta[$bk->osis_id] = [
                'name'   => $o['name'] ?? $bk->name,
                'slug'   => $bk->slug,
                'off'    => (int) ($o['chapter_offset'] ?? 0),
                'single' => ! $o && ($maxCh[$bk->osis_id] ?? 1) <= 1,
                'short'  => $o ? null : ($short[$bk->slug] ?? null),

                // Pericope-cell fields (additive; the Acts feed ignores them):
                //   abbr  — the short label shown INSIDE the coloured cell, the
                //           same one the QuickNav button uses (DB short_name,
                //           e.g. "Rom", "1 Cor"). Override books (Five Psalms)
                //           get their reader name ("Psalm"), because their
                //           short_name is a whole-collection tile label that
                //           reads as nonsense mid-reference.
                //   color — the book's canon-section colour (a --tl- palette
                //           name), resolved via Book.section → section_colors,
                //           exactly like QuicknavComposer.
                'abbr'   => $o ? ($o['name'] ?? $bk->name) : ($bk->short_name ?: $bk->name),
                // Section comes from canon.php (sections => books lists), NOT
                // the books.section DB column: the DB values predate the
                // current canon section keys for the Second Testament, so
                // every ST lookup against section_colors missed and fell to
                // clay. canon.php is the display source of truth; the DB
                // column is only a fallback for a book canon.php doesn't list.
                'color'  => $colors[$bySlug[$bk->slug] ?? $bk->section] ?? 'clay',
            ];
        }

        return $meta;
    }

    /**
     * slug => canon section key ('romans' => 'pauline_epistles', …), inverted
     * once from config('canon.sections') and cached for the request. Display
     * grouping (and therefore colour) always follows canon.php, so a re-import
     * can never desynchronise the palette from the canon file.
     *
     * @return array<string,string>
     */
    private static function sectionBySlug(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (config('canon.sections', []) as $section => $def) {
            foreach (($def['books'] ?? []) as $slug) {
                $map[$slug] = $section;
            }
        }
        return $map;
    }

    /**
     * Run the one grouped query and build $counts + $maxCh + $books, memoized.
     * Iteration order is deliberately left to the database (no orderBy) so the
     * emitted arrays match the pre-extraction ActsController output exactly.
     *
     * @return array{counts: array, maxCh: array<string,int>, books: \Illuminate\Support\Collection}
     */
    private static function compute(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        // One grouped query: (book, translation, chapter) => max verse number.
        $rows = Verse::query()
            ->select('book_id', 'translation_id', 'chapter',
                     DB::raw('MAX(verse_number) as verses'))
            ->groupBy('book_id', 'translation_id', 'chapter')
            ->get();

        $txSlugs = Translation::all()->mapWithKeys(
            fn ($t) => [$t->id => strtolower($t->abbreviation)]
        );
        $books = Book::all()->keyBy('id');

        // osis => { txSlug: { chapter: verseCount } }
        $counts = [];
        // osis => highest chapter number seen anywhere (single-chapter test).
        $maxCh = [];
        foreach ($rows as $r) {
            $slug = $txSlugs[$r->translation_id] ?? null;
            $bk   = $books->get($r->book_id);
            if (! $slug || ! $bk) {
                continue;
            }

            $counts[$bk->osis_id][$slug][(int) $r->chapter] = (int) $r->verses;
            $maxCh[$bk->osis_id] = max($maxCh[$bk->osis_id] ?? 0, (int) $r->chapter);
        }

        return self::$memo = [
            'counts' => $counts,
            'maxCh'  => $maxCh,
            'books'  => $books,
        ];
    }

    /**
     * Drop the request-lifetime memo. Only needed under a long-lived worker
     * (Octane/queue) after a re-import changes the underlying verse table.
     * A no-op cost under normal web serving, where the process is short-lived.
     */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
