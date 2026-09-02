<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;
use App\Support\ReferenceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    public function handle(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 200);

        if ($q === '') {
            return redirect()->route('home');
        }

        // 1) Search operators — book:x and tr:x / translation:x. When any
        //    resolve, redirect to the canonical URL (?q=…&book=…&translation=…)
        //    so operator searches are shareable and everything downstream —
        //    shortcuts, reference parsing, full-text — runs on a clean query.
        if ($redirect = $this->applyOperators($q)) {
            return $redirect->with('searched_query', $q);
        }

        // 2) Easter-egg shortcuts (config/shortcuts.php).
        if ($redirect = $this->matchShortcut($q)) {
            return $redirect->with('searched_query', $q);
        }

        // The edition we read/search in. An explicit ?translation= (set by the
        // results-page switcher) wins; otherwise whatever the reader last used
        // (cookie set by RememberTranslation), falling back to KJV. A bad slug
        // never 404s a search — it just falls through to the cookie/KJV default.
        $explicit  = $request->query('translation');
        $explicit  = is_string($explicit) ? strtolower(trim($explicit)) : null;
        $preferred = ($explicit ? Translation::findBySlug($explicit) : null)
            ?? Translation::findBySlug(strtolower($request->cookie('reader_translation', 'kjv')))
            ?? Translation::findBySlug('kjv');

        // 3) Read the query as one or more Bible references.
        $refs = $this->referenceResolver()->parseMany($q);

        // A query may name more references than we're willing to look up.
        // Keep the first N in typed order and say so on the results page
        // rather than quietly answering a different question.
        $refsTruncated = count($refs) > $this->referenceCap();
        if ($refsTruncated) {
            $refs = array_slice($refs, 0, $this->referenceCap());
        }

        // Two or more references → list every one of them in the results page.
        if (count($refs) >= 2) {
            return $this->referenceResults($q, $refs, $preferred, $request, $refsTruncated);
        }

        // Exactly one reference → jump straight to it, as before.
        if (count($refs) === 1 && $target = $this->resolveReference($refs[0], $preferred)) {
            return redirect()->to($target)->with('searched_query', $q);
        }

        // 4) Otherwise: full-text search across the active translation.
        return $this->textSearch($q, $preferred, $request);
    }

    /*
    |--------------------------------------------------------------------------
    | Ceilings (config/search.php)
    |--------------------------------------------------------------------------
    */

    /**
     * How deep into a result set a reader may page.
     *
     * Now that results are paginated this is no longer about the size of one
     * response — a page is per_page rows and nothing more. What it bounds is
     * OFFSET: without it, ?page=99999 asks MySQL to walk a million rows and
     * throw all but a hundred away. Anything past this ceiling is reached by
     * FILTERING (the book chips), not by paging further.
     */
    private function resultCap(): int
    {
        return max(1, (int) config('search.max_results', 1000));
    }

    /** Verses rendered on one page. */
    private function perPage(): int
    {
        return max(1, (int) config('search.per_page', 100));
    }

    /** Hard ceiling on how many references one query may name. */
    private function referenceCap(): int
    {
        return max(1, (int) config('search.max_references', 20));
    }

    /*
    |--------------------------------------------------------------------------
    | The two entry points — both build a query and hand it to resultsPage()
    |--------------------------------------------------------------------------
    */

    /**
     * Full-text search, scoped to one translation. Requires every term the
     * reader typed (boolean AND).
     */
    private function textSearch(string $q, Translation $t, Request $request)
    {
        $boolean = $this->booleanQuery($q);

        $base = Verse::query()->where('translation_id', $t->id);

        // An empty boolean expression means nothing usable was entered → no
        // matches. Express that as a query rather than an empty collection so
        // the pipeline below has exactly one shape to handle.
        $boolean === ''
            ? $base->whereRaw('1 = 0')
            : $base->whereFullText('text', $boolean, ['mode' => 'boolean']);

        return $this->resultsPage(
            $base, $t, $q, $request,
            highlightQuery: $q,
            isReferences: false,
        );
    }

    /**
     * Multi-reference results. No highlighting — there's no search term, the
     * reader named the verses directly.
     */
    private function referenceResults(string $q, array $refs, Translation $t, Request $request, bool $refsTruncated = false)
    {
        return $this->resultsPage(
            $this->referenceQuery($refs, $t), $t, $q, $request,
            highlightQuery: null,
            isReferences: true,
            refsTruncated: $refsTruncated,
        );
    }

    /**
     * Every parsed reference as ONE query, each reference an OR'd clause:
     *
     *   translation_id = 1 AND (
     *        (book_id = 1)                                        -- "genesis"
     *     OR (book_id = 2 AND chapter = 3)                        -- "exodus 3"
     *     OR (book_id = 43 AND chapter = 3 AND verse_number = 16) -- "john 3:16"
     *   )
     *
     * The previous version ran one query per reference and merged the results
     * in PHP, which meant it could never be ordered, counted or paginated as a
     * whole — and needed a running budget plus a unique('id') pass to stay
     * bounded. One query gives all of that for free: SQL can't return the same
     * row twice, so overlapping references ("john 3, john 3:16") de-duplicate
     * themselves, and LIMIT/OFFSET mean exactly what they say.
     */
    private function referenceQuery(array $refs, Translation $t): Builder
    {
        $base = Verse::query()->where('translation_id', $t->id);

        // Resolve slugs to books first: a reference naming a book we don't
        // have contributes no clause at all.
        $clauses = [];
        foreach ($refs as $ref) {
            if ($book = Book::findBySlug($ref['book'])) {
                $clauses[] = [$book, $ref];
            }
        }

        if ($clauses === []) {
            return $base->whereRaw('1 = 0');
        }

        return $base->where(function (Builder $outer) use ($clauses) {
            foreach ($clauses as [$book, $ref]) {
                $outer->orWhere(function (Builder $q) use ($book, $ref) {
                    $q->where('book_id', $book->id);

                    if ($ref['type'] === 'chapter') {
                        $q->where('chapter', $ref['chapter']);
                    } elseif ($ref['type'] === 'passage') {
                        $q->where('chapter', $ref['chapter']);
                        $this->applyVerseFilter($q, $ref['verses']);
                    }
                    // type 'book' → book_id alone; the whole book.
                });
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The shared results pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * Facet, filter, order, paginate, render — the one path both searches take.
     *
     * The ORDER of the steps below is the whole answer to "why did my book
     * chips disappear?". The chips are built from a COUNT … GROUP BY book_id
     * over the ENTIRE match set, taken BEFORE any book filter or LIMIT touches
     * the query. So a search for "lord" shows a chip for every book that
     * contains it, each with its true tally, even though only one page of
     * verses is rendered. Chips are links, not client-side toggles: clicking
     * one adds its slug to ?book= and re-runs the search scoped to that book,
     * which is how a reader reaches results sitting past the paging ceiling.
     *
     * @param string|null $highlightQuery  terms to <mark>, or null for none
     * @param bool        $refsTruncated   the query named more refs than we read
     */
    private function resultsPage(
        Builder $base,
        Translation $t,
        string $q,
        Request $request,
        ?string $highlightQuery,
        bool $isReferences,
        bool $refsTruncated = false,
    ) {
        $cap     = $this->resultCap();
        $perPage = $this->perPage();

        $books = Book::all()->keyBy('id');
        $canon = $this->canonIndex();

        // ---- 1) Facets: every book in the match set, with its true tally ----
        // Cloned before the filter and the LIMIT touch $base, so this counts
        // the whole set. One aggregate row per book (~60), not one per verse.
        $tallies = (clone $base)
            ->selectRaw('book_id, COUNT(*) as tally')
            ->groupBy('book_id')
            ->pluck('tally', 'book_id');

        $matchedTotal = (int) $tallies->sum();

        $chips = $tallies
            ->map(function ($tally, $bookId) use ($books, $canon) {
                $book = $books[$bookId] ?? null;
                if (! $book) {
                    return null;   // orphan verse pointing at a book that's gone
                }
                return [
                    'book'  => $book,
                    'slug'  => $book->slug,
                    'count' => (int) $tally,
                    'color' => $canon[$book->slug]['color'] ?? 'clay',
                    'order' => $canon[$book->slug]['order'] ?? PHP_INT_MAX,
                ];
            })
            ->filter()
            ->sortBy('order')
            ->values();

        // ---- 2) The ?book= filter, validated against the facets ----
        // Only slugs that actually matched survive. An unknown or stale slug is
        // dropped here, server-side — never by rewriting the reader's URL.
        $allSlugs  = $chips->pluck('slug')->all();
        $requested = array_filter(array_map('trim', explode(',', (string) $request->query('book', ''))));
        $active    = array_values(array_intersect($allSlugs, $requested));   // canon order

        if ($active !== []) {
            $ids = $chips->whereIn('slug', $active)->map(fn ($c) => $c['book']->id)->all();
            $base->whereIn('book_id', $ids);
        }

        // Verses the reader's current selection matches, filter included.
        $selectedTotal = $active === []
            ? $matchedTotal
            : (int) $chips->whereIn('slug', $active)->sum('count');

        // ---- 3) Pagination geometry ----
        // Only the first $cap results are reachable by paging; the rest are
        // reached by narrowing with a chip (which re-runs the query, so the
        // ceiling then applies to the smaller set).
        $reachable  = min($selectedTotal, $cap);
        $totalPages = max(1, (int) ceil($reachable / $perPage));
        $page       = max(1, (int) $request->query('page', 1));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // ---- 4) The page itself ----
        $this->orderByCanon($base);
        $verses = $reachable === 0
            ? collect()
            : $base->offset($offset)->limit($perPage)->get();

        // ---- 5) URLs. Built here, never in Blade — one place decides what a
        // search URL looks like, and route() keeps it honest. ----
        $searchUrl = function (array $bookSlugs, int $toPage = 1) use ($q, $t) {
            $params = ['q' => $q, 'translation' => strtolower($t->abbreviation)];
            if ($bookSlugs !== []) {
                $params['book'] = implode(',', $bookSlugs);
            }
            if ($toPage > 1) {
                $params['page'] = $toPage;
            }
            return route('search', $params);
        };

        // Each chip's link toggles its own slug in and out of the selection and
        // always returns to page 1 — page 7 of Genesis is meaningless once the
        // reader has switched to Matthew. array_intersect against the canon-
        // ordered slug list keeps the resulting ?book= list in canon order.
        $chips = $chips->map(function (array $c) use ($active, $allSlugs, $searchUrl) {
            $on   = in_array($c['slug'], $active, true);
            $next = $on
                ? array_diff($active, [$c['slug']])
                : array_merge($active, [$c['slug']]);

            $c['is_active'] = $on;
            $c['url']       = $searchUrl(array_values(array_intersect($allSlugs, $next)));

            return $c;
        });

        // ---- 6) Attach books + highlighting, then group for display ----
        $verses->each(function (Verse $v) use ($highlightQuery, $books) {
            $v->ref_book    = $books[$v->book_id] ?? null;
            $v->highlighted = $highlightQuery !== null
                ? $this->highlight($v->text, $highlightQuery)
                : e($v->text);
        });

        // SQL already returned this page in canon order (orderByCanon), so the
        // groups come out in canon order too, verses in reading order.
        $groups = $verses
            ->groupBy(fn (Verse $v) => $v->ref_book?->slug)
            ->map(fn ($groupVerses) => [
                'book'   => $groupVerses->first()->ref_book,
                'color'  => $canon[$groupVerses->first()->ref_book?->slug]['color'] ?? 'clay',
                'verses' => $groupVerses,
            ])
            ->filter(fn ($group) => $group['book'] !== null)
            ->values();

        return view('search.results', [
            'q'                 => $q,
            'translation'       => $t,
            'otherTranslations' => $this->searchableTranslations($t),
            'groups'            => $groups,
            'chips'             => $chips,
            'activeBooks'       => $active,
            'clearUrl'          => $searchUrl([]),
            'isReferences'      => $isReferences,

            // Counts. selectedTotal is the TRUE figure for what the reader is
            // looking at; from/to describe what this page holds.
            'matchedTotal'      => $matchedTotal,
            'selectedTotal'     => $selectedTotal,
            'bookCount'         => $active === [] ? $chips->count() : count($active),
            'from'              => $verses->count() ? $offset + 1 : 0,
            'to'                => $offset + $verses->count(),

            // Ceilings, so the page can say so out loud.
            'truncated'         => $selectedTotal > $cap,
            'refsTruncated'     => $refsTruncated,
            'resultCap'         => $cap,
            'refCap'            => $this->referenceCap(),

            'pagination'        => $this->pagination($page, $totalPages, $active, $searchUrl),

            // The switcher re-runs THIS query in another edition, carrying the
            // book filter but not the page number.
            'switchParams'      => $active === []
                ? ['q' => $q]
                : ['q' => $q, 'book' => implode(',', $active)],
        ]);
    }

    /**
     * Pre-computed pagination geometry. The ceiling holds this to at most
     * max_results / per_page pages (ten, by default), so every page can get a
     * numbered link and there's no ellipsis logic to get wrong.
     */
    private function pagination(int $page, int $totalPages, array $active, callable $searchUrl): array
    {
        $pages = [];
        for ($n = 1; $n <= $totalPages; $n++) {
            $pages[] = [
                'n'       => $n,
                'url'     => $searchUrl($active, $n),
                'current' => $n === $page,
            ];
        }

        return [
            'current' => $page,
            'total'   => $totalPages,
            'prev'    => $page > 1 ? $searchUrl($active, $page - 1) : null,
            'next'    => $page < $totalPages ? $searchUrl($active, $page + 1) : null,
            'pages'   => $pages,
        ];
    }

    /**
     * Order a verse query into canon reading order IN SQL — book (per
     * config/canon.php), then chapter, then verse. This is what makes LIMIT
     * and OFFSET meaningful: page 2 is the second hundred verses in reading
     * order, never "whatever rows InnoDB happened to hand back next".
     *
     * FIELD(book_id, …) returns each book's position in the list, and 0 for a
     * book the list doesn't mention. Since 0 would sort FIRST, the leading
     * "= 0" clause pushes those uncatalogued books to the END instead.
     */
    private function orderByCanon(Builder $query): void
    {
        $canon = $this->canonIndex();

        $ids = Book::all(['id', 'slug'])
            ->filter(fn (Book $b) => isset($canon[$b->slug]))
            ->sortBy(fn (Book $b) => $canon[$b->slug]['order'])
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            // Interpolated rather than bound: these are integer primary keys
            // read straight out of the database and cast again here, so there
            // is nothing user-supplied in the string.
            $list = implode(',', array_map('intval', $ids));

            $query->orderByRaw("FIELD(book_id, {$list}) = 0")
                  ->orderByRaw("FIELD(book_id, {$list})");
        }

        $query->orderBy('chapter')->orderBy('verse_number');
    }

    /**
     * Constrain a verse query to a canonical verse list ("9-15,20").
     *
     * Ranges go to SQL as ranges — two bindings for "9-15", not seven. The old
     * expandVerses() built one binding per verse, so a passage reference became
     * a whereIn whose size scaled with the SPAN the reader typed. The resolver
     * now clamps that span (ReferenceResolver::MAX_VERSE), so this is
     * belt-and-braces rather than the only guard — but it is also simply the
     * right shape for the query.
     *
     * The list is wrapped in its own nested where() so the ORs can't leak out
     * and disturb the book_id / chapter constraints already on the builder —
     * which matters more than ever now that each reference is itself an OR'd
     * clause inside a bigger group.
     */
    private function applyVerseFilter(Builder $query, string $verses): void
    {
        $ranges = ReferenceResolver::verseRanges($verses);

        if ($ranges === []) {
            $query->whereRaw('1 = 0');   // nothing addressable was named
            return;
        }

        $query->where(function (Builder $q) use ($ranges) {
            foreach ($ranges as [$start, $end]) {
                if ($start === $end) {
                    $q->orWhere('verse_number', $start);
                } else {
                    $q->orWhereBetween('verse_number', [$start, $end]);
                }
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reference resolution, operators, shortcuts
    |--------------------------------------------------------------------------
    */

    /** Build the resolver from the DB (slug/name/short_name) + the alias config. */
    private function referenceResolver(): ReferenceResolver
    {
        return new ReferenceResolver(
            $this->bookLookup(),
            config('canon.chapter_remaps', []),
        );
    }

    /**
     * normalized book name => slug, from the DB (name, short name, slug) plus
     * config/book_aliases.php. One map feeds BOTH the reference resolver and
     * the book: search operator, so "book:matt", "book:mt", and "book:matthew"
     * all work wherever "matt 5" already does.
     */
    private function bookLookup(): array
    {
        $lookup = [];

        foreach (Book::all(['slug', 'name', 'short_name']) as $b) {
            foreach ([$b->name, $b->short_name, $b->slug, str_replace('-', ' ', $b->slug)] as $variant) {
                if ($variant) {
                    $lookup[ReferenceResolver::key($variant)] = $b->slug;
                }
            }
        }
        foreach (config('book_aliases', []) as $alias => $slug) {
            $lookup[ReferenceResolver::key($alias)] = $slug;
        }

        return $lookup;
    }

    /**
     * Search operators: pull book:x and tr:x / translation:x out of the query
     * and redirect to the canonical URL form, e.g.
     *
     *   book:matt sin            → /search?q=sin&book=matthew
     *   tr:kjv sin               → /search?q=sin&translation=kjv
     *   book:matt book:mark sin  → /search?q=sin&book=matthew,mark
     *   book:matt,mark sin       → same (comma list inside one operator)
     *
     * That ?book= is now a REAL server-side filter (see resultsPage), so this
     * redirect and a click on the results page's book chip produce the same
     * URL and the same SQL. They used to differ: ?book= was read only by the
     * results page's JavaScript, which silently dropped any slug that wasn't
     * among the rendered results — so "book:matt lord" lost its filter the
     * moment Matthew fell outside the first page of hits.
     *
     * Rules of the road:
     *   - Only operators that RESOLVE are stripped and acted on. An operator
     *     with an unknown value ("book:zzz") is left in the query as literal
     *     text, and if nothing resolves we return null — no redirect, the
     *     normal search flow continues. This also protects innocent colon
     *     usage ("the book: of life") from being eaten.
     *   - book: values go through the same lookup as reference parsing, so
     *     every alias works. Several book: operators (or one comma list)
     *     accumulate; duplicates collapse.
     *   - tr: — first resolvable one wins; any later ones are stripped but
     *     ignored.
     *   - Operators inside "quoted phrases" are NOT matched (the boundary
     *     requires start-of-string or whitespace before the operator name,
     *     and inside a phrase the preceding character is the quote or a word).
     *   - Terms-free searches still land somewhere sensible:
     *       tr:web            → the canon index, in that translation (there is
     *                           no translation home page; the edition is
     *                           carried by the reader_translation cookie
     *                           instead, on the same terms as
     *                           RememberTranslation — is_global only)
     *       book:matt [tr:x]  → re-enters search with the book name as the
     *                           query, and the reference path lands on the
     *                           book hub (honouring tr:, since ?translation=
     *                           feeds $preferred on the next pass)
     *
     * @return RedirectResponse|null  null = no resolvable operators found
     */
    private function applyOperators(string $q): ?RedirectResponse
    {
        // book:x / tr:x / translation:x — case-insensitive, value runs to the
        // next whitespace. (?:^|(?<=\s)) keeps us off mid-word and quoted hits.
        $pattern = '/(?:^|(?<=\s))(book|tr|translation)\s*:\s*(\S+)/iu';

        if (! preg_match_all($pattern, $q, $mm, PREG_SET_ORDER)) {
            return null;
        }

        $lookup  = null;   // built lazily — only if a book: operator shows up
        $books   = [];     // slug => true, in typed order (dedupes repeats)
        $tr      = null;   // first resolvable tr: value (a translation slug)
        $trModel = null;   // …and its model, kept for the is_global check below
        $strip   = [];     // the exact operator tokens to remove from the query

        foreach ($mm as $m) {
            [$token, $op, $val] = [$m[0], strtolower($m[1]), strtolower(trim($m[2]))];

            if ($op === 'book') {
                $lookup ??= $this->bookLookup();
                $hit = false;
                foreach (explode(',', $val) as $one) {
                    if ($slug = $this->resolveBookOperand($one, $lookup)) {
                        $books[$slug] = true;
                        $hit = true;
                    }
                }
                if ($hit) {
                    $strip[] = $token;
                }
            } elseif ($t = Translation::findBySlug($val)) {
                if ($tr === null) {       // first wins…
                    $tr      = $val;
                    $trModel = $t;
                }
                $strip[] = $token;        // …but every resolvable tr: is stripped
            }
        }

        if ($books === [] && $tr === null) {
            return null;   // nothing resolved → treat the query as plain text
        }

        // Remove the resolved operator tokens, then collapse the whitespace
        // they leave behind. Each removal is boundary-anchored like the match.
        $rest = $q;
        foreach (array_unique($strip) as $token) {
            $rest = preg_replace(
                '/(?:^|(?<=\s))' . preg_quote($token, '/') . '(?=\s|$)/u',
                ' ',
                $rest
            );
        }
        $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));

        // Terms-free landings (see docblock).
        if ($rest === '') {
            if ($books === []) {
                // There is no translation home page, so honour the intent
                // rather than the URL: remember the edition and drop the
                // reader on the canon index, whose book links follow the
                // cookie. Cookie mirrors RememberTranslation exactly — same
                // name, same year, and the same is_global-only rule, so a
                // single-work edition can be searched without ever becoming
                // the reader's sticky default.
                $to = redirect()->route('home');

                if ($trModel->is_global) {
                    $to->withCookie(
                        cookie('reader_translation', strtolower($tr), 60 * 24 * 365)
                    );
                }

                return $to;
            }
            $rest = implode(' ', array_map(
                fn (string $slug) => str_replace('-', ' ', $slug),
                array_keys($books)
            ));
            $books = [];   // the book(s) ride in q now, not in the filter param
        }

        $params = ['q' => $rest];
        if ($books !== []) {
            $params['book'] = implode(',', array_keys($books));
        }
        if ($tr !== null) {
            $params['translation'] = $tr;
        }

        return redirect()->route('search', $params);
    }

    /**
     * Resolve one book: operand against the shared lookup. Hyphens and
     * underscores count as spaces ("1-john"), and a missing space between a
     * numeral and a word is forgiven ("1john") — the same tolerance the
     * reference resolver's loosen() gives typed references.
     */
    private function resolveBookOperand(string $val, array $lookup): ?string
    {
        $key = ReferenceResolver::key(str_replace(['-', '_'], ' ', $val));
        if (isset($lookup[$key])) {
            return $lookup[$key];
        }

        // "1john" → "1 john", "psalm151" → "psalm 151"
        $key = preg_replace('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/u', ' ', $key);

        return $lookup[$key] ?? null;
    }

    /** Turn a parsed reference into a destination URL, or null if it leads nowhere. */
    private function resolveReference(array $ref, ?Translation $preferred): ?string
    {
        $book = Book::findBySlug($ref['book']);
        if (! $book) {
            return null;
        }

        // Prefer the reader's edition; otherwise the highest-priority one that has it.
        $t = $this->translationFor($book, null, $preferred);
        if (! $t) {
            return null;   // catalogued but verse-less (placeholder) book
        }

        $trans = strtolower($t->abbreviation);

        if ($ref['type'] === 'book') {
            return route('bible.book', ['translation' => $trans, 'book' => $book->slug]);
        }

        $maxChapter = (int) Verse::where('translation_id', $t->id)
            ->where('book_id', $book->id)->max('chapter');

        $chapter = $ref['chapter'];
        $verses  = $ref['verses'] ?? null;

        // Single-chapter book typed like "Jude 5" → that's verse 5, not chapter 5.
        if ($maxChapter === 1 && $verses === null && $chapter > 1) {
            $verses  = (string) $chapter;
            $chapter = 1;
        }

        // Out-of-range chapter → land on the book hub instead of dead-ending.
        if ($chapter < 1 || $chapter > $maxChapter) {
            return route('bible.book', ['translation' => $trans, 'book' => $book->slug]);
        }

        $params = ['translation' => $trans, 'book' => $book->slug, 'chapter' => $chapter];
        if ($verses !== null && $verses !== '') {
            $params['v'] = $verses;   // route() URL-encodes the comma to %2C
        }

        return route('bible.chapter', $params);
    }

    /** Reader's edition if it has the book/chapter, else the best one that does. */
    private function translationFor(Book $book, ?int $chapter, ?Translation $preferred): ?Translation
    {
        $has = function (Translation $t) use ($book, $chapter) {
            $q = Verse::where('translation_id', $t->id)->where('book_id', $book->id);
            if ($chapter !== null) {
                $q->where('chapter', $chapter);
            }
            return $q->exists();
        };

        if ($preferred && $has($preferred)) {
            return $preferred;
        }

        return Translation::orderByDesc('is_global')->orderBy('sort_order')->get()
            ->first(fn (Translation $t) => $has($t));
    }

    /** Easter-egg lookup. Case- and separator-insensitive. */
    private function matchShortcut(string $q): ?RedirectResponse
    {
        $key = $this->phraseKey($q);

        foreach ((array) config('shortcuts', []) as $phrase => $target) {
            if ($this->phraseKey((string) $phrase) !== $key) {
                continue;
            }
            if (is_string($target)) {
                return redirect()->to($target);
            }
            if (! empty($target['route'])) {
                return redirect()->route($target['route'], $target['params'] ?? []);
            }
            if (! empty($target['url'])) {
                return redirect()->to($target['url']);
            }
        }
        return null;
    }

    private function phraseKey(string $s): string
    {
        return trim(preg_replace('/[\s\-]+/u', ' ', mb_strtolower($s)));
    }

    /**
     * Every translation that actually holds verses — so the results-page switcher
     * only offers editions you can really search — minus the one being searched,
     * which the switcher partial adds back as the active row. Ordered like the
     * reader's switcher elsewhere: global (full-canon) editions first, then
     * sort_order. Unlike the chapter switcher's "has THIS chapter", search spans
     * the whole corpus, so the test is simply "has any verses".
     */
    private function searchableTranslations(Translation $current): Collection
    {
        return Translation::query()
            ->whereIn('id', function ($sub) {
                $sub->select('translation_id')->from('verses')->distinct();
            })
            ->where('id', '!=', $current->id)
            ->orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * A flat map of book slug => ['order' => int, 'color' => string], built from
     * config/canon.php. This is the single source of truth for how search
     * results are ordered and which timeline colour each book's filter chip
     * gets. It walks testaments → sections → books exactly like QuicknavComposer,
     * but flattened (no testament/section labels are kept — we group by book only).
     */
    private function canonIndex(): array
    {
        $sections = config('canon.sections', []);
        $colors   = config('canon.section_colors', []);

        $index = [];
        $order = 0;

        foreach (config('canon.testaments', []) as $testament) {
            foreach ($testament['sections'] as $sectionKey) {
                $section = $sections[$sectionKey] ?? null;
                if (! $section) {
                    continue;
                }

                $color = $colors[$sectionKey] ?? 'clay';

                // A section lists its books flat ('books') or in labelled
                // subgroups. Flatten both into one ordered slug list.
                $slugs = ! empty($section['subgroups'])
                    ? collect($section['subgroups'])->flatMap(fn ($g) => $g['books'] ?? [])->all()
                    : ($section['books'] ?? []);

                foreach ($slugs as $slug) {
                    $index[$slug] = ['order' => $order++, 'color' => $color];
                }
            }
        }

        return $index;
    }

    /**
     * Turn a plain reader query into a MySQL boolean-mode expression where every
     * term is required (logical AND):
     *
     *   without sin        →  +without +sin
     *   "son of man" grace →  +"son of man" +grace
     *
     * Quoted text is kept together as a phrase. Any other boolean operators the
     * reader might type (- > < ~ * ( ) @) are stripped from bare words so the
     * behaviour stays predictable for non-technical readers: we always treat
     * every term as required, nothing more.
     */
    private function booleanQuery(string $q): string
    {
        // Words MySQL's FULLTEXT index never stored can't be REQUIRED: a term
        // shorter than innodb_ft_min_token_size, or on the stopword list, is
        // absent from the index, so "+be" matches ZERO documents and one such
        // word silently kills the whole search ("let there be light" → nothing).
        // We drop those words from the boolean expression instead. Both limits
        // live in config/search.php and MUST mirror the MySQL server's actual
        // settings — see that file for the server-side fix that makes every
        // word searchable (at which point these guards become no-ops).
        $minLen    = (int) config('search.min_token_length', 3);
        $stopwords = array_flip(array_map('mb_strtolower', config('search.stopwords', [])));

        $terms = [];

        // 1) Pull out "quoted phrases" first and keep each one intact as a phrase.
        //    (Phrases are exempt from the guard: MySQL ignores unindexed words
        //    INSIDE a phrase rather than failing it — that's why the quoted
        //    version of a search works when the bare version doesn't.)
        if (preg_match_all('/"([^"]+)"/u', $q, $matches)) {
            foreach ($matches[1] as $phrase) {
                $phrase = trim($phrase);
                if ($phrase !== '') {
                    $terms[] = '+"' . $phrase . '"';
                }
            }
            // Remove the phrases we just handled so they aren't re-processed below.
            $q = preg_replace('/"[^"]+"/u', ' ', $q);
        }

        // 2) Every remaining indexable bare word becomes a required (+) term.
        foreach (preg_split('/\s+/u', trim($q)) as $word) {
            $word = preg_replace('/[+\-<>~()*@"]+/u', '', $word);
            if ($word === '') {
                continue;
            }
            if (mb_strlen($word) < $minLen || isset($stopwords[mb_strtolower($word)])) {
                continue;   // not in the index — requiring it guarantees zero results
            }
            $terms[] = '+' . $word;
        }

        return implode(' ', $terms);
    }

    /** Escape the verse, then wrap each query term (2+ chars) in <mark>. */
    private function highlight(string $text, string $query): string
    {
        $html = e($text);

        // Strip boolean/quote characters so phrase and multi-word searches still
        // highlight the underlying words (e.g. "son of man" highlights son/of/man).
        $terms = array_unique(array_filter(
            array_map(
                fn ($w) => preg_replace('/[+\-<>~()*@"]+/u', '', $w),
                preg_split('/\s+/u', trim($query))
            ),
            fn ($w) => mb_strlen($w) >= 2
        ));

        foreach ($terms as $term) {
            $html = preg_replace('/(' . preg_quote($term, '/') . ')/iu', '<mark>$1</mark>', $html);
        }

        return $html;
    }
}
