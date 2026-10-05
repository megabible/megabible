<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;
use App\Models\Heading;
use App\Models\Footnote;
use App\Models\SharedHeading;
use App\Models\OriginalToken;
use App\Models\ChapterAnimation;
use App\Support\ChapterLayout;
use App\Support\BookMetadata;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // hub-qn r1: the h1 QuickNav trigger draws this many chapter cells.
        // max(), not count() — a gap chapter would otherwise shrink the grid.
        $maxChapter = (int) $chapters->max();

        // bk-seen r1: devices seen in this book over the rolling seven-day
        // window (today + the previous six), summed from the anonymous daily
        // counters. Cached briefly per book — the pill is a vibe, not a
        // ledger. Raw number only in the cache; nothing route() produced.
        $readerCount = (int) Cache::remember(
            'book-visits:' . $b->osis_id,
            now()->addMinutes(10),
            fn () => DB::table('book_visits')
                ->where('osis', $b->osis_id)
                ->where('visit_date', '>=', now()->subDays(6)->toDateString())
                ->sum('hits')
        );

        // bk-seen r1: a book may carry its own reader word (config
        // canon.reader_words — "Ben Adam" for Genesis, subreddit-style).
        // Unlisted books fall back to reader/readers, pluralised against
        // the live count so "1 readers" never renders.
        $readerWord = config('canon.reader_words.' . $b->slug)
            ?? Str::plural('reader', $readerCount);

        $otherTranslations = Translation::whereIn('id', function ($q) use ($b) {
                $q->select('translation_id')->from('verses')->where('book_id', $b->id)->distinct();
            })
            ->where('id', '!=', $t->id)
            ->orderBy('sort_order')->get();

        // hub-src r2: letter => slug map for the inline "(a)" source markers,
        // read off the pivot (the importer already policed format and
        // duplicates). excerptSource is the resolved Source model for the
        // excerpt's attribution line — null when unset or not in this
        // book's list (the importer warned at import time).
        $sourceLetters = [];
        foreach ($b->sources as $s) {
            if ($s->pivot->letter) {
                $sourceLetters[$s->pivot->letter] = $s->slug;
            }
        }

        return view('bible.book', [
            'translation'       => $t,
            'book'              => $b,
            'chapters'          => $chapters,
            'maxChapter'        => $maxChapter,    // hub-qn r1
            'readerCount'       => $readerCount,   // bk-seen r1
            'readerWord'        => $readerWord,    // bk-seen r1
            'otherTranslations' => $otherTranslations,
            'timeline'          => $this->buildTimeline($b, $t),
            'sourceLetters'     => $sourceLetters,
            'excerptSource'     => $b->intro?->excerpt_source
                ? $b->sources->firstWhere('slug', $b->intro->excerpt_source)
                : null,
        ]);
    }

    /**
     * bk-seen r1 — THE "SEEN" BEACON  ·  POST /bible/seen
     *
     * A device opened some part of a book today (reader, vigil, or a scrim
     * on one of its verses). Bump the anonymous daily counter and answer
     * 204 — no body, nothing to render, the client fires and forgets.
     *
     * Dedup is CLIENT-side: public/js/book-seen.js keeps a per-book
     * "already counted today" date in localStorage (mbSeen.v1) and fires at
     * most once per book per day per device. Spoofable by clearing storage
     * — and fine: like scrim_plays, this is a counter with no prize
     * attached, and the pill's number is openly approximate.
     *
     * NOTHING PERSONAL BY CONSTRUCTION: no IP, no hash, no cookie — the
     * row is (osis, date, hits) and nothing else.
     */
    public function seen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'osis' => 'required|string|max:16',
        ]);

        // Only real books count. Silent 204 either way — it's a counter,
        // not an API; bad input earns nothing, including an error to probe.
        if (! Book::where('osis_id', $data['osis'])->exists()) {
            return response()->json([], 204);
        }

        // One atomic upsert — the scrim_plays pattern: race-proof under
        // concurrent beacons, no read-modify-write window.
        DB::statement(
            'INSERT INTO book_visits (osis, visit_date, hits, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE hits = hits + 1, updated_at = NOW()',
            [$data['osis'], now()->toDateString()]
        );

        return response()->json([], 204);
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

        // Watch mode (watch r1): the default (lowest sort_order) live video
        // for this chapter, in ANY edition — the eyeball shows wherever an
        // animation exists, and its link lands in the video's own script
        // edition, exactly like the homepage's fallback links. One indexed
        // query + one row; null keeps the eyeball out of the folder.
        $watchDefault = ChapterAnimation::live()
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->orderBy('sort_order')->orderBy('id')
            ->first();

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
            // Pericope marks (marks r1): the book's canon-section palette
            // name for the underline color. colorFor() is config-only — it
            // deliberately avoids displayMeta(), whose verse-count query
            // has no business running on every chapter load.
            'sectionColor'     => BookMetadata::colorFor($b),
            // Watch URL for the head folder's eyeball; null = no app drawn.
            // Built here, not in Blade — route() args carry commas, and a
            // comma-bearing expression must never sit inside a directive.
            'watchUrl'         => $watchDefault ? route('bible.watch', [
                'translation' => strtolower($watchDefault->translation->abbreviation),
                'book'        => $b->slug,
                'chapter'     => $chapter,
            ]) : null,            
            'nav'         => $this->chapterNav($t, $b, $chapter, $maxChapter),
        ]);
    }

    /**
     * WATCH MODE  ·  /bible/{t}/{b}/{c}/watch  ·  watch r1
     *
     * The chapter's animation above the chapter's text. The text is ALWAYS
     * the video's own script edition — the program's first rule is that the
     * voiceover follows one translation exactly, so page and player can
     * never disagree. If the URL names a different edition, this redirects
     * to the canonical one rather than showing mismatched text.
     *
     * Video choice, in order:
     *   1. ?video={id}  an explicit alternate picked from the page's list;
     *   2. the first live video whose script matches the URL's edition;
     *   3. the chapter's default (lowest sort_order, then id).
     *
     * Step 3 (cue sync) builds on this page without touching this method:
     * the cues ride the model, and the flow partial already renders every
     * verse with data-verse + id anchors.
     */
    public function watchChapter(Request $request, string $translation, string $book, int $chapter)
    {
        $t = Translation::findBySlug($translation);
        abort_if(! $t, 404, 'Translation not found');

        $b = Book::findBySlug($book);
        abort_if(! $b, 404, 'Book not found');

        // Every live video for this chapter, default-first, any edition.
        $videos = ChapterAnimation::liveFor($b->id, $chapter);
        abort_if($videos->isEmpty(), 404, 'No animation for this chapter yet');
        $videos->load('translation');

        // Explicit pick beats edition match beats default.
        $video = null;
        if (($vid = (int) $request->query('video')) > 0) {
            $video = $videos->firstWhere('id', $vid);
        }
        $video = $video
            ?? $videos->first(fn ($v) => $v->translation_id === $t->id)
            ?? $videos->first();

        // Canonical URL carries the video's own edition. ?video= survives
        // the hop only when the visitor asked for that alternate by id.
        if ($video->translation_id !== $t->id) {
            $params = [
                'translation' => strtolower($video->translation->abbreviation),
                'book'        => $b->slug,
                'chapter'     => $chapter,
            ];
            if ((int) $request->query('video') === $video->id) {
                $params['video'] = $video->id;
            }
            return redirect()->route('bible.watch', $params);
        }

        // From here the URL edition IS the script edition; assemble the
        // text exactly as the reader does, minus footnotes (the empty map
        // keeps markers out of the follow-along flow).
        $verses = Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->orderBy('verse_number')->get();

        // Only reachable by a data-entry slip (a video pinned to an edition
        // that lacks its own chapter) — fail loudly rather than render an
        // empty page under a working video.
        abort_if($verses->isEmpty(), 404, 'Video is pinned to an edition without this chapter');

        $headings = $this->headingsFor($t, $b, $chapter);
        $layout   = ChapterLayout::build($verses, $headings, []);

        $maxChapter = (int) Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->max('chapter');

        [$refBook, $refChapter] = $this->readerRef($b, $chapter, $maxChapter);

        // Alternates: every other live video, as ready-made links — URL and
        // label built here so the Blade only prints. Label prefers the
        // video's title, then its creator, then its edition.
        $alternates = $videos
            ->reject(fn ($v) => $v->id === $video->id)
            ->map(fn ($v) => [
                'url'   => route('bible.watch', [
                    'translation' => strtolower($v->translation->abbreviation),
                    'book'        => $b->slug,
                    'chapter'     => $chapter,
                    'video'       => $v->id,
                ]),
                'label' => $v->title
                    ?? ($v->creator_name ? 'by ' . $v->creator_name : $v->translation->abbreviation),
            ])
            ->values()->all();

        return view('bible.watch', [
            'translation' => $t,
            'book'        => $b,
            'chapter'     => $chapter,
            'video'       => $video,
            'isCrowd'     => $video->origin === ChapterAnimation::ORIGIN_CROWD,
            'alternates'  => $alternates,
            'layout'      => $layout,
            'refBook'     => $refBook,
            'refChapter'  => $refChapter,
            // Cues for watch-sync.js, client-shaped here: only verse-start
            // cues (kind 'v') — later kinds ship to the client only once a
            // script exists that understands them — as bare {v, t} pairs,
            // sorted by t so the client's binary search needs no defenses.
            // [] when uncued: the sync script stands down on its own.
            'cues'        => collect($video->cues ?? [])
                ->filter(fn ($c) => ($c['k'] ?? null) === 'v' && isset($c['v'], $c['t']))
                ->map(fn ($c) => ['v' => (int) $c['v'], 't' => (float) $c['t']])
                ->sortBy('t')
                ->values()
                ->all(),
            'readUrl'     => route('bible.chapter', [
                'translation' => strtolower($t->abbreviation),
                'book'        => $b->slug,
                'chapter'     => $chapter,
            ]),
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

 
    /* =========================================================================
    hp-hero r1 — BibleController::index()
    -------------------------------------------------------------------------
    Everything
    down to the end of the $linkTranslation loop is UNCHANGED; the new block
    is the "top five books this week" section, and the view() call gains one
    key ('topBooks').
    
    IMPORT CHECK — the method uses these facades/classes. Cache, DB, and Str
    are already imported (showBook uses all three).
    ========================================================================= */
    
    public function index(Request $request): View
    {
        // Fast slug → Book lookup so the view can resolve the slugs in config/canon.php.
        $books = Book::all()->keyBy('slug');
    
        // The never-404 link rule, shared with Top Books — see
        // resolveLinkTranslations() for the full story.
        $linkTranslation = $this->resolveLinkTranslations($request);
    
        // hp-hero r1: the five most-read books over the rolling seven-day window
        // (today + the previous six) — the readers pill's exact window, summed
        // per book from the anonymous daily counters. Cached briefly, RAW ROWS
        // ONLY: nothing route() produced ever goes in the cache (the scrimboard
        // hub lesson — a cached absolute URL is a snapshot of whoever warmed it).
        $topRows = Cache::remember('home:top-books', now()->addMinutes(10), function () {
            return DB::table('book_visits')
                ->where('visit_date', '>=', now()->subDays(6)->toDateString())
                ->select('osis', DB::raw('SUM(hits) as hits'))
                ->groupBy('osis')
                ->orderByDesc('hits')
                ->limit(5)
                ->get()
                // PLAIN ARRAYS into the cache — never raw row objects. Scalars and
                // arrays survive every cache serializer identically; row objects
                // are at the mercy of the store. Same reason the pill caches a
                // bare int and the scrimboard hub caches arrays.
                ->map(fn ($r) => ['osis' => (string) $r->osis, 'hits' => (int) $r->hits])
                ->all();
        });
    
        // Resolve each cached row to a live book + link + colour per request.
        // A row is skipped if its book vanished (re-import churn) or has no
        // verses anywhere (no linkTranslation entry — can't happen for a book
        // that earned visits, but cheap to guard). Fewer than five rows just
        // renders fewer chips; zero rows hides the strip entirely (the view's
        // count() guard), which is the honest face on a fresh database.
        $byOsis   = $books->keyBy('osis_id');
        $topBooks = [];
        foreach ($topRows as $row) {
            // Self-heal: if a stale cached value from before this fix is still
            // inside its TTL, skip its entries instead of crashing — the strip
            // just renders empty until the key expires or is cleared.
            if (! is_array($row) || ! isset($row['osis'])) {
                continue;
            }

            $bk = $byOsis->get($row['osis']);
            $tx = $bk ? ($linkTranslation[$bk->id] ?? null) : null;
            if (! $tx) {
                continue;
            }

            $topBooks[] = [
                'name'  => config('canon.home_names.' . $bk->slug) ?? $bk->name,
                'short' => config('canon.home_short_names.' . $bk->slug),
                'hits'  => $row['hits'],
                'word'  => config('canon.reader_words.' . $bk->slug)
                        ?? Str::plural('reader', $row['hits']),
                'color' => BookMetadata::colorFor($bk),
                'href'  => route('bible.book', [$tx, $bk->slug]),
            ];
        }
    
        // hp-demo r1: the hero's synthesis-card facsimile — Genesis 1:1 in
        // the KJV, flipped against the SAME /interlinear endpoint the
        // reader's cards use, so the demo can never drift from the real
        // thing. Null (and the blade hides the card) if the verse isn't
        // imported. The leading pilcrow some KJV verses carry is display
        // noise on a hero card and is trimmed.
        $demo    = null;
        $kjvTx   = Translation::findBySlug('kjv');
        $genesis = $books->get('genesis');
        if ($kjvTx && $genesis) {
            $v = Verse::where('translation_id', $kjvTx->id)
                ->where('book_id', $genesis->id)
                ->where('chapter', 1)
                ->where('verse_number', 1)
                ->first();
            if ($v) {
                $demo = [
                    'text' => preg_replace('/^\x{00B6}\s*/u', '', $v->text),
                    'ref'  => 'Genesis 1:1',
                    'tx'   => 'KJV',
                    'lang' => 'Hebrew',   // eternally true for this verse
                    'url'  => route('bible.interlinear', ['kjv', 'genesis', 1]) . '?v=1',
                ];
            }
        }

        // hp-ring r1: run each testament blurb through the token linkifier
        // (see linkifyBlurb) so the view can raw-echo pre-escaped HTML.
        $testaments = config('canon.testaments', []);
        foreach ($testaments as $tKey => $t) {
            $testaments[$tKey]['blurb'] = array_map(
                fn ($p) => $this->linkifyBlurb((string) $p),
                (array) ($t['blurb'] ?? [])
            );
        }

        return view('bible.index', [
            'testaments' => $testaments,
            'sections'        => config('canon.sections'),
            'books'           => $books,
            'linkTranslation' => $linkTranslation,
            'demo'            => $demo,
            'topBooks'        => $topBooks,   // hp-hero r1
        ]);
    }

    /**
     * TB-RANK R1 · the never-404 link rule, shared by the homepage and the
     * Top Books table — extracted verbatim from index() so the two can't
     * drift. For every book with verses in SOME translation, pick the URL
     * slug a link should use: the reader's remembered translation if it
     * has the book, else the highest-priority translation that does.
     *
     * @return array<int,string>  book_id => translation URL slug
     */
    private function resolveLinkTranslations(Request $request): array
    {
        // The translation the reader last viewed (remembered by the
        // RememberTranslation middleware), falling back to KJV if the
        // cookie is absent or stale.
        $pref    = strtolower($request->cookie('reader_translation', 'kjv'));
        $primary = Translation::findBySlug($pref) ?? Translation::findBySlug('kjv');

        // All translations, ordered by priority: global (full-canon)
        // editions first, then sort_order within each tier.
        $translations = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        // Every (book, translation) pair that actually has verses.
        $availableByBook = DB::table('verses')
            ->select('book_id', 'translation_id')
            ->distinct()
            ->get()
            ->groupBy('book_id');

        // For every book that exists in *some* translation:
        //   1. the reader's current translation, if it has the book, else
        //   2. the highest-priority translation that does.
        // Books absent from this map (no verses anywhere yet) are the
        // caller's "soon" case.
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

        return $linkTranslation;
    }

    /**
     * HP-RING R1 · blurb deep links. canon.php blurbs may carry
     * [[section_key|Display text]] tokens; this escapes the WHOLE
     * paragraph first, then swaps each token for a #section_key anchor
     * tinted with that section's palette colour — so the only unescaped
     * bytes in the output are the ones this method wrote itself. An
     * unknown key degrades to its plain text, so a canon.php typo can
     * never ship a dead link.
     */
    private function linkifyBlurb(string $para): string
    {
        $colors   = config('canon.section_colors', []);
        $sections = config('canon.sections', []);

        return preg_replace_callback(
            '/\[\[([a-z0-9_]+)\|([^\]|]+)\]\]/',
            function ($m) use ($colors, $sections) {
                if (! isset($sections[$m[1]])) {
                    return $m[2];                      // typo-proof fallback
                }
                $cg = $colors[$m[1]] ?? 'clay';
                return '<a href="#' . $m[1] . '" style="--cg:var(--tl-' . $cg . ')">' . $m[2] . '</a>';
            },
            e($para)
        );
    }

    /**
     * TB-RANK R1 · /extras/top-books — the whole canon ranked by the four
     * anonymous counters. Weekly window by default (the pill's rolling
     * seven days); ?window=all for all-time. Aggregates are cached as
     * PLAIN SCALAR ARRAYS only (house rule — never row objects, never
     * route() output); names, links, and colours resolve per request.
     */
    public function topBooks(Request $request): View
    {
        $window = $request->query('window') === 'all' ? 'all' : 'weekly';

        $agg = Cache::remember('topbooks:' . $window, now()->addMinutes(10), function () use ($window) {
            $since = $window === 'weekly' ? now()->subDays(6)->toDateString() : null;

            // osis => hits for the three osis-keyed counter tables. One
            // grouped SUM each; $since === null is the all-time case.
            $sum = function (string $table, string $dateCol) use ($since): array {
                $q = DB::table($table)
                    ->select('osis', DB::raw('SUM(hits) as n'))
                    ->groupBy('osis');
                if ($since) {
                    $q->where($dateCol, '>=', $since);
                }
                return array_map('intval', $q->pluck('n', 'osis')->all());
            };

            // scrim_plays is slug-keyed and counts in `plays`. No mode or
            // lang filter — "scrimmed" means every finished round, both
            // modes, both languages, by this page's definition.
            $scrim = DB::table('scrim_plays')
                ->select('book_slug', DB::raw('SUM(plays) as n'))
                ->whereNotNull('book_slug')
                ->groupBy('book_slug');
            if ($since) {
                $scrim->where('play_date', '>=', $since);
            }

            return [
                'readers'   => $sum('book_visits', 'visit_date'),
                'typed'     => $sum('vigil_typed', 'typed_date'),
                'collected' => $sum('pericope_collects', 'collect_date'),
                'scrimmed'  => array_map('intval', $scrim->pluck('n', 'book_slug')->all()),
            ];
        });

        // One row per canon book, built in canon display order — the same
        // testaments → sections → subgroups walk the homepage renders, so
        // the two pages can never disagree about what "the canon" is.
        $books           = Book::all()->keyBy('slug');
        $linkTranslation = $this->resolveLinkTranslations($request);

        $rows = [];
        $pos  = 0;
        foreach (config('canon.testaments', []) as $testament) {
            foreach (($testament['sections'] ?? []) as $sectionKey) {
                $section = config('canon.sections.' . $sectionKey);
                if (! $section) {
                    continue;
                }
                $groups = $section['subgroups'] ?? [['books' => $section['books'] ?? []]];
                foreach ($groups as $group) {
                    foreach (($group['books'] ?? []) as $slug) {
                        $bk = $books->get($slug);
                        if (! $bk) {
                            continue;
                        }

                        $typed     = $agg['typed'][$bk->osis_id]     ?? 0;
                        $collected = $agg['collected'][$bk->osis_id] ?? 0;
                        $scrimmed  = $agg['scrimmed'][$slug]         ?? 0;
                        $tx        = $linkTranslation[$bk->id]       ?? null;

                        $rows[] = [
                            'canon'     => $pos++,   // stable tie-break + the Book column's sort key
                            'name'      => config('canon.home_names.' . $slug) ?? $bk->name,
                            // tb-abbr r2: the homepage's short-name chain —
                            // only the handful of genuinely long names have
                            // one ("1 Thess", "Wisdom of Sol"). The DB
                            // short_name abbreviations from r1 are retired:
                            // the mobile view is now Book + Total only, so
                            // full names fit.
                            'abbr'      => config('canon.home_short_names.' . $slug),
                            'color'     => BookMetadata::colorFor($bk),
                            'href'      => $tx ? route('bible.book', [$tx, $slug]) : null,
                            'readers'   => $agg['readers'][$bk->osis_id] ?? 0,
                            'typed'     => $typed,
                            'scrimmed'  => $scrimmed,
                            'collected' => $collected,
                            'total'     => $typed + $scrimmed + $collected,
                        ];
                    }
                }
            }
        }

        // Server-side default order: readers descending, canon tie-break —
        // the first paint matches the sorter's default state, and a no-JS
        // visitor still gets a fully ranked page.
        usort($rows, fn ($a, $b) => [$b['readers'], $a['canon']] <=> [$a['readers'], $b['canon']]);

        return view('extras.topbooks', [
            'rows'   => $rows,
            'window' => $window,
        ]);
    }    

    /**
     * VERSE PERMALINK  ·  /bible/{t}/{b}/{c}/{v}  →  301 → chapter ?v={v}
     *
     * The path form is the URL people guess and old backlinks carry; the
     * reader's real deep-link grammar is ?v=, which Focus mode parses,
     * highlights, scrolls to, and normalises (focus-synthesis.js init()).
     * Every internal producer already builds ?v= — this route exists only
     * to hand the guessers and the backlinks to the canonical form.
     *
     * {verse} accepts the whole selection grammar ("16", "16-18", "1-3,8")
     * — the route constraint owns the shape. NO existence check, by
     * design: John 3:99 redirects anyway, the client filters it out and
     * cleans the URL — a graceful landing on the chapter with no per-hit
     * query spent validating. showChapter stays the single 404 authority
     * for bad translation/book/chapter, one hop later.
     *
     * 301, not 302: ?v= has always been the canonical here (every internal
     * link, the chapter's rel=canonical), so caches and crawlers should
     * consolidate on it permanently.
     */
    public function showVerse(Request $request, string $translation, string $book, int $chapter, string $verse): RedirectResponse
    {
        // The incoming query rides along (…&view=synthesis survives), with
        // the path verse overriding any stray ?v= — the path is the more
        // specific intent. route() folds keys that aren't route parameters
        // into the query string on its own.
        return redirect()->route('bible.chapter', array_merge($request->query(), [
            'translation' => $translation,
            'book'        => $book,
            'chapter'     => $chapter,
            'v'           => $verse,
        ]), 301);
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

        // tl-fix r7: canon.php's homepage short names double as the desktop
        // "mid" label — swapped in by the fit pass only when a full name
        // measurably overflows the label column. Null = no mid exists.
        $midNames = config('canon.home_short_names', []);

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
                        // tl-fix r10: the popover's pieces, kept separate —
                        // the partial stamps them as data attributes and the
                        // panel lays them out. (Replaces the old title string.)
                        'full'    => $ly['full'] ?? '',
                        'range'   => $this->layerDateLabel($s, $e),
                    ];
                }
                if (empty($segments)) {
                    continue;
                }
                // tl-fix r6: date_display/date_pos are gone chart-wide —
                // ranges live in the segment popover (phase 6), not
                // printed beside bars.
                $bars[] = [
                    'label'    => $b->name,
                    'short'    => $b->short_name ?: $b->name,   // tl-fix r4: mobile label
                    'mid'      => $midNames[$b->slug] ?? null,  // tl-fix r7: desktop fallback
                    'slug'     => $b->slug,
                    'book_id'  => $b->id,
                    'current'  => $isCurrent,
                    'layered'  => false,
                    'segments' => $segments,
                    'start'    => min(array_column($segments, 'start')),
                    'end'      => max(array_column($segments, 'end')),
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
                    'label'    => $b->name,
                    'short'    => $b->short_name ?: $b->name,   // tl-fix r4: mobile label
                    'mid'      => $midNames[$b->slug] ?? null,  // tl-fix r7: desktop fallback
                    'slug'     => $b->slug,
                    'book_id'  => $b->id,
                    'current'  => $isCurrent,
                    'layered'  => false,
                    'segments' => [[
                        'start'   => $s,
                        'end'     => $e,
                        'color'   => $color,
                        'label'   => '',
                        'full'    => '',                               // tl-fix r10
                        'range'   => $this->layerDateLabel($s, $e),
                    ]],
                    'start'    => $s,
                    'end'      => $e,
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

        // --- 5. Sort in CANON ORDER (canon.php), then compute geometry --------
        // tl-fix r4: rows follow the homepage's canonical sequence, not
        // composition date — Gen→Exod→Lev reads as the shelf order a reader
        // knows. The JSON groups[].books arrays now control MEMBERSHIP only.
        // Books absent from canon.php (shouldn't happen, but never break the
        // page over content) sort last, by start year among themselves.
        $order = $this->canonOrderIndex();
        usort($bars, function ($a, $b) use ($order) {
            $ai = $order[$a['slug']] ?? PHP_INT_MAX;
            $bi = $order[$b['slug']] ?? PHP_INT_MAX;

            return $ai === $bi
                ? ([$a['start'], $a['end']] <=> [$b['start'], $b['end']])
                : $ai <=> $bi;
        });
        // tl-fix r5: the current row's highlight ends at its own bar, not
        // the chart edge. Hand the view the bar's end as a 0–1 fraction of
        // the track width; timeline-styles turns it into a calc() width.
        $hlEnd = null;

        foreach ($bars as &$bar) {
            foreach ($bar['segments'] as &$seg) {
                $left        = $pct($seg['start']);
                $seg['left']  = $left;
                $seg['width'] = max(0.0, $pct($seg['end']) - $left);
            }
            unset($seg);
            if ($bar['current']) {
                $hlEnd = round($pct($bar['end']) / 100, 4);
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
            // tl-fix r5: current bar's end, 0–1 of track width (null when
            // the current book didn't plot). Drives the row highlight.
            'hl_end' => $hlEnd,
        ];
    }

    /**
     * tl-fix r4: slug => running index across the whole canon, in homepage
     * display order — testaments → sections → subgroups → books, exactly the
     * walk pickerTestaments() does in TypingController. Static-cached per
     * request; the config never changes mid-request.
     */
    private function canonOrderIndex(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        $i   = 0;
        foreach (config('canon.testaments', []) as $testament) {
            foreach (($testament['sections'] ?? []) as $key) {
                $section = config('canon.sections')[$key] ?? null;
                if (! $section) {
                    continue;
                }
                $groups = $section['subgroups'] ?? [['books' => $section['books'] ?? []]];
                foreach ($groups as $group) {
                    foreach (($group['books'] ?? []) as $slug) {
                        $map[$slug] ??= $i++;
                    }
                }
            }
        }

        return $map;
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
            // tl-fix r4: only the LAST tick carries the era (BC/AD). The
            // first used to as well, but on narrow screens both end labels
            // clipped against the chart edges; a bare number always fits on
            // the left, and the right end is clamped by the partial's script.
            $label = ($i === $last)
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

    /**
     * Range label with its era, e.g. "950–850 BC" or "54–55 AD".
     * tl-fix r10: a range that crosses the era line now labels both ends
     * ("50 BC – 20 AD") — before, it printed a bare "50–20".
     */
    private function layerDateLabel(int $start, int $end): string
    {
        if ($start < 0 && $end > 0) {
            return abs($start) . ' BC – ' . $end . ' AD';
        }

        $suffix = ($end <= 0) ? ' BC' : ' AD';

        return $this->timelineRangeLabel($start, $end) . $suffix;
    }
}