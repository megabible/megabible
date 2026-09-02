<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\DailyVerse;
use App\Models\Translation;
use App\Models\TypingPassage;
use App\Models\TypingScore;
use App\Models\Verse;
use App\Support\Sabbath;
use App\Support\Challenge;
use App\Support\ChapterLayout;
use App\Support\DifficultyRater;
use App\Support\PassageSelector;
use App\Support\DailyVersePicker;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * /extras/bible-typing — the typing games.
 *
 * Endpoints:
 *   GET  /extras/bible-typing/vigil                 → vigil default (redirect)
 *   GET  /extras/bible-typing/vigil/{t}/{b}         → vigil book (redirect → ch. 1)
 *   GET  /extras/bible-typing/vigil/{t}/{b}/{c}     → TYPING VIGIL chapter view
 *   GET  /extras/scrimmage             → resolve a URL-defined challenge, issue a start token
 *   GET  /extras/scrimmage/board       → the leaderboard for one exact challenge
 *   GET  /extras/bible-typing/outline               → books+chapters+verse-counts
 *   GET  /extras/bible-typing/passage               → serve a passage to type (legacy prototype)
 *   POST /extras/bible-typing/score                 → submit a ranked score (legacy prototype)
 *   GET  /extras/bible-typing/leaderboard           → top scores (legacy prototype)
 *
 * DIAL-NAME BOARDS (scrimmage): names are exactly four characters (A–Z, 0–9,
 * picked on four dials), UNIQUE per board — one row per name, the better
 * score holding the seat (a worse submission is answered with the defender's
 * numbers instead of a rejection). Boards cap at typing.board_cap rows a
 * day and are cut to typing.board_size nightly by scrim:trim; survivors
 * carry a stamp the board renders as the ★ champion mark.
 */
class TypingController extends Controller
{
    public function __construct(private readonly PassageSelector $selector)
    {
    }

    /* ===================================================================== */
    /*  TYPING VIGIL                                                          */
    /* ===================================================================== */

    /**
     * The vigil HOME — a sibling of the reader's homepage. Shows the whole
     * canon (every book that exists in at least one translation) with a
     * completion bar per book.
     *
     * Progress lives only in the browser, so the server can't know a book's
     * percentage. Instead it ships the *denominators* — for every book, the
     * total verse count in each translation that carries it — as one JSON blob
     * ($counts). The page script reads localStorage, computes typed/total per
     * translation, takes the highest, and paints the bars. Cheap and cacheable:
     * the counts only change on re-import.
     */
    public function vigilHome(): View
    {
        // book_id => [ txSlug => totalVerses ]   (denominators, all translations)
        $totals = $this->verseTotalsByBookTranslation();

        // Resolve a display translation per book — the highest-priority edition
        // that carries it — so a book's tile links into a real vigil hub.
        $translations = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')->get();

        $books = Book::all()->keyBy('slug');

        // book_id => slug of the translation its tile should link to.
        $linkTx = [];
        foreach ($totals as $bookId => $byTx) {
            foreach ($translations as $t) {
                $slug = strtolower($t->abbreviation);
                if (isset($byTx[$slug])) { $linkTx[$bookId] = $slug; break; }
            }
        }

        // $counts is keyed by OSIS id (what localStorage keys by), so the
        // client can look a book up without a slug→osis round-trip.
        $counts = [];
        foreach ($totals as $bookId => $byTx) {
            $bk = $books->firstWhere('id', $bookId);
            if ($bk) { $counts[$bk->osis_id] = $byTx; }
        }

        return view('extras.vigil-home', [
            'testaments'   => config('canon.testaments'),
            'sections'     => config('canon.sections'),
            'sectionColors'=> config('canon.section_colors', []),
            'books'        => $books,
            'linkTx'       => $linkTx,          // book_id => tx slug (or absent = soon)
            'counts'       => $counts,          // osis => { txSlug: totalVerses }
        ]);
    }

    /**
     * The vigil BOOK HUB — a sibling of the reader's book page, trimmed to what
     * the vigil needs: a per-chapter completion breakdown and a link back to
     * the regular hub. Same client/server split as the home: the server ships
     * per-chapter denominators, the script fills the percentages.
     *
     * The {translation} in the URL is the edition you'll type in when you open
     * a chapter, and the one every stat on the page is scoped to — switching
     * the translation switcher shows THAT edition's own progress. (The home
     * page keeps the best-across-translations view; a hub is inside one.)
     * $chapterCounts still carries every translation's denominators so the
     * client has them on hand, but the page reads only the current slug.
     */
    public function vigilBook(string $translation, string $book): View
    {
        $t = Translation::findBySlug($translation);
        abort_if(! $t, 404, 'Translation not found');

        $b = Book::findBySlug($book);
        abort_if(! $b, 404, 'Book not found');

        // Every chapter this book has in the URL translation, in order — the
        // grid we render. 404 if the book isn't in this edition at all.
        $chapters = Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->select('chapter')->distinct()->orderBy('chapter')
            ->pluck('chapter');

        abort_if($chapters->isEmpty(), 404, 'This book is not available in this translation');

        // Per-chapter denominators across ALL translations that carry the book:
        //   { chapter => { txSlug => verseCount } }
        $rows = Verse::where('book_id', $b->id)
            ->select('translation_id', 'chapter', DB::raw('MAX(verse_number) as verses'))
            ->groupBy('translation_id', 'chapter')
            ->get();

        $txById = Translation::whereIn('id', $rows->pluck('translation_id')->unique())
            ->get()->keyBy('id');

        $chapterCounts = [];
        foreach ($rows as $r) {
            $tx = $txById->get($r->translation_id);
            if (! $tx) { continue; }
            $chapterCounts[(int) $r->chapter][strtolower($tx->abbreviation)] = (int) $r->verses;
        }
        ksort($chapterCounts);

        $txSlug     = strtolower($t->abbreviation);
        $maxChapter = (int) $chapters->max();
        [$refBook]  = app(BibleController::class)->readerRef($b, 1, $maxChapter);

        // Editions that carry THIS book (any chapter), for the switcher — same
        // scoping idea as the reader, book-level rather than chapter-level.
        $otherTranslations = Translation::whereIn('id', function ($q) use ($b) {
                $q->select('translation_id')->from('verses')
                  ->where('book_id', $b->id)->distinct();
            })
            ->where('id', '!=', $t->id)
            ->orderBy('sort_order')->get();

        return view('extras.vigil-book', [
            'translation'  => $t,
            'txSlug'       => $txSlug,
            'book'         => $b,
            'refBook'      => $refBook,
            'chapters'     => $chapters,
            'cellOffset'   => $b->chapterCellOffset(),
            'osisId'       => $b->osis_id,
            'chapterCounts'=> $chapterCounts,   // { chapter: { txSlug: verseCount } }
            'otherTranslations' => $otherTranslations,
            'readerHubUrl' => route('bible.book', ['translation' => $txSlug, 'book' => $b->slug]),
        ]);
    }

    /**
     * The vigil chapter view — a sibling of BibleController::showChapter.
     *
     * Same resolution + 404 discipline as the reader, same ChapterLayout
     * pipeline, same headings merge — but deliberately NO footnotes (their
     * markers would pollute the typing surface) and no interlinear/synthesis
     * plumbing. The view renders the shared reading-flow partial; the vigil
     * engine in the blade does everything else client-side.
     */
    public function vigil(string $translation, string $book, int $chapter): View
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

        // Same merged heading set the reader shows (translation's own titles +
        // its set's shared section headings). headingsFor() lives on
        // BibleController — made public so the vigil can reuse it rather than
        // duplicating the shared-set merge logic. (Candidate for extraction to
        // App\Support if a third caller ever appears.)
        $headings = app(BibleController::class)->headingsFor($t, $b, $chapter);

        // No footnotes on purpose: empty array = attachNotes() finds nothing
        // to attach, so no markers render in the typing surface. (build()'s
        // third parameter is typed `array`, not Collection.)
        $layout = ChapterLayout::build($verses, $headings, []);

        $maxChapter = (int) Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->max('chapter');

        // "Psalm 151" for the Five Psalms of David, "Genesis 2" for everyone
        // else — same display logic as the reader (readerRef() made public).
        [$refBook, $refChapter] = app(BibleController::class)->readerRef($b, $chapter, $maxChapter);

        // Translations that carry THIS exact chapter, so every switcher row is
        // guaranteed not to 404 — identical scoping to the reader.
        $otherTranslations = Translation::whereIn('id', function ($q) use ($b, $chapter) {
                $q->select('translation_id')->from('verses')
                  ->where('book_id', $b->id)
                  ->where('chapter', $chapter)
                  ->distinct();
            })
            ->where('id', '!=', $t->id)
            ->orderBy('sort_order')->get();

        $txSlug = strtolower($t->abbreviation);

