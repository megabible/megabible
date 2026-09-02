<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;
use App\Models\Heading;
use App\Models\Footnote;
use App\Models\SharedHeading;
use App\Models\OriginalToken;
use App\Support\ChapterLayout;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class BibleController extends Controller
{
    /**
     * VERSE ACROSS TRANSLATIONS  ·  JSON, feeds the Pericope card switcher.
     *
     * A pericope card stores its verse TEXT as a snapshot in one translation.
     * Switching translations on the board therefore needs the same reference
     * re-fetched. Given (book slug, chapter, ?v=range), this returns every
     * translation that actually carries that book+chapter+verse selection —
     * so no row the switcher offers can produce empty text — each with its
     * short code, name, year, and the joined verse text.
     *
     * Deliberately mirrors interlinear(): same ?v= grammar and sanity caps,
     * same "one query, then shape" style, same cache header (text changes
     * only on re-import). The verse range is queried by RAW book/chapter/verse
     * numbers (exactly what the card stored), so display offsets like the Five
     * Psalms of David never enter here.
     *
     * ?v=  "28"  or  "28-30"  (a single verse or a contiguous run).
     * Response:
     *   { "translations": [
     *       { "abbr":"kjv", "short":"KJV", "name":"King James Version",
     *         "year":1873, "text":"…verse 28…\n…verse 29…\n…verse 30…" },
     *       …
     *   ] }
     */
    public function verseTranslations(Request $request)
    {
        $b = Book::findBySlug((string) $request->query('book', ''));
        abort_if(! $b, 404, 'Book not found');

        $chapter = (int) $request->query('chapter');
        abort_if($chapter < 1, 400, 'Bad chapter');

        // Parse ?v= into a [v1, v2] span. Accepts "n" or "a-b"; swaps a
        // reversed range; caps the span so "1-99999" can't fan out a huge IN().
        $raw = trim((string) $request->query('v'));
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $raw, $m)) {
            $v1 = (int) $m[1];
            $v2 = (int) $m[2];
        } elseif (ctype_digit($raw)) {
            $v1 = $v2 = (int) $raw;
        } else {
            abort(400, 'No verses requested');
        }
        if ($v2 < $v1) { [$v1, $v2] = [$v2, $v1]; }
        $v2 = min($v2, $v1 + 200);

        // Every verse in this book+chapter+range across ALL translations, in
        // one grouped read. A translation only appears if it has ≥1 verse in
        // the range, so the switcher can never offer an empty selection.
        $rows = Verse::query()
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->whereBetween('verse_number', [$v1, $v2])
            ->orderBy('translation_id')
            ->orderBy('verse_number')
            ->get(['translation_id', 'verse_number', 'text']);

        $byTx = $rows->groupBy('translation_id');
        if ($byTx->isEmpty()) {
            return response()->json(['translations' => []]);
        }

        $translations = Translation::whereIn('id', $byTx->keys())
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($translations as $id => $t) {
            $rows2 = $byTx->get($id);
            $out[] = [
                'abbr'   => strtolower($t->abbreviation),
                'short'  => strtoupper($t->abbreviation),
                'name'   => $t->name,
                'year'   => $t->year_published,
                // Per-verse [number, text] pairs — feeds numbering, paging, and
                // the self-heal of legacy blob cards on the board.
                'verses' => $rows2->map(fn ($r) => [(int) $r->verse_number, $r->text])->values(),
                // Joined fallback, kept for the card's `text` field.
                'text'   => $rows2->pluck('text')->implode("\n"),
            ];
        }

        return response()
            ->json(['translations' => $out])
            ->header('Cache-Control', 'public, max-age=600');
    }

    /** Max translations shown side by side in the parallel view. */
    private const PARALLEL_MAX_COLUMNS = 2;
    /** Heading kinds that stay per-translation once a book adopts the shared set. */
    private const PER_TRANSLATION_KINDS = ['d'];

    public function showBook(string $translation, string $book): View
    {
        $t = Translation::findBySlug($translation);
        abort_if(! $t, 404, 'Translation not found');

        $b = Book::with(['intro', 'manuscripts', 'timelineEvents', 'sources'])->where('slug', $book)->first();
        abort_if(! $b, 404, 'Book not found');

        $chapters = Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->select('chapter')->distinct()->orderBy('chapter')
            ->pluck('chapter');

        abort_if($chapters->isEmpty(), 404, 'This book is not available in this translation');

        $otherTranslations = Translation::whereIn('id', function ($q) use ($b) {
                $q->select('translation_id')->from('verses')->where('book_id', $b->id)->distinct();
            })
            ->where('id', '!=', $t->id)
            ->orderBy('sort_order')->get();

        return view('bible.book', [
            'translation'       => $t,
            'book'              => $b,
            'chapters'          => $chapters,
            'otherTranslations' => $otherTranslations,
            'timeline'          => $this->buildTimeline($b, $t),
        ]);
    }

    public function showChapter(string $translation, string $book, int $chapter): View
    {
        $t = Translation::findBySlug($translation);
        abort_if(! $t, 404, 'Translation not found');

        $b = Book::findBySlug($book);
        abort_if(! $b, 404, 'Book not found');

        $verses = Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->orderBy('verse_number')->get();

        abort_if($verses->isEmpty(), 404, 'Chapter not found in this translation');

        $headings = $this->headingsFor($t, $b, $chapter);

        // Footnotes for this chapter: letter markers assigned in reading
        // order, grouped by verse for ChapterLayout, flattened for the
        // end-of-chapter list, and rolled up per source for the colophon.
        [$chapterFootnotes, $footnotesByVerse, $footnoteCredits] = $this->footnoteData($t, $b, $chapter);

        $layout = ChapterLayout::build($verses, $headings, $footnotesByVerse);

        $headingCredits = $this->headingCredits($headings);

        // Highest chapter number for this book in this translation — decides
        // whether the "next" arrow gets drawn.
        $maxChapter = (int) Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->max('chapter');

        // Display reference parts ("Psalm 151" for the Five Psalms of
        // David, "Genesis 2" for everyone else). See readerRef().
        [$refBook, $refChapter] = $this->readerRef($b, $chapter, $maxChapter);    

        // Other translations that ALSO have THIS exact chapter — so every row
        // in the switcher is guaranteed not to 404 when the reader picks it.
        // (Versification differs between traditions, e.g. Psalms, so scoping to
        // the chapter — not just the book — is the safe choice in the reader.)
        $otherTranslations = Translation::whereIn('id', function ($q) use ($b, $chapter) {
                $q->select('translation_id')->from('verses')
                  ->where('book_id', $b->id)
                  ->where('chapter', $chapter)
                  ->distinct();
            })
            ->where('id', '!=', $t->id)
            ->orderBy('sort_order')->get();

        return view('bible.chapter', [
            'translation' => $t,
            'book'        => $b,
            'chapter'     => $chapter,
            'verses'      => $verses,
            'layout'      => $layout,   // updated (june26) render sequence
            'maxChapter'  => $maxChapter,   // lets the view hide "1" on single-chapter books
            'refBook'     => $refBook,
            'refChapter'  => $refChapter,
            'otherTranslations' => $otherTranslations,
            // Verse numbers in this chapter that have original-language
            // tokens. Index-only DISTINCT on original_tokens' unique key —
            // cheap enough to run per request. Gates the flip button.
            'interlinearVerses' => OriginalToken::coveredLangs($b->id, $chapter),
            // Endpoint URL for this chapter's interlinear tokens — built here,
            // not in Blade, because @json() splits its argument on commas (to
            // support its optional flags/depth args), so any comma-bearing
            // expression inside @json compiles to mangled PHP.
            'interlinearUrl'    => route('bible.interlinear', [
                'translation' => strtolower($t->abbreviation),
                'book'        => $b->slug,
                'chapter'     => $chapter,
            ]),
            // Scrimmage jump target for the FAB's quill button, with a
            // __V__ placeholder the client swaps for the selected verse
            // number (the same sentinel-token pattern TypingController's
            // scrimUrlPattern() uses). Built here, not in Blade, for the
            // usual comma-in-json-directive reason — and with route(), so
            // a route rename can never strand it. Always valid: Challenge
            // resolves any verse that exists in the verses table, and the
            // reader is by definition displaying one that does.
            'scrimUrl'          => route('typing.scrimmage.verse', [
                't' => strtolower($t->abbreviation),
                'b' => $b->slug,
                'c' => $chapter,
                'v' => '__V__',
            ]),
            'headingCredits' => $headingCredits,
            'chapterFootnotes' => $chapterFootnotes,
            'footnoteCredits'  => $footnoteCredits,
            'nav'         => $this->chapterNav($t, $b, $chapter, $maxChapter),
        ]);
    }

    /**
     * Original-language tokens for a set of verses, as JSON — feeds the
     * Synthesis card backs. Translation is accepted in the URL only to
     * mirror the chapter URL shape (tokens are translation-independent).
     *
     * ?v= uses the reader's own selection syntax: "16", "3,8", "1-3,8".
     * Response shape (token arrays are positional to keep payloads small):
     *
     *   {
     *     langs:   { hbo: {name, rtl}, ... },          // from config
     *     credits: { tahot: {label, credit, url, license}, ... },
     *     verses:  { "16": { lang, source, tokens: [[surface, translit, gloss], ...] } }
     *   }
     */
    public function interlinear(Request $request, string $translation, string $book, int $chapter)
    {
        $b = Book::findBySlug($book);
        abort_if(! $b, 404, 'Book not found');

        // Parse ?v= — same grammar as the reader's parseParam(), with sanity
        // caps so a hostile "1-99999" can't turn into a giant query.
        $wanted = collect(explode(',', (string) $request->query('v')))
            ->flatMap(function ($part) {
                $part = trim($part);
                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                    $a = min((int) $m[1], (int) $m[2]);
                    $z = max((int) $m[1], (int) $m[2]);
                    return range($a, min($z, $a + 200));
                }
                return ctype_digit($part) ? [(int) $part] : [];
            })
            ->unique()->sort()->take(150)->values();

        abort_if($wanted->isEmpty(), 400, 'No verses requested');

        $tokens = OriginalToken::where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->whereIn('verse', $wanted)
            ->orderBy('verse')->orderBy('position')
            ->get(['verse', 'lang', 'surface', 'translit', 'gloss', 'source_key']);

        $verses = $tokens->groupBy('verse')->map(fn ($group) => [
            'lang'   => $group->first()->lang,
            'source' => $group->first()->source_key,
            'tokens' => $group->map(fn ($t) => [$t->surface, $t->translit, $t->gloss])->values(),
        ]);

        // Tokens only change on re-import, so let browsers keep them a while.
        return response()
            ->json([
                'langs'   => config('interlinear.languages'),
                'credits' => config('interlinear.sources'),
                'verses'  => $verses,
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function index(Request $request): View
    {
        // Fast slug → Book lookup so the view can resolve the slugs in config/canon.php.
        $books = Book::all()->keyBy('slug');

        // Point the homepage at the translation the reader last viewed (remembered by
        // the RememberTranslation middleware), falling back to KJV if the cookie is
        // absent or stale.
        $pref    = strtolower($request->cookie('reader_translation', 'kjv'));
        $primary = Translation::findBySlug($pref) ?? Translation::findBySlug('kjv');

        // All translations, ordered by priority: global (full-canon) editions first,
        // then sort_order within each tier. Used both to resolve a translation's URL
        // slug and to pick a sensible fallback when the reader's current translation
        // doesn't carry a given book.
        $translations = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        // Every (book, translation) pair that actually has verses, grouped by book.
        // A book may appear under several translations (Genesis in KJV + WEB) or just
        // one (Psalm 151 in WEB only; later, 1 Enoch in its own lone edition).
        $availableByBook = DB::table('verses')
            ->select('book_id', 'translation_id')
            ->distinct()
            ->get()
            ->groupBy('book_id');

        // For every book that exists in *some* translation, decide where a homepage
        // click should land:
        //   1. the reader's current translation, if it has the book (stay put), else
        //   2. the highest-priority translation that does (so the link never 404s).
        // Books absent from this map (no verses anywhere yet) fall through to a
        // dashed "soon" placeholder in the view.
        $linkTranslation = [];   // book_id => translation URL slug
        foreach ($availableByBook as $bookId => $rows) {
            $ids = $rows->pluck('translation_id')->all();

            $chosen = in_array($primary->id, $ids, true)
                ? $primary
                : $translations->first(fn ($t) => in_array($t->id, $ids, true));

            if ($chosen) {
                $linkTranslation[$bookId] = strtolower($chosen->abbreviation);
            }
        }

        return view('bible.index', [
            'testaments'      => config('canon.testaments'),
            'sections'        => config('canon.sections'),
            'books'           => $books,
            'linkTranslation' => $linkTranslation,
        ]);
    }

    public function showVerse(string $translation, string $book, int $chapter, int $verse): View
    {
        return $this->showChapter($translation, $book, $chapter);
    }

    /**
     * Headings for one rendered chapter, merged from two sources.
     *
     * A book is "adopted" once its translation's heading_set has ANY shared
     * heading for that book. The switch is per-book so a book-by-book rollout
     * never makes existing headings vanish before their shared replacements
     * are imported:
     *
     *   - Adopted book   → section headings (s/ms/mr/r/sr/sp) come ONLY from
     *     the shared set; the per-translation table contributes only its
     *     descriptive/Psalm titles (kind 'd'). No doubles.
     *   - Unadopted book → the per-translation table contributes EVERYTHING it
     *     has, exactly as before. Nothing changes until you import that book.
     *
     * Translations with heading_set = null are always "unadopted": they render
     * precisely what they render today.
     */
    public function headingsFor(Translation $t, Book $b, int $chapter): \Illuminate\Support\Collection
    {
        $set = $t->heading_set;

        // Shared headings for this chapter, and whether the book is adopted.
        $shared      = collect();
        $bookAdopted = false;

        if ($set) {
            $shared = SharedHeading::where('set_key', $set)
                ->where('book_id', $b->id)
                ->where('chapter', $chapter)
                ->get();

            // If this chapter has shared rows the book is clearly adopted; only
            // when it doesn't do we pay for a book-level existence check (covers
            // chapters that legitimately have no headings in an adopted book).
            $bookAdopted = $shared->isNotEmpty()
                ? true
                : SharedHeading::where('set_key', $set)->where('book_id', $b->id)->exists();
        }

        // Per-translation headings: titles only if adopted, otherwise all kinds.
        $own = Heading::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->when($bookAdopted, fn ($q) => $q->whereIn('kind', self::PER_TRANSLATION_KINDS))
            ->get();

        // Tag every heading with the key used to credit it in the colophon:
        //   - shared headings are credited by their set (e.g. 'en-standard')
        //   - per-translation headings by their own source_key (may be null,
        //     e.g. Psalm titles that need no separate credit)
        $shared->each(fn ($h) => $h->credit_key = $h->source_key ?: $h->set_key);
        $own->each(fn ($h) => $h->credit_key = $h->source_key);

        // Stable render order at each anchor verse: titles first, then major
        // sections, sections, references. Fixed-width string keys keep the
        // ordering unambiguous across the two tables.
        $rank = ['d' => 0, 'ms' => 1, 'mr' => 2, 's' => 3, 'sr' => 4, 'r' => 5, 'sp' => 6];

        return $own->concat($shared)
            ->sortBy(fn ($h) => sprintf(
                '%05d-%d-%d-%010d',
                (int) $h->before_verse,
                $rank[$h->kind] ?? 9,
                (int) $h->level,
                (int) $h->id,
            ))
            ->values();
    }

    /**
     * Turn a chapter's merged headings into a short attribution list for the
     * colophon: one entry per distinct source actually present on the page,
     * each with a display name, URL and count, resolved from
     * config/heading_sources.php. Headings with no credit_key (e.g. Psalm
     * titles that are simply part of the base text) are left out.
     */
    private function headingCredits(\Illuminate\Support\Collection $headings): \Illuminate\Support\Collection
    {
        $sources = config('heading_sources', []);

        return $headings
            ->filter(fn ($h) => ! empty($h->credit_key))
            ->groupBy('credit_key')
            ->map(function ($group, $key) use ($sources) {
                $meta = $sources[$key] ?? [];
                return [
                    'key'        => $key,
                    'name'       => $meta['name'] ?? $key,
                    'source_url' => $meta['source_url'] ?? null,
                    'license'    => $meta['license'] ?? null,
                    'count'      => $group->count(),
                ];
            })
            ->values();
    }

    /**
     * Everything the chapter view needs to render footnotes, from one query.
     *
     * Returns [chapterFootnotes, footnotesByVerse, footnoteCredits]:
     *
     *   chapterFootnotes  flat list for the end-of-chapter block, in reading
     *                     order: ['marker','verse','anchor','text'] per note.
     *   footnotesByVerse  verse number => [['marker' => 'a'], …] — handed to
     *                     ChapterLayout::build(), which attaches each verse's
     *                     markers to its last text fragment.
     *   footnoteCredits   colophon lines, one per distinct source_key on the
     *                     page, resolved from config/footnote_sources.php. A
     *                     NULL source_key credits the translation itself — a
     *                     translator's notes are part of the edition unless
     *                     stamped otherwise.
     *
     * Markers are per-chapter letters (a…z, then aa, ab…) assigned by position
     * in the chapter's reading order — see Footnote::marker().
     */
    private function footnoteData(Translation $t, Book $b, int $chapter): array
    {
        $notes = Footnote::forChapter($t->id, $b->id, $chapter)->get();

        $chapterFootnotes = [];
        $footnotesByVerse = [];
        foreach ($notes as $i => $note) {
            $marker = Footnote::marker($i);
            $chapterFootnotes[] = [
                'marker' => $marker,
                'verse'  => $note->verse_number,
                'anchor' => $note->anchor_text,
                'text'   => $note->text,
            ];
            $footnotesByVerse[$note->verse_number][] = ['marker' => $marker];
        }

        $sources = config('footnote_sources', []);

        $footnoteCredits = $notes
            ->groupBy(fn ($n) => $n->source_key ?? '')
            ->map(function ($group, $key) use ($sources, $t) {
                $meta = $key !== '' ? ($sources[$key] ?? []) : [];
                return [
                    'key'        => $key,
                    'name'       => $meta['name']       ?? ($key !== '' ? $key : $t->name),
                    'license'    => $meta['license']    ?? ($key !== '' ? null : $t->license),
                    'source_url' => $meta['source_url'] ?? ($key !== '' ? null : $t->source_url),
                    'count'      => $group->count(),
                ];
            })
            ->values();

        return [$chapterFootnotes, $footnotesByVerse, $footnoteCredits];
    }
   
    /**
     * Build the prev/next targets for the floating chapter-navigation arrows.
     *
     * MegaBible treats the book page as "page zero":
     *   - Chapter 1's "previous" arrow points back to the book hub.
     *   - The book hub's "next" arrow points forward into chapter 1.
     *   - There is NO cross-book navigation: the arrows simply disappear at the
     *     first chapter (no left) and the last chapter (no right).
     *
     * Pass $current = 0 for the book page (page zero), or 1..N for a chapter.
     * A null value on either side means "draw no arrow on that side".
     */
    private function chapterNav(Translation $t, Book $b, int $current, int $maxChapter): array
    {
        $trans = strtolower($t->abbreviation);

        $toChapter = fn (int $n) => route('bible.chapter', [
            'translation' => $trans, 'book' => $b->slug, 'chapter' => $n,
        ]);
        $toBook = route('bible.book', [
            'translation' => $trans, 'book' => $b->slug,
        ]);

        // LEFT arrow
        $prev = match (true) {
            $current <= 0  => null,     // page zero → start of book, no left arrow
            $current === 1 => $toBook,  // chapter 1 → back to the book hub
            default        => $toChapter($current - 1),
        };

        // RIGHT arrow (this also covers page zero → chapter 1).
        $next = $current < $maxChapter ? $toChapter($current + 1) : null;

        // At the LAST chapter there's no next chapter, so instead of an empty
        // right slot we drop in a "rewind to the book hub" button.
        //   - $current >= 1 excludes page zero: the hub never points to itself.
        //   - $maxChapter > 1 skips single-chapter books, where chapter 1's LEFT
        //     arrow already returns to the hub — no need for two buttons to the
        //     same place. Delete that clause if you'd rather always show it.
        $rewind = ($next === null && $current >= 1 && $maxChapter > 1) ? $toBook : null;

        return ['prev' => $prev, 'next' => $next, 'rewind' => $rewind];
    }

    /**
     * Reader-facing reference parts for a chapter: [$refBook, $refChapter].
     *
     * Normal books: the book name, plus the chapter number only when the
     * book is multi-chapter (so "Jude", but "Genesis 2"). A null
     * $refChapter means "render no chapter number".
     *
     * Books listed in config('canon.reader_labels') override BOTH parts:
     * Five Psalms of David renders as "Psalm 151"–"Psalm 155" so the
     * collection reads as a continuation of the Psalter. Override books
     * ALWAYS show their computed number — even when a translation carries
     * only one chapter of them (WEB's Psalm 151 must still read
     * "Psalm 151", not "Five Psalms of David"), which is why this takes
     * $maxChapter rather than testing it in Blade.
     *
     * URLs, routes, and the DB keep the real 1-based chapter numbers;
     * this is display only.
     */
    public function readerRef(Book $b, int $chapter, int $maxChapter): array
    {
        $o = config("canon.reader_labels.{$b->osis_id}");

        if ($o) {
            return [$o['name'], $chapter + ($o['chapter_offset'] ?? 0)];
        }

        return [$b->name, $maxChapter > 1 ? $chapter : null];
    }

    /**
     * Assemble the data for a book's Gantt-style timeline.
     *
     * Each book is drawn as a horizontal bar spanning its dating_start →
     * dating_end years. Bar colours come from the "groups" defined in this
     * book's hub JSON; the current book uses its own timeline_color override.
     * Linked historical events become vertical markers (labelled beneath the
     * chart). Returns null if there's nothing worth drawing.
     *
     * Every bar also carries a 'url' — the book-hub link the row should point
     * at. It follows the same rule the homepage uses (see index()): stay in
     * the CURRENT translation when it carries the book, otherwise fall back to
     * the highest-priority translation that does (global editions first, then
     * sort_order). null when NO translation has the book yet, which the view
     * renders as plain unlinked text — so a timeline click can never 404.
     *
     * Output shape (everything the Blade view needs, geometry pre-computed):
     *   [
     *     'ticks'  => [ ['pos'=>%, 'label'=>'70 AD'], ... ],
     *     'events' => [ ['pos'=>%, 'label'=>'Crucifixion', 'date_display'=>'c. 30 AD'], ... ],
     *     'groups' => [ ['label'=>'Gospels', 'color'=>'terracotta'], ... ],  // legend
     *     'books'  => [
     *         ['label','slug','url','color','date_display','current'(bool),
     *          'left'=>%, 'width'=>%, 'date_pos'=>%], ...
     *     ],
     *   ]
     */
    
    private function buildTimeline(Book $book, Translation $translation): ?array
    {
        $intro = $book->intro;
        if (! $intro) {
            return null;
        }

        // --- 1. Groups: which books appear + (for UNLAYERED books) their colour.
        $groups         = $intro->timeline_groups ?? [];
        $groupColorName = [];   // color => group label (legend candidates)
        $colorByOsis    = [];   // OSIS => group colour
        foreach ($groups as $g) {
            $color = $g['color'] ?? 'clay';
            $groupColorName[$color] = $g['label'] ?? '';
            foreach (($g['books'] ?? []) as $osis) {
                $colorByOsis[$osis] = $color;
            }
        }

        $osisToPlot = array_keys($colorByOsis);
        if (empty($groups) && ! empty($intro->timeline_books)) {
            $osisToPlot = $intro->timeline_books;
        }
        $osisToPlot[] = $book->osis_id;
        $osisToPlot = array_values(array_unique($osisToPlot));

        // --- 2. Resolve each OSIS to a bar of one or more segments ------------
        $eras            = config('timeline.eras', []);
        $bars            = [];
        $usedGroupColors = [];   // group colours actually drawn → group legend
        $usedEras        = [];   // era label => colour actually drawn → era legend

        foreach ($osisToPlot as $osis) {
            $b  = ($osis === $book->osis_id)
                ? $book
                : Book::with('intro')->where('osis_id', $osis)->first();
            $bi = $b?->intro;
            if (! $bi) {
                continue;
            }

            $isCurrent = $b->id === $book->id;
            $layers    = $bi->composition_layers ?? null;

            if (is_array($layers) && count($layers) > 0) {
                // LAYERED — era-coloured segments, no group colour, no trailing date.
                $segments = [];
                foreach ($layers as $ly) {
                    if (! isset($ly['start'], $ly['end'])) {
                        continue;
                    }
                    $s   = (int) $ly['start'];
                    $e   = (int) $ly['end'];
                    $era = $this->eraFor($s, $e, $eras);
                    if ($era) {
                        $usedEras[$era['label']] = $era['color'];
                    }
                    $segments[] = [
                        'start'   => $s,
                        'end'     => $e,
                        'color'   => $era['color'] ?? 'clay',
                        'label'   => $ly['label'] ?? '',
                        'tooltip' => $b->name . ' — ' . ($ly['full'] ?? $ly['label'] ?? '')
                                     . ' · ' . $this->layerDateLabel($s, $e),
                    ];
                }
                if (empty($segments)) {
                    continue;
                }
                $bars[] = [
                    'label'        => $b->name,
                    'slug'         => $b->slug,
                    'book_id'      => $b->id,
                    'current'      => $isCurrent,
                    'layered'      => true,
                    'segments'     => $segments,
                    'start'        => min(array_column($segments, 'start')),
                    'end'          => max(array_column($segments, 'end')),
                    'date_display' => null,
                    'date_pos'     => null,
                ];
            } else {
                // UNLAYERED — single group-coloured bar (unchanged behaviour).
                if ($bi->dating_start === null || $bi->dating_end === null) {
                    continue;
                }
                $s     = (int) $bi->dating_start;
                $e     = (int) $bi->dating_end;
                $color = $isCurrent
                    ? ($intro->timeline_color ?? $colorByOsis[$osis] ?? 'clay')
                    : ($colorByOsis[$osis] ?? 'clay');
                $usedGroupColors[$color] = true;

                $bars[] = [
                    'label'        => $b->name,
                    'slug'         => $b->slug,
                    'book_id'      => $b->id,
                    'current'      => $isCurrent,
                    'layered'      => false,
                    'segments'     => [[
                        'start'   => $s,
                        'end'     => $e,
                        'color'   => $color,
                        'label'   => '',
                        'tooltip' => $b->name . ' · ' . $this->layerDateLabel($s, $e),
                    ]],
                    'start'        => $s,
                    'end'          => $e,
                    'date_display' => $this->timelineRangeLabel($s, $e),
                    'date_pos'     => null,   // set once $pct is known
                ];
            }
        }

        if (count($bars) < 2) {
            return null;   // a one-bar chart isn't a timeline
        }

        // --- 2b. Links: point each bar at a translation that HAS the book -----
        // Same rule as the homepage grid (see index()): stay in the reader's
        // current translation when it carries the book; otherwise fall back to
        // the highest-priority translation that does (global editions first,
        // then sort_order); null when no translation has it yet, so the view
        // renders plain text instead of a link that would 404 — e.g. reading
        // 1 Enoch in Charles and clicking 2 Esdras, which Charles lacks.
        $fallbackOrder = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();

        // ONE grouped query answers "which translations contain each plotted
        // book?" — it rides the unique (translation, book, chapter, verse)
        // index, so it never touches verse rows themselves.
        $availableByBook = DB::table('verses')
            ->select('book_id', 'translation_id')
            ->whereIn('book_id', array_column($bars, 'book_id'))
            ->distinct()
            ->get()
            ->groupBy('book_id');

        foreach ($bars as &$bar) {
            $bar['url'] = null;

            $rows = $availableByBook->get($bar['book_id']);
            if (! $rows) {
                continue;   // no verses anywhere yet → unlinked label
            }

            $ids = $rows->pluck('translation_id')->all();

            $chosen = in_array($translation->id, $ids, true)
                ? $translation
                : $fallbackOrder->first(fn ($t) => in_array($t->id, $ids, true));

            if ($chosen) {
                $bar['url'] = route('bible.book', [
                    'translation' => strtolower($chosen->abbreviation),
                    'book'        => $bar['slug'],
                ]);
            }
        }
        unset($bar);

        // --- 3. Events --------------------------------------------------------
        $events = [];
        foreach ($book->timelineEvents as $ev) {
            if ($ev->date_sort !== null) {
                $events[] = [
                    'year'         => (int) $ev->date_sort,
                    'label'        => $ev->label,
                    'date_display' => $ev->date_display,
                ];
            }
        }

        // --- 4. Axis bounds ---------------------------------------------------
        $allYears = array_merge(
            array_column($bars, 'start'),
            array_column($bars, 'end'),
            array_column($events, 'year')
        );
        $dataMin = min($allYears);
        $dataMax = max($allYears);
        $pad     = max(1, (int) round(($dataMax - $dataMin) * 0.06));

        $min = $intro->timeline_start ?? ($dataMin - $pad);
        $max = $intro->timeline_end   ?? ($dataMax + $pad);
        if ($max <= $min) {
            $max = $min + 1;
        }
        $span = $max - $min;
        $pct  = fn ($year) => max(0.0, min(100.0, round((($year - $min) / $span) * 100, 2)));

        // --- 5. Sort by start year, then compute segment geometry -------------
        usort($bars, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        foreach ($bars as &$bar) {
            foreach ($bar['segments'] as &$seg) {
                $left        = $pct($seg['start']);
                $seg['left']  = $left;
                $seg['width'] = max(0.0, $pct($seg['end']) - $left);
            }
            unset($seg);
            if (! $bar['layered']) {
                $bar['date_pos'] = $pct($bar['end']);
            }
        }
        unset($bar);

        foreach ($events as &$ev) {
            $ev['pos'] = $pct($ev['year']);
        }
        unset($ev);

        // --- 6. Legends: only colours/eras actually drawn ---------------------
        $eraLegend = [];
        foreach ($eras as $era) {   // config order
            if (isset($usedEras[$era['label']])) {
                $eraLegend[] = ['label' => $era['label'], 'color' => $era['color']];
            }
        }
        $groupLegend = [];
        foreach ($groupColorName as $color => $label) {
            if (isset($usedGroupColors[$color])) {
                $groupLegend[] = ['label' => $label, 'color' => $color];
            }
        }

        return [
            'ticks'  => $this->timelineTicks($min, $max),
            'events' => $events,
            'legend' => array_merge($eraLegend, $groupLegend),
            'books'  => $bars,
            'text'   => $intro->timeline_text,
        ];
    }

    /**
     * Generate ~5–7 evenly spaced "nice" axis ticks between $min and $max.
     * The first and last ticks carry the era label (AD/BC); the ones between
     * are bare numbers to keep the axis uncluttered.
     */
    private function timelineTicks(int $min, int $max): array
    {
        $span = max(1, $max - $min);

        $targets = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000];
        $step = end($targets);
        foreach ($targets as $t) {
            if (intdiv($span, $t) <= 7) { $step = $t; break; }
        }

        $first = (int) (ceil($min / $step) * $step);
        $years = [];
        for ($y = $first; $y <= $max; $y += $step) {
            $years[] = $y;
        }
        if (empty($years)) {
            $years = [$min, $max];
        }

        $ticks = [];
        $last  = count($years) - 1;
        foreach ($years as $i => $y) {
            $label = ($i === 0 || $i === $last)
                ? $this->yearLabel($y)
                : (string) abs($y);
            $ticks[] = [
                'pos'   => round((($y - $min) / $span) * 100, 2),
                'label' => $label,
            ];
        }
        return $ticks;
    }

    /** Render a signed year as 70 AD / 586 BC */
    private function yearLabel(int $n): string
    {
        return $n < 0 ? abs($n) . ' BC' : $n . ' AD';
    }

    /**
     * Format a bar's date label as bare range numbers — no era, no "c.".
     * "65–75". Equal start/end collapse to one number ("70").
     *
     * abs() is deliberate: it mirrors what stripping "BC"/"AD"/"c." from the
     * display string would leave behind (bare digits, never a minus sign), and
     * for a BC range it keeps the earlier year first, e.g. 1000–900 BC.
     *
     * Era (AD/BC) is intentionally gone, so this reads correctly only on a
     * SINGLE-era timeline (all-AD Gospels, all-BC Torah). On a chart that mixes
     * eras the numbers alone are ambiguous — revisit here if you ever build one.
     */
    private function timelineRangeLabel(int $start, int $end): string
    {
        $start = abs($start);
        $end   = abs($end);

        return $start === $end
            ? (string) $start
            : "{$start}–{$end}";
    }

    /** Find the composition era a layer belongs to, by its midpoint year. */
    private function eraFor(int $start, int $end, array $eras): ?array
    {
        $mid = (int) floor(($start + $end) / 2);
        foreach ($eras as $era) {
            if ($mid >= $era['start'] && $mid < $era['end']) {
                return $era;
            }
        }
        return $eras[count($eras) - 1] ?? null;   // beyond the last boundary → last era
    }

    /** Range label with a single era suffix, e.g. "950–850 BC". Single-era only. */
    private function layerDateLabel(int $start, int $end): string
    {
        $suffix = ($start < 0 && $end <= 0) ? ' BC'
                : (($start >= 0 && $end >= 0) ? ' AD' : '');

        return $this->timelineRangeLabel($start, $end) . $suffix;
    }

    public function showParallel(string $translations, string $book, int $chapter): View
    {
        // Parse the comma-separated slugs: trim, lowercase, dedupe, cap at the column limit.
        $slugs = collect(explode(',', $translations))
            ->map(fn ($s) => strtolower(trim($s)))
            ->filter()
            ->unique()
            ->take(self::PARALLEL_MAX_COLUMNS)
            ->values();

        abort_if($slugs->count() < 2, 404, 'Parallel view needs at least two translations');

        $b = Book::findBySlug($book);
        abort_if(! $b, 404, 'Book not found');

        // Build a reading column for each slug. A translation that doesn't carry
        // THIS exact chapter is skipped (versification differs between traditions —
        // e.g. the Hebrew Psalm-title offset). If fewer than two survive, there's
        // nothing to compare, so 404.
        $columns = [];
        foreach ($slugs as $slug) {
            $t = Translation::findBySlug($slug);
            if (! $t) {
                continue;
            }

            $verses = Verse::where('translation_id', $t->id)
                ->where('book_id', $b->id)
                ->where('chapter', $chapter)
                ->orderBy('verse_number')->get();

            if ($verses->isEmpty()) {
                continue;
            }

            // Same merge the single-chapter view uses: this translation's own
            // titles + its set's shared section headings, credit-tagged. Per
            // column, because each translation may sit in a different set.
            $headings = $this->headingsFor($t, $b, $chapter);

            // Footnotes per column, exactly like the single-chapter view —
            // each translation carries its own notes, markers restart per
            // column, and the idPrefix keeps ids unique ("web-fn-a").
            [$chapterFootnotes, $footnotesByVerse, $footnoteCredits] = $this->footnoteData($t, $b, $chapter);

            $columns[] = [
                'translation'      => $t,
                'slug'             => $slug,
                'layout'           => ChapterLayout::build($verses, $headings, $footnotesByVerse),
                'verseCount'       => $verses->count(),
                'headingCredits'   => $this->headingCredits($headings),
                'chapterFootnotes' => $chapterFootnotes,
                'footnoteCredits'  => $footnoteCredits,
            ];
        }

        abort_if(count($columns) < 2, 404, 'Not enough translations carry this chapter to compare');

        // Chapter nav follows the FIRST column's translation — it's the edition
        // whose chapter range we trust for "is there a next / previous chapter?".
        $primary    = $columns[0]['translation'];
        $maxChapter = (int) Verse::where('translation_id', $primary->id)
            ->where('book_id', $b->id)
            ->max('chapter');

        [$refBook, $refChapter] = $this->readerRef($b, $chapter, $maxChapter);

        return view('bible.parallel', [
            'book'       => $b,
            'chapter'    => $chapter,
            'columns'    => $columns,
            'maxChapter' => $maxChapter,
            'refBook'    => $refBook,
            'refChapter' => $refChapter,
            'slugCsv'    => implode(',', array_column($columns, 'slug')),
            'nav'        => $this->parallelNav($b, $chapter, $maxChapter, $columns),
        ]);
    }

    /**
     * Prev/next targets for the parallel view's floating arrows.
     *
     * Parallel follows the FIRST column's translation, so it shares the single
     * chapter view's "page zero" model: chapter 1's LEFT arrow returns to that
     * edition's book hub, and the LAST chapter's right slot becomes a "rewind to
     * the hub" button (instead of an empty slot). Between those it just steps
     * through the parallel chapters within the same two translations.
     *
     * Parallel chapter URLs are built with url() rather than route('parallel', …)
     * on purpose: the comma in the slug list (kjv,web) is kept literal this way,
     * instead of being percent-encoded to %2C. The hub URL is a normal single-
     * translation route, so route() is fine (and correct) there.
     */
    private function parallelNav(Book $b, int $chapter, int $maxChapter, array $columns): array
    {
        $csv = implode(',', array_column($columns, 'slug'));
        $to  = fn (int $n) => url("/parallel/{$csv}/{$b->slug}/{$n}");

        // The hub of the first column's translation — the same page the title
        // link and the single-view toggle already point at.
        $toBook = route('bible.book', [
            'translation' => $columns[0]['slug'], 'book' => $b->slug,
        ]);

        // LEFT arrow: previous parallel chapter, or back to the hub from ch. 1.
        $prev = $chapter > 1 ? $to($chapter - 1) : $toBook;

        // RIGHT arrow: next parallel chapter; at the last chapter there is none,
        // so the slot becomes a rewind to the hub. The $maxChapter > 1 guard
        // skips single-chapter books, where the LEFT arrow already returns to the
        // hub and a second button to the same place would be redundant.
        $next   = $chapter < $maxChapter ? $to($chapter + 1) : null;
        $rewind = ($next === null && $maxChapter > 1) ? $toBook : null;

        return ['prev' => $prev, 'next' => $next, 'rewind' => $rewind];
    }
}