<?php

namespace App\Http\Controllers;

use App\Models\VerseLike;
use App\Support\BookMetadata;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PERICOPE  ·  /extras/pericope
 *
 * Milanote-for-verses: collect verses into boards ("pericopes") while reading,
 * then arrange them. Like Vigil and Acts, the data lives only in the visitor's
 * browser (localStorage, via public/js/pericope-store.js → window.MBPericope) —
 * there are no accounts and the server stores nothing about a board. So these
 * pages are thin Blade shells that the client fills from its own storage.
 *
 * hub()    — the collection page. Lists the visitor's boards as tiles (name,
 *            card count, last-updated), rendered client-side from MBPericope.
 * board()  — one board's GRID: the canvas, editing, presentation. The source
 *            of truth for a pericope.
 * scroll() — the same board as a read-only FEED (scroll r3): its own page at
 *            /{slug}/scroll so the two views load separately, scroll
 *            separately, and carry different head-folder apps. The grid page
 *            carries a tiny inline dispatcher that sends visitors here when
 *            their saved view preference (or a phone's default) says Scroll.
 * shared() — the receiving end of a share link (fragment-borne board data).
 *
 * The like endpoints (scroll r2) are the one place this controller touches a
 * table: verse_likes, an anonymous aggregate per like key — the book_visits
 * pattern. Nothing personal by construction; dedup is client-side.
 */
class PericopeController extends Controller
{
    public function hub(): View
    {
        return view('extras.pericope.hub', [
            // Base for building per-board links on the client: hubUrl + '/' + slug.
            // Derived from the named hub route (not a hardcoded path), and
            // generated per request so LAN/mobile devices get the right host —
            // never a cached 127.0.0.1 link.
            'hubUrl'   => route('extras.pericope'),
            // Canon display rules (shared with Acts and the board page) so the
            // tile thumbnails can derive "Psalm 151:3" from a raw card.
            'bookMeta' => BookMetadata::displayMeta(),
        ]);
    }

    /**
     * The RECEIVING end of a share link — /extras/pericope/shared (S2).
     * The board rides in the URL FRAGMENT, which browsers never send to the
     * server, so this action knows nothing about what it is about to import:
     * it only ships the shell and the lookups the client-side rebuild needs.
     *
     *   bookMeta  — osis => {slug, …}: the fragment stores OSIS ids (stable
     *               keys), but the verse endpoint takes book SLUGS.
     *   cardTxUrl — the verseTranslations JSON endpoint that refills each
     *               card's text by reference.
     *   hubUrl    — for the redirect to the freshly created board and the
     *               error panel's way back.
     */
    public function shared(): View
    {
        $hubUrl = route('extras.pericope');

        return view('extras.pericope.shared', [
            'hubUrl'       => $hubUrl,
            'sharedConfig' => [
                'bookMeta'  => BookMetadata::displayMeta(),
                'cardTxUrl' => route('bible.verse-translations'),
                'hubUrl'    => $hubUrl,
            ],
        ]);
    }

    /**
     * ONE config array for BOTH board pages (grid and scroll), built in one
     * place so the two shells can never disagree about what the client sees.
     * The view hands it over in a single JSON directive as
     * window.MBPericopeBoardConfig. One variable, one directive: Blade splits
     * directive arguments on commas, so building this inline in a view would
     * silently truncate it. Keys:
     *
     *   slug             — the URL segment to resolve client-side
     *   bookMeta         — canon display rules (shared with Acts) so the client
     *                      can derive "Psalm 151:3" from a card's raw osis+chapter
     *   sectionLabels    — canon section key => label, for the scroll feed's
     *                      summary line ("3 verses from the Torah")
     *   readerUrlPattern — /bible/__TX__/__BOOK__/__CH__; the client fills the
     *                      slots and appends ?v=. Sentinel style, exactly like
     *                      ActsController's scrimUrlPattern (route() doesn't
     *                      enforce the {chapter} digit constraint on generation)
     *   hubUrl           — the hub, for the back link and rename's URL rewrite
     *   cardTxUrl        — JSON endpoint: one ref across every translation, for
     *                      the per-card translation switcher
     *   interlinearUrlPattern — tokens endpoint as a pattern (sentinel style)
     *   gridUrl / scrollUrl   — each view's own URL, for the view pill and the
     *                      grid page's mode dispatcher
     *   likeUrl / likeCountsUrl / csrf — the scroll r2 anonymous like counters;
     *                      csrf rides in config because the beacons are fetch()
     *                      POSTs that must carry the X-CSRF-TOKEN header
     *                      (book-seen's pattern — sendBeacon can't send headers)
     */
    private function boardConfig(string $slug): array
    {
        return [
            'slug'             => $slug,
            'bookMeta'         => BookMetadata::displayMeta(),
            'sectionLabels'    => BookMetadata::sectionLabels(),
            'readerUrlPattern' => route('bible.chapter', [
                'translation' => '__TX__', 'book' => '__BOOK__', 'chapter' => '__CH__',
            ], false),
            'hubUrl'           => route('extras.pericope'),
            'cardTxUrl'        => route('bible.verse-translations'),
            // Interlinear tokens endpoint as a pattern the client fills per
            // card (same sentinel style as readerUrlPattern). The route needs
            // a translation segment but the tokens are translation-agnostic —
            // the card's own tx keeps the URL well-formed.
            'interlinearUrlPattern' => route('bible.interlinear', [
                'translation' => '__TX__', 'book' => '__BOOK__', 'chapter' => '__CH__',
            ], false),
            'gridUrl'          => route('extras.pericope.board', ['slug' => $slug]),
            'scrollUrl'        => route('extras.pericope.scroll', ['slug' => $slug]),
            // Scroll r2: the anonymous like counters. likeUrl takes one
            // {key, op} beacon; likeCountsUrl takes {keys:[…]} and returns
            // the batch.
            'likeUrl'          => route('extras.pericope.like'),
            'likeCountsUrl'    => route('extras.pericope.likes'),
            'csrf'             => csrf_token(),
        ];
    }

    /**
     * A single board's GRID. The server can't know the slug's CONTENTS (they
     * live in the visitor's localStorage), so it renders this shell for any
     * slug; the script (public/js/pericope-board.js) resolves {slug} against
     * window.MBPericope and shows the board, an empty state, or "not found".
     *
     * $scrollUrl is ALSO passed on its own (besides riding in the config)
     * because the shell's inline mode dispatcher needs it BEFORE the deferred
     * config script runs — it redirects to the scroll view when the saved
     * preference (or a phone with no preference) says so, before first paint.
     */
    public function board(string $slug): View
    {
        return view('extras.pericope.board', [
            'hubUrl'      => route('extras.pericope'),
            'scrollUrl'   => route('extras.pericope.scroll', ['slug' => $slug]),
            'boardConfig' => $this->boardConfig($slug),
        ]);
    }

    /**
     * The same board as a FEED — /extras/pericope/{slug}/scroll (scroll r3).
     * A read-only sibling shell: no editing scripts, no grid markup, its own
     * head-folder apps (home, zoom-out, share with scroll settings, Aa). The
     * script (public/js/pericope-scroll.js) resolves the slug the same way
     * the grid does and paints posts from MBPericope.feed(). Visiting this
     * URL never rewrites the visitor's view preference — only choosing from
     * the view pill does.
     */
    public function scroll(string $slug): View
    {
        return view('extras.pericope.scroll', [
            'hubUrl'      => route('extras.pericope'),
            'gridUrl'     => route('extras.pericope.board', ['slug' => $slug]),
            'boardConfig' => $this->boardConfig($slug),
        ]);
    }

    /**
     * SCROLL R2 · one like beacon — the book_visits::seen pattern applied
     * to a feed post. The key is MBPericope.likeKey(post): refs sorted,
     * joined, translation-free ("Gen.1.1+Rom.8.28-30"), validated here by
     * shape so only well-formed keys can ever create rows. like: one
     * atomic upsert, race-proof under concurrent beacons. unlike: one
     * atomic decrement, floored at zero, creating nothing — an unlike for
     * a row that never existed earns the same silent 204 as everything
     * else. No IP, no hash of a person, no cookie; dedup is client-side.
     */
    public function like(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:1000',
                      'regex:/^[A-Za-z0-9]{1,12}\.[0-9]{1,3}\.[0-9]{1,4}(-[0-9]{1,4})?(\+[A-Za-z0-9]{1,12}\.[0-9]{1,3}\.[0-9]{1,4}(-[0-9]{1,4})?)*$/'],
            'op'  => 'required|in:like,unlike',
        ]);

        $hash = hash('sha256', $data['key']);

        if ($data['op'] === 'like') {
            DB::statement(
                'INSERT INTO verse_likes (like_hash, like_key, hits, created_at, updated_at)
                 VALUES (?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE hits = hits + 1, updated_at = NOW()',
                [$hash, $data['key']]
            );
        } else {
            VerseLike::where('like_hash', $hash)
                ->where('hits', '>', 0)
                ->decrement('hits');
        }

        return response()->json([], 204);
    }

    /**
     * SCROLL R2 · the batch read — one POST per feed open, every key at
     * once. Only rows with hits are returned; the client defaults the
     * rest to zero, so the response stays small on a young site. Keys are
     * only length-capped here (not shape-validated): a malformed key can't
     * match a row, and reads create nothing worth guarding.
     */
    public function likeCounts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys'   => 'required|array|max:300',
            'keys.*' => 'string|max:1000',
        ]);

        $byHash = [];
        foreach (array_unique($data['keys']) as $key) {
            $byHash[hash('sha256', $key)] = $key;
        }

        $counts = [];
        VerseLike::whereIn('like_hash', array_keys($byHash))
            ->where('hits', '>', 0)
            ->get(['like_hash', 'hits'])
            ->each(function ($row) use ($byHash, &$counts) {
                $counts[$byHash[$row->like_hash]] = (int) $row->hits;
            });

        return response()->json(['counts' => $counts]);
    }
}
