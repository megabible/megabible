<?php

namespace App\Http\Controllers;

use App\Support\BookMetadata;
use Illuminate\Contracts\View\View;

/**
 * PERICOPE  ·  /extras/pericope
 *
 * Milanote-for-verses: collect verses into boards ("pericopes") while reading,
 * then arrange them. Like Vigil and Acts, the data lives only in the visitor's
 * browser (localStorage, via public/js/pericope-store.js → window.MBPericope) —
 * there are no accounts and the server stores nothing. So these pages are thin
 * Blade shells that the client fills from its own storage.
 *
 * hub()  — the collection page. Lists the visitor's boards as tiles (name,
 *          card count, last-updated), rendered client-side from MBPericope.
 *          The board pages themselves (and sharing) come in later steps; until
 *          then a tile's link 404s, which is expected.
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
     * A single board. The server can't know the slug's CONTENTS (they live in
     * the visitor's localStorage), so it renders this shell for any slug; the
     * script (public/js/pericope-board.js) resolves {slug} against
     * window.MBPericope and shows the board, an empty state, or "not found".
     *
     * Everything the script needs travels as ONE array, $boardConfig, which
     * the view hands over in a single JSON directive as
     * window.MBPericopeBoardConfig. One variable, one directive: Blade splits
     * directive arguments on commas, so building this inline in the view would
     * silently truncate it. Keys:
     *
     *   slug             — the URL segment to resolve client-side
     *   bookMeta         — canon display rules (shared with Acts) so the client
     *                      can derive "Psalm 151:3" from a card's raw osis+chapter
     *   readerUrlPattern — /bible/__TX__/__BOOK__/__CH__; the client fills the
     *                      slots and appends ?v=. Sentinel style, exactly like
     *                      ActsController's scrimUrlPattern (route() doesn't
     *                      enforce the {chapter} digit constraint on generation)
     *   hubUrl           — the hub, for the back link and rename's URL rewrite
     *   cardTxUrl        — JSON endpoint: one ref across every translation, for
     *                      the per-card translation switcher
     *
     * hubUrl is ALSO passed on its own because the Blade shell uses it in two
     * server-rendered links (the back link and the "not found" state).
     */
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

    public function board(string $slug): View
    {
        $hubUrl = route('extras.pericope');

        $boardConfig = [
            'slug'             => $slug,
            'bookMeta'         => BookMetadata::displayMeta(),
            'readerUrlPattern' => route('bible.chapter', [
                'translation' => '__TX__', 'book' => '__BOOK__', 'chapter' => '__CH__',
            ], false),
            'hubUrl'           => $hubUrl,
            'cardTxUrl'        => route('bible.verse-translations'),
            // Interlinear tokens endpoint as a pattern the client fills per
            // card (same sentinel style as readerUrlPattern). The route needs
            // a translation segment but the tokens are translation-agnostic —
            // the card's own tx keeps the URL well-formed.
            'interlinearUrlPattern' => route('bible.interlinear', [
                'translation' => '__TX__', 'book' => '__BOOK__', 'chapter' => '__CH__',
            ], false),
        ];

        return view('extras.pericope.board', [
            'hubUrl'      => $hubUrl,
            'boardConfig' => $boardConfig,
        ]);
    }
}
