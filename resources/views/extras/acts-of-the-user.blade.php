@extends('layouts.app')

@section('title', 'Acts of the User — MEGABIBLE.net')

{{--
  =====================================================================
  ACTS OF THE USER  ·  /extras/acts-of-the-user
  ---------------------------------------------------------------------
  The keeper of the user's WHOLE record, promoted out of the vigil.

  THE FEED — every deed, 20 to a page. Two badges only, because there
  are only two ways to type on this site:

    VIGIL   the contemplative verse-by-verse mode. Derived from
            mbVigil.v1 — free, no separate log.
              range    consecutive verses typed in one SITTING collapse
                       into a single row — "Genesis 1:1–31" — instead of
                       one row per verse. A sitting is a run of verse
                       first-typings with no gap longer than SESSION_GAP
                       between them; a longer pause starts a new row. The
                       verses need not be typed in order (15–31 then
                       1–14 still reads 1–31), and holes are shown
                       honestly ("1–10, 15–31"), never papered over into
                       a min–max span. Collapsing is bounded to one
                       chapter: back-to-back chapters keep separate rows.
              chapter  "Completed John 3"  — DERIVED: the moment the
                       last verse of a chapter was first typed. Needs
                       the server's per-chapter verse counts (COUNTS)
                       to know when a chapter is full.
              book     "Completed the book of…" — same idea, one level
                       up. Chapter and book rows keep their leading
                       word because a bare reference could not tell a
                       milestone from an ordinary typed verse. On the
                       keystroke that finishes a chapter (or book), the
                       milestone shares a timestamp with the range that
                       finished it; a rank tiebreak sits the milestone
                       above its range.
    SCRIM   the timed competitive mode. Read from mbActs.v1, the
            append-only event log written by window.MBActs
            (layouts/app). Carries a marks score.

  Verse and scrim rows show NO leading verb: the badge already says
  which mode it was, so the reference does the rest of the talking.

  Everything is local, so "pagination" is just slicing one in-memory
  array — no fetching, no background loading. Even a fully typed canon
  sorts in milliseconds.

  EXPORT / IMPORT are unchanged from the vigil era: the file covers the
  ENTIRE localStorage for this origin, so mbActs.v1 rides along free.
  CLEAR is now the one true reset: it wipes ALL storage — progress,
  acts, settings, theme, unlock flags — after two confirmations.
  =====================================================================
--}}
@section('styles')
<style>
    .acts-hero { margin: 0 0 1.6rem; }
    .acts-hero h1 { font-size: 2.4rem; font-weight: 400; margin: 0 0 .3rem; letter-spacing: -.01em; }
    .acts-hero p { color: var(--muted); font-family: var(--sans); font-size: .9rem; margin: 0; max-width: 58ch; }

    /* "Learn more ↓" tail on the hero blurb — a link that glides down to the
       record section. Reads in the accent; the arrow rides its own span so it
       can nudge on hover without disturbing anything else. */
    .acts-learn { color: var(--accent); font-weight: 600; text-decoration: none; white-space: nowrap; }
    .acts-learn:hover { text-decoration: underline; }
    .acts-learn-arrow { display: inline-block; transition: transform .12s; }
    .acts-learn:hover .acts-learn-arrow { transform: translateY(2px); }

    /* ---- Overall record panel ----
       The whole-record summary. .acts-top is the flex row that carries both
       the hero and the panel: the hero flexes to fill, the panel holds its
       width on the right. On desktop that pins the panel top-right, level with
       the hero; below 600px the row wraps, dropping the panel under the last
       hero line and above the controls/feed. */
    .acts-top {
        display: flex; align-items: flex-start; justify-content: space-between;
        flex-wrap: wrap; gap: 1.5rem;
        margin: 0 0 1.6rem;                 /* spacing that used to live on .acts-hero */
    }
    .acts-top .acts-hero { margin: 0; flex: 1 1 22rem; }

    .acts-overall {
        flex: 0 0 auto; min-width: 15rem;
        font-family: var(--sans);
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .55rem .9rem;
    }
    .acts-overall[hidden] { display: none; }
    .acts-ov-row {
        display: flex; align-items: baseline; justify-content: space-between;
        gap: 1.2rem; padding: .3rem 0;
        border-bottom: 1px solid var(--rule);
    }
    .acts-ov-row:last-child { border-bottom: none; }
    .acts-ov-label {
        flex: 0 0 auto;
        color: var(--muted); font-size: .68rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; white-space: nowrap;
    }
    .acts-ov-value {
        color: var(--ink); font-size: .86rem; font-weight: 600;
        font-variant-numeric: tabular-nums; text-align: right;
    }
    /* The clock and the marks read in the accent, like the feed's tallies. */
    #ov-time, #ov-scrim { color: var(--accent); }

    /* Links in the panel take the feed's link treatment: inherit the row's
       colour and underline on hover. */
    .acts-ov-value a { color: inherit; text-decoration: none; }
    .acts-ov-value a:hover { color: var(--accent); text-decoration: underline; }    

    /* Below the feed breakpoint the panel spans the full width so it reads as
       its own band between the hero and the controls. */
    @media (max-width: 600px) {
        .acts-overall { width: 100%; min-width: 0; }
    }    

    /* ---- Feed controls: the three type pills + the sort toggle ----
       No max-width any more: the feed and its furniture run the full
       width of the page container. */
    .acts-controls {
        display: flex; flex-wrap: wrap; align-items: center; gap: .4rem;
        font-family: var(--sans); margin: 0 0 1rem;
    }
    .acts-pill {
        font-size: .78rem; font-weight: 600; color: var(--muted);
        background: none; border: 1px solid var(--rule); border-radius: 999px;
        padding: .3rem .8rem; cursor: pointer;
        transition: color .12s, border-color .12s, background .12s;
    }
    .acts-pill:hover { color: var(--accent); border-color: var(--accent); }
    .acts-pill.is-active { color: #fff; background: var(--accent); border-color: var(--accent); }
    .acts-pill b { font-variant-numeric: tabular-nums; font-weight: 600; }

    /* The type filter reuses the translation switcher's .tx dropdown styling
       wholesale (defined in app.blade.php). Its options carry no href, so the
       only thing missing is the link cursor — restore it. */
    .acts-filter .tx-option { cursor: pointer; }

    /* ---- Sort toggle: rides the right edge of the controls row ----
       Shaped after the site's translation switcher — outlined pill,
       label plus a caret. It is a plain toggle, not a menu, so there is
       no <details> underneath it. */
    .acts-sort {
        margin-left: auto;              /* pins it to the far right */
        display: inline-flex; align-items: center; gap: .45rem;
        font-family: var(--sans);
        font-size: .82rem;              /* ← SORT LABEL SIZE — tweak me */
        font-weight: 600;
        color: var(--ink); background: var(--bg);
        border: 1px solid var(--rule); border-radius: 999px;
        padding: .35rem .75rem .35rem .95rem;
        cursor: pointer;
        transition: color .12s, border-color .12s, background .12s;
    }
    .acts-sort:hover { color: var(--accent); border-color: var(--accent); }
    .acts-sort-caret {
        font-size: .7rem; line-height: 1; color: var(--muted);
        transition: color .12s;
    }
    .acts-sort:hover .acts-sort-caret { color: var(--accent); }

    /* ---- The feed itself ---- */
    .acts-feed {
        border: 1px solid var(--rule); border-radius: 8px;
        font-family: var(--sans);
        overflow: hidden;
    }
    .acts-row {
        display: flex; align-items: baseline; gap: .6rem;
        padding: .55rem .9rem;
        border-bottom: 1px solid var(--rule);
        font-size: .86rem;
    }
    .acts-row:last-child { border-bottom: none; }
    .acts-kind {
        flex: 0 0 auto;
        min-width: 3.4rem; text-align: center;   /* SCRIM / VIGIL align in a column */
        font-size: .62rem; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--muted);
        border: 1px solid var(--rule); border-radius: 3px;
        padding: .08rem .35rem;
        font-variant-numeric: tabular-nums;
    }
    /* Chapter and book completions still read as VIGIL, but the badge
       takes the accent so a milestone is spottable at a glance. */
    .acts-row.is-milestone .acts-kind { color: var(--accent); border-color: var(--accent); }
    .acts-deed { flex: 1 1 auto; min-width: 0; }
    .acts-deed a { color: var(--ink); text-decoration: none; }
    .acts-deed a:hover { color: var(--accent); text-decoration: underline; }
    .acts-deed .tx { color: var(--muted); font-weight: 600; font-size: .74rem; }
    .acts-deed .marks,
    .acts-deed .tally { color: var(--accent); font-weight: 600; font-variant-numeric: tabular-nums; }
    .acts-deed .acts-ms { color: var(--accent); font-weight: 600; }

    /* Book-name swap, mirroring the homepage's .bk-full / .bk-short pattern:
       the short label is emitted only when one exists and stays hidden until
       the mobile breakpoint below flips the pair. */
    .acts-deed .bk-short { display: none; }
    .acts-when {
        flex: 0 0 auto; color: var(--muted); font-size: .74rem;
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .acts-empty { padding: 1.4rem .9rem; color: var(--muted); font-size: .86rem; font-style: italic; }

    /* ---- Pager ----
       Three tracks: Previous pinned left, the page label dead centre
       under the feed, Next pinned right. The 1fr / auto / 1fr split
       keeps the label centred no matter how wide the buttons get. */
    .acts-pager {
        display: grid; grid-template-columns: 1fr auto 1fr;
        align-items: center; gap: .8rem;
        font-family: var(--sans); font-size: .8rem; color: var(--muted);
        margin: .7rem 0 2.2rem;
    }
    .acts-prev { justify-self: start; }
    .acts-next { justify-self: end; }
    .acts-page-label {
        justify-self: center; text-align: center;
        font-variant-numeric: tabular-nums;
    }
    .acts-pager .acts-pill:disabled { opacity: .35; cursor: default; }
    .acts-pager .acts-pill:disabled:hover { color: var(--muted); border-color: var(--rule); }

    /* ---- Record cards (export / import / clear) ----
       Full width, matching the feed above them. */
    .acts-cards { display: grid; gap: .8rem; grid-template-columns: 1fr; }

    /* Desktop: the three cards ride one row, all equal width and equal height.
       Three equal columns fill the container; align-items: stretch (the grid
       default) squares the row to the tallest card. Mobile keeps the stacked
       single column above, untouched. */
    @media (min-width: 601px) {
        .acts-cards { grid-template-columns: repeat(3, 1fr); }
        /* The cards are already equal height (grid stretch). Make each a column
           and let the action button eat the space above it (margin-top:auto),
           dropping all three buttons to a shared baseline so their centres line
           up no matter how much text sits above them. */
        .acts-card { display: flex; flex-direction: column; }
        .acts-card .acts-btn { margin-top: auto; }
    }

    .acts-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: 1rem 1.1rem 1.1rem;
        background: var(--bg);
        font-family: var(--sans);
    }
    .acts-card h2 { font-family: var(--serif); font-size: 1.25rem; font-weight: 400; margin: 0 0 .3rem; }
    .acts-card p  { color: var(--muted); font-size: .84rem; margin: 0 0 .8rem; line-height: 1.5; }

    .acts-btn {
        font-family: var(--sans); font-size: .84rem; font-weight: 600;
        color: var(--accent); background: none;
        border: 1px solid var(--accent); border-radius: 999px;
        padding: .45rem 1.1rem; cursor: pointer;
        transition: color .12s, background .12s;
    }
    .acts-btn:hover { color: #fff; background: var(--accent); }

    /* The destructive one reads muted until you mean it. */
    .acts-card.danger { border-style: dashed; }
    .acts-card.danger .acts-btn { color: var(--muted); border-color: var(--rule); }
    .acts-card.danger .acts-btn:hover { color: #fff; background: var(--accent); border-color: var(--accent); }

    /* Hidden native file input; the styled button proxies it. */
    .acts-file { display: none; }

    .acts-back { font-family: var(--sans); font-size: .88rem; margin-top: 2rem; }
    .acts-back a { color: var(--accent); text-decoration: none; margin-right: 1.2rem; }
    .acts-back a:hover { text-decoration: underline; }

    .acts-section-title {
        font-family: var(--serif); font-size: 1.5rem; font-weight: 400;
        margin: 2.4rem 0 .8rem;
    }

    /* Scroll-to target gets a little breathing room. If your layout grows a
       sticky top nav that covers this heading on the jump, raise this to the
       nav's height. */
    #your-record { scroll-margin-top: 1rem; }

    .acts-record-intro {
        font-family: var(--sans); font-size: .88rem; color: var(--muted);
        line-height: 1.6; margin: 0 0 1.2rem;   /* max-width removed → fills container */
    }
    .acts-record-intro p { margin: 0 0 .8rem; }        /* ← SPACE BETWEEN PARAGRAPHS — knob */
    .acts-record-intro p:last-child { margin-bottom: 0; }
    .acts-record-intro strong { color: var(--ink); font-weight: 600; }

    /* ---- Mobile feed ----
       Below this width the feed rows are deliberately reshaped so nothing
       wraps at the mercy of the text, and EVERY row is at least two lines so
       the feed reads uniformly:
         · long book names swap to their short labels (.bk-short);
         · the "N marks / N verses" tail (.acts-suffix) drops to its own line,
           left-justified, with the interpunct dropped;
         · a milestone lead ("Completed Chapter:") sits on line 1 with its
           reference dropped beneath it (.acts-lead);
         · the row centres its flanks, squares the emblem, and stacks the
           timestamp — month/day over time, right-aligned, no interpunct.
       Desktop is untouched: every part above renders inline exactly as before.
       BREAKPOINT — the feed is denser than the homepage tiles (an emblem and
       a timestamp flank every row), so it swaps earlier than the homepage's
       420px. Tune here. */
    @media (max-width: 600px) {
        .acts-deed .bk-full  { display: none; }
        .acts-deed .bk-short { display: inline; }

        .acts-deed .acts-suffix { display: block; }   /* count tail → own line */
        .acts-deed .acts-dot    { display: none; }    /* drop its interpunct */

        /* Uniform two-line rows: centre the flanks, square the emblem, and
           stack the timestamp date-over-time, right-aligned, no interpunct. */
        .acts-row  { align-items: center; }
        .acts-kind { padding-top: .4rem; padding-bottom: .4rem; }  /* squarer emblem — tune */
        .acts-when { text-align: right; white-space: normal; }
        .acts-when .when-date,
        .acts-when .when-time { display: block; }
        .acts-when .when-sep  { display: none; }
    }
</style>
@endsection

@section('content')
    <div class="acts-top">
        <div class="acts-hero">
            <h1>Acts of the User</h1>
            <p>A record of your actions on MEGABIBLE.net. </p><p>These acts exist only on this device.</p><p><a class="acts-learn" href="#your-record"> Learn more <span class="acts-learn-arrow" aria-hidden="true">&darr;</span></a></p>
        </div>

        {{-- Overall record: four whole-record figures. Painted by the script
             from the same localStorage the feed reads; the values start as
             dashes and the whole panel hides itself when the record is empty. --}}
        <aside class="acts-overall" id="acts-overall" aria-label="Overall record">
            <div class="acts-ov-row">
                <span class="acts-ov-label">First act</span>
                <span class="acts-ov-value" id="ov-first">&mdash;</span>
            </div>
            <div class="acts-ov-row">
                <span class="acts-ov-label">Top book</span>
                <span class="acts-ov-value" id="ov-book">&mdash;</span>
            </div>
            <div class="acts-ov-row">
                <span class="acts-ov-label">Best scrim</span>
                <span class="acts-ov-value" id="ov-scrim">&mdash;</span>
            </div>
            <div class="acts-ov-row">
                <span class="acts-ov-label">Bible Time</span>
                <span class="acts-ov-value" id="ov-time">&mdash;</span>
            </div>
        </aside>
    </div>

    {{-- Type filter. A <details class="tx"> dropdown, reusing the translation
         switcher's styling and its app.blade click-away/Escape script. The
         options carry no href — a click filters the feed in place (see the
         script) — and the per-type count rides in the .tx-year slot. --}}
    <div class="acts-controls" id="acts-controls">
        <details class="tx acts-filter" id="acts-filter">
            <summary class="tx-pill">
                <span id="acts-filter-current">All</span>
                <span class="tx-caret">▾</span>
            </summary>

            <div class="tx-menu">
                <a class="tx-option is-current" data-filter="all" role="button" tabindex="0" aria-current="true">
                    <span class="tx-check">✓</span>
                    <span class="tx-name">All</span>
                    <span class="tx-year" data-count>0</span>
                </a>
                <a class="tx-option" data-filter="scrim" role="button" tabindex="0">
                    <span class="tx-check"></span>
                    <span class="tx-name">Scrim</span>
                    <span class="tx-year" data-count>0</span>
                </a>
                <a class="tx-option" data-filter="daily" role="button" tabindex="0">
                    <span class="tx-check"></span>
                    <span class="tx-name">Daily</span>
                    <span class="tx-year" data-count>0</span>
                </a>
                <a class="tx-option" data-filter="vigil" role="button" tabindex="0">
                    <span class="tx-check"></span>
                    <span class="tx-name">Vigil</span>
                    <span class="tx-year" data-count>0</span>
                </a>
                <a class="tx-option" data-filter="pericope" role="button" tabindex="0">
                    <span class="tx-check"></span>
                    <span class="tx-name">Pericope</span>
                    <span class="tx-year" data-count>0</span>
                </a>
            </div>
        </details>

        {{-- Sort toggle, pinned to the right end of the same row. --}}
        <button type="button" class="acts-sort" id="acts-sort" aria-label="Change sort order">
            <span id="acts-sort-label">Latest</span>
            <span class="acts-sort-caret" aria-hidden="true">▾</span>
        </button>
    </div>

    <div class="acts-feed" id="acts-feed">
        <div class="acts-empty">Loading your record&hellip;</div>
    </div>

    <div class="acts-pager" id="acts-pager">
        <button type="button" class="acts-pill acts-prev" id="acts-prev">&larr; Previous</button>
        <span class="acts-page-label" id="acts-page-label"></span>
        <button type="button" class="acts-pill acts-next" id="acts-next">Next &rarr;</button>
    </div>

    <h2 class="acts-section-title" id="your-record">Your record</h2>

    <div class="acts-record-intro">
        <p>MEGABIBLE.net never saves user data to its servers. All data points on this page exist only in this
        browser's local storage. Clearing your browser's site data will erase your information!</p> 
        
        <p>You can use the tools below to <strong>export</strong> a backup directly to your device,
        <strong>import</strong> a previously saved record to pick up where you left off, or
        <strong>reset</strong> your system and clear all data.</p>
    </div>

    <div class="acts-cards">
        <div class="acts-card">
            <h2>Export</h2>
            <p>Download a copy of your records in JSON format. This includes all verses typed in Vigil, all Scrimmages, text and theme preferences.</p>
            <button type="button" class="acts-btn" id="acts-export">Download</button>
        </div>

        <div class="acts-card">
            <h2>Import</h2>
            <p>Upload a previously exported JSON record to pick up where you left off. Restoring from a backup will overwrite your existing data.</p>
            <button type="button" class="acts-btn" id="acts-import">Choose a file&hellip;</button>
            <input type="file" class="acts-file" id="acts-file" accept="application/json,.json">
        </div>

        <div class="acts-card danger">
            <h2>Reset</h2>
            <p>Clear <strong>ALL</strong> records stored on this device: Vigil history, Scrimmages, text and theme preferences, and any unlocked secrets.</p><p> <strong>This cannot be undone.</strong></p>
            <button type="button" class="acts-btn" id="acts-clear">Reset</button>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    (function () {
        'use strict';

        const VIGIL_KEY = 'mbVigil.v1';
        const ACTS_KEY  = 'mbActs.v1';
        const PAGE_SIZE = 20;

        /* ---- Server constants (single-variable json only) ---------------
           COUNTS   osis => { txSlug: { chapter: verseCount } }  denominators
           META     osis => { name, slug, off, single }          display rules
           URL patterns carry sentinel tokens, swapped client-side. */
        const COUNTS     = @json($chapterCounts);
        const META       = @json($bookMeta);
        const VIGIL_URL  = @json($vigilUrlPattern);
        const BOOK_URL   = @json($vigilBookUrlPattern);
        const SCRIM_URL  = @json($scrimUrlPattern);

        // Two vigil verse first-typings within this many ms count as the same
        // sitting and collapse into one range row; a larger pause splits them.
        // Server-authoritative (config/typing.php → vigil.session_gap_minutes).
        const SESSION_GAP_MS = @json($vigilSessionGapMs);

        // Every scrim/daily round runs this many seconds on the clock
        // (config/typing.php → challenge.scrimmage_duration). The Bible-Time
        // total counts one of these per logged scrim.
        const SCRIM_SECONDS = @json($scrimSeconds);        

        // Slug → OSIS, inverted from META. Scrim and daily rows log the book
        // SLUG (not the osis), so this lets them rebuild their reference through
        // the same refOf() the vigil rows use — inheriting short names and
        // reader labels instead of the raw name the scrim baked at write time.
        const SLUG_TO_OSIS = {};
        for (const _osis in META) {
            const _slug = META[_osis] && META[_osis].slug;
            if (_slug) SLUG_TO_OSIS[_slug] = _osis;
        }

        const $ = function (id) { return document.getElementById(id); };
        function esc(s) {
            const d = document.createElement('div');
            d.textContent = String(s);
            return d.innerHTML;
        }

        /* Every event moment is normalised to MILLISECONDS here, because the
           feed's clock (new Date) and its sort both speak ms. Sources differ:
           mbVigil.v1 stores unix SECONDS (matching the vigil's own fmtUTC),
           while mbActs.v1 stores Date.now() milliseconds. A real seconds
           stamp is ~1.7e9 and a real ms stamp ~1.7e12, so the 1e11 threshold
           (≈ 1973 in ms, ≈ year 5138 in seconds) separates them cleanly for
           any date this site will ever see. */
        function msOf(ts) {
            const n = Number(ts);
            if (!isFinite(n) || n <= 0) return 0;
            return n < 1e11 ? n * 1000 : n;
        }

        /* The two families the feed now speaks in. Raw event types are kept
           on the event objects (the row builder still needs to know a book
           from a verse); this is only the badge + filter grouping. */
        function groupOf(t) {
            if (t === 'daily') return 'daily';
            if (t === 'scrim') return 'scrim';
            // 'verse' kept for safety though the builder no longer emits it;
            // 'verserange' is the collapsed sitting that replaces it.
            if (t === 'verse' || t === 'verserange' ||
                t === 'chapter' || t === 'book') return 'vigil';
            if (t.indexOf('pericope.') === 0) return 'pericope';
            return 'other';                          // future event types
        }

        /* =================================================================
           SESSIONIZING — the machinery behind collapsed verse-range rows.
           ================================================================= */

        // Group verse first-typings into SITTINGS. Sort by time, then start a
        // new sitting whenever the gap to the previous typing exceeds gapMs.
        // Out-of-order typing (15..31 then 1..14) still lands in one sitting as
        // long as the timestamps stay within the gap — order is decided later,
        // by verse number, in mergeRuns().
        function sessionize(items, gapMs) {
            if (!items.length) return [];
            const byTime = items.slice().sort(function (a, b) { return a.ts - b.ts; });
            const sessions = [];
            let cur = [byTime[0]];
            for (let i = 1; i < byTime.length; i++) {
                if (byTime[i].ts - byTime[i - 1].ts > gapMs) { sessions.push(cur); cur = []; }
                cur.push(byTime[i]);
            }
            sessions.push(cur);
            return sessions;
        }

        // Sort verse numbers and merge them into contiguous runs:
        //   [3,1,2,5,4,9,10] -> [[1,5],[9,10]]
        // The client-side, single-chapter cousin of the server's
        // normalizeVerses(); the input is already bounded to one chapter, so
        // there's nothing here to guard against.
        function mergeRuns(nums) {
            const sorted = nums.slice().sort(function (a, b) { return a - b; });
            const runs = [];
            let lo = sorted[0], hi = sorted[0];
            for (let i = 1; i < sorted.length; i++) {
                const n = sorted[i];
                if (n === hi || n === hi + 1) { hi = n; }   // contiguous (or dup)
                else { runs.push([lo, hi]); lo = hi = n; }
            }
            runs.push([lo, hi]);
            return runs;
        }

        // Tiebreak for equal timestamps: a milestone outranks the range it
        // finished, so "Completed Genesis 1" sits above "Genesis 1:1–31".
        function rankOf(t) {
            if (t === 'book') return 0;
            if (t === 'chapter') return 1;
            return 2;                                // verserange, scrim, daily…
        }

        /* =================================================================
           BUILD THE EVENT LIST — the whole record, once, in memory.

           Vigil events are DERIVED from mbVigil.v1:
             range    stored verses with n>0, keyed by their `first` moment
                      (falling back to `ts` for entries written before the
                      first-timestamp field existed), clustered into sittings
                      by sessionize() and compressed into verse runs by
                      mergeRuns(). One row per sitting; its moment is the
                      sitting's last new verse.
             chapter  when every verse the chapter holds (per COUNTS) is
                      typed, its completion moment is the LATEST first-
                      timestamp among them — the keystroke that finished it.
             book     all chapters complete; moment = latest chapter moment.

           Scrimmage events come verbatim from the mbActs.v1 log.
           ================================================================= */
        function buildEvents() {
            const evs = [];

            let store = {};
            try { store = JSON.parse(localStorage.getItem(VIGIL_KEY)) || {}; } catch (e) {}

            for (const tx in store) {
                for (const osis in store[tx]) {
                    const chapters = store[tx][osis];
                    const txCounts = (COUNTS[osis] || {})[tx] || {};
                    const totalChapters = Object.keys(txCounts).length;
                    const chapterDone = {};              // ch => completion ts

                    for (const ch in chapters) {
                        const verses = chapters[ch];
                        const total  = txCounts[ch];
                        let typed = 0, latest = 0;
                        const items = [];               // { v, ts } — first-typings

                        for (const v in verses) {
                            const rec = verses[v];
                            if (!rec || !(rec.n > 0)) continue;

                            // `first` is a scalar; `ts` is an ARRAY of the last
                            // seven completions, so the pre-`first` fallback has
                            // to take its oldest element, not the array itself.
                            const raw = rec.first
                                || (Array.isArray(rec.ts) ? rec.ts[0] : rec.ts);
                            const ts = msOf(raw);
                            if (!ts) continue;           // ancient entry, no clock

                            typed++;
                            if (ts > latest) latest = ts;
                            items.push({ v: +v, ts: ts });
                        }

                        // One row per SITTING: cluster the first-typings by time,
                        // then compress each cluster's verse NUMBERS into runs.
                        // The row's moment is the sitting's last new verse, so a
                        // range sits beside the milestone it finished.
                        sessionize(items, SESSION_GAP_MS).forEach(function (session) {
                            let rowTs = 0;
                            const nums = session.map(function (it) {
                                if (it.ts > rowTs) rowTs = it.ts;
                                return it.v;
                            });
                            evs.push({
                                t: 'verserange', ts: rowTs,
                                tx: tx, osis: osis, ch: +ch,
                                ranges: mergeRuns(nums),      // [[1,10],[15,31]]
                            });
                        });

                        if (total && typed >= total && latest) {
                            chapterDone[ch] = latest;
                            // Single-chapter books get ONLY the "Completed Book"
                            // row — a chapter row would be a near-duplicate of it.
                            // chapterDone is still set so the book check below fires.
                            if (totalChapters !== 1) {
                                evs.push({ t: 'chapter', ts: latest, tx: tx, osis: osis, ch: +ch });
                            }
                        }
                    }

                    // Book completion: every chapter this edition carries is done.
                    const allCh = Object.keys(txCounts);
                    if (allCh.length && allCh.every(function (c) { return chapterDone[c]; })) {
                        let ts = 0;
                        allCh.forEach(function (c) { if (chapterDone[c] > ts) ts = chapterDone[c]; });
                        evs.push({ t: 'book', ts: ts, tx: tx, osis: osis });
                    }
                }
            }

            let log = [];
            try { log = JSON.parse(localStorage.getItem(ACTS_KEY)) || []; } catch (e) {}
            if (Array.isArray(log)) {
                log.forEach(function (e) {
                    if (!e || !e.t || !e.ts) return;
                    // Already ms today, but normalising costs nothing and keeps
                    // the feed correct if a future writer logs seconds.
                    evs.push(Object.assign({}, e, { ts: msOf(e.ts) }));
                });
            }

            // Newest first. On identical timestamps — a chapter/book finishes on
            // the same keystroke as the range that filled it — show the bigger
            // milestone above its supporting detail.
            evs.sort(function (a, b) {
                return (b.ts - a.ts) || (rankOf(a.t) - rankOf(b.t));
            });
            return evs;
        }

        /* =================================================================
           DISPLAY HELPERS
           ================================================================= */

        // A book name for a feed row — safe HTML. When a short label exists we
        // emit BOTH, mirroring the homepage's .bk-full / .bk-short pair so the
        // mobile breakpoint can swap them with pure CSS; otherwise just the
        // escaped full name. Because these helpers now return markup, their
        // callers no longer esc() the result.
        function bookLabel(m) {
            const full = esc(m.name);
            if (!m.short) return full;
            return '<span class="bk-full">' + full + '</span>' +
                   '<span class="bk-short">' + esc(m.short) + '</span>';
        }

        // Reader-level reference — the readerRef() rules, precomputed into META:
        // override books add their chapter offset; single-chapter books drop the
        // chapter number ("Jude 5"). Returns safe HTML (see bookLabel).
        function refOf(osis, ch, v) {
            const m = META[osis] || { name: osis, off: 0, single: false };
            const dispCh = (ch || 0) + (m.off || 0);
            let tail;
            if (v != null) tail = m.single ? (' ' + v) : (' ' + dispCh + ':' + v);
            else           tail = m.single ? '' : (' ' + dispCh);
            return bookLabel(m) + tail;
        }

        // The range cousin of refOf(), honouring the same META rules. `ranges`
        // is a list of [lo, hi] runs within ONE chapter, so the chapter prefix
        // is written once and the verse parts comma-join:
        //   "Genesis 1:1–31" · "Genesis 1:1–10, 15–31" · "Jude 5–9" ·
        //   "Psalm 151:1–7".  A single-verse run drops its dash ("Genesis 1:15").
        //   Returns safe HTML (see bookLabel).
        function refRangeOf(osis, ch, ranges) {
            const m = META[osis] || { name: osis, off: 0, single: false };
            const dispCh = (ch || 0) + (m.off || 0);
            const versePart = ranges.map(function (r) {
                return r[0] === r[1] ? String(r[0]) : (r[0] + '\u2013' + r[1]);
            }).join(', ');
            const tail = m.single ? (' ' + versePart) : (' ' + dispCh + ':' + versePart);
            return bookLabel(m) + tail;
        }

        // The "· N marks / N verses" tail. The interpunct rides in its own
        // .acts-dot so mobile can drop the tail to its own line AND hide the
        // dot; on desktop the whole thing stays inline. `inner` is safe HTML.
        function suffix(inner) {
            return '<span class="acts-suffix"><span class="acts-dot"> \u00B7 </span>' + inner + '</span>';
        }

        // The reference for a scrim/daily row. These log the book SLUG plus a
        // chapter and verse, so when the book is one we know, we rebuild the
        // reference through refOf() — identical formatting to the vigil rows,
        // short names and reader labels (Psalm 153, Jude 5) included. Only when
        // the slug is unknown or the numbers are missing do we fall back to the
        // string the scrim baked at write time. Returns safe HTML either way.
        function scrimRef(e, fallback) {
            const osis = SLUG_TO_OSIS[e.b];
            const c = Number(e.c), v = Number(e.v);
            // c >= 1 && v >= 1 rejects a missing or blank number, since
            // Number(null) and Number('') are a finite 0, not NaN.
            if (osis && c >= 1 && v >= 1) {
                return refOf(osis, c, v);
            }
            return esc(e.ref || fallback);
        }

        /* Pericope rows. The event logs the board's id/slug/name and, for an
           add, the RAW refs. We prefer the LIVE board (in case it was renamed)
           when window.MBPericope is loaded; since this script runs inline, that
           usually isn't the case yet, so we fall back to the logged slug/name —
           correct unless a board was renamed after the fact. Delete rows never
           link (the board is gone). Returns safe HTML. */
        function pericopeRefsHtml(e) {
            const refs = e.refs || [];
            if (refs.length === 1) {
                const r = refs[0];
                return refRangeOf(r.osis, r.ch, [[r.v1, r.v2]]);   // "Romans 8:28–30", safe HTML
            }
            const n = refs.length || e.count || 0;
            return esc(n + (n === 1 ? ' card' : ' cards'));
        }
        function pericopeDeed(e) {
            const live = (window.MBPericope && e.id) ? window.MBPericope.get(e.id) : null;
            const name = live ? live.name : (e.name || 'a pericope');
            const slug = live ? live.slug : (e.slug || null);
            const base = window.MB_PERICOPE_BASE;
            const link = (slug && base && e.t !== 'pericope.delete')
                ? (base + '/' + encodeURIComponent(slug)) : null;
            const named = link ? ('<a href="' + link + '">' + esc(name) + '</a>') : esc(name);

            if (e.t === 'pericope.create') return 'Started ' + named;
            if (e.t === 'pericope.delete') return 'Removed ' + esc(name);
            return 'Added ' + pericopeRefsHtml(e) + ' to ' + named;   // add
        }

        function fill(pattern, parts) {
            return pattern
                .replace('__T__', encodeURIComponent(parts.t))
                .replace('__B__', encodeURIComponent(parts.b))
                .replace('__C__', parts.c)
                .replace('__V__', parts.v);
        }

        const THIS_YEAR = new Date().getFullYear();
        function fmtWhen(ts) {
            const d = new Date(ts);
            const opts = { month: 'short', day: 'numeric' };
            if (d.getFullYear() !== THIS_YEAR) opts.year = 'numeric';
            const date = d.toLocaleDateString(undefined, opts);
            const time = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
            // Three parts so mobile can stack date over time and drop the dot;
            // inline on desktop it reads exactly as before: "Jan 5 · 3:42 PM".
            return '<span class="when-date">' + esc(date) + '</span>' +
                   '<span class="when-sep"> \u00B7 </span>' +
                   '<span class="when-time">' + esc(time) + '</span>';
        }

        function txBadge(tx) {
            return ' <span class="tx">' + esc(String(tx).toUpperCase()) + '</span>';
        }

        /* One feed row.

           Badge: VIGIL or SCRIM, nothing else.
           Deed:  the linked reference alone for range and scrim rows — the
                  badge already says how it was typed. Chapter and book rows
                  lead with "Completed Chapter:" / "Completed Book:" (in an
                  .acts-lead span that drops to its own line on mobile), so a
                  bare reference can't be mistaken for an ordinary typed verse.

           Links: range and chapter rows point into the vigil chapter; book
                  rows at the vigil book hub; scrim rows back to the scrim. */
        function rowHtml(e) {
            const kind = groupOf(e.t);
            let deed = '';
            let milestone = false;

            if (e.t === 'verserange') {
                const slug = (META[e.osis] || {}).slug;
                const ref  = refRangeOf(e.osis, e.ch, e.ranges);   // safe HTML
                const body = slug
                    ? '<a href="' + fill(VIGIL_URL, { t: e.tx, b: slug, c: e.ch, v: '' }) + '">' + ref + '</a>'
                    : ref;
                deed = body + txBadge(e.tx);
                // A verse tally, styled like the scrim marks count. Always shown,
                // singular for one verse ("1 verse"), so a lone typed verse still
                // reads as a deed rather than a bare reference.
                const n = e.ranges.reduce(function (s, r) { return s + (r[1] - r[0] + 1); }, 0);
                deed += suffix('<span class="tally">' + n + (n === 1 ? ' verse' : ' verses') + '</span>');

            } else if (e.t === 'verse') {
                // Legacy single-verse row — the builder no longer emits these,
                // but the branch stays so an imported old record still renders.
                const slug = (META[e.osis] || {}).slug;
                const ref  = refOf(e.osis, e.ch, e.v);             // safe HTML
                const body = slug
                    ? '<a href="' + fill(VIGIL_URL, { t: e.tx, b: slug, c: e.ch, v: '' }) + '">' + ref + '</a>'
                    : ref;
                deed = body + txBadge(e.tx);

            } else if (e.t === 'chapter') {
                const slug = (META[e.osis] || {}).slug;
                const ref  = refOf(e.osis, e.ch, null);            // safe HTML
                const body = slug
                    ? '<a href="' + fill(VIGIL_URL, { t: e.tx, b: slug, c: e.ch, v: '' }) + '">' + ref + '</a>'
                    : ref;
                deed = body + txBadge(e.tx) + suffix('<span class="acts-ms">Completed Chapter</span>');
                milestone = true;

            } else if (e.t === 'book') {
                const m    = META[e.osis] || { name: e.osis };
                const body = m.slug
                    ? '<a href="' + fill(BOOK_URL, { t: e.tx, b: m.slug, c: '', v: '' }) + '">' + bookLabel(m) + '</a>'
                    : bookLabel(m);
                deed = body + txBadge(e.tx) + suffix('<span class="acts-ms">Completed Book</span>');
                milestone = true;

            } else if (e.t === 'daily') {
                // The rarest deed in the log — one per date, ever — so it
                // wears the milestone accent. No leading label: the DAILY
                // emblem on the left already names the mode. Links to the
                // verse's REGULAR scrim (the daily page only ever shows
                // today); when the archive pages exist this can point at the
                // frozen day.
                const ref  = scrimRef(e, 'the daily verse');       // safe HTML
                const body = (e.b && e.c && e.v)
                    ? '<a href="' + fill(SCRIM_URL, { t: e.tx, b: e.b, c: e.c, v: e.v }) + '">' + ref + '</a>'
                    : ref;
                deed = body + txBadge(e.tx);
                if (typeof e.score === 'number') {
                    deed += suffix('<span class="marks">' + esc(e.score) + ' marks</span>');
                }
                milestone = true;

            } else if (e.t === 'scrim') {
                const ref  = scrimRef(e, (e.b || 'a verse'));      // safe HTML
                const body = (e.b && e.c && e.v)
                    ? '<a href="' + fill(SCRIM_URL, { t: e.tx, b: e.b, c: e.c, v: e.v }) + '">' + ref + '</a>'
                    : ref;
                deed = body + txBadge(e.tx);
                if (typeof e.score === 'number') {
                    deed += suffix('<span class="marks">' + esc(e.score) + ' marks</span>');
                }

            } else if (e.t === 'pericope.create' || e.t === 'pericope.add' || e.t === 'pericope.delete') {
                deed = pericopeDeed(e);

            } else {
                deed = esc(e.t);                     // future event types
            }

            const cls = 'acts-row k-' + esc(kind) + (milestone ? ' is-milestone' : '');
            const badge = kind === 'other' ? esc(e.t) : kind;

            return '<div class="' + cls + '">' +
                       '<span class="acts-kind">' + badge + '</span>' +
                       '<span class="acts-deed">' + deed + '</span>' +
                       '<span class="acts-when">' + fmtWhen(e.ts) + '</span>' +
                   '</div>';
        }

        /* =================================================================
           STATE + RENDER — filter, sort, slice, paint. All in memory.
           ================================================================= */
        const EVENTS = buildEvents();                    // newest-first master
        const state  = { filter: 'all', asc: false, page: 0 };

        function filtered() {
            const list = state.filter === 'all'
                ? EVENTS
                : EVENTS.filter(function (e) { return groupOf(e.t) === state.filter; });
            return state.asc ? list.slice().reverse() : list;
        }

        function render() {
            const list  = filtered();
            const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
            if (state.page >= pages) state.page = pages - 1;
            if (state.page < 0) state.page = 0;

            const start = state.page * PAGE_SIZE;
            const slice = list.slice(start, start + PAGE_SIZE);

            const feed = $('acts-feed');
            feed.innerHTML = slice.length
                ? slice.map(rowHtml).join('')
                : '<div class="acts-empty">No acts yet recorded.</div>';

            // The pager always shows; the buttons simply go dead when there
            // is nowhere to go. One page means both are disabled.
            $('acts-prev').disabled = state.page === 0;
            $('acts-next').disabled = state.page >= pages - 1;
            $('acts-page-label').textContent =
                'Page ' + (state.page + 1) + ' of ' + pages +
                ' \u00B7 ' + list.length + (list.length === 1 ? ' act' : ' acts');
        }

        // Filter counts, once — the record doesn't change while you look at it.
        // Each count rides in its option's .tx-year slot: "All … 42".
        const filterDD = $('acts-filter');
        (function paintCounts() {
            const tally = { all: EVENTS.length, scrim: 0, vigil: 0, daily: 0, pericope: 0 };
            EVENTS.forEach(function (e) {
                const g = groupOf(e.t);
                if (tally[g] != null) tally[g]++;
            });
            filterDD.querySelectorAll('.tx-option').forEach(function (opt) {
                const c = opt.querySelector('[data-count]');
                if (c) c.textContent = tally[opt.dataset.filter] || 0;
            });
        })();

        /* =================================================================
           THE OVERALL PANEL — four whole-record figures, painted once.
           Same localStorage the feed reads; the only server input is the
           scrim clock length. Hidden entirely when the record is empty so a
           new visitor never meets a wall of dashes.
           ================================================================= */
        (function paintOverall() {
            const panel = $('acts-overall');
            if (!panel) return;
            if (!EVENTS.length) { panel.hidden = true; return; }

            // ---- First act: the oldest moment on record. ---------------------
            let firstMs = Infinity;
            EVENTS.forEach(function (e) { if (e.ts && e.ts < firstMs) firstMs = e.ts; });
            $('ov-first').textContent = isFinite(firstMs)
                ? new Date(firstMs).toLocaleDateString(undefined,
                    { year: 'numeric', month: 'short', day: 'numeric' })
                : '\u2014';

            // ---- Fav book: most interactions — each typed verse and each scrim
            //      worth one, counted per translation. Milestones (chapter/book
            //      completions) are skipped so they don't double-count the range
            //      that earned them. Ties break to the most recent. The link
            //      points at the vigil book hub in the translation the book was
            //      most recently touched in. -----------------------------------
            const count = {}, lastTs = {}, lastTx = {};
            function bump(osis, by, ts, tx) {
                if (!osis) return;
                count[osis] = (count[osis] || 0) + by;
                if (ts > (lastTs[osis] || 0)) { lastTs[osis] = ts; lastTx[osis] = tx; }
            }
            EVENTS.forEach(function (e) {
                if (e.t === 'verserange') {
                    const n = e.ranges.reduce(function (s, r) { return s + (r[1] - r[0] + 1); }, 0);
                    bump(e.osis, n, e.ts, e.tx);
                } else if (e.t === 'verse') {           // legacy single-verse row
                    bump(e.osis, 1, e.ts, e.tx);
                } else if (e.t === 'scrim' || e.t === 'daily') {
                    bump(SLUG_TO_OSIS[e.b], 1, e.ts, e.tx);
                }
            });
            let favOsis = null, favN = -1, favTs = -1;
            for (const o in count) {
                const c = count[o], t = lastTs[o] || 0;
                if (c > favN || (c === favN && t > favTs)) { favOsis = o; favN = c; favTs = t; }
            }
            if (favOsis) {
                const m    = META[favOsis] || {};
                const name = esc(m.name || favOsis);
                const tx   = lastTx[favOsis];
                $('ov-book').innerHTML = (m.slug && tx)
                    ? '<a href="' + fill(BOOK_URL, { t: tx, b: m.slug, c: '', v: '' }) + '">' + name + '</a>'
                    : name;
            } else {
                $('ov-book').textContent = '\u2014';
            }

            // ---- Best scrim: top marks across scrim AND daily rounds, linked
            //      back to the verse that earned them. On tied top scores the
            //      most recent wins (EVENTS is newest-first, and only a STRICTLY
            //      higher score displaces the incumbent). -----------------------
            let bestEv = null;
            EVENTS.forEach(function (e) {
                if ((e.t === 'scrim' || e.t === 'daily') && typeof e.score === 'number') {
                    if (bestEv === null || e.score > bestEv.score) bestEv = e;
                }
            });
            if (bestEv === null) {
                $('ov-scrim').textContent = '\u2014';
            } else {
                const label = esc(bestEv.score.toLocaleString() + ' marks');
                $('ov-scrim').innerHTML = (bestEv.b && bestEv.c && bestEv.v)
                    ? '<a href="' + fill(SCRIM_URL, { t: bestEv.tx, b: bestEv.b, c: bestEv.c, v: bestEv.v }) + '">' + label + '</a>'
                    : label;
            }

            // ---- Bible Time: real measured typing time (summed tms over the
            //      WHOLE vigil store, every translation) plus a fixed clock per
            //      scrim/daily round. No overlap — vigil lives in mbVigil.v1,
            //      scrims in mbActs.v1 — so the two halves never count the same
            //      moment. dd:hh:mm:ss, zero-padded. -------------------------
            let typingMs = 0;
            try {
                const store = JSON.parse(localStorage.getItem(VIGIL_KEY)) || {};
                for (const tx in store) {
                    for (const osis in store[tx]) {
                        const chapters = store[tx][osis];
                        for (const ch in chapters) {
                            const verses = chapters[ch];
                            for (const v in verses) {
                                const rec = verses[v];
                                if (rec && rec.tms > 0) typingMs += rec.tms;
                            }
                        }
                    }
                }
            } catch (e) {}

            let scrimPlays = 0;
            EVENTS.forEach(function (e) {
                if (e.t === 'scrim' || e.t === 'daily') scrimPlays++;
            });

            const totalMs = typingMs + scrimPlays * SCRIM_SECONDS * 1000;
            $('ov-time').textContent = (function (ms) {
                let s = Math.floor(ms / 1000);
                const d = Math.floor(s / 86400); s -= d * 86400;
                const h = Math.floor(s / 3600);  s -= h * 3600;
                const m = Math.floor(s / 60);    s -= m * 60;
                // Largest non-zero unit down to seconds. Leading zero units are
                // dropped ("3m 10s", never "0d 0h 3m 10s"); interior zeros stay
                // ("1d 0h 4m 22s"); nothing is padded. All zero → "0s".
                const parts = [];
                if (d) parts.push(d + 'd');
                if (parts.length || h) parts.push(h + 'h');
                if (parts.length || m) parts.push(m + 'm');
                parts.push(s + 's');
                return parts.join(' ');
            })(totalMs);
        })();

        // Choosing an option filters the feed in place: move the current marker
        // and check, relabel the pill, close the dropdown, repaint. (Click-away
        // and Escape closing are handled globally by app.blade's .tx script.)
        function chooseFilter(opt) {
            state.filter = opt.dataset.filter;
            state.page = 0;
            filterDD.querySelectorAll('.tx-option').forEach(function (o) {
                const on = o === opt;
                o.classList.toggle('is-current', on);
                const chk = o.querySelector('.tx-check');
                if (chk) chk.textContent = on ? '\u2713' : '';
                if (on) o.setAttribute('aria-current', 'true');
                else    o.removeAttribute('aria-current');
            });
            const name = opt.querySelector('.tx-name');
            if (name) $('acts-filter-current').textContent = name.textContent;
            filterDD.open = false;
            render();
        }

        filterDD.querySelectorAll('.tx-option').forEach(function (opt) {
            opt.addEventListener('click', function (ev) { ev.preventDefault(); chooseFilter(opt); });
            opt.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); chooseFilter(opt); }
            });
        });

        $('acts-sort').addEventListener('click', function () {
            state.asc = !state.asc;
            state.page = 0;
            $('acts-sort-label').textContent = state.asc ? 'Oldest' : 'Latest';
            render();
        });

        // Previous and Next mean previous and next PAGE, whichever way the
        // feed is sorted — so their labels never swap. On a phone, a page turn
        // also lifts the viewport back to the top of the feed, so you're not
        // stranded down by the pager looking at content that already changed.
        const MOBILE_Q = window.matchMedia('(max-width: 600px)');
        function turnPage(delta) {
            state.page += delta;
            render();
            if (MOBILE_Q.matches) {
                $('acts-feed').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        $('acts-prev').addEventListener('click', function () { turnPage(-1); });
        $('acts-next').addEventListener('click', function () { turnPage(1); });

        // "Learn more ↓" in the hero glides down to the record section instead
        // of hard-jumping — the same smooth scroll the mobile pager uses.
        const learnLink  = document.querySelector('.acts-learn');
        const recordHead = $('your-record');
        if (learnLink && recordHead) {
            learnLink.addEventListener('click', function (ev) {
                ev.preventDefault();
                recordHead.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        render();

        /* ---------------- EXPORT: every localStorage key ---------------- */
        const exportBtn = $('acts-export');
        if (exportBtn) exportBtn.addEventListener('click', function () {
            let payload;
            try {
                const data = {};
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    data[k] = localStorage.getItem(k);
                }
                payload = {
                    app: 'megabible', kind: 'user-record', version: 1,
                    exported_at: new Date().toISOString(), data: data,
                };
            } catch (e) {
                mbNotify(['Could not read storage: ' + e.message]);
                return;
            }

            const stamp = payload.exported_at.slice(0, 10).replace(/-/g, '');
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'megabible-record-' + stamp + '.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);

            const n = Object.keys(payload.data).length;
            mbNotify(['Exported ' + n + (n === 1 ? ' entry.' : ' entries.')], { check: true });
        });

        /* ---------------- IMPORT: restore a record file ------------------ */
        const importBtn = $('acts-import');
        const fileInput = $('acts-file');
        if (importBtn && fileInput) {
            importBtn.addEventListener('click', function () { fileInput.click(); });

            fileInput.addEventListener('change', function () {
                const file = fileInput.files && fileInput.files[0];
                fileInput.value = '';                       // allow re-picking same file
                if (!file) return;

                const reader = new FileReader();
                reader.onerror = function () {
                    mbNotify(['Could not read that file.']);
                };
                reader.onload = function () {
                    let payload;
                    try { payload = JSON.parse(reader.result); }
                    catch (e) {
                        mbNotify(['That is not a valid record file (bad JSON).']);
                        return;
                    }
                    if (!payload || payload.app !== 'megabible' ||
                        payload.kind !== 'user-record' || typeof payload.data !== 'object') {
                        mbNotify(['That is not a MEGABIBLE record file.']);
                        return;
                    }

                    const keys = Object.keys(payload.data);
                    const when = payload.exported_at ? payload.exported_at.slice(0, 10) : 'unknown date';

                    mbConfirm(
                        ['Restore this record?',
                         keys.length + ' entries, exported ' + when + '.',
                         'Matching entries on this device will be overwritten.'],
                        { confirmLabel: 'Restore', cancelLabel: 'Cancel' }
                    ).then(function (go) {
                        if (!go) { mbNotify(['Import cancelled.']); return; }

                        let written = 0, failed = 0;
                        keys.forEach(function (k) {
                            try { localStorage.setItem(k, payload.data[k]); written++; }
                            catch (e) { failed++; }
                        });

                        if (failed) {
                            mbNotify(['Restored ' + written + ' entries; ' + failed + ' failed (storage full?).']);
                        } else {
                            mbNotify(['Restored ' + written + ' entries. Reloading…'],
                                     { check: true, autoReload: true });
                            setTimeout(function () { location.reload(); }, 900);
                        }
                    });
                };
                reader.readAsText(file);
            });
        }

        /* ---------------- CLEAR: everything, double-confirmed ------------ */
        const clearBtn = $('acts-clear');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            mbConfirm(
                ['Clear everything this device remembers?',
                 'Vigil history, scrimmage acts, text settings, theme, and any unlocked secrets will all be erased.'],
                { confirmLabel: 'Continue', cancelLabel: 'Cancel' }
            ).then(function (one) {
                if (!one) return;
                return mbConfirm(
                    ['Are you absolutely sure?',
                     'This cannot be undone. There is no way to recover your record once it is cleared.',
                     'Consider exporting your record first.'],
                    { confirmLabel: 'Erase everything', cancelLabel: 'Cancel' }
                ).then(function (two) {
                    if (!two) return;
                    try { localStorage.clear(); } catch (e) {}
                    mbNotify(['Everything cleared.'], { check: true, autoReload: true });
                    setTimeout(function () { location.reload(); }, 700);
                });
            });
        });
    })();
</script>
@endsection
