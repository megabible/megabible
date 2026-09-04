@extends('layouts.app')

@section('title', $refBook . ($refChapter !== null ? ' ' . $refChapter : '') . ' — Typing Vigil — MEGABIBLE.net')

{{--
  =====================================================================
  TYPING VIGIL  ·  /extras/vigil/{translation}/{book}/{chapter}
  ---------------------------------------------------------------------
  A reader you type. This view is a sibling of the chapter reader: same
  sticky head, same QuickNav triggers, same reading-flow partial, same
  floating chapter arrows — but every verse is a typing target.

  Tap any verse to arm it. Untyped characters fade; correct keystrokes
  ink them back in. Completing a verse highlights it, stamps the moment
  into localStorage, and the engine flows on to the next verse. No WPM,
  no timer, no leaderboard — a vigil, not a race.

  Progress lives ONLY in the browser (schema mbVigil.v1, documented at
  the storage helpers below). Phase 2 adds the progress home screen,
  export/import, and batched anonymous telemetry.
  =====================================================================
--}}
@section('styles')
{{--
  Canonical points at the READER's copy of this chapter. The vigil is the
  same text under a different skin, so search engines should index the
  reader page, not thousands of near-duplicate vigil URLs.
--}}
<link rel="canonical" href="{{ route('bible.chapter', ['translation' => $txSlug, 'book' => $book->slug, 'chapter' => $chapter]) }}">
<style>
    @include('bible.partials.sticky-head')

    /* ---- Vigil-specific head bits ----------------------------------------
       The corner cluster is the apps folder (components/head-folder) holding
       just the candle and Aa — and it arrives OPEN, with the candle pressed,
       so the way back to the reader is one tap. Because the pill is out by
       default here, the title reserve is sized for the OPEN pill (two apps +
       the circle ≈ 9rem, plus the cluster's 1.5rem offset) rather than the
       shut circle: a long book name wraps beside the pill, never under it.
       KNOB: drop this toward 4.5rem if you'd rather the pill overlap. ---- */
    .chapter-head { --mb-head-reserve: 10.5rem; }

    /* MUSTACHE — the mode label sits UNDER the h1, not over it. This is what
       keeps the title flush with the top of the head, exactly like the
       reader's, so the corner buttons land level with the title on both
       pages. Shrinks with the head, but LESS than the title does.
       SPACING KNOB: the margin below. */
    .vg-title-wrap { display: block; min-width: 0; }
    .vg-eyebrow {
        display: block;
        color: var(--accent); font-family: var(--sans);
        font-size: .76rem; font-weight: 700;
        letter-spacing: .12em; text-transform: uppercase;
        margin: .12rem 0 0;
    }
    .chapter-head.is-stuck .vg-eyebrow { font-size: .68rem; }

    /* Hub back link and tap hint. Both live BELOW the head, on the scrolling
       surface, so they slide up under the sticky header with the verse text.
       SPACING KNOBS: the margins. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 .55rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    .vg-hint {
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: 0 0 1.2rem;
    }

    @include('bible.partials.reading-styles')

    /* ======================================================================
       VIGIL SKIN
       ----------------------------------------------------------------------
       TWEAK KNOBS — the custom properties on .vg-wrap are the values to
       adjust to taste:
         --vg-done-bg     background wash on a completed verse
         --vg-armed-bg    background on the verse being typed (default: none;
                          the faded characters already mark it)
         --vg-caret       caret colour
         --vg-fade        colour of not-yet-typed characters in an armed verse
       ====================================================================== */
    .vg-wrap {
        /* Arming a verse re-renders it as one span per character, which drops
       kerning and ligatures and makes the text measure a hair wider than the
       same verse unarmed — enough to bump a word to the next line and re-wrap
       the paragraph on click. Turning both off for the whole vigil makes the
       armed and unarmed measurements identical, so nothing moves. */
        font-kerning: none;
        font-variant-ligatures: none;
        position: relative;
        --vg-done-bg:  rgba(107,31,31,.09);                          /* fallback */
        --vg-done-bg:  color-mix(in srgb, var(--accent) 9%, transparent);
        --vg-armed-bg: var(--rule);     /* the ACTIVE verse — same token as the
                                           reader's focus selection, so "where am
                                           I typing" reads instantly on a blank
                                           page. Tweak me to taste. */
        --vg-hover-bg: var(--panel);    /* mouseover preview — the reader's soft
                                           hover token. */
        --vg-caret:    var(--accent);
        --vg-fade:     var(--soon);
    }

    /* Arming a verse re-renders it as one span per character, which drops
       kerning and ligatures and makes the text measure a hair wider than the
       same verse unarmed — enough to bump a word to the next line and re-wrap
       the paragraph on click. Turning both off for the whole vigil makes the
       armed and unarmed measurements identical, so nothing moves. */
    .vg-wrap {
        font-kerning: none;
        font-variant-ligatures: none;
    }

    /* Verses invite a tap — the reader's own affordance. */
    .vg-wrap .verse { cursor: pointer; touch-action: pan-y; }

    /* ALL vigil text sits faded until it's typed — this is what visually
       distinguishes the vigil from the reader at a glance. Verse numbers keep
       their accent so wayfinding survives; completed verses ink back to normal
       (rule below). Armed verses fade per-character via .vg-ch instead. */
    .vg-wrap .reading,
    .vg-wrap.reading,
    .vg-wrap .verse { color: var(--vg-fade); }
    .vg-wrap .verse-number { color: var(--accent); }

    /* Completed verses read at full strength — the reward for finishing. */
    .vg-wrap .verse.vg-done,
    .vg-wrap p.poetry.vg-done .vt { color: var(--ink); }

    /* Highlights hug the text: prose verses are inline spans (backgrounds
       already wrap the words); poetry verses are blocks, so their inner .vt
       carries the wash instead — the reader's Focus-mode fix, reused.

       GEOMETRY IS UNCONDITIONAL. See the note in reading-styles: cloned
       padding on a wrapped inline box costs (N-1) x .5rem of width, so a
       state rule that carries padding re-wraps the paragraph the moment you
       hover and shoves the rest of the page around. States swap colour only. */
    .vg-wrap span.verse,
    .vg-wrap p.poetry.verse > .vt {
        background: transparent;
        border-radius: 3px;
        padding: .1rem .25rem;   /* HUG KNOB: vertical is layout-free on an
                                    inline box; horizontal costs width per
                                    wrapped line, so keep it modest. */
        margin: 0 -.25rem;
        transition: background-color .15s ease;
        -webkit-box-decoration-break: clone;
                box-decoration-break: clone;
    }

    .vg-wrap span.verse.vg-done,
    .vg-wrap p.poetry.vg-done > .vt { background: var(--vg-done-bg); }

    .vg-wrap span.verse.vg-armed,
    .vg-wrap p.poetry.vg-armed > .vt { background: var(--vg-armed-bg); }

    /* Mouseover preview on any verse that isn't the armed one. Wins over
       .vg-done on specificity, as the old :hover rule did. */
    .vg-wrap span.verse.is-hover:not(.vg-armed),
    .vg-wrap p.poetry.verse.is-hover:not(.vg-armed) > .vt {
        background: var(--vg-hover-bg);
    }

    /* ---- Per-character states inside an armed verse -------------------- */
    .vg-ch { position: relative; white-space: pre-wrap; color: var(--vg-fade); }
    .vg-ch.ok { color: var(--ink); }
    .vg-ch.vg-cur::before {
        content: "";
        position: absolute;
        left: -1px; top: .08em; bottom: .08em;
        width: 2px;
        background: var(--vg-caret);
        animation: vg-blink 1s steps(2) infinite;
    }
    @keyframes vg-blink { 50% { opacity: 0; } }

    /* Bad keystroke: the current character judders left-right. Animated via
       `left`, NOT transform — .vg-ch is an inline box, and transforms are
       ignored on inline elements (which is why the old shake never showed).
       Relies on .vg-ch { position: relative } declared above. */
    .vg-ch.vg-err { animation: vg-shake .2s ease; color: var(--accent); }
    @keyframes vg-shake {
        20%, 60% { left: -2.5px; }
        40%, 80% { left: 2.5px; }
    }
    @media (prefers-reduced-motion: reduce) { .vg-ch.vg-err { animation: none; } }

    /* ---- Completion badge: the multiplier / history trigger ------------ */
    .vg-badge {
        font-family: var(--sans);
        font-size: .68rem;
        font-weight: 700;
        font-style: normal;
        vertical-align: super;
        line-height: 0;
        color: var(--accent);
        margin-left: .22rem;
        cursor: pointer;
        user-select: none;
        transition: color .12s;
    }
    .vg-badge:hover { color: var(--ink); }

    /* ---- Completion popover (rides the shared .fn-pop chrome, INCLUDING its
            swell-on-hover — the .fn-pop:hover rule in reading-styles). We only
            add sizing + the dated-line layout. --------------------------------- */
    .fn-pop.vg-pop { min-width: 210px; }
    .vg-pop-line {
        color: var(--ink); white-space: nowrap; margin: .12rem 0;
        font-variant-numeric: tabular-nums;
    }
    .vg-pop-ord { color: var(--accent); font-weight: 700; margin-right: .25rem; }
    .vg-pop-more { color: var(--muted); font-style: italic; margin-top: .3rem; font-size: .8rem; }

    /* ---- Chapter progress meter (lives in the sticky head) ------------- */
    .vg-meter-row {
        display: flex; align-items: center; gap: .7rem;
        margin-top: .6rem;
        font-family: var(--sans); font-size: .75rem; color: var(--muted);
    }
    .vg-meter {
        flex: 1; height: 4px;
        background: var(--rule);
        border-radius: 999px;
        overflow: hidden;
    }
    .vg-meter-fill {
        height: 100%; width: 0%;
        background: var(--accent);
        border-radius: 999px;
        transition: width .25s ease;
    }
    .vg-meter-label { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .vg-meter-label.is-complete { color: var(--accent); font-weight: 700; }
    .vg-meter-label a { color: var(--accent); }

    /* ---- Completed-chapter stats (collapsible, under the meter) -------- */
    .vg-stats { margin-top: .6rem; }
    .vg-stats-box { font-family: var(--sans); font-size: .8rem; }
    .vg-stats-box > summary {
        list-style: none; cursor: pointer; color: var(--accent); font-weight: 700;
        display: inline-flex; align-items: baseline; gap: .1rem;
    }
    .vg-stats-box > summary::-webkit-details-marker { display: none; }
    .vg-stats-cue {
        font-weight: 600; color: var(--muted); font-size: .72rem;
        text-transform: uppercase; letter-spacing: .06em;
        border: 1px solid var(--rule); border-radius: 999px; padding: .05rem .45rem;
    }
    .vg-stats-box[open] > summary { margin-bottom: .5rem; }
    .vg-stats-grid {
        margin: 0; display: grid; grid-template-columns: auto 1fr;
        gap: .25rem .9rem; color: var(--ink);
    }
    .vg-stats-grid dt { color: var(--muted); }
    .vg-stats-grid dd { margin: 0; font-variant-numeric: tabular-nums; }
    .vg-stats-note { color: var(--muted); font-style: italic; margin: .5rem 0 0; }

    /* ---- Keyboard capture: focusable but out of the way ----------------
       Position:absolute inside .vg-wrap and MOVED to the armed verse's own
       offset, so mobile browsers scrolling "the input" into view scroll to
       the right place. Not display:none — that kills focus. */
    .vg-capture {
        position: absolute;
        width: 1px; height: 1px;
        top: 0; left: 0;
        opacity: 0;
        pointer-events: none;
        border: 0; padding: 0; resize: none;
    }
</style>
@endsection

@section('content')
    {{-- Invisible marker just above the head; when it clears the top of the
         viewport the head has pinned, and the observer below flags it stuck.
         Its height is set in the styles above and cancelled by a matching
         negative margin, so it occupies no visible space. --}}
    <div class="chapter-head-sentinel"></div>

    <div class="chapter-head">
        {{-- Corner cluster: the apps folder, open on arrival. The candle is
             pressed (aria-pressed) because the vigil IS the active mode —
             tapping it returns to the reader, and the script at the foot of
             the page rewrites its href to carry the armed verse. The folder's
             badge reads aria-pressed too, so if the user shuts the pill the
             circle still shows a dot: something in here is switched on. --}}
        <div class="head-actions">
            <x-head-folder persist="reader">
                @include('bible.partials.vigil-sheet', [
                    'mode'        => 'exit',
                    'href'        => $readerUrl,
                    'lead'        => 'Typing Vigil is currently active.',
                    'actionLabel' => 'Back to reader',
                ])
                <details class="pericope-app" id="app-pericope">
                    <summary class="fld-app" aria-label="Pericopes" title="Pericopes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
                    </summary>
                    <div class="ps-panel" role="group" aria-label="Pericopes"></div>
                </details>                
                @include('bible.partials.text-settings')
            </x-head-folder>
        </div>

        <div class="chapter-head-top">
            @if ($maxChapter > 1)
                {{-- The book name is a QuickNav trigger — clicking it opens the
                     chapter grid, pre-rendered with VIGIL chapter links. --}}
                <div class="vg-title-wrap">
                    <details class="qn show-chapters"
                             data-open-name="{{ $book->name }}"
                             data-open-title-url="{{ route('typing.vigil.book', ['translation' => $txSlug, 'book' => $book->slug]) }}"
                             data-open-base="{{ route('typing.vigil.book', ['translation' => $txSlug, 'book' => $book->slug]) }}"
                             data-open-chapters="{{ $maxChapter }}"
                             data-open-chapter-offset="{{ $book->chapterCellOffset() }}">
                        <summary class="qn-book-trigger" aria-label="Jump to another chapter of {{ $book->name }}">
                            <h1><span class="book-link">{{ $refBook }} {{ $refChapter }}</span></h1>
                        </summary>
                        @include('bible.partials.quicknav-panel', [
                            'openName'     => $book->name,
                            'openTitleUrl' => route('typing.vigil.book', ['translation' => $txSlug, 'book' => $book->slug]),
                            'openBase'     => route('typing.vigil.book', ['translation' => $txSlug, 'book' => $book->slug]),
                            'openChapters' => $maxChapter,
                            'openChapterOffset' => $book->chapterCellOffset(),
                        ])
                    </details>
                </div>
            @else
                <div class="vg-title-wrap">
                    <h1><span class="book-link">{{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</span></h1>
                </div>
            @endif

            {{-- Mustache: one instance now, below the title, outside the
                 details so it is a label and not a click target. --}}
            <span class="vg-eyebrow">Typing Vigil</span>
        </div>

        @include('bible.partials.translation-switcher', [
            'switchRoute'  => 'typing.vigil',
            'switchParams' => ['book' => $book->slug, 'chapter' => $chapter],
        ])

        <div class="vg-meter-row">
            <div class="vg-meter"><div class="vg-meter-fill" id="vg-meter-fill"></div></div>
            <div class="vg-meter-label" id="vg-meter-label"></div>
        </div>

        {{-- Filled by the engine once every verse is done (item 9). Stays in
             the head so it reads as part of the progress cluster. --}}
        <div class="vg-stats" id="vg-stats" hidden></div>
    </div>

    {{-- Breadcrumb and tap hint now live BELOW the sticky head, on the same
         scrolling surface as the verse text, so they slide up under the header
         instead of vanishing. Back link first (it follows the progress meter
         above it), hint second so it sits closest to the text it describes. --}}
    <p class="hub-back-row"><a class="hub-back"
        href="{{ route('typing.vigil.book', ['translation' => $txSlug, 'book' => $book->slug]) }}">&larr; Back to {{ $book->name }} Hub</a></p>

    <p class="vg-hint">Tap any verse and start typing. Completed verses are remembered on this device.</p>

    <div class="reading vg-wrap" id="vg-wrap" data-verse-hover>
        @include('bible.partials.reading-flow', [
            'layout'          => $layout,
            'linkTranslation' => $txSlug,
            'linkMode'        => 'vigil',
        ])
        <textarea class="vg-capture" id="vg-capture"
                  autocomplete="off" autocorrect="off" autocapitalize="off"
                  spellcheck="false" aria-label="Typing input"></textarea>
    </div>

    @include('bible.partials.chapter-nav')
@endsection

@section('scripts')
<script src="{{ asset('js/verse-hover.js') }}?v={{ filemtime(public_path('js/verse-hover.js')) }}" defer></script>
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>

<script>
    /* ======================================================================
       QUICKNAV → VIGIL REWRITE
       ----------------------------------------------------------------------
       The shared QuickNav script (layouts.app) reads each book button's
       data-url at CLICK time and builds chapter links from it. The composer
       fills those with reader URLs (/bible/{t}/{book}); on this page we
       rewrite them once at load to vigil URLs, so BOTH QuickNavs — the header
       logo and the book-title trigger — navigate inside the vigil. The
       wordmark still exits to the homepage, as everywhere else.
       ====================================================================== */
    (function () {
        const PREFIX = @json($vigilPrefix);
        document.querySelectorAll('.qn .qn-book[data-url]').forEach(function (btn) {
            const m = btn.dataset.url.match(/\/bible\/([^\/]+)\/([^\/]+)\/?$/);
            if (m) btn.dataset.url = PREFIX + '/' + m[1] + '/' + m[2];
        });

    })();
</script>

<script>
    /* ======================================================================
       THE VIGIL ENGINE
       ----------------------------------------------------------------------
       Vanilla JS, one IIFE, module pattern — matches the rest of the codebase.

       States:
         idle    — nothing armed; every verse is a tap target.
         armed   — one verse is live: its characters are individual spans,
                   untyped ones faded, a caret marks the next expected char.
         (verse complete) — original markup restored, .vg-done + badge applied,
                   the store stamped, and the NEXT verse armed automatically —
                   so typing straight through a chapter never touches the mouse.

       A verse may span several DOM fragments (prose → poetry lines → prose);
       the reader marks every fragment with data-verse. The engine treats the
       fragment texts as one target string joined by single spaces, and the
       player types a space (or Enter) to cross a fragment boundary.
       ====================================================================== */
    (function () {
        'use strict';

        /* ---- Server-provided constants (all single-variable json — the
                Blade comma trap never gets a chance) ----------------------- */
        const TX       = @json($txSlug);
        const OSIS     = @json($osisId);
        const CH       = {{ $chapter }};
        const VERSES   = @json($verseNumbers);
        const NEXT_URL = @json($nav['next']);

        const wrap    = document.getElementById('vg-wrap');
        const capture = document.getElementById('vg-capture');
        const meterFill  = document.getElementById('vg-meter-fill');
        const meterLabel = document.getElementById('vg-meter-label');
        if (!wrap || !capture) return;

        // Fine vs coarse pointer decides hover-vs-tap for popovers and whether
        // we auto-focus the keyboard on load.
        const COARSE = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

        /* =================================================================
           STORAGE  ·  mbVigil.v1
           -----------------------------------------------------------------
           { [translation]: { [osisId]: { [chapter]: {
               [verse]: {
                 n:     <total completions, uncapped>,
                 ts:    [<unix s>, … last 7],   // completion moments (display)
                 ms:    [<durationMs>, … last 7], // per-run typing time (parallel to ts)
                 tms:   <total typing ms, uncapped>,  // for the time counters
                 first: <unix s of first completion>  // for "chapter started"
               }
           } } } }

           `n`, `tms` and `first` are kept forever; `ts`/`ms` are capped so a
           devoted repeat-typist can't balloon toward the localStorage quota.
           Entries written before this update simply lack ms/tms/first — the
           counters treat those as "no timing recorded" (0) and the popup falls
           back to min(ts) for the first-completion moment. The root key stays
           versioned so a future breaking change can migrate rather than nuke.
           ================================================================= */
        const KEY   = 'mbVigil.v1';
        const TSCAP = 7;                     // history lines shown in the popup
        const MAX_VERSE_MS = 5 * 60 * 1000;  // clamp a single run (walked-away guard)

        function loadStore() {
            try { return JSON.parse(localStorage.getItem(KEY)) || {}; }
            catch (e) { return {}; }
        }
        function saveStore() {
            try { localStorage.setItem(KEY, JSON.stringify(STORE)); }
            catch (e) { console.warn('Vigil: could not save progress.', e); }
        }
        const STORE = loadStore();

        /** The {verse: {n, ts, ms, tms, first}} map for THIS chapter. */
        function chapterMap() {
            const a = STORE[TX]   = STORE[TX]   || {};
            const b = a[OSIS]     = a[OSIS]     || {};
            return b[CH]          = b[CH]        || {};
        }
        function entryFor(vn) {
            const e = chapterMap()[vn];
            return (e && e.n > 0) ? e : null;
        }

        /* ---- Formatting shared by the popover and the stats block --------- */

        // "2026-07-15 @ 20:06 UTC" from unix seconds. Always UTC so a shared
        // record reads the same everywhere (and matches the ranked boards).
        function fmtUTC(unixSec) {
            const d = new Date(unixSec * 1000);
            const p = function (x) { return String(x).padStart(2, '0'); };
            return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate())
                 + ' @ ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ' UTC';
        }

        // Ordinal suffix: 1→1st, 2→2nd, 3→3rd, 11→11th …
        function ordinal(k) {
            const s = ['th', 'st', 'nd', 'rd'], v = k % 100;
            return k + (s[(v - 20) % 10] || s[v] || s[0]);
        }

        // "1h 23m", "4m 12s", "45s", "—" for zero/unknown. Approximate on purpose.
        function fmtDur(ms) {
            if (!ms || ms < 0) return '—';
            const s = Math.round(ms / 1000);
            if (s < 60) return s + 's';
            const m = Math.floor(s / 60), rs = s % 60;
            if (m < 60) return m + 'm' + (rs ? ' ' + rs + 's' : '');
            const h = Math.floor(m / 60), rm = m % 60;
            return h + 'h' + (rm ? ' ' + rm + 'm' : '');
        }

        /* =================================================================
           INPUT EQUIVALENCE
           -----------------------------------------------------------------
           The corpus is full of characters no keyboard produces (curly
           quotes in the WEB, em dashes, the odd diaeresis in the apocrypha)
           — and smart mobile keyboards produce curly quotes the corpus may
           NOT have. Both sides canonicalise before comparing, so either
           direction matches. Ellipsis … completes on a single period.
           Newline/Enter counts as space, which is how fragment boundaries
           (poetry line breaks) are crossed.
           ================================================================= */
        const EQUIV = {
            '\u2018': "'", '\u2019': "'", '\u201A': "'", '\u02BC': "'", '`': "'", '\u00B4': "'",
            '\u201C': '"', '\u201D': '"', '\u201E': '"',
            '\u2013': '-', '\u2014': '-', '\u2010': '-', '\u2011': '-', '\u2212': '-',
            '\u00A0': ' ', '\n': ' ', '\r': ' ', '\t': ' ',
            '\u2026': '.',
        };
        function canon(ch) {
            ch = EQUIV[ch] || ch;
            const bare = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            return bare.length ? bare : ch;
        }

        /* =================================================================
           ARM / DISARM / COMPLETE
           ================================================================= */
        let armed = null;   // { vn, parts:[{frag, container, original}], spans, target, idx, start }
        let lastArmedVn = null;   // survives disarm — the toggle's ?v= handoff reads
                                  // this, because clicking the toggle blurs the
                                  // capture (disarming) BEFORE the click lands.

        function fragmentsFor(vn) {
            return Array.prototype.slice.call(
                wrap.querySelectorAll('.verse[data-verse="' + vn + '"]')
            );
        }

        function armVerse(vn) {
            if (armed && armed.vn === vn) { capture.focus(); return; }
            disarm();

            const frags = fragmentsFor(vn);
            if (!frags.length) return;

            const parts = [], spans = [], target = [];

            frags.forEach(function (frag, fi) {
                const container = frag.querySelector('.vt') || frag;
                parts.push({ frag: frag, container: container, original: container.innerHTML });

                // Fragment text = the container's TEXT NODES only — the
                // superscript number and any badge are elements and stay out.
                let txt = '';
                container.childNodes.forEach(function (node) {
                    if (node.nodeType === 3) txt += node.textContent;
                });
                txt = txt.replace(/\s+/g, ' ').trim();

                const vnEl = container.querySelector('.verse-number');
                container.innerHTML = '';
                if (vnEl) container.appendChild(vnEl);

                const chars = Array.from(txt);
                if (fi < frags.length - 1) chars.push(' ');   // fragment join

                chars.forEach(function (ch) {
                    const s = document.createElement('span');
                    s.className = 'vg-ch';
                    s.textContent = ch;
                    container.appendChild(s);
                    spans.push(s);
                    target.push(ch);
                });

                frag.classList.remove('vg-done');   // retyping clears the wash
                frag.classList.add('vg-armed');
            });

            if (!target.length) {                    // pathological empty verse
                parts.forEach(function (p) { p.container.innerHTML = p.original; });
                frags.forEach(function (f) { f.classList.remove('vg-armed'); });
                return;
            }

            armed = { vn: vn, parts: parts, spans: spans, target: target, idx: 0, start: null };
            lastArmedVn = vn;
            setCaret();

            // Park the capture at the verse so mobile "scroll input into view"
            // behaviour lands in the right place.
            capture.style.top = Math.max(0, frags[0].offsetTop) + 'px';
            capture.value = '';
            capture.focus();
            followCaret();   // auto-advance can land on an offscreen verse
        }

        function setCaret() {
            armed.spans.forEach(function (s) { s.classList.remove('vg-cur'); });
            const cur = armed.spans[armed.idx];
            if (cur) cur.classList.add('vg-cur');
        }

        /** Restore original markup and re-apply done-state from the store. */
        function disarm() {
            if (!armed) return;
            const vn = armed.vn;
            armed.parts.forEach(function (p) { p.container.innerHTML = p.original; });
            armed.parts.forEach(function (p) {
                p.frag.classList.remove('vg-armed');
                p.frag.classList.toggle('vg-done', !!entryFor(vn));
            });
            armed = null;
        }

        function step(ch) {
            if (!armed) return;
            const want = armed.target[armed.idx];
            const cur  = armed.spans[armed.idx];

            if (canon(ch) === canon(want)) {
                if (armed.start === null) armed.start = Date.now();  // timer: first keystroke
                cur.classList.remove('vg-cur', 'vg-err');
                cur.classList.add('ok');
                armed.idx++;
                if (armed.idx >= armed.target.length) { completeArmed(); return; }
                setCaret();
                followCaret();
            } else if (ch.trim() !== '' || canon(want) === ' ') {
                // Wrong key: flash, don't advance. Stray spaces between words
                // are forgiven silently (no flash) — everything else objects.
                cur.classList.remove('vg-err');
                void cur.offsetWidth;               // restart the animation
                cur.classList.add('vg-err');
            }
        }

        function completeArmed() {
            const vn    = armed.vn;
            const parts = armed.parts;
            // Typing time for this run: first keystroke → now, clamped so a
            // walked-away tab doesn't record an hour on one verse.
            const dur = armed.start !== null
                ? Math.min(Date.now() - armed.start, MAX_VERSE_MS)
                : 0;
            armed = null;

            parts.forEach(function (p) { p.container.innerHTML = p.original; });
            parts.forEach(function (p) {
                p.frag.classList.remove('vg-armed');
                p.frag.classList.add('vg-done');
            });

            // Stamp the store: bump the multiplier, push the moment + duration,
            // accumulate total typing time, remember the first-ever completion.
            const map = chapterMap();
            const e = map[vn] || { n: 0, ts: [], ms: [], tms: 0, first: 0 };
            e.ts  = e.ts  || [];
            e.ms  = e.ms  || [];
            e.tms = e.tms || 0;

            const now = Math.floor(Date.now() / 1000);
            e.n += 1;
            if (!e.first) e.first = now;
            e.ts.push(now);
            e.ms.push(dur);
            e.tms += dur;
            if (e.ts.length > TSCAP) e.ts = e.ts.slice(-TSCAP);
            if (e.ms.length > TSCAP) e.ms = e.ms.slice(-TSCAP);
            map[vn] = e;
            saveStore();

            decorate(vn);
            updateMeter();
            renderChapterStats();      // refresh the completed-chapter stats block

            // Flow on: arm the next verse in the chapter.
            const next = VERSES[VERSES.indexOf(vn) + 1];
            if (next !== undefined) {
                armVerse(next);
            } else {
                capture.blur();
            }
        }

        /** Apply the badge (✓ once, ×N thereafter) to a verse's last fragment. */
        function decorate(vn) {
            const frags = fragmentsFor(vn);
            if (!frags.length) return;
            frags.forEach(function (f) {
                f.querySelectorAll('.vg-badge').forEach(function (b) { b.remove(); });
            });
            const e = entryFor(vn);
            if (!e) return;

            const last = frags[frags.length - 1];
            const container = last.querySelector('.vt') || last;
            const badge = document.createElement('sup');
            badge.className = 'vg-badge';
            badge.textContent = e.n > 1 ? '\u00D7' + e.n : '\u2713';
            badge.title = 'Typed ' + e.n + (e.n === 1 ? ' time' : ' times');
            container.appendChild(badge);
        }

        /* =================================================================
           METER
           ================================================================= */
        function updateMeter() {
            const map  = chapterMap();
            const done = VERSES.filter(function (v) { return map[v] && map[v].n > 0; }).length;
            const pct  = VERSES.length ? Math.round((done / VERSES.length) * 100) : 0;
            meterFill.style.width = pct + '%';

            if (done === VERSES.length && VERSES.length > 0) {
                meterLabel.classList.add('is-complete');
                meterLabel.innerHTML = NEXT_URL
                    ? 'Chapter complete \u2713 &nbsp;<a href="' + NEXT_URL + '">next chapter \u2192</a>'
                    : 'Chapter complete \u2713';
            } else {
                meterLabel.classList.remove('is-complete');
                meterLabel.textContent = done + ' / ' + VERSES.length + ' verses';
            }
        }

        /* =================================================================
           COMPLETION POPOVER (badge) — rides the shared .fn-pop chrome
           -----------------------------------------------------------------
           Content:
             typed once  → "Typed on 2026-07-15 @ 20:06 UTC"
             typed 2..n  → one line per run, "1st Typed on …", newest last,
                           capped at the last 7 lines. When there have been
                           more than 7 runs the visible lines keep their TRUE
                           ordinal (e.g. runs 4th..10th of ten), so the numbers
                           never lie about how many times the verse was typed.
           Behaviour mirrors the footnote popover: hover the badge to show it,
           the panel itself is hoverable and swells (the .fn-pop:hover rule in
           reading-styles). On touch there's no hover, so a tap toggles it.
           ================================================================= */
        let pop = null, popHideTimer = null;

        function closePop() {
            if (popHideTimer) { clearTimeout(popHideTimer); popHideTimer = null; }
            if (pop) { pop.remove(); pop = null; }
        }

        function popBody(e) {
            if (e.n === 1) {
                return '<div class="vg-pop-line">Typed on ' + fmtUTC(e.ts[e.ts.length - 1] || e.first) + '</div>';
            }
            // Show the last up-to-7 runs, oldest→newest, with true ordinals.
            const shown = Math.min(e.ts.length, TSCAP);
            const firstOrdinal = e.n - shown + 1;   // ordinal of the oldest shown
            let rows = '';
            for (let i = e.ts.length - shown; i < e.ts.length; i++) {
                const ord = firstOrdinal + (i - (e.ts.length - shown));
                rows += '<div class="vg-pop-line"><span class="vg-pop-ord">' +
                        ordinal(ord) + '</span> Typed on ' + fmtUTC(e.ts[i]) + '</div>';
            }
            const hidden = e.n - shown;
            const more = hidden > 0
                ? '<div class="vg-pop-more">' + hidden + ' earlier ' +
                  (hidden === 1 ? 'time' : 'times') + ' not shown</div>'
                : '';
            return rows + more;
        }

        function showPop(badge, vn) {
            closePop();
            const e = entryFor(vn);
            if (!e) return;

            pop = document.createElement('div');
            pop.className = 'fn-pop vg-pop';
            pop.innerHTML = popBody(e);

            // Keep the panel open while the pointer is on it (like the footnote
            // popover); leaving both badge and panel closes it after a beat.
            pop.addEventListener('mouseenter', function () {
                if (popHideTimer) { clearTimeout(popHideTimer); popHideTimer = null; }
            });
            pop.addEventListener('mouseleave', scheduleHide);

            wrap.appendChild(pop);

            const wr = wrap.getBoundingClientRect();
            const br = badge.getBoundingClientRect();
            const pw = pop.offsetWidth, ph = pop.offsetHeight;

            let left = br.left - wr.left + br.width / 2 - pw / 2;
            left = Math.max(4, Math.min(left, wrap.clientWidth - pw - 4));

            let top = br.top - wr.top - ph - 10;
            if (br.top - ph - 10 < 110) {           // would slide under the head
                top = br.bottom - wr.top + 10;
                pop.classList.add('is-below');
            }
            pop.style.left = left + 'px';
            pop.style.top  = top + 'px';
            pop.style.setProperty('--chev-x',
                (br.left - wr.left + br.width / 2 - left) + 'px');
        }

        function scheduleHide() {
            if (popHideTimer) clearTimeout(popHideTimer);
            popHideTimer = setTimeout(closePop, 180);
        }

        /* =================================================================
           EVENTS
           ================================================================= */
        // Desktop: hovering a completed badge shows its popover; the panel and
        // badge share a small grace period so moving between them doesn't flicker.
        wrap.addEventListener('mouseover', function (ev) {
            if (COARSE) return;
            const badge = ev.target.closest('.vg-badge');
            if (!badge) return;
            const holder = badge.closest('.verse');
            if (holder) showPop(badge, parseInt(holder.dataset.verse, 10));
        });
        wrap.addEventListener('mouseout', function (ev) {
            if (COARSE) return;
            if (ev.target.closest('.vg-badge')) scheduleHide();
        });

        wrap.addEventListener('click', function (ev) {
            const badge = ev.target.closest('.vg-badge');
            if (badge) {
                ev.stopPropagation();
                // Touch has no hover, so a tap toggles the popover.
                if (COARSE) {
                    const holder = badge.closest('.verse');
                    if (pop) closePop();
                    else if (holder) showPop(badge, parseInt(holder.dataset.verse, 10));
                }
                return;   // a badge click never arms the verse
            }

            closePop();

            const v = ev.target.closest('.verse');
            if (!v) return;

            // A drag-select is not a tap.
            const sel = window.getSelection();
            if (sel && sel.type === 'Range' && String(sel).length) return;

            armVerse(parseInt(v.dataset.verse, 10));
        });

        document.addEventListener('click', function (ev) {
            if (pop && !pop.contains(ev.target) && !ev.target.closest('.vg-badge')) closePop();
        });

        // Keystrokes arrive via the input event so mobile keyboards work.
        capture.addEventListener('input', function () {
            const data = capture.value;
            capture.value = '';
            if (!armed || !data) return;
            Array.from(data).forEach(step);
        });
        capture.addEventListener('paste', function (ev) { ev.preventDefault(); });
        capture.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') capture.blur();
            if (ev.key === 'Enter') { ev.preventDefault(); step(' '); }
        });

        // Focus lost and not immediately reclaimed (e.g. by arming another
        // verse) = the vigil pauses: partial progress quietly discarded.
        capture.addEventListener('blur', function () {
            setTimeout(function () {
                if (document.activeElement !== capture) disarm();
            }, 120);
        });

        /** Keep the caret comfortably on screen while typing flows. */
        function followCaret() {
            if (!armed) return;
            const cur = armed.spans[armed.idx];
            if (!cur) return;
            const r = cur.getBoundingClientRect();
            if (r.bottom > window.innerHeight - 90 || r.top < 130) {
                cur.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }

        /* =================================================================
           CHAPTER STATS (item 9) — a collapsible panel that appears once the
           whole chapter is complete: when it was started, when it was finished,
           the approx time per verse, and the approx time spent typing it.
           All from the timing fields; verses typed before this update simply
           don't contribute time (their tms is absent → 0).
           ================================================================= */
        function renderChapterStats() {
            const host = document.getElementById('vg-stats');
            if (!host) return;

            const map = chapterMap();
            const done = VERSES.filter(function (v) { return map[v] && map[v].n > 0; });
            const complete = done.length === VERSES.length && VERSES.length > 0;

            if (!complete) { host.hidden = true; host.innerHTML = ''; return; }

            let firstTs = Infinity, lastTs = 0, totalMs = 0, timedVerses = 0;
            done.forEach(function (v) {
                const e = map[v];
                const f = e.first || (e.ts && e.ts.length ? e.ts[0] : 0);
                const l = (e.ts && e.ts.length) ? e.ts[e.ts.length - 1] : f;
                if (f && f < firstTs) firstTs = f;
                if (l && l > lastTs) lastTs = l;
                if (e.tms) { totalMs += e.tms; timedVerses++; }
            });
            if (firstTs === Infinity) firstTs = 0;

            const perVerse = timedVerses ? totalMs / timedVerses : 0;
            const partial  = timedVerses && timedVerses < done.length;

            host.hidden = false;
            host.innerHTML =
                '<details class="vg-stats-box">' +
                  '<summary>Chapter complete \u2713 &nbsp;<span class="vg-stats-cue">stats</span></summary>' +
                  '<dl class="vg-stats-grid">' +
                    '<dt>Started</dt><dd>' + (firstTs ? fmtUTC(firstTs) : '\u2014') + '</dd>' +
                    '<dt>Completed</dt><dd>' + (lastTs ? fmtUTC(lastTs) : '\u2014') + '</dd>' +
                    '<dt>Time typing</dt><dd>' + fmtDur(totalMs) + '</dd>' +
                    '<dt>Per verse (avg)</dt><dd>' + fmtDur(perVerse) + '</dd>' +
                  '</dl>' +
                  (partial
                    ? '<p class="vg-stats-note">Timing covers ' + timedVerses + ' of ' +
                      done.length + ' verses — the rest were typed before timing was added.</p>'
                    : '') +
                '</details>';
        }

        /* =================================================================
           BOOT: paint stored completions, fill the meter, show stats, and
           arm the first still-untyped verse (item 2).
           ================================================================= */
        VERSES.forEach(function (vn) {
            if (!entryFor(vn)) return;
            fragmentsFor(vn).forEach(function (f) { f.classList.add('vg-done'); });
            decorate(vn);
        });
        updateMeter();
        renderChapterStats();

        // Auto-arm on load (item: caret already blinking where you left off).
        // Priority: an explicit ?v=N in the URL (the reader's toggle carries
        // its focused verse over) → otherwise the first still-untyped verse.
        // Focus only on a fine pointer — auto-focusing on touch would spring
        // the keyboard up unbidden.
        (function armFirstOpen() {
            let target;
            const q = parseInt(new URLSearchParams(location.search).get('v'), 10);
            if (!isNaN(q) && VERSES.indexOf(q) !== -1) {
                target = q;
            } else {
                const map = chapterMap();
                target = VERSES.find(function (v) { return !(map[v] && map[v].n > 0); });
            }
            if (target === undefined) return;       // whole chapter already done
            armVerse(target);
            if (COARSE) capture.blur();             // caret shows; no keyboard pop
        })();

        // Leaving for the reader? Carry the active verse: rewrite the sheet's
        // exit link at click time so /bible/... opens with ?v=<armed verse>
        // focused. (No folder-state write here any more — fold-unify r5: to
        // reach this link the folder is necessarily open, and an open folder
        // means mb.fold.reader already reads '1'. One memory, written only by
        // the user's own toggles.)
        document.querySelectorAll('#app-vigil .vs-action').forEach(function (t) {
            t.addEventListener('click', function () {
                if (lastArmedVn === null) return;
                try {
                    const u = new URL(t.href, location.origin);
                    u.searchParams.set('v', lastArmedVn);
                    t.href = u.toString();
                } catch (e) { /* malformed href — leave it be */ }
            });
        });
    })();
</script>
@endsection