        return view('extras.vigil', [
            'translation'       => $t,
            'book'              => $b,
            'chapter'           => $chapter,
            'layout'            => $layout,
            'maxChapter'        => $maxChapter,
            'refBook'           => $refBook,
            'refChapter'        => $refChapter,
            'otherTranslations' => $otherTranslations,
            'nav'               => $this->vigilNav($txSlug, $b, $chapter, $maxChapter),
            // Reader twin of this chapter — the mode-toggle's target.
            'readerUrl'         => route('bible.chapter', [
                'translation' => $txSlug, 'book' => $b->slug, 'chapter' => $chapter,
            ]),

            // ---- Engine payload (all single-variable @json in the blade —
            //      commas stay out of Blade directives, per the house rules) --
            'txSlug'       => $txSlug,
            'osisId'       => $b->osis_id,
            'verseNumbers' => $verses->pluck('verse_number')->values(),
            // Derived from the router, not hardcoded — rename the route and
            // the client-side QuickNav rewriting follows automatically.
            'vigilPrefix'  => rtrim(route('typing.vigil.home'), '/'),
        ]);
    }

    /**
     * Prev/next targets for the vigil's floating arrows — the reader's
     * chapterNav shape (see chapter-nav partial), but every URL stays inside
     * the vigil. Chapter 1 has no left arrow; the last chapter's right slot
     * rewinds to the book hub (its own vigil hub).
     */
    private function vigilNav(string $txSlug, Book $b, int $chapter, int $maxChapter): array
    {
        $to = fn (int $n) => route('typing.vigil', [
            'translation' => $txSlug, 'book' => $b->slug, 'chapter' => $n,
        ]);

        $next   = $chapter < $maxChapter ? $to($chapter + 1) : null;
        $rewind = ($next === null && $maxChapter > 1)
            ? route('typing.vigil.book', ['translation' => $txSlug, 'book' => $b->slug])
            : null;

        return [
            'prev'   => $chapter > 1 ? $to($chapter - 1) : null,
            'next'   => $next,
            'rewind' => $rewind,
        ];
    }

    /**
     * book_id => [ txSlug => totalVerseCount ] for every book that exists in
     * any translation. One grouped query: per (book, translation, chapter) the
     * max verse number, summed to a per-(book, translation) total. That total
     * is the denominator the vigil's "% typed" bars divide into.
     */
    private function verseTotalsByBookTranslation(): array
    {
        $rows = Verse::query()
            ->select('book_id', 'translation_id', 'chapter',
                     DB::raw('MAX(verse_number) as verses'))
            ->groupBy('book_id', 'translation_id', 'chapter')
            ->get();

        $txSlugs = Translation::all()->mapWithKeys(
            fn ($t) => [$t->id => strtolower($t->abbreviation)]
        );

        $out = [];
        foreach ($rows as $r) {
            $slug = $txSlugs[$r->translation_id] ?? null;
            if (! $slug) { continue; }
            $out[$r->book_id][$slug] = ($out[$r->book_id][$slug] ?? 0) + (int) $r->verses;
        }

        return $out;
    }

    /* ===================================================================== */
    /*  TYPING SCRIMMAGE                                                      */
    /* ===================================================================== */

    /**
     * The BUILDER — /extras/scrimmage
     *
     * Verse picker only. No scrim ever renders here; a URL carrying a verse
     * goes to scrimmageVerse() and renders its own blade. (The old single
     * blade hid both screens until JS chose one, which is what made a
     * refresh flash.) The picker's "Build" navigates to a real page.
     *
     * $error carries a message back from a rejected scrim URL.
     */
    public function scrimmage(Request $request): View
    {
        $translations = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get(['abbreviation', 'name'])
            ->map(fn ($t) => [
                'slug' => strtolower($t->abbreviation),
                'name' => $t->name,
            ])->values();

        // Today's daily, for the card at the top of the builder. forDate
        // persists a deterministic pick if the scheduler never ran, so the
        // card can't be empty; the card's "done today" badge is the
        // client's business (the one-shot flag lives in localStorage).
        $today = DailyVersePicker::normaliseDate();

        // On the sabbath there is no ledger row and the picker refuses to
        // make one; the card gets a rest-day payload instead. Part 2 gives
        // it proper dress — until then it reads a little oddly ("Take the
        // daily" leads to the sabbath page), which is honest enough.
        if (Sabbath::isSabbath()) {
            return view('extras.scrimmage', [
                'translations'     => $translations,
                'pickerTestaments' => $this->pickerTestaments(),
                'scrimUrlPattern'  => $this->scrimUrlPattern(),
                'error'            => $request->session()->get('scrim_error'),
                'daily'            => [
                    'date'    => $today,
                    'label'   => 'The Sabbath — a day of rest',
                    'note'    => 'The daily returns at midnight tonight.',
                    'url'     => route('typing.scrimmage.daily'),
                    'sabbath' => true,
                ],
            ]);
        }

        $ledger = DailyVersePicker::forDate($today);

        return view('extras.scrimmage', [
            'translations'     => $translations,
            'pickerTestaments' => $this->pickerTestaments(),
            'scrimUrlPattern'  => $this->scrimUrlPattern(),
            'error'            => $request->session()->get('scrim_error'),
            'daily'            => [
                'date'  => $today,
                'label' => $ledger->label(),
                'note'  => $ledger->note,
                'url'   => route('typing.scrimmage.daily'),
            ],
        ]);
    }

    /* ===================================================================== */
    /*  Scrimboard hub + full boards                                         */
    /* ===================================================================== */

    /**
     * THE HUB  ·  /extras/scrimmage/scrimboards
     *
     * Two aggregates over the anonymous play counters:
     *
     *   TRENDING — the most-played VERSES, scrimmage and daily plays summed
     *   (total interest in the words, however the interest arrived — a
     *   daily day sends its verse up this list on purpose).
     *
     *   TOP BOARDS — the most-played BOARDS (scrimmage keys only, since
     *   those are the boards that live and defend), each previewing its top
     *   rows. Ranked by plays, not by top score: this page answers "where
     *   is everyone?", and the board's own page answers "how good are they?"
     *
     * ?period=week|month|year|all filters both — a SUM over play_date, which
     * is the entire reason scrim_plays rolls up daily. Cached per period;
     * every query here is aggregate and nobody needs it live.
     */
    public function scrimboards(Request $request): View
    {
        $period = (string) $request->query('period', 'all');
        if (! in_array($period, ['week', 'month', 'year', 'all'], true)) {
            $period = 'all';
        }

        // The hub is per-language. Hardwired until Spanish imports land;
        // the language switcher arrives with megabiblia.net.
        $lang = 'en';
        $tz   = config('typing.board_trim.timezone', 'America/Denver');

        $data = Cache::remember(
            "mb:hub:{$lang}:{$period}",
            now()->addMinutes((int) config('typing.hub.cache_minutes', 10)),
            function () use ($period, $lang, $tz) {

                $cutDays = ['week' => 7, 'month' => 30, 'year' => 365][$period] ?? null;
                $cutoff  = $cutDays
                    ? now($tz)->subDays($cutDays)->toDateString()
                    : null;

                $base = function () use ($lang, $cutoff) {
                    $q = DB::table('scrim_plays')->where('lang', $lang);
                    if ($cutoff) {
                        $q->where('play_date', '>=', $cutoff);
                    }
                    return $q;
                };

                // ---- Trending verses (all modes) --------------------------
                $trending = $base()
                    ->select('book_slug', 'chapter', 'verse',
                             DB::raw('SUM(plays) as plays'))
                    ->groupBy('book_slug', 'chapter', 'verse')
                    ->orderByDesc('plays')
                    ->limit((int) config('typing.hub.trending_count', 10))
                    ->get()
                    ->map(function ($r) {
                        // NO URLs IN THE CACHE. route() resolves against the
                        // CURRENT request's host, so a cached absolute URL is
                        // a snapshot of whoever warmed the cache — the desktop
                        // at 127.0.0.1, and every phone on the LAN then gets
                        // sent there. Cache the verse; build links per request.
                        return [
                            'label' => $this->verseLabel($r->book_slug, (int) $r->chapter, (int) $r->verse),
                            'plays' => (int) $r->plays,
                            'b'     => $r->book_slug,
                            'c'     => (int) $r->chapter,
                            'v'     => (int) $r->verse,
                        ];
                    });

                // ---- Top boards (scrimmage keys), previews attached -------
                $hot = $base()
                    ->where('mode', 'scrimmage')
                    ->select('challenge_key', 'book_slug', 'chapter', 'verse',
                             DB::raw('SUM(plays) as plays'))
                    ->groupBy('challenge_key', 'book_slug', 'chapter', 'verse')
                    ->orderByDesc('plays')
                    ->limit((int) config('typing.hub.board_count', 6))
                    ->get();

                $rowsPer = (int) config('typing.hub.board_rows', 5);
                $txAbbr  = Translation::pluck('abbreviation', 'id');
                $censor  = (array) config('typing.censor', []);

                $boards = [];
                foreach ($hot as $h) {
                    // The board's own sort — score, accuracy, incumbency —
                    // so the preview is exactly the board's top slice.
                    $rows = TypingScore::where('challenge_key', $h->challenge_key)
                        ->orderByDesc('final_score')
                        ->orderByDesc('accuracy')
                        ->orderBy('created_at')
                        ->limit($rowsPer)
                        ->get(['player_name', 'final_score', 'net_wpm',
                               'accuracy', 'translation_id', 'survived_trim_at']);

                    $boards[] = [
                        'label' => $this->verseLabel($h->book_slug, (int) $h->chapter, (int) $h->verse),
                        'plays' => (int) $h->plays,
                        'names' => TypingScore::where('challenge_key', $h->challenge_key)->count(),
                        'b'     => $h->book_slug,
                        'c'     => (int) $h->chapter,
                        'v'     => (int) $h->verse,
                        'rows'  => $rows->map(fn ($r) => [
                            // Censored names substitute on server pages (the
                            // scrim page blurs; a static page has no need of
                            // the theatre — the clean alt does the job).
                            'name'  => $censor[$r->player_name] ?? $r->player_name,
                            'held'  => $r->survived_trim_at !== null,
                            'score' => (float) $r->final_score,
                            'net'   => (float) $r->net_wpm,
                            'acc'   => (float) $r->accuracy,
                            'tx'    => $txAbbr[$r->translation_id] ?? '',
                        ])->values()->all(),
                    ];
                }

                return ['trending' => $trending->values()->all(), 'boards' => $boards];
            }
        );

        // Links are built HERE, outside the cache, once per request — so they
        // always carry the host the visitor actually asked for. The blade's
        // contract ($t['boardUrl'], $b['boardUrl']) is unchanged.
        $withUrl = fn (array $r) => $r + ['boardUrl' => route('typing.scrimmage.board', [
            'b' => $r['b'], 'c' => $r['c'], 'v' => $r['v'], 'lang' => $lang,
        ])];

        // THE SABBATH VEIL, applied after the cache for the same reason the
        // URLs are: the cache stores raw truth; the request decorates it.
        // Trending stays lit — play counts are analytics, not boards, and
        // people still play on the sabbath. Board previews go dark.
        $sabbath = Sabbath::isSabbath();
        $boards  = array_map($withUrl, $data['boards']);
        if ($sabbath) {
            $boards = array_map(function (array $b) {
                $b['rows'] = [];
                return $b;
            }, $boards);
        }

        return view('extras.scrimboards', [
            'trending' => array_map($withUrl, $data['trending']),
            'boards'   => $boards,
            'period'   => $period,
            'lang'     => $lang,
            'sabbath'  => $sabbath,
        ]);
    }

    /**
     * ONE FULL BOARD  ·  /extras/scrimmage/{b}/{c}/{v}/scrimboard-{lang}
     *
     * The whole intra-day field for one verse in one language, server-
     * rendered and UNCACHED — this board changes on every submission, and
     * unlike the hub it is the page people refresh to watch a duel.
     *
     * No translation anywhere: the key comes straight from
     * Challenge::scrimmageKey(lang, b, c, v). The play CTA resolves the
     * language's priority edition once, here, so the hub never has to.
     *
     * `-es` before Spanish imports exist renders the coming-soon state
     * rather than a 404: the URL is reserved and real, its content merely
     * future.
     */
    public function scrimboardFull(string $b, int $c, int $v, string $lang): View
    {
        $book = Book::findBySlug(strtolower($b));
        if (! $book) {
            abort(404);
        }

        // Which editions of THIS LANGUAGE carry the verse, priority order.
        $editionIds = Verse::where('book_id', $book->id)
            ->where('chapter', $c)
            ->where('verse_number', $v)
            ->pluck('translation_id');

        if ($editionIds->isEmpty()) {
            abort(404);                     // the verse exists in no edition at all
        }

        $editions = Translation::whereIn('id', $editionIds)
            ->where('language', $lang)
            ->orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();

        // The verse is real but this language doesn't carry it yet — the
        // reserved-for-Spanish case. A real page, honestly empty.
        $comingSoon = $editions->isEmpty();

        $label = $book->name . ' ' . $c . ':' . $v;
        $key   = Challenge::scrimmageKey($lang, $book->slug, $c, $v);

        $txAbbr = Translation::pluck('abbreviation', 'id');
        $censor = (array) config('typing.censor', []);

        // The sabbath veil: rows exist, uncut, unseen until Sunday. Part 2
        // gives the blade its proper copy; until then an empty board reads
        // as empty, which is at least never a lie about the ranks.
        $sabbath = Sabbath::isSabbath();

        $rows = ($comingSoon || $sabbath) ? collect() : TypingScore::where('challenge_key', $key)
            ->orderByDesc('final_score')
            ->orderByDesc('accuracy')
            ->orderBy('created_at')
            ->limit((int) config('typing.board_cap'))
            ->get()
            ->map(fn ($r) => [
                'name'  => $censor[$r->player_name] ?? $r->player_name,
                'held'  => $r->survived_trim_at !== null,
                'score' => (float) $r->final_score,
                'net'   => (float) $r->net_wpm,
                'acc'   => (float) $r->accuracy,
                'tx'    => $txAbbr[$r->translation_id] ?? '',
                'when'  => $r->created_at?->timezone(
                               config('typing.board_trim.timezone', 'America/Denver')
                           )->format('M j'),
            ])->values();

        // Lifetime plays for THIS board (its own key, so scrimmage only —
        // the verse's daily outings are a different board's story).
        $plays = (int) DB::table('scrim_plays')->where('challenge_key', $key)->sum('plays');

        return view('extras.scrimboard-full', [
            'sabbath'    => $sabbath,
            'label'      => $label,
            'lang'       => $lang,
            'rows'       => $rows,
            'plays'      => $plays,
            'comingSoon' => $comingSoon,
            'playUrl'    => $comingSoon ? null : route('typing.scrimmage.verse', [
                't' => strtolower($editions->first()->abbreviation),
                'b' => $book->slug, 'c' => $c, 'v' => $v,
            ]),
            'readerUrl'  => $comingSoon ? null : route('bible.chapter', [
                'translation' => strtolower($editions->first()->abbreviation),
                'book'        => $book->slug,
                'chapter'     => $c,
            ]) . '?v=' . $v,
            'hubUrl'     => route('typing.scrimmage.boards'),
        ]);
    }

    /** "Psalms 138:2" from the denormalised triple, tolerant of a lost book. */
    private function verseLabel(string $slug, int $chapter, int $verse): string
    {
        $book = Book::findBySlug($slug);

        return ($book?->name ?? $slug) . ' ' . $chapter . ':' . $verse;
    }

    /* ===================================================================== */
    /*  The daily archive                                                    */
    /* ===================================================================== */

    /**
     * THE ARCHIVE INDEX  ·  /extras/scrimmage/daily/archive
     *
     * Every day that has happened, newest first — read from the LEDGER, not
     * from the snapshots. A day nobody played is still a day that happened,
     * and its verse has still had its one turn in the century; listing the
     * ledger keeps the record honest and makes this page the ancestor of
     * the corpus progress tracker.
     *
     * Each row gains its champion and its turnout from the snapshots, and
     * days that are past but NOT yet frozen are marked as such — normal for
     * a few minutes after midnight, and a symptom worth seeing if it lasts
     * (the scheduler has stopped).
     *
     * Uncached, deliberately: a page of 30 indexed rows, and a page full of
     * generated links has no business in a cache.
     */
    public function dailyArchive(Request $request): View
    {
        $lang  = 'en';
        $today = DailyVersePicker::normaliseDate();

        // simplePaginate, not paginate: prev/next is all this needs, and it
        // skips the COUNT(*) over a table that grows for a century.
        $days = DailyVerse::where('date', '<', $today)
            ->orderByDesc('date')
            ->simplePaginate(30)
            ->withQueryString();

        $dates = collect($days->items())->map(fn ($d) => $d->date->toDateString());

        // Turnout per day, one grouped query for the whole page.
        $stats = DB::table('daily_snapshot_entries')
            ->where('lang', $lang)
            ->whereIn('date', $dates)
            ->select('date', DB::raw('COUNT(*) as seats'), DB::raw('MAX(final_score) as top'))
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($r) => (string) $r->date);

        // The champions, one query for the whole page.
        $champs = DB::table('daily_snapshot_entries')
            ->where('lang', $lang)
            ->whereIn('date', $dates)
            ->where('rank', 1)
            ->get(['date', 'player_name', 'final_score', 'translation_abbr'])
            ->keyBy(fn ($r) => (string) $r->date);

        $censor = (array) config('typing.censor', []);

        $rows = collect($days->items())->map(function ($d) use ($stats, $champs, $censor, $lang) {
            $date = $d->date->toDateString();
            $s    = $stats->get($date);
            $c    = $champs->get($date);

            return [
                'date'    => $date,
                'label'   => $d->date->format('M j, Y'),
                'verse'   => $d->label(),
                'note'    => $d->note,
                'seats'   => $s ? (int) $s->seats : 0,
                'frozen'  => (bool) $s,
                'champion' => $c ? [
                    'name'  => $censor[$c->player_name] ?? $c->player_name,
                    'score' => (float) $c->final_score,
                    'tx'    => $c->translation_abbr,
                ] : null,
                'dayUrl'  => route('typing.scrimmage.daily.day', ['date' => $date]),
                'boardUrl' => route('typing.scrimmage.board', [
                    'b' => $d->book_slug, 'c' => $d->chapter,
                    'v' => $d->verse, 'lang' => $lang,
                ]),
            ];
        });

        return view('extras.daily-archive', [
            'rows'      => $rows,
            'paginator' => $days,
            // The walk so far. `days` counts the ledger including the queue,
            // so past days is the honest figure for "how far we have come".
            'daysDone'  => DailyVerse::where('date', '<', $today)->count(),
        ]);
    }

    /**
     * ONE FROZEN DAY  ·  /extras/scrimmage/daily/archive/{date}
     *
     * The whole field as it stood at the freeze, ranks and all, exactly as
     * ArchiveDailyBoards wrote them. Nothing here is recomputed — the table
     * IS the record, and it will render identically in fifty years when the
     * translations have been reseeded and the formula bumped four times.
     *
     * Three states a past day can be in: FROZEN (the normal case), PAST BUT
     * UNFROZEN (the minutes after midnight, or a dead scheduler), and PLAYED
     * BY NOBODY. Each says so plainly.
     */
    public function dailyArchiveDay(string $date): View|RedirectResponse
    {
        $lang  = 'en';
        $today = DailyVersePicker::normaliseDate();

        $ledger = DailyVerse::where('date', $date)->first();
        if (! $ledger) {
            abort(404);
        }

        // Today and the future belong to the live page, not the archive.
        if ($date >= $today) {
            return redirect()->route('typing.scrimmage.daily');
        }

        $censor = (array) config('typing.censor', []);

        $rows = DB::table('daily_snapshot_entries')
            ->where('date', $date)
            ->where('lang', $lang)
            ->orderBy('rank')
            ->get()
            ->map(fn ($r) => [
                'rank'  => (int) $r->rank,
                'name'  => $censor[$r->player_name] ?? $r->player_name,
                'score' => (float) $r->final_score,
                'net'   => (float) $r->net_wpm,
                'acc'   => (float) $r->accuracy,
                'wraps' => (int) $r->wraps,
                'tx'    => $r->translation_abbr,
            ]);

        // A past day with no snapshot hasn't been frozen yet — normal for a
        // few minutes past midnight (the archive runs late on purpose so
        // rounds begun before midnight can still file), and a sign the
        // scheduler has stopped if it persists.
        $frozen = DB::table('daily_snapshot_entries')
            ->where('date', $date)->where('lang', $lang)->exists();

        // The verse's ordinary board — permanent, unlimited, still open.
        $editionIds = Verse::whereHas('book', fn ($q) => $q->where('slug', $ledger->book_slug))
            ->where('chapter', $ledger->chapter)
            ->where('verse_number', $ledger->verse)
            ->pluck('translation_id');

        $edition = Translation::whereIn('id', $editionIds)
            ->where('language', $lang)
            ->orderByDesc('is_global')
            ->orderBy('sort_order')
            ->first();

        return view('extras.daily-archive-day', [
            'date'      => $date,
            'label'     => $ledger->date->format('l, F j, Y'),
            'verse'     => $ledger->label(),
            'note'      => $ledger->note,
            'rows'      => $rows,
            'frozen'    => $frozen,
            'topN'      => (int) config('typing.board_size'),
            'archiveUrl' => route('typing.scrimmage.daily.archive'),
            'boardUrl'  => route('typing.scrimmage.board', [
                'b' => $ledger->book_slug, 'c' => $ledger->chapter,
                'v' => $ledger->verse, 'lang' => $lang,
            ]),
            'playUrl'   => $edition ? route('typing.scrimmage.verse', [
                't' => strtolower($edition->abbreviation),
                'b' => $ledger->book_slug, 'c' => $ledger->chapter, 'v' => $ledger->verse,
            ]) : null,
            'readerUrl' => $edition ? route('bible.chapter', [
                'translation' => strtolower($edition->abbreviation),
                'book'        => $ledger->book_slug,
                'chapter'     => $ledger->chapter,
            ]) . '?v=' . $ledger->verse : null,
        ]);
    }

    /**
     * TODAY'S DAILY  ·  /extras/scrimmage/daily
     *
     * One verse, chosen by the ledger, the same for everyone on earth for one
     * day. Mechanically an ordinary scrim; what differs is the ceremony —
     * one shot, results sealed until the midnight freeze, then permanent.
     *
     * THE DATE IS THE SERVER'S, ALWAYS. It is handed to the blade so the
     * client never computes "today" from a local clock: a player in Tokyo and
     * a player in Texas must agree on which day they are playing, and on which
     * day their one-shot flag was spent.
     *
     * The ledger is read through DailyVersePicker::forDate, which persists a
     * deterministic pick if the scheduler never ran — so this page cannot
     * fail for want of a cron.
     */
    public function daily(Request $request): View|RedirectResponse
    {
        $date = DailyVersePicker::normaliseDate();

        // The sabbath: no verse exists, none will be created (the picker
        // refuses Saturdays outright), and the page says why.
        if (Sabbath::isSabbath()) {
            $tz = config('typing.board_trim.timezone', 'America/New_York');
            return view('extras.daily-sabbath', [
                'label' => \Illuminate\Support\Carbon::parse($date, $tz)->format('l, F j'),
            ]);
        }

        $ledger = DailyVersePicker::forDate($date);

        $book = Book::findBySlug($ledger->book_slug);
        if (! $book) {
            return redirect()->route('typing.scrimmage')
                ->with('scrim_error', "Today's verse is in a book that isn't in the canon.");
        }

        // Which editions carry it, in site priority order. The daily is a
        // VERSE, not an edition — every English edition shares one board —
        // so this only chooses what to show first; the blade's pills switch
        // freely from there.
        $editionIds = Verse::where('book_id', $book->id)
            ->where('chapter', $ledger->chapter)
            ->where('verse_number', $ledger->verse)
            ->pluck('translation_id');

        $editions = Translation::whereIn('id', $editionIds)
            ->where('language', 'en')
            ->orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();

        if ($editions->isEmpty()) {
            return redirect()->route('typing.scrimmage')
                ->with('scrim_error', "Today's verse isn't in any English edition.");
        }

        // Honour ?t= when it names an edition that actually carries the verse;
        // otherwise the highest-priority one. A bad ?t= is ignored, never an
        // error — this URL gets shared, and a stale edition slug in it should
        // still land the reader on today's daily.
        $wanted  = strtolower(trim((string) $request->query('t', '')));
        $edition = $editions->first(fn ($t) => strtolower($t->abbreviation) === $wanted)
            ?? $editions->first();

        try {
            $ch = Challenge::fromParams([
                'mode' => 'daily',
                'date' => $date,
                't'    => strtolower($edition->abbreviation),
                'b'    => $ledger->book_slug,
                'c'    => $ledger->chapter,
                'v'    => $ledger->verse,
            ]);
        } catch (\RuntimeException $e) {
            return redirect()->route('typing.scrimmage')
                ->with('scrim_error', $e->getMessage());
        }

        $defaultNames = array_values(array_filter(
            config('typing.default_names', []),
            fn ($n) => is_string($n) && preg_match('/^[A-Z0-9]{4}$/', $n)
        ));

        // The SITE clock, in epoch terms the client can compare against
        // Date.now() without knowing any timezone: the exact millisecond
        // this daily stops being today. The blade's stale-day watcher and
        // the one-shot flag both key off the server's day, never the
        // browser's — a player in Tokyo and one in Texas are on the same
        // board, and their flags spend on the same date.
        $tz = config('typing.board_trim.timezone', 'America/Denver');
        $rolloverAtMs = \Illuminate\Support\Carbon::parse($date, $tz)
            ->addDay()->getTimestampMs();

        // ONE BLADE, TWO CEREMONIES: this is the ordinary scrim view with a
        // $daily payload switching on the daily dress — the one-shot copy,
        // the sealed board, the practice link. Null on ordinary scrims.
        return view('extras.scrimmage-verse', [
            'scrim'        => $this->challengePayload($ch),
            'defaultNames' => $defaultNames,

            'daily' => [
                'date'         => $date,
                'label'        => \Illuminate\Support\Carbon::parse($date, $tz)->format('l, F j'),
                'note'         => $ledger->note,
                'rolloverAtMs' => $rolloverAtMs,
                // The same verse on its ordinary, permanent, unlimited
                // board. The daily is the ceremony; this is the training
                // ground — practising first is allowed and expected.
                'practiceUrl'  => route('typing.scrimmage.verse', [
                    't' => strtolower($edition->abbreviation),
                    'b' => $ledger->book_slug,
                    'c' => $ledger->chapter,
                    'v' => $ledger->verse,
                ]),
            ],

            'readerUrl'    => route('bible.chapter', [
                'translation' => strtolower($edition->abbreviation),
                'book'        => $ledger->book_slug,
                'chapter'     => $ledger->chapter,
            ]) . '?v=' . $ledger->verse,

            'scrimUrlPattern'  => $this->scrimUrlPattern(),
            'readerUrlPattern' => $this->readerUrlPattern(),
            // The daily's board is sealed, so the full-board link never
            // renders there — but the blade is shared and its constants
            // must exist on every path through it.
            'boardUrlPattern'   => $this->boardUrlPattern(),
            'boardShow'         => (int) config('typing.hub.board_show', 20),
            // The page must know the day BEFORE the first keystroke — a
            // round typed in ignorance and refused afterwards is a bait.
            'sabbath'           => Sabbath::isSabbath(),
        ]);
    }

    /**
     * A SCRIM — /extras/scrimmage/{t}/{b}/{c}/{v}
     *
     * The challenge is resolved HERE, server-side, and handed to the blade
     * as one pre-computed payload: reference, duration, and every edition's
     * text / difficulty / sealed token. The page paints complete on first
     * frame — no fetch stands between the URL and the verse.
     *
     * Tokens are therefore minted at HTML-generation time; the blade
     * re-mints silently if one goes stale (see typing.token_ttl_ms).
     *
     * An unresolvable URL bounces to the builder with its reason, rather
     * than rendering an empty scrim.
     */
    public function scrimmageVerse(string $t, string $b, int $c, int $v): View|RedirectResponse
    {
        try {
            $ch = Challenge::fromParams([
                'mode' => 'scrimmage',
                't'    => $t,
                'b'    => $b,
                'c'    => $c,
                'v'    => $v,
            ]);
        } catch (\RuntimeException $e) {
            return redirect()->route('typing.scrimmage')
                ->with('scrim_error', $e->getMessage());
        }

        // Dial defaults: only names the four dials could actually produce.
        // The config is curated to 4-char Bible names already; the filter is
        // belt-and-braces so a stray edit can't pre-set an impossible name.
        $defaultNames = array_values(array_filter(
            config('typing.default_names', []),
            fn ($n) => is_string($n) && preg_match('/^[A-Z0-9]{4}$/', $n)
        ));

        return view('extras.scrimmage-verse', [
            'scrim'             => $this->challengePayload($ch),
            'daily'             => null,            // ordinary scrim: no daily dress
            'defaultNames'      => $defaultNames,
            'readerUrl'         => route('bible.chapter', [
                'translation' => $ch->txSlug,
                'book'        => $ch->refs[0]['book']->slug,
                'chapter'     => $ch->refs[0]['chapter'],
            ]) . '?v=' . $ch->refs[0]['verse'],
            'scrimUrlPattern'   => $this->scrimUrlPattern(),
            'readerUrlPattern'  => $this->readerUrlPattern(),
            'boardUrlPattern'   => $this->boardUrlPattern(),
            'boardShow'         => (int) config('typing.hub.board_show', 20),
            // The page must know the day BEFORE the first keystroke — a
            // round typed in ignorance and refused afterwards is a bait.
            'sabbath'           => Sabbath::isSabbath(),
        ]);
    }

    /**
     * URL SHAPES FOR THE CLIENT. The router builds them with placeholders
     * so the JS never hardcodes a path — rename a route and these follow.
     * (encodeURIComponent on the client would mangle a real slug, hence the
     * sentinel tokens.)
     */
    private function scrimUrlPattern(): string
    {
        return route('typing.scrimmage.verse', [
            't' => '__T__', 'b' => '__B__', 'c' => '__C__', 'v' => '__V__',
        ], false);
    }

    private function readerUrlPattern(): string
    {
        return route('bible.chapter', [
            'translation' => '__T__', 'book' => '__B__', 'chapter' => '__C__',
        ], false) . '?v=__V__';
    }

    /** /extras/scrimmage/__B__/__C__/__V__/scrimboard-__L__ — the full board. */
    private function boardUrlPattern(): string
    {
        return route('typing.scrimmage.board', [
            'b' => '__B__', 'c' => '__C__', 'v' => '__V__', 'lang' => '__L__',
        ], false);
    }

    /**
     * Canon-ordered book chips for the verse picker: one flat, canon-ordered
     * list per TESTAMENT (like the QuickNav — books from different sections
     * and subgroups share lines), each book carrying its section's canon
     * tint. `label` is the compact chip text (the Book row's `short`, the
     * same source the QuickNav uses), `offset` the chapter display offset
     * (Five Psalms of David → 151..155). Translation availability is decided
     * CLIENT-SIDE against the outline endpoint, so this is edition-agnostic
     * and cacheable.
     */
    private function pickerTestaments(): array
    {
        $books = Book::all()->keyBy('slug');

        $out = [];
        foreach (config('canon.testaments') as $testament) {
            $list = [];
            foreach ($testament['sections'] as $key) {
                $section = config('canon.sections')[$key] ?? null;
                if (! $section) {
                    continue;
                }
                $color  = config('canon.section_colors')[$key] ?? 'clay';
                $groups = $section['subgroups'] ?? [['books' => $section['books'] ?? []]];

                foreach ($groups as $group) {
                    foreach ($group['books'] as $slug) {
                        $bk = $books->get($slug);
                        if (! $bk) {
                            continue;
                        }
                        $list[] = [
                            'slug'   => $bk->slug,
                            'name'   => $bk->name,
                            'label'  => $bk->short_name ?: $bk->name,
                            'color'  => $color,
                            'offset' => $bk->chapterCellOffset(),
                        ];
                    }
                }
            }
            if ($list) {
                $out[] = ['label' => $testament['label'], 'books' => $list];
            }
        }

        return $out;
    }

    /* ===================================================================== */
    /*  Daily: the ledger is the authority                                   */
    /* ===================================================================== */

    /**
     * Validate a client-supplied daily challenge against daily_verses.
     *
     * THE ATTACK THIS CLOSES: `date` arrives as a query param, so without
     * this check anyone could mint a token for any day they liked —
     * pre-farming next month's board before its verse is public, or
     * re-opening a day whose board is already frozen. The URL does not get
     * to say what the daily is. The ledger says.
     *
     * Three rules:
     *   1. The date must not be in the future. Tomorrow's verse is chosen,
     *      but it is nobody's business until tomorrow.
     *   2. The verse must be EXACTLY the one the ledger holds for that date.
     *   3. For token minting (`$mintable`), the date must be TODAY — a past
     *      day's board is finished, and a token for it would either land on
     *      a board about to be frozen or on nothing at all.
     *
     * Returns null when the challenge is legitimate, or the JSON error to
     * send back when it isn't.
     */
    private function dailyGuard(Challenge $ch, bool $mintable = true): ?JsonResponse
    {
        if ($ch->mode !== 'daily') {
            return null;
        }

        $today = DailyVersePicker::normaliseDate();

        // Named before the ledger lookup, so a Saturday date gets the real
        // reason rather than a confusing "no verse is recorded".
        if (Sabbath::dateIsSabbath($ch->date)) {
            return response()->json([
                'error' => 'There is no daily on the sabbath — a day of rest.',
            ], 422);
        }

        if ($ch->date > $today) {
            return response()->json(['error' => "That day hasn't come yet."], 422);
        }

        if ($mintable && $ch->date !== $today) {
            return response()->json([
                'error' => 'That daily is over. Today has its own verse.',
            ], 422);
        }

        $ledger = DailyVerse::where('date', $ch->date)->first();
        if (! $ledger) {
            return response()->json(['error' => 'No daily verse is recorded for that day.'], 422);
        }

        $r = $ch->refs[0];
        if ($ledger->book_slug !== $r['book']->slug
            || (int) $ledger->chapter !== (int) $r['chapter']
            || (int) $ledger->verse !== (int) $r['verse']) {
            return response()->json([
                'error' => 'That is not the daily verse for that day.',
            ], 422);
        }

        return null;
    }

    /**
     * Has this daily board been frozen into the archive yet?
     *
     * The freeze — not midnight — is what ends a daily board. A round begun
     * at 23:59 still submits to yesterday for a few minutes afterwards (see
     * the archive command's timing note), and it should be allowed to: the
     * player typed it in good faith while the day was still running. Once
     * the snapshot exists, the day is set in stone and nothing further may
     * touch it.
     */
    private function dailyIsFrozen(string $challengeKey): bool
    {
        return DB::table('daily_snapshot_entries')
            ->where('challenge_key', $challengeKey)
            ->exists();
    }

    /**
     * Resolve a URL-defined challenge and issue its start token(s).
     *
     * GET /extras/bible-typing/challenge?mode=scrimmage&t=kjv&b=romans&c=8&v=1
     * GET /extras/bible-typing/challenge?mode=triad&t=kjv&p=romans.8.1_john.3.16_psalms.23.1
     *
     * Still the live endpoint for reruns, token re-mints, and the builder's
     * verse preview — but the scrim PAGE no longer needs it to paint, since
     * scrimmageVerse() calls challengePayload() directly.
     */
    public function challenge(Request $request): JsonResponse
    {
        try {
            $ch = Challenge::fromParams($request->query());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // The ledger, not the URL, decides what the daily is.
        if ($guard = $this->dailyGuard($ch, mintable: true)) {
            return $guard;
        }

        return response()->json($this->challengePayload($ch));
    }

    /**
     * THE PAYLOAD — one resolved challenge, everything the client needs.
     *
     * SCRIMMAGE payloads carry a `variants` array: EVERY edition of the
     * verse, each with its own text, char count, difficulty modifier, and
     * sealed token (text and length differ per edition, so each needs its
     * own anchor). The client swaps editions with no refetch; the challenge
     * key is shared across all of them (see Challenge::canonical), so one
     * SCRIMBOARD serves every edition. The `t` param picks which variant
     * loads first — nothing more.
     *
     * The server resolves the text (never trusts the client), rates its
     * difficulty, and seals everything the scorer will need into each
     * encrypted token: identity, char count, modifier + formula version,
     * duration, and the issue time for wall-clock validation.
     */
    private function challengePayload(Challenge $ch): array
    {
        $issued = $this->nowMs();

        $sealToken = function (int $tid, int $chars, float $dmod, array $params) use ($ch, $issued) {
            return Crypt::encryptString(json_encode([
                'v'         => 2,
                'ck'        => $ch->key(),
                'mode'      => $ch->mode,
                'tid'       => $tid,
                'label'     => $ch->referenceLabel,
                'chars'     => $chars,
                'dmod'      => $dmod,
                'fver'      => DifficultyRater::VERSION,
                'dur'       => $ch->duration,
                'params'    => $params,
                'issued_ms' => $issued,
            ]));
        };

        // ---- Scrimmage + daily: build every edition of the verse -----------
        // A daily IS a scrimmage mechanically — one verse, one clock, the
        // same wrap unit — so it gets the same per-edition variants and the
        // same instant translation switching. Only the identity differs
        // (the date is in the key), and identity is Challenge's business.
        $variants = [];
        if ($ch->mode === 'scrimmage' || $ch->mode === 'daily') {
            $r = $ch->refs[0];

            $verseRows = Verse::where('book_id', $r['book']->id)
                ->where('chapter', $r['chapter'])
                ->where('verse_number', $r['verse'])
                ->get(['translation_id', 'text'])
                ->keyBy('translation_id');

            // Only editions in the challenge's LANGUAGE become variants —
            // a scrimboard is per-language (…|en|… in the key), so Spanish
            // text must never appear as a swap option on an English board.
            // A no-op while every edition is 'en'; load-bearing later.
            $editions = Translation::whereIn('id', $verseRows->keys())
                ->where('language', $ch->lang)
                ->orderByDesc('is_global')
                ->orderBy('sort_order')
                ->get();

            foreach ($editions as $t) {
                $slug  = strtolower($t->abbreviation);
                $text  = $verseRows[$t->id]->text;
                $chars = mb_strlen($text);
                $dmod  = DifficultyRater::rate($text);

                $params      = $ch->shareParams();
                $params['t'] = $slug;

                $variants[] = [
                    'slug'                => $slug,
                    'name'                => $t->name,
                    'year'                => $t->year_published,
                    'text'                => $text,
                    'char_count'          => $chars,
                    'difficulty_modifier' => $dmod,
                    'formula_version'     => DifficultyRater::VERSION,
                    'token'               => $sealToken($t->id, $chars, $dmod, $params),
                ];
            }
        }

        // ---- Active edition: what the top-level fields describe ------------
        $activeModifier = DifficultyRater::rate($ch->text);
        $activeToken    = $sealToken($ch->translation->id, $ch->charCount, $activeModifier, $ch->shareParams());
        $activeText     = $ch->text;
        $activeChars    = $ch->charCount;

        foreach ($variants as $v) {
            if ($v['slug'] === $ch->txSlug) {
                $activeModifier = $v['difficulty_modifier'];
                $activeToken    = $v['token'];
                $activeText     = $v['text'];
                $activeChars    = $v['char_count'];
                break;
            }
        }

        return [
            'mode'                => $ch->mode,
            'challenge_key'       => $ch->key(),
            'lang'                => $ch->lang,     // the board's language — the
                                                    // blade builds /scrimboard-{lang}
                                                    // links from it
            'translation'         => $ch->txSlug,
            'reference'           => $ch->referenceLabel,
            'refs'                => array_map(fn ($r) => [
                'book'    => $r['book']->name,
                'slug'    => $r['book']->slug,
                'chapter' => $r['chapter'],
                'verse'   => $r['verse'],
                'text'    => $r['text'],
            ], $ch->refs),
            'text'                => $activeText,      // triad: full target;
                                                       // scrimmage: the wrap unit
            'char_count'          => $activeChars,
            'duration'            => $ch->duration,
            'difficulty_modifier' => $activeModifier,
            'formula_version'     => DifficultyRater::VERSION,
            'params'              => $ch->shareParams(),
            'variants'            => $variants,        // scrimmage only; [] for triad
            'token'               => $activeToken,
        ];
    }

    /**
     * The leaderboard for one exact challenge — same params as challenge(),
     * so any share URL can render its own board with no pre-registration.
     * Scrimmage boards are translation-unified: rows from every edition
     * mingle, each carrying its edition's short label for the TR column.
     *
     * DIAL-NAME extras per row:
     *   alt    — the censor replacement (typing.censor), or null when clean.
     *            The DB keeps the raw name; the map is consulted at serve
     *            time, so editing the config retro-censors live boards.
     *   held   — this row survived a nightly scrim:trim (★ champion mark).
     *   claims — how many holders the name has had here (takeover count).
     *   since  — when the name first appeared on this board ("Jul 12").
     *
     * Board-level: trimmed_last_night = a survivor stamp fresher than the
     * last local midnight exists → the blade shows its "yesterday's top 10"
     * caption. Only a board the knife actually touched earns the language.
     */
    public function challengeBoard(Request $request): JsonResponse
    {
        try {
            $ch = Challenge::fromParams($request->query());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // A board may be read for any past day, but only for the real verse.
        if ($guard = $this->dailyGuard($ch, mintable: false)) {
            return $guard;
        }

        $key = $ch->key();

        // ---- THE SEALED ENVELOPE --------------------------------------------
        // A daily board is not readable while its day is running. Nobody gets
        // to see the mark they need to beat: everyone types into the dark and
        // the field is revealed together at the freeze. The player count is
        // the one thing shown — how many showed up, never how they did.
        if ($ch->mode === 'daily') {
            if (! $this->dailyIsFrozen($key)) {
                return response()->json([
                    'sealed'  => true,
                    'date'    => $ch->date,
                    'players' => TypingScore::where('challenge_key', $key)->count(),
                    'board'   => [],
                ]);
            }

            // Frozen: served from the archive, ranks exactly as they froze.
            // This is also what the daily archive pages will read (phase D).
            $frozen = DB::table('daily_snapshot_entries')
                ->where('challenge_key', $key)
                ->orderBy('rank')
                ->get();

            $censor = array_flip((array) config('typing.censor', []));

            return response()->json([
                'sealed' => false,
                'frozen' => true,
                'date'   => $ch->date,
                'board'  => $frozen->map(fn ($r) => [
                    'rank'                => (int) $r->rank,
                    'player_name'         => $r->player_name,
                    'censored'            => isset($censor[$r->player_name]),
                    'alt'                 => $censor[$r->player_name] ?? null,
                    'final_score'         => (float) $r->final_score,
                    'net_wpm'             => (float) $r->net_wpm,
                    'accuracy'            => (float) $r->accuracy,
                    'wraps'               => (int) $r->wraps,
                    'best_combo'          => (int) $r->best_combo,
                    'error_count'         => (int) $r->error_count,
                    'tx'                  => $r->translation_abbr,
                    'difficulty_modifier' => $r->difficulty_modifier !== null
                        ? (float) $r->difficulty_modifier : null,
                    'formula_version'     => $r->formula_version,
                ])->values(),
            ]);
        }

        // ---- THE SABBATH VEIL -----------------------------------------------
        // On Saturdays scrimboards are present, already cut, and merely
        // unseen — the Sunday "restoration" is nothing but this branch
        // ceasing to match at midnight. No job restores anything.
        if ($ch->mode === 'scrimmage' && Sabbath::isSabbath()) {
            return response()->json([
                'sabbath' => true,
                'board'   => [],
            ]);
        }

        // Scrimboards show the whole intra-day field (up to the cap);
        // triad boards keep the classic top-N.
        $limit = $ch->mode === 'scrimmage'
            ? (int) config('typing.board_cap')
            : (int) config('typing.board_size');

        $rows = TypingScore::where('challenge_key', $key)
            ->orderByDesc('final_score')
            ->orderByDesc('accuracy')
            ->orderBy('created_at')            // ties: the incumbent outranks
            ->limit($limit)
            ->get(['player_name', 'final_score', 'net_wpm', 'accuracy',
                   'translation_id', 'difficulty_modifier', 'formula_version',
                   'error_count', 'wraps', 'best_combo',
                   'claim_count', 'first_claimed_at', 'survived_trim_at',
                   'created_at']);

        // One lookup for the TR column: translation_id → short label.
        $txById = Translation::whereIn(
                'id', $rows->pluck('translation_id')->filter()->unique()
            )->get()->keyBy('id');

        // Flipped once for O(1) membership tests — the config is a flat list.
        $censor = array_flip((array) config('typing.censor', []));

        $board = $rows->values()->map(function ($r, $i) use ($txById, $censor) {
            $t = $txById->get($r->translation_id);
            return [
                'rank'            => $i + 1,
                'name'            => $r->player_name,
                'alt'             => $censor[$r->player_name] ?? null,
                'held'            => $r->survived_trim_at !== null,
                'claims'          => (int) ($r->claim_count ?? 1),
                'since'           => $r->first_claimed_at ? $r->first_claimed_at->format('M j') : null,
                'final_score'     => $r->final_score,
                'net_wpm'         => $r->net_wpm,
                'accuracy'        => $r->accuracy,
                'tx'              => $t ? strtoupper($t->abbreviation) : '',
                'errors'          => $r->error_count,
                'combo'           => $r->best_combo,   // null on pre-v4 rows → "—"
                'wraps'           => $r->wraps,        // null on pre-v4 rows → "—"
                'modifier'        => $r->difficulty_modifier,
                'formula_version' => $r->formula_version,
                'when'            => $r->created_at->diffForHumans(),
                'censored'        => isset($censor[$r->player_name]),
            ];
        });

        return response()->json([
            'challenge_key'       => $key,
            'mode'                => $ch->mode,
            'reference'           => $ch->referenceLabel,
            'formula_version'     => DifficultyRater::VERSION,
            'entries'             => TypingScore::where('challenge_key', $key)->count(),
            'board_size'          => (int) config('typing.board_size'),
            // Key name kept for the client contract; the MEANING is now
            // "crowned at the last sabbath cut" (part 2 updates the copy).
            'trimmed_last_night'  => $this->trimmedSinceLastCut($rows),
            'board'               => $board,
        ]);
    }

    /**
     * True when any row's survivor stamp is fresher than the most recent
     * SABBATH CUT (Saturday 00:00, site clock) — the trim is weekly now,
     * so this holds all week and the champion caption stands with it.
     * Carbon compares instants, so stored-UTC vs Eastern-midnight is
     * handled correctly.
     */
    private function trimmedSinceLastCut($rows): bool
    {
        $cut = Sabbath::lastCutAt();

        return $rows->contains(
            fn ($r) => $r->survived_trim_at !== null
                    && $r->survived_trim_at->greaterThanOrEqualTo($cut)
        );
    }

    /* ===================================================================== */
    /*  Free-Play menus: book → chapter → verse-count for one translation    */
    /* ===================================================================== */

    public function outline(Request $request): JsonResponse
    {
        $t = Translation::findBySlug((string) $request->query('translation', ''));
        if (! $t) {
            return response()->json(['error' => 'Unknown translation.'], 422);
        }

        // One grouped query: for this translation, every (book, chapter) and how
        // many verses it has. Cheap, and powers all three Free Play dropdowns.
        $rows = Verse::where('translation_id', $t->id)
            ->select('book_id', 'chapter', DB::raw('MAX(verse_number) as verses'))
            ->groupBy('book_id', 'chapter')
            ->get();

        $books = Book::orderBy('book_order')->get(['id', 'slug', 'name'])->keyBy('id');

        // Shape: [{ slug, name, chapters: { "1": 31, "2": 25, ... } }, ...]
        $out = [];
        foreach ($rows->groupBy('book_id') as $bookId => $chapters) {
            $book = $books->get($bookId);
            if (! $book) {
                continue;
            }
            $map = [];
            foreach ($chapters->sortBy('chapter') as $c) {
                $map[(int) $c->chapter] = (int) $c->verses;
            }
            $out[] = [
                'slug'     => $book->slug,
                'name'     => $book->name,
                'order'    => $book->book_order,
                'chapters' => $map,
            ];
        }

        usort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        return response()->json(['books' => $out]);
    }

    /* ===================================================================== */
    /*  Serve a passage to type                                              */
    /* ===================================================================== */

    public function passage(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', '');

        try {
            // ---- FREE PLAY: exact, unranked, no token ----------------------
            if ($mode === 'freeplay') {
                $data = $request->validate([
                    'translation' => 'required|string',
                    'book'        => 'required|string',
                    'chapter'     => 'required|integer|min:1',
                    'verse_start' => 'required|integer|min:1',
                    'verse_end'   => 'nullable|integer|min:1',
                ]);

                $t = Translation::findBySlug($data['translation']);
                $b = Book::findBySlug($data['book']);
                abort_unless($t && $b, 422, 'Unknown translation or book.');

                $payload = $this->selector->freePlay(
                    $t, $b, (int) $data['chapter'],
                    (int) $data['verse_start'],
                    isset($data['verse_end']) ? (int) $data['verse_end'] : null
                );

                return response()->json([
                    'ranked'     => false,
                    'text'       => $payload['text'],
                    'reference'  => $payload['reference'],
                    'word_count' => $payload['word_count'],
                    'char_count' => $payload['char_count'],
                ]);
            }

            // ---- RANKED: length-controlled, stored, token-anchored ---------
            $data = $request->validate([
                'tier'       => 'required|in:sprint,standard,endurance',
                'difficulty' => 'required|in:normal,hard',
                'book'       => 'nullable|string',   // set = roulette (lock to book)
            ]);

            $book = ! empty($data['book']) ? Book::findBySlug($data['book']) : null;

            $passage = $this->selector->ranked($data['tier'], $data['difficulty'], $book);

            // Signed, encrypted start token. The browser can't read or forge it;
            // it just hands it back on submit so we can time the round server-side.
            $token = Crypt::encryptString(json_encode([
                'pid'        => $passage->id,
                'chars'      => $passage->char_count,
                'mode'       => $data['tier'],
                'difficulty' => $data['difficulty'],
                'issued_ms'  => $this->nowMs(),
            ]));

            return response()->json([
                'ranked'     => true,
                'token'      => $token,
                'text'       => $passage->text,
                'reference'  => $passage->reference_label,
                'word_count' => $passage->word_count,
                'char_count' => $passage->char_count,
                'mode'       => $data['tier'],
                'difficulty' => $data['difficulty'],
            ]);
        } catch (\RuntimeException $e) {
            // Selector "soft" failures (translation not loaded, empty selection)
            // are user-facing messages, not 500s.
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /* ===================================================================== */
    /*  Round-completion beacon (anonymous play counter)                     */
    /* ===================================================================== */

    /**
     * A round finished. Bump scrim_plays and answer 204 — no body, nothing
     * to render, the client fires and forgets.
     *
     * WHY THIS ISN'T PART OF /score: submitting a name is a CLAIM, and a
     * claim is neither necessary nor unique per round. A defended name can
     * be re-challenged all afternoon on one token (inflation), a zero-score
     * round never submits at all (silence), and a good round whose player
     * shrugs and reruns submits nothing either (bias). The only event that
     * means "this verse was played once" is the end of the round.
     *
     * IDEMPOTENT PER TOKEN: one minted token is one round (newRound()
     * re-mints), so a cache flag keyed on the token makes a second count
     * for the same round impossible — retries, double-fires, and a jumpy
     * network all collapse to one.
     *
     * The cheap gates below aren't anti-cheat so much as anti-nonsense: a
     * real token, unexpired, a clock that actually ran, and enough
     * keystrokes to call it typing. Inflating a count would cost a fresh
     * throttled token and a real 20-second wait per increment — steep
     * enough for a number with no prize attached.
     */
    public function played(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'            => 'required|string',
            'duration_ms'      => 'required|integer|min:1000',
            'total_keystrokes' => 'required|integer|min:0',
        ]);

        // ---- 1. A real, unexpired token -------------------------------------
        try {
            $claim = json_decode(Crypt::decryptString($data['token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException $e) {
            return response()->json([], 204);        // silent: it's a counter
        }

        if ((int) ($claim['v'] ?? 1) !== 2) {
            return response()->json([], 204);        // legacy tokens don't count
        }

        $issuedMs   = (int) ($claim['issued_ms'] ?? 0);
        $serverWall = $this->nowMs() - $issuedMs;
        if ($serverWall < 0 || $serverWall > (int) config('typing.token_ttl_ms')) {
            return response()->json([], 204);
        }

        // ---- 2. The clock actually ran --------------------------------------
        // Same wall-clock logic the score path uses: you can't have typed
        // for longer than the server has been waiting. Combined with the
        // tier check, a beacon fired at page load counts for nothing.
        $grace = (int) config('typing.token_grace_ms');
        if ((int) $data['duration_ms'] > $serverWall + $grace) {
            return response()->json([], 204);
        }

        $dur = (int) ($claim['dur'] ?? 0);
        if ($dur > 0) {
            $tolerance = (int) config('typing.challenge.time_tolerance_ms');
            if (abs((int) $data['duration_ms'] - $dur * 1000) > $tolerance) {
                return response()->json([], 204);
            }
        }

        // ---- 3. Somebody actually typed -------------------------------------
        // A round where nothing was entered is a page left open, not a play.
        // The floor is deliberately low — a bad round is still a round.
        $minKeys = (int) config('typing.play_min_keystrokes', 10);
        if ((int) $data['total_keystrokes'] < $minKeys) {
            return response()->json([], 204);
        }

        // ---- 4. Once per round, no matter how many times this fires ---------
        $this->recordPlay($claim, $data['token']);

        return response()->json([], 204);
    }

    /* ===================================================================== */
    /*  Submit a ranked score                                                */
    /* ===================================================================== */

    public function score(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'            => 'required|string',
            'player_name'      => 'required|string|max:24',
            'duration_ms'      => 'required|integer|min:1000',     // ≥1s
            'total_keystrokes' => 'required|integer|min:1',
            'error_count'      => 'required|integer|min:0',
            'char_count'       => 'required|integer|min:1',
            'best_combo'       => 'nullable|integer|min:0',
        ]);

        // ---- 1. Decode the start token --------------------------------------
        try {
            $claim = json_decode(Crypt::decryptString($data['token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException $e) {
            return response()->json(['error' => 'Invalid or tampered start token.'], 422);
        }

        // ---- 2. Wall-clock: shared by both token species --------------------
        $issuedMs   = (int) ($claim['issued_ms'] ?? 0);
        $serverWall = $this->nowMs() - $issuedMs;            // real ms since serve
        $ttl        = (int) config('typing.token_ttl_ms');
        if ($serverWall < 0 || $serverWall > $ttl) {
            return response()->json(['error' => 'That round has expired.'], 422);
        }

        // You physically can't have typed for LESS time than elapsed on the
        // server (minus a little grace for clock skew). This forces anyone
        // scripting fake scores to actually wait out the time they claim.
        $grace = (int) config('typing.token_grace_ms');
        if ((int) $data['duration_ms'] > $serverWall + $grace) {
            return response()->json([
                'error' => 'Timing mismatch — the round was timed on the server.',
            ], 422);
        }

        if ((int) $data['error_count'] > (int) $data['total_keystrokes']) {
            return response()->json(['error' => 'Impossible error count.'], 422);
        }

        // ---- 3. Branch on token schema: v2 = challenge, else legacy ---------
        if ((int) ($claim['v'] ?? 1) === 2) {
            return $this->scoreChallenge($request, $data, $claim);
        }
        return $this->scoreLegacy($request, $data, $claim);
    }

    /**
     * Score a CHALLENGE round (scrimmage / triad). The token carries the
     * authoritative identity, char count, and difficulty; the browser only
     * reports raw counts, and every metric is recomputed here.
     *
     * DIAL NAMES + TAKEOVER: names must be ^[A-Z0-9]{4}$ (the only alphabet
     * the four dials produce). One row per (challenge_key, player_name) —
     * enforced here inside a transaction AND by the uk_board_name unique
     * index underneath:
     *
     *   no row yet          → insert (claim_count 1, first_claimed_at now),
     *                         unless the board is at typing.board_cap and
     *                         this score doesn't beat its floor (→ board_full).
     *   row exists, ≥ ours  → the DEFENDER HOLDS. 200 with held:true and the
     *                         defender's numbers — the client renders the
     *                         duel plaque, never an error.
     *   row exists, < ours  → TAKEOVER: metrics replaced, claim_count bumped,
     *                         first_claimed_at preserved, survivor stamp
     *                         cleared, created_at refreshed (the row is a
     *                         new deed).
     */
    private function scoreChallenge(Request $request, array $data, array $claim): JsonResponse
    {
        $mode = (string) ($claim['mode'] ?? '');
        if (! in_array($mode, Challenge::MODES, true)) {
            return response()->json(['error' => 'Invalid or tampered start token.'], 422);
        }

        // ---- The sabbath keeps no scores ------------------------------------
        // Gated on the MINT moment, not the submission moment, which grants
        // the grace both ways: a round begun Friday 23:59 files into
        // Friday's week a few minutes after midnight (mirroring the daily
        // freeze), and a round begun Saturday 23:59 stays scoreless even
        // submitted Sunday 00:01 — a round belongs to the day it began.
        // This is the ONLY enforcement that matters; everything the blades
        // do about it is courtesy.
        if ($mode === 'scrimmage'
            && Sabbath::mintedOnSabbath((int) ($claim['issued_ms'] ?? 0))) {
            return response()->json([
                'error' => 'It is the sabbath — a day of rest. Rounds may be typed, '
                         . 'but no score is kept and no name is set. The boards return tonight.',
            ], 422);
        }

        // ---- A frozen day takes no more scores ------------------------------
        // Not "is it still today" — the freeze is the ending, and it runs a
        // few minutes late on purpose so a round begun before midnight can
        // still be filed. After that the archive is written and the ranks
        // are permanent; nothing may join a field that is already stone.
        if ($mode === 'daily' && $this->dailyIsFrozen((string) $claim['ck'])) {
            return response()->json([
                'error' => "That day's board is set in stone. Today has its own verse.",
            ], 422);
        }

        // ---- Dial name: the four-character contract -------------------------
        $name = strtoupper(trim((string) $data['player_name']));
        if (! preg_match('/^[A-Z0-9]{4}$/', $name)) {
            return response()->json([
                'error' => 'Names are four dial characters — letters and numbers only.',
            ], 422);
        }

        // ---- Mode-specific anchors ------------------------------------------
        if ($mode === 'triad') {
            // A triad is complete or it's nothing: the typed char count must
            // equal the served target exactly.
            if ((int) $data['char_count'] !== (int) $claim['chars']) {
                return response()->json(['error' => 'Score does not match the served passage.'], 422);
            }
        } else {
            // SCRIMMAGE: the clock is the anchor, not the char count (the
            // verse wraps, so chars vary). The claimed duration must sit on
            // its tier within tolerance…
            $tierMs    = ((int) $claim['dur']) * 1000;
            $tolerance = (int) config('typing.challenge.time_tolerance_ms');
            if (abs((int) $data['duration_ms'] - $tierMs) > $tolerance) {
                return response()->json(['error' => 'Timing does not match the challenge duration.'], 422);
            }
            // …chars can't exceed keystrokes, and the char rate can't be
            // superhuman even if the keystroke count somehow was plausible.
            if ((int) $data['char_count'] > (int) $data['total_keystrokes']) {
                return response()->json(['error' => 'Impossible character count.'], 422);
            }
            $charWpm = (($data['char_count'] / 5) / ($data['duration_ms'] / 60000));
            if ($charWpm > (float) config('typing.max_gross_wpm')) {
                return response()->json(['error' => 'Score rejected as implausible.'], 422);
            }
        }

        // ---- Metrics: recomputed server-side, never trusted -----------------
        // Speed math lives in DifficultyRater (v3) so the controller, the
        // legacy path, and the client mirror can't drift apart.
        $grossWpm = DifficultyRater::grossWpm((int) $data['total_keystrokes'], (int) $data['duration_ms']);
        $netWpm   = DifficultyRater::netWpm(
            (int) $data['total_keystrokes'], (int) $data['error_count'], (int) $data['duration_ms']
        );
        $accuracy = (($data['total_keystrokes'] - $data['error_count']) / $data['total_keystrokes']) * 100;

        if ($grossWpm > (float) config('typing.max_gross_wpm')) {
            return response()->json(['error' => 'Score rejected as implausible.'], 422);
        }

        // The one number boards sort by. Modifier + formula version come from
        // the token — the SAME values shown at issue, pinned to this row.
        $modifier   = (float) $claim['dmod'];
        $formulaVer = (int) $claim['fver'];

        // v2 scrimmage bonuses: wraps are DERIVED (⌊chars typed / verse
        // length⌋), never claimed, and "perfect" is simply a zero error
        // count — both verifiable from data already validated above.
        $wraps = 0;
        $wrapMult = 1.0;
        $perfectMult = 1.0;
        if (($mode === 'scrimmage' || $mode === 'daily') && (int) $claim['chars'] > 0) {
            $wraps       = intdiv((int) $data['char_count'], (int) $claim['chars']);
            $wrapMult    = DifficultyRater::wrapMultiplier($wraps);
            $perfectMult = DifficultyRater::perfectMultiplier($wraps, (int) $data['error_count']);
        }

        $finalScore = round(
            DifficultyRater::finalScore($netWpm, $accuracy, $modifier) * $wrapMult * $perfectMult,
            2
        );

        // A round that scored nothing leaves no name.
        // characters each in the net-WPM penalty, so accuracy at or below
        // 80% floors net WPM at zero and takes the whole product with it.
        // The client hides the claim row in that case; this is the backstop
        // against a crafted submission.
        if ($finalScore <= 0) {
            return response()->json([
                'ok'       => true,
                'no_score' => true,
                'score'    => ['final_score' => $finalScore],
            ]);
        }

        // Everything a row holds, whether it's born fresh or seizes a seat.
        $metrics = [
            'typing_passage_id'   => null,
            'translation_id'      => (int) $claim['tid'],
            'reference_label'     => (string) $claim['label'],
            'mode'                => $mode,          // fills the legacy column too
            'difficulty'          => null,           // replaced by the modifier
            'player_name'         => $name,
            'gross_wpm'           => round($grossWpm, 2),
            'net_wpm'             => round($netWpm, 2),
            'accuracy'            => round($accuracy, 2),
            'char_count'          => (int) $data['char_count'],
            'total_keystrokes'    => (int) $data['total_keystrokes'],
            'error_count'         => (int) $data['error_count'],
            'duration_ms'         => (int) $data['duration_ms'],
            'ip_hash'             => $this->ipHash($request),
            'challenge_key'       => (string) $claim['ck'],
            'challenge_mode'      => $mode,
            'final_score'         => $finalScore,
            'difficulty_modifier' => $modifier,
            'formula_version'     => $formulaVer,
            'duration_config'     => $claim['dur'] !== null ? (int) $claim['dur'] : null,
            'params_json'         => $claim['params'] ?? null,
            // Popover detail stats. wraps: the same server-derived figure the
            // bonus already used. best_combo: client-claimed, clamped so it
            // can never exceed the characters actually typed; display-only.
            'wraps'               => in_array($mode, ['scrimmage', 'daily'], true) ? $wraps : null,
            'best_combo'          => isset($data['best_combo'])
                ? min((int) $data['best_combo'], (int) $data['char_count'])
                : null,
        ];

        // ---- The seat contest, inside one transaction -----------------------
        // Two submissions for the same fresh name can still race past the
        // SELECT together; the unique index catches the loser's INSERT, and
        // one retry sends it down the compare-and-takeover path instead.
        $result = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $result = DB::transaction(function () use ($claim, $mode, $name, $finalScore, $metrics) {
                    $existing = TypingScore::where('challenge_key', (string) $claim['ck'])
                        ->where('player_name', $name)
                        ->lockForUpdate()
                        ->first();

                    // The defender holds — an equal score keeps the seat too
                    // (same rule as the trim's tie: the incumbent wins).
                    if ($existing && (float) $existing->final_score >= $finalScore) {
                        return ['outcome' => 'held', 'row' => $existing];
                    }

                    // TAKEOVER: same seat, new holder.
                    if ($existing) {
                        $existing->fill($metrics);
                        $existing->claim_count      = ((int) $existing->claim_count) + 1;
                        $existing->survived_trim_at = null;   // nothing defended yet
                        $existing->created_at       = now();  // the deed is fresh
                        $existing->save();
                        return ['outcome' => 'takeover', 'row' => $existing];
                    }

                    // FRESH NAME on a FULL board: only a score that beats the
                    // board's floor earns a seat (intra-day flood ceiling —
                    // the nightly trim does the real cleaning).
                    //
                    // THE DAILY IS EXEMPT. Its board is never trimmed and the
                    // whole field is archived — one player or ten thousand,
                    // everyone who showed up is in the record. A cap here
                    // would turn the archive into a top-N list and quietly
                    // discard the very thing it exists to keep. The per-IP
                    // daily rate limit remains the abuse ceiling.
                    $cap   = (int) config('typing.board_cap');
                    $count = TypingScore::where('challenge_key', (string) $claim['ck'])->count();
                    if ($mode !== 'daily' && $count >= $cap) {
                        $floor = TypingScore::where('challenge_key', (string) $claim['ck'])
                            ->orderByDesc('final_score')
                            ->orderByDesc('accuracy')
                            ->orderBy('created_at')
                            ->skip($cap - 1)
                            ->value('final_score');
                        if ($floor !== null && $finalScore <= (float) $floor) {
                            return ['outcome' => 'full', 'floor' => (float) $floor];
                        }
                        // A seat is earned; the overflow row below the cap
                        // will fall in tonight's trim.
                    }

                    $row = TypingScore::create($metrics + [
                        'claim_count'      => 1,
                        'first_claimed_at' => now(),
                    ]);
                    return ['outcome' => 'claimed', 'row' => $row];
                });
                break;
            } catch (QueryException $e) {
                // 23000 = integrity violation (the racing INSERT). Loop once:
                // the row exists now, so the takeover path handles it.
                if ($attempt === 0 && (string) $e->getCode() === '23000') {
                    continue;
                }
                throw $e;
            }
        }

        // Flipped once for O(1) membership tests — the config is a flat list.
        $censor = array_flip((array) config('typing.censor', []));

        // ---- HELD: the defender's numbers, never an error -------------------
        if ($result['outcome'] === 'held') {
            $holder = $result['row'];
            // SEALED DAILY: "that name is defended" is all a challenger may
            // learn — the holder's numbers would be a read on one sealed
            // row. Ordinary boards keep the full duel plaque.
            return response()->json([
                'ok'     => true,
                'held'   => true,
                'name'   => $name,
                'holder' => $mode === 'daily' ? null : [
                    'final_score' => $holder->final_score,
                    'censored'    => isset($censor[$holder->player_name]),
                    'alt'         => $censor[$holder->player_name] ?? null,
                    'claims'      => (int) ($holder->claim_count ?? 1),
                    'since'       => $holder->first_claimed_at
                        ? $holder->first_claimed_at->format('M j') : null,
                    'when'        => $holder->created_at->diffForHumans(),
                ],
                // The challenger's own (authoritative) numbers, for the plaque.
                'score'  => [
                    'final_score'        => $finalScore,
                    'net_wpm'            => round($netWpm, 2),
                    'accuracy'           => round($accuracy, 2),
                    'wraps'              => $wraps,
                    'wrap_multiplier'    => $wrapMult,
                    'perfect_multiplier' => $perfectMult,
                ],
            ]);
        }

        // ---- FULL: the board is at its intra-day cap ------------------------
        if ($result['outcome'] === 'full') {
            return response()->json([
                'ok'         => true,
                'board_full' => true,
                'floor'      => $result['floor'],
                'cap'        => (int) config('typing.board_cap'),
                'score'      => [
                    'final_score' => $finalScore,
                    'net_wpm'     => round($netWpm, 2),
                    'accuracy'    => round($accuracy, 2),
                ],
            ]);
        }

        $score = $result['row'];

        // Rank within THIS challenge (the share-link loop), by final score.
        // SEALED DAILY: no rank — your placement among the field is exactly
        // what the freeze reveals. Null here; the client shows a sealed
        // confirmation instead of a glowing row.
        $rank = null;
        if ($mode !== 'daily') {
            $rank = TypingScore::where('challenge_key', $score->challenge_key)
                ->where('final_score', '>', $score->final_score)
                ->count() + 1;
        }

        return response()->json([
            'ok'          => true,
            'held'        => false,
            'takeover'    => $result['outcome'] === 'takeover',
            'name'        => $name,
            'censored'    => isset($censor[$name]),
            'alt'         => $censor[$name] ?? null,
            'claims'      => (int) $score->claim_count,
            'rank'        => $rank,
            'made_board'  => $rank !== null && $rank <= (int) config('typing.board_size'),
            'score'       => [
                'final_score'         => $score->final_score,
                'gross_wpm'           => $score->gross_wpm,
                'net_wpm'             => $score->net_wpm,
                'accuracy'            => $score->accuracy,
                'difficulty_modifier' => $score->difficulty_modifier,
                'formula_version'     => $score->formula_version,
                'wraps'               => $wraps,
                'wrap_multiplier'     => $wrapMult,
                'perfect_multiplier'  => $perfectMult,
            ],
        ]);
    }

    /**
     * Score a LEGACY prototype round (stored-passage tokens). Unchanged
     * behaviour, isolated here so the old game keeps working until retired.
     * (Names now pass through the 4-char cleanName coercion — the column
     * shrank underneath this path.)
     */
    private function scoreLegacy(Request $request, array $data, array $claim): JsonResponse
    {
        $passage = TypingPassage::find($claim['pid'] ?? 0);
        if (! $passage) {
            return response()->json(['error' => 'That round has expired.'], 422);
        }

        // Anchor: typed text must match the served text.
        if ((int) $data['char_count'] !== (int) $claim['chars']) {
            return response()->json(['error' => 'Score does not match the served passage.'], 422);
        }

        // Metrics: recomputed server-side from raw counts, via the shared
        // v3 speed math (see DifficultyRater).
        $grossWpm = DifficultyRater::grossWpm((int) $data['total_keystrokes'], (int) $data['duration_ms']);
        $netWpm   = DifficultyRater::netWpm(
            (int) $data['total_keystrokes'], (int) $data['error_count'], (int) $data['duration_ms']
        );
        $accuracy = (($data['total_keystrokes'] - $data['error_count']) / $data['total_keystrokes']) * 100;

        if ($grossWpm > (float) config('typing.max_gross_wpm')) {
            return response()->json(['error' => 'Score rejected as implausible.'], 422);
        }

        $score = TypingScore::create([
            'typing_passage_id' => $passage->id,
            'translation_id'    => $passage->translation_id,
            'reference_label'   => $passage->reference_label,
            'mode'              => (string) $claim['mode'],
            'difficulty'        => (string) $claim['difficulty'],
            'player_name'       => $this->cleanName($data['player_name']),
            'gross_wpm'         => round($grossWpm, 2),
            'net_wpm'           => round($netWpm, 2),
            'accuracy'          => round($accuracy, 2),
            'char_count'        => (int) $data['char_count'],
            'total_keystrokes'  => (int) $data['total_keystrokes'],
            'error_count'       => (int) $data['error_count'],
            'duration_ms'       => (int) $data['duration_ms'],
            'ip_hash'           => $this->ipHash($request),
        ]);

        $rank = TypingScore::where('mode', $score->mode)
            ->where('difficulty', $score->difficulty)
            ->where('net_wpm', '>', $score->net_wpm)
            ->count() + 1;

        return response()->json([
            'ok'        => true,
            'rank'      => $rank,
            'made_board'=> $rank <= (int) config('typing.board_size'),
            'score'     => [
                'gross_wpm' => $score->gross_wpm,
                'net_wpm'   => $score->net_wpm,
                'accuracy'  => $score->accuracy,
            ],
        ]);
    }

    /* ===================================================================== */
    /*  Leaderboard                                                           */
    /* ===================================================================== */

    public function leaderboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode'       => 'required|in:sprint,standard,endurance',
            'difficulty' => 'required|in:normal,hard',
        ]);

        $rows = TypingScore::where('mode', $data['mode'])
            ->where('difficulty', $data['difficulty'])
            ->orderByDesc('net_wpm')
            ->orderByDesc('accuracy')
            ->limit((int) config('typing.board_size'))
            ->get(['player_name', 'net_wpm', 'gross_wpm', 'accuracy', 'reference_label', 'created_at']);

        $board = $rows->values()->map(fn ($s, $i) => [
            'rank'      => $i + 1,
            'name'      => $s->player_name,
            'net_wpm'   => $s->net_wpm,
            'gross_wpm' => $s->gross_wpm,
            'accuracy'  => $s->accuracy,
            'reference' => $s->reference_label,
            'when'      => $s->created_at->diffForHumans(),
        ]);

        return response()->json(['board' => $board]);
    }

    /* ===================================================================== */
    /*  Helpers                                                               */
    /* ===================================================================== */

    private function nowMs(): int
    {
        // Carbon, not microtime(): identical in production (both read the
        // system clock), but Carbon::setTestNow() can steer it — which is
        // what makes the sabbath gate, the token TTL, and the wall-clock
        // check testable on a Tuesday. Timing logic that can't be moved
        // through time can only be tested by waiting for the calendar.
        return \Illuminate\Support\Carbon::now()->getTimestampMs();
    }

    /**
     * Bump the anonymous per-verse play counter — one atomic upsert into
     * scrim_plays, keyed (challenge_key, play_date). Counts only; no IP,
     * no hash, no name — nothing personal by construction.
     *
     * IDEMPOTENT PER ROUND. Cache::add is atomic and returns false when the
     * key already exists, so the FIRST call for a token wins and every
     * repeat is a no-op. One token is one round, so this is exactly "count
     * each finished round once". The flag outlives the token's own TTL by a
     * margin, so a late duplicate can't slip in behind an expiry.
     *
     * play_date uses the SITE clock (typing.board_trim.timezone), the same
     * midnight the trim and the daily challenge run on, so "today" means
     * one thing everywhere.
     *
     * Scrimmage only for now; the daily mode joins in phase B. Triads carry
     * `p` rather than b/c/v and would need a different row shape.
     *
     * NEVER throws: a broken counter must not disturb a round — failures
     * are report()ed and swallowed.
     */
    private function recordPlay(array $claim, string $token): void
    {
        try {
            // Scrimmage and daily both hang off one verse and carry b/c/v in
            // their params, so both fit this table's row shape. They are
            // stored under DIFFERENT challenge keys and different `mode`
            // values, so the hub can count them together (total interest in
            // a verse) or apart (how the daily itself performed). Triads
            // carry `p` instead and would need a different shape.
            $mode = (string) ($claim['mode'] ?? '');
            if (! in_array($mode, ['scrimmage', 'daily'], true)) {
                return;
            }

            $params = (array) ($claim['params'] ?? []);
            $b = strtolower(trim((string) ($params['b'] ?? '')));
            $c = (int) ($params['c'] ?? 0);
            $v = (int) ($params['v'] ?? 0);
            if ($b === '' || $c < 1 || $v < 1) {
                return;
            }

            // THE DEDUPE GATE. Atomic add: true only for the first caller.
            // Kept for twice the token TTL so a repeat can never outlive it.
            $ttlSeconds = (int) ceil(((int) config('typing.token_ttl_ms')) / 1000) * 2;
            if (! Cache::add('scrimplay:' . sha1($token), true, $ttlSeconds)) {
                return;
            }

            // The language the key was minted under. Read from the edition
            // typed (tid, sealed in the token) — the same source canonical()
            // used, so the lang column always matches the key column.
            $lang = Translation::find((int) ($claim['tid'] ?? 0))?->language ?? 'en';

            $date = now(config('typing.board_trim.timezone', 'America/Denver'))
                ->toDateString();

            DB::statement(
                'INSERT INTO scrim_plays
                    (challenge_key, mode, lang, book_slug, chapter, verse,
                     play_date, plays, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE plays = plays + 1, updated_at = NOW()',
                [(string) $claim['ck'], $mode, $lang, $b, $c, $v, $date]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * LEGACY-PATH ONLY (challenge names are strictly validated instead).
     * Coerce whatever the old prototype sends into something the char(4)
     * column can hold: alphanumerics only, uppercased, first four.
     */
    private function cleanName(string $name): string
    {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $name = mb_substr($name, 0, 4);
        return $name === '' ? 'ANON' : $name;
    }

    /** Salted hash of the IP — never the raw address. */
    private function ipHash(Request $request): string
    {
        return hash('sha256', $request->ip() . '|' . config('app.key'));
    }
}
