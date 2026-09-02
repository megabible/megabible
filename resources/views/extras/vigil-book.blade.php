@extends('layouts.app')

@section('title', $refBook . ' — Typing Vigil — MEGABIBLE.net')

{{--
  =====================================================================
  TYPING VIGIL BOOK HUB  ·  /extras/vigil/{translation}/{book}
  ---------------------------------------------------------------------
  A sibling of the reader's book page, trimmed to what the vigil needs:
  a per-chapter completion grid, a book-level progress bar, and a toggle
  back to the regular hub.

  Client/server split (same as the home): the server ships per-chapter
  denominators ($chapterCounts: chapter => { txSlug: verseCount }); the
  script reads localStorage and fills each cell, the book bar, and the
  time counter — all scoped to the CURRENT translation (the URL's), so
  switching editions shows that edition's own progress. (The home page
  keeps the best-across-translations view; here you're inside one.)
  =====================================================================
--}}
@section('styles')
<style>
    @include('bible.partials.sticky-head')

    /* ---- Vigil book hub head ---------------------------------------------
       Same title weight and corner reserve as the regular book hub, so
       toggling between the two leaves the title AND the buttons exactly
       where they were. */
    .chapter-head {
        --mb-head-title:       2.8rem;
        --mb-head-title-stuck: 1.8rem;
        --mb-head-reserve:     6.5rem;
    }

    /* MUSTACHE — mode label UNDER the h1, mirroring vigil.blade. Shrinks
       with the head, but less than the title does.
       SPACING KNOB: the margin above. */
    .vg-eyebrow {
        display: block;
        color: var(--accent); font-family: var(--sans);
        font-size: .76rem; font-weight: 700;
        letter-spacing: .12em; text-transform: uppercase;
        margin: .12rem 0 0;
    }
    .chapter-head.is-stuck .vg-eyebrow { font-size: .68rem; }

    /* Hub back link. Lives BELOW the head, on the scrolling surface, so it
       slides up under the sticky header with the chapter rows. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 .55rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    /* Blurb between the back link and the chapter list. Same voice and
       styling as the vigil reader's tap hint. Margin stays at 0 — the
       .vg-chapters top margin (line 83) owns the gap below it. */
    .vg-hint {
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: 0 0 2rem;
    }

    @include('bible.partials.vigil-summary')

    /* ---- Chapter rows (full-width) ---------------------------------------
       Each chapter is a wide tappable row built from up to three text lines
       plus the bar:

         line 1   Chapter 1 · 12/25 verses          Time Spent: 4m 20s
         line 2   Started on … · Last typed on …                    48%
         line 3   Completed on …                                   100%
         foot     [=========------------------------------]

       Every line is a flex row: text on the left, a right-hand slot on the
       right. The percentage is ONE element that the script parks in the
       right slot of whichever line is currently last above the bar — so it
       always sits directly over the meter no matter how tall the row is.

       PERCENT SIZE KNOB: .vg-chap-pct font-size below — tweak to taste. */
    .vg-chapters { display: flex; flex-direction: column; gap: .55rem; margin: .6rem 0 2.4rem; }

    .vg-chap-row {
        display: block;
        border: 1px solid var(--rule); border-radius: 8px;
        text-decoration: none; color: var(--ink);
        font-family: var(--sans);
        padding: .2rem .85rem .6rem;
        background: var(--bg);
        position: relative; overflow: hidden;
        transition: border-color .12s, background .12s,
                    transform .14s ease, box-shadow .14s ease;
    }
    .vg-chap-row:hover {
        border-color: var(--accent);
        transform: scale(1.012);            /* webfeel: the footnote-popover swell */
        box-shadow: 0 4px 14px rgba(0,0,0,.08);
        z-index: 2;
    }

    /* Shared line geometry: left text, right slot, baseline-aligned. */
    .vg-chap-line {
        display: flex; align-items: baseline;
        justify-content: space-between; gap: .6rem;
    }
    /* `display: flex` above beats the hidden attribute, so restate it. */
    .vg-chap-line[hidden] { display: none; }

    .vg-chap-right { flex: 0 0 auto; white-space: nowrap; }

    /* Line 1 — chapter name + verse counter. */
    .vg-chap-head { display: flex; align-items: baseline; gap: .45rem; min-width: 0; }
    .vg-chap-name  { font-size: 1.02rem; font-weight: 600; }
    .vg-chap-sep   { color: var(--rule); }
    .vg-chap-verses { color: var(--muted); font-size: .85rem; font-variant-numeric: tabular-nums; }

    /* Lines 2 & 3 — the typing history. */
    .vg-chap-hist {
        color: var(--muted); font-size: .76rem;
        font-variant-numeric: tabular-nums;
        min-width: 0;
    }
    .vg-chap-hist .sep { color: var(--rule); margin: 0 .3rem; }

    .vg-chap-line-started   { margin-top: .3rem; }
    .vg-chap-line-completed { margin-top: .1rem; }
    /* Item 2b: the completion date is the row's headline fact — bold it.
       COLOUR KNOB: swap to var(--accent) if you want it to sing louder. */
    .vg-chap-done { font-weight: 700; }

    /* Time Spent — top-right corner of the cell, level with the chapter name. */
    .vg-chap-time {
        color: var(--muted); font-size: .76rem;
        font-variant-numeric: tabular-nums;
    }
    .vg-chap-time[hidden] { display: none; }

    /* Foot is now just the bar; the percentage lives on the line above it. */
    .vg-chap-foot { display: block; margin-top: .4rem; }
    .vg-chap-bar  { display: block; height: 4px; background: var(--rule); border-radius: 999px; overflow: hidden; }
    .vg-chap-fill { display: block; height: 100%; width: 0%; background: var(--accent); border-radius: 999px; transition: width .3s ease; }
    .vg-chap-pct {
        font-size: .82rem;                  /* ← PERCENT SIZE — tweak me */
        font-weight: 700; color: var(--muted);
        font-variant-numeric: tabular-nums; line-height: 1;
        min-width: 4ch; text-align: right;
        display: inline-block;
    }

    /* ---- Untouched chapters: dimmed to --soon so started books pop -------
       The row keeps its full hover behaviour (swell, accent ring, shadow) —
       only the resting colours change, and the colour transition is shared
       with the border/background transition already on .vg-chap-row. */
    .vg-chap-row.is-untouched { border-color: color-mix(in srgb, var(--rule) 55%, transparent);}
    .vg-chap-row.is-untouched .vg-chap-name,
    .vg-chap-row.is-untouched .vg-chap-verses,
    .vg-chap-row.is-untouched .vg-chap-pct { color: var(--soon); }
    .vg-chap-row.is-untouched .vg-chap-sep { color: color-mix(in srgb, var(--soon) 45%, transparent); }
    .vg-chap-row.is-untouched .vg-chap-bar { background: color-mix(in srgb, var(--rule) 55%, transparent); }

    /* On hover the row wakes back up to normal weight. */
    .vg-chap-row.is-untouched:hover { border-color: var(--accent); }
    .vg-chap-row.is-untouched:hover .vg-chap-name { color: var(--ink); }
    .vg-chap-row.is-untouched:hover .vg-chap-verses,
    .vg-chap-row.is-untouched:hover .vg-chap-pct { color: var(--muted); }
    .vg-chap-row.is-untouched:hover .vg-chap-bar { background: var(--rule); }

    /* Completed rows: accent ring + soft wash + the periodic diagonal shine. */
    .vg-chap-row.is-complete {
        border-color: var(--accent);
        box-shadow: 0 0 0 1.5px var(--accent);
        background: color-mix(in srgb, var(--accent) 8%, var(--bg));
    }
    .vg-chap-row.is-complete .vg-chap-pct { color: var(--accent); }
    .vg-chap-row.is-complete::after {
        content: "";
        position: absolute; top: 0; bottom: 0; left: -40%; width: 30%;
        background: linear-gradient(105deg,
            transparent 0%, rgba(255,255,255,.45) 50%, transparent 100%);
        transform: skewX(-12deg);
        animation: vg-shine 6s ease-in-out infinite;
        pointer-events: none;
    }
    @keyframes vg-shine {
        0%   { left: -40%; }
        18%  { left: 120%; }
        100% { left: 120%; }
    }
    @media (prefers-reduced-motion: reduce) {
        .vg-chap-row:hover { transform: none; }
        .vg-chap-row.is-complete::after { animation: none; display: none; }
    }

    /* Narrow screens: let the history text wrap under its own line rather
       than crushing the percentage against it. */
    @media (max-width: 480px) {
        .vg-chap-line { gap: .4rem; }
        .vg-chap-hist { font-size: .72rem; }
        .vg-chap-time { font-size: .72rem; }
    }
</style>
@endsection

@section('content')
    {{-- Corner cluster: candle + Aa. Floated OUTSIDE the header flow so a
         wrapping title never moves them (the scrimmage-verse pattern).
         Aa suppresses the visibility checkboxes — no verse text here. --}}
    <div class="chapter-head-sentinel"></div>

    <div class="chapter-head">
        {{-- Corner cluster: candle + Aa. Absolutely anchored to the head so a
             wrapping title never moves it and it rides along when pinned. --}}
        <div class="head-actions">
            @include('bible.partials.mode-toggle', [
                'href'   => $readerHubUrl,
                'label'  => 'Open the regular book page',
                'active' => true,
            ])
            @include('bible.partials.text-settings', ['tsChecks' => false])
        </div>

        {{-- Book-name H1 first, mode label under it — same shape as the vigil
             reader, same top edge as the regular book hub. --}}
        <div class="chapter-head-top">
            <h1>{{ $refBook }}</h1>
            <span class="vg-eyebrow">Typing Vigil</span>
        </div>

        @include('bible.partials.translation-switcher', [
            'switchRoute'  => 'typing.vigil.book',
            'switchParams' => ['book' => $book->slug],
        ])
    </div>

    <p class="hub-back-row"><a class="hub-back" href="{{ route('typing.vigil.home') }}">&larr; All books</a></p>

    <p class="vg-hint">Select a chapter to begin a typing vigil. Completed chapters and verses are remembered on this device.</p>

    <div class="vg-chapters">
        @foreach ($chapters as $n)
            <a class="vg-chap-row" data-chapter="{{ $n }}"
               href="{{ route('typing.vigil', ['translation' => $txSlug, 'book' => $book->slug, 'chapter' => $n]) }}">

                {{-- Line 1: name + verses on the left; Time Spent on the right.
                     The percentage starts here (the untouched-chapter resting
                     state) and the script relocates it when history exists. --}}
                <span class="vg-chap-line vg-chap-line-head">
                    <span class="vg-chap-head">
                        <span class="vg-chap-name">Chapter {{ $n + $cellOffset }}</span>
                        <span class="vg-chap-sep">&middot;</span>
                        <span class="vg-chap-verses" data-verses>&mdash;</span>
                    </span>
                    <span class="vg-chap-right" data-slot="1">
                        <span class="vg-chap-time" data-time hidden></span>
                        <span class="vg-chap-pct" data-pct></span>
                    </span>
                </span>

                {{-- Line 2: Started / Last typed. Hidden until the script finds
                     history, so an untouched chapter is a shorter row. --}}
                <span class="vg-chap-line vg-chap-line-started" data-line="started" hidden>
                    <span class="vg-chap-hist" data-started></span>
                    <span class="vg-chap-right" data-slot="2"></span>
                </span>

                {{-- Line 3: Completed on … — only on finished chapters. --}}
                <span class="vg-chap-line vg-chap-line-completed" data-line="completed" hidden>
                    <span class="vg-chap-hist vg-chap-done" data-completed></span>
                    <span class="vg-chap-right" data-slot="3"></span>
                </span>

                <span class="vg-chap-foot">
                    <span class="vg-chap-bar"><span class="vg-chap-fill" data-fill></span></span>
                </span>
            </a>
        @endforeach
    </div>

    {{-- FOOT SUMMARY — book totals for THIS translation, below the chapter
         list. Same component as the vigil home page's canon summary; only
         the label and the element ids differ. --}}
    <div class="vg-summary">
        <div class="vg-summary-gauge">
            <div class="vg-summary-row">
                <span class="vg-summary-label">This book</span>
                <span class="vg-summary-pct" id="vg-hub-pct">—</span>
            </div>
            <div class="vg-summary-meter"><div class="vg-summary-fill" id="vg-hub-meter-fill"></div></div>
        </div>
        <div class="vg-summary-total" id="vg-hub-overall"></div>
        {{-- Item 11: approx typing time for this book. --}}
        <p class="vg-summary-note" id="vg-hub-time" hidden></p>
    </div>
@endsection

@section('scripts')
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
<script>
    /* ======================================================================
       Fill per-chapter bars, the book bar, and the book time counter — all
       for the CURRENT translation (the one in the URL). Switching the
       translation switcher loads the other edition's page, and these numbers
       follow it: 100% of Joel in KJV is 0% of Joel in WEB until you type it.

       COUNTS[chapter] = { txSlug: verseCount, … }        (server denominators)
       STORE[tx][osis][chapter][verse] = { n, ts, ms, tms, first }  (browser)
       ====================================================================== */
    (function () {
        const COUNTS = @json($chapterCounts);
        const OSIS   = @json($osisId);
        const TX     = @json($txSlug);
        const KEY    = 'mbVigil.v1';

        let STORE = {};
        try { STORE = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) {}

        function fmtDur(ms) {
            if (!ms || ms < 0) return '0s';
            const s = Math.round(ms / 1000);
            if (s < 60) return s + 's';
            const m = Math.floor(s / 60), rs = s % 60;
            if (m < 60) return m + 'm' + (rs ? ' ' + rs + 's' : '');
            const h = Math.floor(m / 60), rm = m % 60;
            return h + 'h' + (rm ? ' ' + rm + 'm' : '');
        }

        function typedIn(chapter) {
            const verses = (((STORE[TX] || {})[OSIS] || {})[chapter]) || {};
            let typed = 0;
            for (const v in verses) if (verses[v] && verses[v].n > 0) typed++;
            return typed;
        }

        // "2026-07-17" (UTC) from unix seconds — the history line's date form.
        function fmtDate(unixSec) {
            const d = new Date(unixSec * 1000);
            const p = function (x) { return String(x).padStart(2, '0'); };
            return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate());
        }

        // History aggregates for one chapter (this translation):
        //   started   = earliest first-completion among its verses
        //   last      = latest completion timestamp anywhere in the chapter
        //   completed = when the final verse was FIRST completed — i.e. the
        //               moment the chapter reached 100% (max of per-verse
        //               firsts); only meaningful when the chapter is complete
        //   ms        = summed typing time
        function chapterHistory(chapter) {
            const verses = (((STORE[TX] || {})[OSIS] || {})[chapter]) || {};
            let started = Infinity, last = 0, maxFirst = 0, ms = 0, any = false;
            for (const v in verses) {
                const e = verses[v];
                if (!e || !e.n) continue;
                any = true;
                const f = e.first || (e.ts && e.ts.length ? e.ts[0] : 0);
                const l = (e.ts && e.ts.length) ? e.ts[e.ts.length - 1] : f;
                if (f && f < started) started = f;
                if (f && f > maxFirst) maxFirst = f;
                if (l && l > last) last = l;
                if (e.tms) ms += e.tms;
            }
            return any
                ? { started: started === Infinity ? 0 : started, last: last, completedAt: maxFirst, ms: ms }
                : null;
        }

        // This chapter, THIS translation only.
        function chapterStats(chapter) {
            const total = (COUNTS[chapter] || {})[TX] || 0;
            if (!total) return { pct: 0, typed: 0, total: 0 };
            const typed = Math.min(typedIn(chapter), total);
            return { pct: (typed / total) * 100, typed: typed, total: total };
        }

        // Typing time in this book, THIS translation only.
        function bookTimeMs() {
            let ms = 0;
            const book = (STORE[TX] || {})[OSIS];
            if (!book) return 0;
            for (const ch in book) {
                const verses = book[ch];
                for (const v in verses) {
                    if (verses[v] && verses[v].tms) ms += verses[v].tms;
                }
            }
            return ms;
        }

        let overallTyped = 0, overallTotal = 0;

        document.querySelectorAll('.vg-chap-row[data-chapter]').forEach(function (cell) {
            const ch = cell.dataset.chapter;
            const b  = chapterStats(ch);
            const rounded  = Math.round(b.pct);
            const complete = rounded >= 100 && b.total > 0;

            const fill    = cell.querySelector('[data-fill]');
            const pct     = cell.querySelector('[data-pct]');
            const timeEl  = cell.querySelector('[data-time]');
            const rowStar = cell.querySelector('[data-line="started"]');
            const rowDone = cell.querySelector('[data-line="completed"]');
            const startedEl   = cell.querySelector('[data-started]');
            const completedEl = cell.querySelector('[data-completed]');

            if (fill) fill.style.width = b.pct + '%';
            if (pct)  pct.textContent  = rounded + '%';
            cell.classList.toggle('is-complete', complete);

            // Line 1: "51/51 verses"
            const versesEl = cell.querySelector('[data-verses]');
            if (versesEl) versesEl.textContent = b.typed + '/' + b.total + ' verses';

            const h = chapterHistory(ch);
            const touched = !!h && b.typed > 0;
            const showDone = touched && complete && !!h.completedAt;

            // Untouched chapters get no history lines at all — the row is
            // shorter AND dimmed, so anything started stands out.
            cell.classList.toggle('is-untouched', !touched);

            // Line 2: Started on … · Last typed on …
            if (rowStar && startedEl) {
                if (touched) {
                    startedEl.innerHTML = 'Started on ' + fmtDate(h.started) +
                        '<span class="sep">&middot;</span>Last typed on ' + fmtDate(h.last);
                    rowStar.hidden = false;
                } else {
                    startedEl.innerHTML = '';
                    rowStar.hidden = true;
                }
            }

            // Line 3: Completed on … (bold, own line, only when finished).
            if (rowDone && completedEl) {
                if (showDone) {
                    completedEl.textContent = 'Completed on ' + fmtDate(h.completedAt);
                    rowDone.hidden = false;
                } else {
                    completedEl.textContent = '';
                    rowDone.hidden = true;
                }
            }

            // Top-right ticker: only once there is time on the clock.
            if (timeEl) {
                if (touched && h.ms > 0) {
                    timeEl.textContent = 'Time Spent: ' + fmtDur(h.ms);
                    timeEl.hidden = false;
                } else {
                    timeEl.textContent = '';
                    timeEl.hidden = true;
                }
            }

            // Park the percentage in the last line above the bar:
            //   untouched → line 1 (beside the verse count)
            //   started   → line 2 (beside "Started on …")
            //   complete  → line 3 (beside "Completed on …")
            const slot = showDone ? '3' : (touched ? '2' : '1');
            const target = cell.querySelector('[data-slot="' + slot + '"]');
            if (pct && target && pct.parentNode !== target) target.appendChild(pct);

            overallTyped += b.typed;
            overallTotal += b.total;
        });

        // Book-level bar + overall line (this translation).
        const bookPct = overallTotal ? (overallTyped / overallTotal) * 100 : 0;

        const meter = document.getElementById('vg-hub-meter-fill');
        if (meter) meter.style.width = bookPct + '%';

        // The percentage now sits in the summary's label row, mirroring the
        // home page — so it comes out of the sentence below.
        const pctEl = document.getElementById('vg-hub-pct');
        if (pctEl) pctEl.textContent = Math.round(bookPct) + '%';

        const overall = document.getElementById('vg-hub-overall');
        if (overall) {
            overall.innerHTML = '<b>' + overallTyped.toLocaleString() + '</b> of <b>' +
                overallTotal.toLocaleString() + '</b> verses typed';
        }

        // Book time counter (this translation) — only when there's time to show.
        const ms = bookTimeMs();
        const timeEl = document.getElementById('vg-hub-time');
        if (timeEl && ms > 0) {
            timeEl.hidden = false;
            timeEl.innerHTML = 'Approx. time typing this book: <b>' + fmtDur(ms) + '</b>';
        }
    })();
</script>
@endsection
