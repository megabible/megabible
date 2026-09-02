@extends('layouts.app')

@section('title', 'Typing Vigil — MEGABIBLE.net')

{{--
  =====================================================================
  TYPING VIGIL HOME  ·  /extras/vigil
  ---------------------------------------------------------------------
  A sibling of the reader's homepage: the whole canon, testament by
  testament, section by section — but every book tile carries a
  completion bar.

  Progress lives only in the browser, so the server can't know a book's
  percentage. It ships the DENOMINATORS ($counts: osis => { txSlug:
  totalVerses }); the script reads localStorage, computes typed/total
  per translation, takes the highest, and paints each bar. Books with no
  verses anywhere render as dashed "soon" tiles with no bar.

  ONLY BOOKS ARE CELLS. Testament and section headers are plain type,
  exactly as on the reader homepage — no card, no bar, no percentage.
  The running totals (overall verses + the stacked ALL BOOKS meter) sit
  at the FOOT of the page, under the canon.
  =====================================================================
--}}
@section('styles')
<style>
    /* ---------------------------------------------------------------------
       HERO — matches the Scrimmage builder's hero exactly: plain ink,
       2.4rem, left-aligned, no accent tint.
       --------------------------------------------------------------------- */
    .vg-home-hero { margin: 0 0 1.6rem; }
    .vg-home-hero h1 {
        font-size: 2.4rem; font-weight: 400; margin: 0;
        letter-spacing: -.01em; color: var(--ink);
    }
    .vg-home-hero p {
        color: var(--muted); font-family: var(--sans); font-size: .9rem;
        margin: .45rem 0 0; max-width: 54ch;
    }

    .testament { margin-bottom: 1rem; }

    /* ---------------------------------------------------------------------
       HEADINGS — mirrors index.blade.php. Plain headings, no cells.
       --------------------------------------------------------------------- */
    .testament-title {
        font-size: 2.2rem; font-weight: 400; letter-spacing: -.01em;
        margin: 1.8rem 0 .6rem;                       /* ← GAP AROUND A TESTAMENT */
    }
    .testament:first-of-type .testament-title { margin-top: 0; }

    .section-head {
        color: var(--accent); font-size: 1.3rem; font-weight: 600;
        letter-spacing: .01em;
        margin: 2.3rem 0 .8rem;                       /* ← GAP AROUND A SECTION */
    }
    .section-head .sub {
        font-style: italic; font-weight: 400; color: var(--muted);
        font-size: 1rem; margin-left: .45rem;
    }
    /* The first section sits right under its testament title — tighten it. */
    .section-block:first-of-type .section-head { margin-top: 1.1rem; }   /* ← KNOB */

    .subgroup-head {
        font-family: var(--sans); font-size: .74rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .1em; color: var(--muted);
        margin: .9rem 0 .65rem;
    }

    @media (max-width: 560px) {
        .vg-home-hero h1 { font-size: 2rem; }
        .testament-title { font-size: 1.75rem; }
        .section-head    { font-size: 1.15rem; }
        .section-head .sub { font-size: .88rem; margin-left: 0; display: block; }
    }

    @include('bible.partials.vigil-summary')

    /* ---- Acts of the User link: quiet, bottom of the page ----------------- */
    .vg-acts-link { margin: 3rem 0 1rem; text-align: center; font-family: var(--sans); }
    .vg-acts-link a {
        font-size: .82rem; color: var(--muted); text-decoration: none;
        border: 1px solid var(--rule); border-radius: 999px; padding: .35rem .95rem;
        transition: color .12s, border-color .12s;
    }
    .vg-acts-link a:hover { color: var(--accent); border-color: var(--accent); }

    .book-grid { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); }

    /* A book tile is a small card: name row + progress bar. The --bk canon
       tint colours the fill, echoing the timeline/quicknav palette. This is
       now the ONLY card on the page. */
    .vg-book {
        display: block; text-decoration: none;
        border: 1px solid var(--rule); border-radius: 6px;
        padding: .55rem .7rem .6rem;
        background: var(--bg);
        position: relative; overflow: hidden;
        transition: background .12s, border-color .12s, color .12s,
                    transform .14s ease, box-shadow .14s ease;
    }
    .vg-book.live { color: var(--ink); }
    .vg-book.live:hover {
        border-color: var(--bk, var(--accent));
        transform: scale(1.025);            /* webfeel: the footnote-popover swell */
        box-shadow: 0 4px 14px rgba(0,0,0,.10);
        z-index: 2;
    }

    /* A finished book: canon-colour ring + soft wash + the periodic shine. */
    .vg-book.live.is-complete {
        border-color: var(--bk, var(--accent));
        box-shadow: 0 0 0 1.5px var(--bk, var(--accent));
        background: color-mix(in srgb, var(--bk, var(--accent)) 8%, var(--bg));
    }
    .vg-book.live.is-complete:hover {
        box-shadow: 0 0 0 1.5px var(--bk, var(--accent)), 0 4px 14px rgba(0,0,0,.10);
    }
    .vg-book.live.is-complete::after {
        content: "";
        position: absolute; top: 0; bottom: 0; left: -60%; width: 45%;
        background: linear-gradient(105deg,
            transparent 0%, rgba(255,255,255,.45) 50%, transparent 100%);
        transform: skewX(-12deg);
        animation: vg-shine 6s ease-in-out infinite;
        pointer-events: none;
    }
    @keyframes vg-shine {
        0%   { left: -60%; }
        18%  { left: 130%; }
        100% { left: 130%; }
    }
    @media (prefers-reduced-motion: reduce) {
        .vg-book.live:hover { transform: none; }
        .vg-book.live.is-complete::after { animation: none; display: none; }
    }
    .vg-book.soon { color: var(--soon); border-style: dashed; cursor: default; }

    .vg-book-row { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; }
    .vg-book-name { font-size: 1.02rem; line-height: 1.25; }
    .vg-book-pct {
        font-family: var(--sans); font-size: .72rem; font-weight: 700;
        color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .vg-book-pct.is-complete { color: var(--bk, var(--accent)); }

    .vg-book-bar { height: 4px; margin-top: .5rem; background: var(--rule); border-radius: 999px; overflow: hidden; }
    .vg-book-fill { height: 100%; width: 0%; background: var(--bk, var(--accent)); border-radius: 999px; transition: width .3s ease; }
    .vg-book.soon .vg-book-bar { visibility: hidden; }

    /* Book names on the Vigil home always use the short name from
       config/canon.php -> home_short_names when one exists, at every width.
       The name is resolved server-side, so there is no full/short span pair
       and no breakpoint swap. The reader homepage is unaffected. */
    @media (max-width: 560px) {
        .book-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
    }
</style>
@endsection

@section('content')
    @php
        $homeShortNames = config('canon.home_short_names', []);
        $homeNames      = config('canon.home_names', []);
    @endphp

    <div class="vg-home-hero">
        <h1>Typing Vigil</h1>
        <p>Type every book of the Bible, verse by verse. Progress is saved only on this device, tracked for each translation.</p>
    </div>

    @foreach ($testaments as $testament)
        <section class="testament">
            <h2 class="testament-title">{{ $testament['label'] }}</h2>

            @foreach ($testament['sections'] as $sectionKey)
                @php $section = $sections[$sectionKey] ?? null; @endphp
                @continue (! $section)

                @php $sectionColor = $sectionColors[$sectionKey] ?? 'clay'; @endphp

                <div class="section-block">
                    <h3 class="section-head">
                        {{ $section['label'] }}@if (! empty($section['subtitle']))<span class="sub">{{ $section['subtitle'] }}</span>@endif
                    </h3>

                    @php
                        $groups = $section['subgroups'] ?? [
                            ['label' => null, 'books' => $section['books'] ?? []],
                        ];
                    @endphp

                    @foreach ($groups as $group)
                        @if (! empty($group['label']))
                            <h4 class="subgroup-head">{{ $group['label'] }}</h4>
                        @endif

                        <ul class="book-grid">
                            @foreach ($group['books'] as $slug)
                                @php $book = $books->get($slug); @endphp
                                @if ($book)
                                    @php
                                        // Name precedence on this page:
                                        //   1. home_short_names  (wins at ALL widths here)
                                        //   2. home_names        (homepage display override)
                                        //   3. the DB book name
                                        $label = $homeShortNames[$book->slug]
                                              ?? $homeNames[$book->slug]
                                              ?? $book->name;

                                        $linkTo = $linkTx[$book->id] ?? null;   // tx slug, or null = soon
                                    @endphp
                                    @if ($linkTo)
                                        <li>
                                            <a class="vg-book live"
                                               style="--bk:var(--tl-{{ $sectionColor }})"
                                               data-osis="{{ $book->osis_id }}"
                                               href="{{ route('typing.vigil.book', ['translation' => $linkTo, 'book' => $book->slug]) }}">
                                                <div class="vg-book-row">
                                                    <span class="vg-book-name">{{ $label }}</span>
                                                    <span class="vg-book-pct" data-pct>—</span>
                                                </div>
                                                <div class="vg-book-bar"><div class="vg-book-fill" data-fill></div></div>
                                            </a>
                                        </li>
                                    @else
                                        <li>
                                            <span class="vg-book soon">
                                                <div class="vg-book-row">
                                                    <span class="vg-book-name">{{ $label }}</span>
                                                </div>
                                                <div class="vg-book-bar"></div>
                                            </span>
                                        </li>
                                    @endif
                                @endif
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach

    {{-- FOOT SUMMARY. The two running totals, after the canon.
         · Overall — weighted verses typed / verses available, best translation
           per book. Caps at 100%.
         · All books — stacked: every book complete in ONE translation = 100%;
           further translations stack, so a fully double-typed canon reads
           200%. The bar caps visually at 100%; the number tells the full
           story. --}}
    <div class="vg-summary">
        <div class="vg-summary-gauge">
            <div class="vg-summary-row">
                <span class="vg-summary-label">All books</span>
                <span class="vg-summary-pct" id="vg-global-pct">—</span>
            </div>
            <div class="vg-summary-meter"><div class="vg-summary-fill" id="vg-global-fill"></div></div>
        </div>
        <div class="vg-summary-total" id="vg-overall"></div>
    </div>
@endsection

@section('scripts')
<script>
    /* ======================================================================
       Fill the completion bars from localStorage.

       COUNTS[osis] = { txSlug: totalVerses, … }   (denominators from server)
       STORE[tx][osis][chapter][verse] = { n, ts } (progress in the browser)

       Per book, per translation: typed = number of verses with n>0 across all
       chapters; pct = typed / total. A book's shown percentage is the HIGHEST
       across the translations it exists in — matching the reader's "furthest
       carried" rule. Everything is clamped to [0,100].
       ====================================================================== */
    (function () {
        const COUNTS = @json($counts);
        const KEY    = 'mbVigil.v1';

        let STORE = {};
        try { STORE = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) {}

        // Count verses with n>0 for one (tx, osis) across every chapter.
        function typedIn(tx, osis) {
            const book = (STORE[tx] || {})[osis];
            if (!book) return 0;
            let typed = 0;
            for (const ch in book) {
                const verses = book[ch];
                for (const v in verses) {
                    if (verses[v] && verses[v].n > 0) typed++;
                }
            }
            return typed;
        }

        // Highest completion ratio for a book across its translations.
        function bookPct(osis) {
            const byTx = COUNTS[osis];
            if (!byTx) return 0;
            let best = 0;
            for (const tx in byTx) {
                const total = byTx[tx];
                if (!total) continue;
                const pct = Math.min(100, (typedIn(tx, osis) / total) * 100);
                if (pct > best) best = pct;
            }
            return best;
        }

        // Weighted overall: sum typed / sum total, using each book's BEST tx so
        // the headline number tracks the same "furthest carried" idea. Along
        // the way each tile stashes its stacked score for the global pass.
        let overallTyped = 0, overallTotal = 0;

        document.querySelectorAll('.vg-book.live[data-osis]').forEach(function (tile) {
            const osis = tile.dataset.osis;
            const pct  = bookPct(osis);
            const rounded = Math.round(pct);

            const fill = tile.querySelector('[data-fill]');
            const label = tile.querySelector('[data-pct]');
            if (fill)  fill.style.width = pct + '%';
            if (label) {
                label.textContent = rounded + '%';
                label.classList.toggle('is-complete', rounded >= 100);
            }
            tile.classList.toggle('is-complete', rounded >= 100);   // ring + shine

            // Best translation's typed/total pair (for the overall line), and
            // the STACKED score — every translation's pct summed, each capped
            // at 100 — for the global >100% number.
            const byTx = COUNTS[osis] || {};
            let bestPct = -1, bestTyped = 0, bestTotal = 0, score = 0;
            for (const tx in byTx) {
                const total = byTx[tx];
                if (!total) continue;
                const typed = typedIn(tx, osis);
                const p = Math.min(100, (typed / total) * 100);
                score += p;
                if (p > bestPct) { bestPct = p; bestTyped = typed; bestTotal = total; }
            }
            tile.dataset.vgScore = score;

            overallTyped += bestTyped;
            overallTotal += bestTotal;
        });

        const overall = document.getElementById('vg-overall');
        if (overall && overallTotal > 0) {
            const pct = Math.round((overallTyped / overallTotal) * 100);
            overall.innerHTML = '<b>' + overallTyped.toLocaleString() +
                '</b> of <b>' + overallTotal.toLocaleString() +
                '</b> verses typed';
        }

        /* Global stacked percentage: mean of every live book's summed
           per-translation score. One full translation of everything = 100%,
           two = 200%. The bar caps at 100; the number does not. */
        (function () {
            const tiles = document.querySelectorAll('.vg-book.live[data-osis]');
            if (!tiles.length) return;
            let sum = 0;
            tiles.forEach(function (t) { sum += parseFloat(t.dataset.vgScore) || 0; });
            const global = sum / tiles.length;
            const fill = document.getElementById('vg-global-fill');
            const pct  = document.getElementById('vg-global-pct');
            if (fill) fill.style.width = Math.min(100, global) + '%';
            if (pct)  pct.textContent = Math.round(global) + '%';
        })();

    })();
</script>
@endsection
