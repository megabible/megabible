<?php

namespace App\Http\Controllers;

use App\Support\BookMetadata;
use Illuminate\Contracts\View\View;

/**
 * ACTS OF THE USER  ·  /extras/acts-of-the-user
 *
 * The keeper of the user's whole record — no longer vigil-only. The page
 * itself is client-rendered (localStorage is the only data source for the
 * feed), but the feed's DERIVATIONS need server denominators:
 *
 *   - A vigil verse event is free: mbVigil.v1 already stores a `first`
 *     timestamp per verse.
 *   - A CHAPTER completion is the `first` timestamp of the last verse that
 *     filled it — which the client can only detect if it knows how many
 *     verses the chapter holds. Same logic one level up for BOOKS.
 *
 * Those denominators (per-chapter verse counts) and the per-book display
 * metadata (reader-label naming rules, so the feed prints "Psalm 151:1" and
 * "Jude 5" correctly) now live in App\Support\BookMetadata, shared with the
 * Pericope pages so the canon rules have exactly one home. This controller is
 * just the Acts-specific wiring: it pairs that metadata with the Acts config
 * values and the sentinel-token URL patterns the feed's JS fills in.
 *
 * Scrimmage events need none of this — they arrive self-contained in the
 * mbActs.v1 log (written by window.MBActs at round completion).
 */
class ActsController extends Controller
{
    public function show(): View
    {
        return view('extras.acts-of-the-user', [
            // Canon denominators + display rules — one grouped query, shared
            // with the Pericope pages (see App\Support\BookMetadata). Both
            // arrays are keyed by OSIS id, matching mbVigil.v1 on the client.
            'chapterCounts' => BookMetadata::chapterCounts(),
            'bookMeta'      => BookMetadata::displayMeta(),

            'vigilSessionGapMs' => (int) config('typing.vigil.session_gap_minutes', 25) * 60 * 1000,
            'scrimSeconds'      => (int) config('typing.challenge.scrimmage_duration', 20),

            // URL shapes from the router, sentinel-token style (the same trick
            // scrimUrlPattern uses) so JS never hardcodes a path.
            'vigilUrlPattern' => route('typing.vigil', [
                'translation' => '__T__', 'book' => '__B__', 'chapter' => '__C__',
            ], false),
            'vigilBookUrlPattern' => route('typing.vigil.book', [
                'translation' => '__T__', 'book' => '__B__',
            ], false),
            'scrimUrlPattern' => route('typing.scrimmage.verse', [
                't' => '__T__', 'b' => '__B__', 'c' => '__C__', 'v' => '__V__',
            ], false),
        ]);
    }
}
