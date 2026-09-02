@extends('layouts.app')

{{-- Page <title>. Falls back to the layout default ('MEGABIBLE.net') if unset.
     A descriptive title here is good for SEO (per the spec). --}}
@section('title', $book->name . ' — ' . $translation->abbreviation . ' — MEGABIBLE.net')

{{--
  ============================================================
  BOOK-HUB-ONLY CSS.
  Injected into the layout's <head> at @yield('styles'), so it loads AFTER the
  base styles and can build on the shared tokens (--bg, --ink, --accent,
  --serif, --sans, --panel, the --tl-* timeline palette, etc.) defined in
  app.blade.php.
  ============================================================
--}}
@section('styles')
<style>
    @include('bible.partials.sticky-head')

    /* ---- Book hub head ---------------------------------------------------
       Two corner buttons (candle, Aa), and a heavier title than the reader's
       — the hub is a landing page, so the book name carries more weight. */
    .chapter-head {
        --mb-head-title:       2.8rem;
        --mb-head-title-stuck: 1.8rem;
        --mb-head-reserve:     6.5rem;
    }
    .chapter-head-top { margin-bottom: .6rem; }   /* gap to the switcher */

    /* Hub back link. Lives BELOW the head, on the scrolling surface, so it
       slides up under the sticky header with the chapter grid. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 1.2rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    .eyebrow {
        font-family: var(--sans);
        font-size: 0.8rem; color: var(--muted); text-transform: uppercase;
        letter-spacing: 0.1em; margin-bottom: 0.75rem;
    }
    h2 {
        font-size: 1.3rem; font-weight: 600; margin: 2.5rem 0 0.75rem;
        color: var(--accent); letter-spacing: 0.01em; clear: both;
    }
    h2.no-clear { clear: none; }

    /* Chapter grid (now at top) */
    .chapters {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(52px, 1fr));
        gap: 0.5rem; margin-top: 0.5rem; margin-bottom: 2.5rem;
    }
    .chapter-cell {
        display: flex; align-items: center; justify-content: center; aspect-ratio: 1 / 1;
        border: 1px solid var(--rule); border-radius: 5px; text-decoration: none;
        color: var(--ink);
        font-family: var(--sans);
        font-size: 1rem; background: var(--bg); transition: background .12s, border-color .12s;
    }
    .chapter-cell:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

    /* JS adds .is-hidden to overflow cells when the grid is collapsed to one row.
       Two classes (0,2,0) outrank the single .chapter-cell (0,1,0), so no !important. */
    .chapter-cell.is-hidden { display: none; }

    /* Expand/collapse control. Same footprint as a cell, but circular. */
    .chapter-toggle {
        display: flex; align-items: center; justify-content: center; aspect-ratio: 1 / 1;
        border: 1px solid var(--rule); border-radius: 50%;
        background: var(--bg); color: var(--ink);
        padding: 0; cursor: pointer;
        transition: background .12s, border-color .12s, color .12s;
    }
    .chapter-toggle:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
    .chapter-toggle svg { width: 1.25rem; height: 1.25rem; transition: transform .18s ease; }

    /* Chevron flips to point up once everything is revealed. */
    .chapters.is-expanded .chapter-toggle svg { transform: rotate(180deg); }

    /* When the whole book already fits on one row, the toggle isn't needed.
       .chapter-toggle[hidden] (0,2,0) outranks .chapter-toggle (0,1,0). */
    .chapter-toggle[hidden] { display: none; }

    /* Infobox */
    .infobox {
        background: var(--panel); border: 1px solid var(--rule); border-radius: 6px;
        padding: 1.1rem 1.25rem; margin: 0 0 1.5rem;
        font-family: var(--sans);
        font-size: 0.9rem;
    }
    .infobox dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 0.35rem 0.9rem; }
    .infobox dt { color: var(--muted); text-transform: uppercase; letter-spacing: .05em; font-size: .72rem; padding-top: .15rem; }
    .infobox dd { margin: 0; color: var(--ink); }
    @media (min-width: 720px) {
        .infobox { float: right; width: 290px; margin: 0.5rem 0 1.25rem 2rem; }
    }
    /* Original-language title + transliteration — stacked in one infobox cell, one shared label. */
    .infobox .original-name {
        display: block;
        text-align: left;
        font-family: var(--serif);
        font-size: 1.15rem;
        line-height: 1.3;
        color: var(--ink);
    }
    .infobox .original-translit {
        display: block;
        margin-top: 0.1rem;
        font-style: italic;
        font-size: 0.85rem;
        color: var(--muted);
    }

    /* Prose — Overview and Authorship. These carry .reader-text (see
       app.blade.php), so family/size/leading come from the reader's Text
       Settings. Only the rhythm and the source-marker treatment live here. */
    /* Paragraph gap tracks the spacing setting: tight at step 0, open at
       step 2. The .6 is the knob — up for airier blocks, toward .4 for
       tighter. Swap back to a flat 1rem if you'd rather it stay fixed. */
    .prose p { margin: 0 0 calc(var(--reading-leading) * .6rem); }
    .prose em { font-style: italic; }
    .prose a[href^="#source-"] {
        font-size: 0.7em; vertical-align: super; line-height: 0;
        font-weight: 600; text-decoration: none; padding: 0 1px;
    }
    .prose a[href^="#source-"]::before { content: "["; }
    .prose a[href^="#source-"]::after  { content: "]"; }

    /* =========================================================
       TIMELINE — horizontal Gantt chart.
       Geometry (left/width/positions) is pre-computed in the controller; this
       just paints it. Bar colours come from the --tl-* palette in app.blade.php
       via inline `background: var(--tl-<name>)`.
       ========================================================= */
    .tl {
        --tl-label-w: 156px;   /* width of the left-hand book-label column */
        --tl-row-h: 34px;      /* height of each book row */
        --tl-bar-h: 16px;      /* thickness of each bar */
        --tl-event-label-w: 120px;  /* width each event label wraps within — tweak to taste */
        --tl-event-gap: 10px;       /* min horizontal gap before two labels get bumped to separate lanes */
        font-family: var(--sans);
        margin: 0.5rem 0 1rem;
    }

    /* Chart zone: everything except the legend. Fluid width, never scrolls.
       overflow:hidden is a safety net — it guarantees the PAGE never grows a
       horizontal scrollbar if a tick/event/date label gets positioned hard
       against the right edge (that label clips instead of pushing the page
       wide). Keep your outermost ticks/events a little inside the range in the
       JSON and nothing ever clips. */
    .tl-chart { position: relative; overflow: hidden; }

    /* Legend */
    .tl-legend { display: flex; flex-wrap: wrap; gap: 0.35rem 1.1rem; margin-bottom: 1.1rem; font-size: 0.8rem; color: var(--muted); }
    .tl-legend span { display: inline-flex; align-items: center; gap: 0.4rem; }
    .tl-legend i { width: 12px; height: 12px; border-radius: 3px; box-shadow: inset 0 0 0 1px rgba(42,31,23,.18); }

    /* Axis (rendered both above AND below the chart) */
    .tl-axis { display: grid; grid-template-columns: var(--tl-label-w) 1fr; align-items: end; }
    .tl-axis.bottom { align-items: start; margin-top: 0.4rem; }
    .tl-axis-scale { position: relative; height: 1.1rem; }
    .tl-tick { position: absolute; transform: translateX(-50%); font-size: 0.75rem; color: var(--muted); white-space: nowrap; }

    /* Body: grid lines + event lines + book rows */
    .tl-body { position: relative; padding-top: 1.25rem; }
    .tl-grid { position: absolute; left: var(--tl-label-w); right: 0; top: 0; bottom: 0; pointer-events: none; }
    .tl-gridline { position: absolute; top: 0; bottom: 0; width: 1px; background: var(--rule); opacity: 0.55; }
    .tl-event { position: absolute; top: 0; bottom: 0; width: 0; border-left: 1.5px dashed var(--accent); opacity: 0.7; }

    .tl-row { display: grid; grid-template-columns: var(--tl-label-w) 1fr; align-items: center; height: var(--tl-row-h); }
    .tl-book { padding-right: 0.9rem; line-height: 1.15; min-width: 0; }
    .tl-name { font-family: var(--serif); font-size: 0.98rem; color: var(--ink); text-decoration: none; }
    a.tl-name:hover { color: var(--accent); text-decoration: underline; }
    .tl-row.current .tl-name { color: var(--accent); font-weight: 700; }

    .tl-track { position: relative; height: 100%; }
    .tl-bar {
        position: absolute; top: 50%; transform: translateY(-50%);
        height: var(--tl-bar-h); min-width: 3px; border-radius: 3px;
        overflow: hidden;
        box-shadow: inset 0 0 0 1px rgba(42,31,23,.18);
    }
    .tl-seg-label {
        display: block; height: 100%;
        font-family: var(--sans); font-weight: 600;
        font-size: 9px; line-height: var(--tl-bar-h);
        text-align: center; color: #fff;
        white-space: nowrap; overflow: hidden;
        pointer-events: none;   /* hover still hits the bar's title tooltip */
    }
    .tl-row.current .tl-bar { box-shadow: inset 0 0 0 1px rgba(42,31,23,.28), 0 0 0 2px rgba(107,31,31,.28); }

    /* Date printed just to the RIGHT of each bar (was under the name before). */
    .tl-bar-date {
        position: absolute; top: 50%; transform: translateY(-50%);
        padding-left: 0.45rem; font-size: 0.74rem; color: var(--muted); white-space: nowrap;
    }

    /* Event labels — a row BELOW the bottom ticks. */
    .tl-events { display: grid; grid-template-columns: var(--tl-label-w) 1fr; margin-top: 0.3rem; }
    .tl-events-scale { position: relative; min-height: 1.1rem; }
    .tl-event-label {
        position: absolute; top: 0;
        width: var(--tl-event-label-w);
        transform: translateX(-50%);
        font-size: 0.72rem; line-height: 1.25; color: var(--accent);
        text-align: center;
    }
    .tl-event-date { display: block; color: var(--muted); }

    .tl-text {
        font-family: var(--sans);
        font-size: 0.9rem; color: var(--ink);
        margin: 0 0 1.1rem;
    }

    /* Outline */
    .outline {
        font-family: var(--sans);
        font-size: 0.95rem; list-style: none; padding-left: 0; margin: 0.5rem 0 0; counter-reset: outline;
    }
    .outline-item { margin: 0.4rem 0; }
    .outline-row { display: flex; justify-content: space-between; gap: 1rem; align-items: baseline; }
    .outline-title { color: var(--ink); }
    .outline-ref { color: var(--accent); font-size: 0.82rem; white-space: nowrap; text-decoration: none; }
    a.outline-ref:hover { text-decoration: underline; }
    .outline-children {
        list-style: none; padding-left: 1.25rem; margin: 0.35rem 0;
        border-left: 1px solid var(--rule); counter-reset: suboutline;
    }
    .outline > .outline-item { counter-increment: outline; }
    .outline > .outline-item > .outline-row .outline-title::before {
        content: counter(outline, upper-roman) ". "; color: var(--muted); font-weight: 600;
    }
    .outline-children > .outline-item { counter-increment: suboutline; }
    .outline-children > .outline-item > .outline-row .outline-title::before {
        content: counter(suboutline, upper-alpha) ". "; color: var(--muted);
    }

    /* Manuscripts */
    .ms-group { margin-bottom: 1.25rem; }
    .ms-group h3 {
        font-family: var(--sans);
        font-size: 0.78rem; text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); margin: 0 0 0.5rem;
    }
    .ms { border-left: 3px solid var(--rule); padding: 0.1rem 0 0.1rem 0.9rem; margin-bottom: 0.9rem; }
    .ms .siglum { font-weight: 700; color: var(--accent); }
    .ms .name { color: var(--ink); }
    .ms .date { color: var(--muted); font-size: 0.9rem; }
    .ms .note { font-size: 0.92rem; }

    /* Sources */
    .sources {
        font-family: var(--sans);
        font-size: 0.85rem; line-height: 1.5; color: var(--ink);
        padding-left: 1.4rem; margin: 0.5rem 0 0;
    }
    .source-item { margin: 0 0 0.7rem; padding-left: 0.3rem; transition: background 0.3s, box-shadow 0.3s; border-radius: 4px; }
    .source-item:target {
        background: var(--panel);
        box-shadow: 0 0 0 6px var(--panel);
    }
    .source-citation { color: var(--ink); }
    .sources a { color: var(--accent); text-decoration: none; }
    .sources a:hover { text-decoration: underline; }
    .source-note { display: block; color: var(--muted); font-style: italic; font-size: 0.8rem; margin-top: 0.15rem; }

    /* Page colophon — the book's licensing line. */
    .colophon {
        margin-top: 4rem; padding-top: 1.5rem; border-top: 1px solid var(--rule);
        font-family: var(--sans); font-size: 0.8rem; color: var(--muted);
    }

    /* Default link colour for any content links not otherwise styled above. */
    a { color: var(--accent); }
</style>
@endsection

{{--
  PAGE BODY. Injected at the layout's @yield('content') — i.e. between the shared
  site header and the shared site footer.

  NOTE: there is no <div class="container"> here. The layout already wraps this
  block in .container, and that single shared container is exactly what keeps the
  book page's 820px width identical to the home page.
--}}
@section('content')
        {{-- Corner cluster: candle + Aa, floated OUTSIDE the header flow so a
         wrapping title never moves them. Aa suppresses the visibility
         checkboxes — this page has no verse text. --}}
    <div class="chapter-head-sentinel"></div>

    <div class="chapter-head">
        {{-- Corner cluster: candle + Aa. Absolutely anchored to the head, so
             a wrapping title never moves it and it rides along when pinned.
             Aa suppresses the visibility checkboxes — no verse text here. --}}
        <div class="head-actions">
            @include('bible.partials.mode-toggle', [
                'href'  => route('typing.vigil.book', [
                    'translation' => strtolower($translation->abbreviation),
                    'book'        => $book->slug,
                ]),
                'label' => 'Type this book (Vigil)',
            ])
            @include('bible.partials.text-settings', ['tsChecks' => false])
        </div>

        <div class="chapter-head-top">
            <h1>{{ $book->name }}</h1>
        </div>

        @include('bible.partials.translation-switcher', [
            'switchRoute'  => 'bible.book',
            'switchParams' => ['book' => $book->slug],
        ])
    </div>

    <p class="hub-back-row"><a class="hub-back" href="{{ route('home') }}">&larr; All books</a></p>

    {{-- Chapters — collapsed to a single row by default; the chevron reveals the rest.
         The script just below measures how many cells fit per row and hides the overflow,
         recomputing on every resize so the row grows/shrinks but never wraps. --}}
    <h2>Chapters</h2>
    @php $cellOffset = $book->chapterCellOffset(); @endphp
    <div class="chapters">
        @foreach ($chapters as $n)
            <a class="chapter-cell"
               href="{{ route('bible.chapter', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $n]) }}">{{ $n + $cellOffset }}</a>
        @endforeach

        <button type="button" class="chapter-toggle" aria-expanded="false"
                aria-label="Show all chapters" title="Show all chapters">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
        </button>
    </div>

    <script>
    (function () {
        const grid = document.querySelector('.chapters');
        if (!grid) return;

        const toggle = grid.querySelector('.chapter-toggle');
        const cells  = Array.from(grid.querySelectorAll('.chapter-cell'));
        if (!toggle || cells.length === 0) return;

        // The browser resolves grid-template-columns to a list of real pixel tracks —
        // one per column — so counting them gives the exact capacity at this width.
        function columnCount() {
            return getComputedStyle(grid).gridTemplateColumns.split(' ').length;
        }

        function apply() {
            const cols = columnCount();

            // Whole book already fits on one row: no toggle, show everything.
            if (cells.length <= cols) {
                cells.forEach(c => c.classList.remove('is-hidden'));
                toggle.hidden = true;
                return;
            }
            toggle.hidden = false;

            if (grid.classList.contains('is-expanded')) {
                cells.forEach(c => c.classList.remove('is-hidden'));
            } else {
                const visible = Math.max(0, cols - 1); // toggle takes the last slot
                cells.forEach((c, i) => c.classList.toggle('is-hidden', i >= visible));
            }
        }

        toggle.addEventListener('click', function () {
            const expanded = grid.classList.toggle('is-expanded');
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const label = expanded ? 'Show fewer chapters' : 'Show all chapters';
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
            apply();
        });

        // Recompute whenever the grid's width changes (desktop resize, rotate, etc.).
        let raf = null;
        new ResizeObserver(function () {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(apply);
        }).observe(grid);

        apply(); // initial collapse, runs as the page parses (minimises any flash)
    })();
    </script>

    @if ($book->intro)
        @php $intro = $book->intro; @endphp

        <aside class="infobox">
            <dl>
                @if ($intro->original_name)
                    <dt>Original title</dt>
                    <dd>
                        <span class="original-name" dir="auto">{{ $intro->original_name }}</span>
                        @if ($intro->original_name_transliteration)
                            <span class="original-translit">{{ $intro->original_name_transliteration }}</span>
                        @endif
                    </dd>
                @endif
                @if ($intro->traditional_author) <dt>Traditional author</dt><dd>{{ $intro->traditional_author }}</dd> @endif
                @if ($intro->scholarly_view)     <dt>Scholarly view</dt><dd>{{ $intro->scholarly_view }}</dd> @endif
                @if ($intro->dating)             <dt>Date written</dt><dd>{{ $intro->dating }}</dd> @endif
                @if ($intro->language)           <dt>Language</dt><dd>{{ $intro->language }}</dd> @endif
                @if ($intro->genre)              <dt>Genre</dt><dd>{{ $intro->genre }}</dd> @endif
                @if ($intro->place_written)      <dt>Place</dt><dd>{{ $intro->place_written }}</dd> @endif
            </dl>
        </aside>

        @if ($intro->summary)
            <h2 class="no-clear">Overview</h2>
            <div class="prose reader-text">{!! \Illuminate\Support\Str::markdown($intro->summary) !!}</div>
        @endif

        @if ($intro->authorship_note)
            <h2>Authorship</h2>
            <div class="prose reader-text">{!! \Illuminate\Support\Str::markdown($intro->authorship_note) !!}</div>
        @endif
    @endif

    {{-- =========================== TIMELINE =========================== --}}
    @if ($timeline)
        <h2>Timeline</h2>
        <div class="tl">

            @if (! empty($timeline['text']))
                <p class="tl-text">{{ $timeline['text'] }}</p>
            @endif

            {{-- LEGEND BAND — sits above the chart, full reading-column width.
                 Never scrolls; wraps to a new line whenever it runs out of room. --}}
            @if (! empty($timeline['legend']))
                <div class="tl-legend">
                    @foreach ($timeline['legend'] as $g)
                        <span><i style="background: var(--tl-{{ $g['color'] }});"></i>{{ $g['label'] }}</span>
                    @endforeach
                </div>
            @endif

            {{-- CHART ZONE — fixed book-name column + fluid gantt track.
                 No horizontal scroll at any width; only the track shrinks. --}}
            <div class="tl-chart">

                {{-- Date markers along the TOP --}}
                <div class="tl-axis">
                    <div></div>
                    <div class="tl-axis-scale">
                        @foreach ($timeline['ticks'] as $t)
                            <span class="tl-tick" style="left: {{ $t['pos'] }}%;">{{ $t['label'] }}</span>
                        @endforeach
                    </div>
                </div>

                {{-- Body: grid lines, event lines, and one row per book --}}
                <div class="tl-body">
                    <div class="tl-grid">
                        @foreach ($timeline['ticks'] as $t)
                            <div class="tl-gridline" style="left: {{ $t['pos'] }}%;"></div>
                        @endforeach
                        @foreach ($timeline['events'] as $e)
                            <div class="tl-event" style="left: {{ $e['pos'] }}%;"></div>
                        @endforeach
                    </div>

                    @foreach ($timeline['books'] as $b)
                        <div class="tl-row {{ $b['current'] ? 'current' : '' }}">
                                <div class="tl-book">
                                    @if ($b['url'] && ! $b['current'])
                                        <a class="tl-name" href="{{ $b['url'] }}">{{ $b['label'] }}</a>
                                    @else
                                        <span class="tl-name">{{ $b['label'] }}</span>
                                    @endif
                                </div>
                            <div class="tl-track">
                                @foreach ($b['segments'] as $seg)
                                    <div class="tl-bar" title="{{ $seg['tooltip'] }}"
                                         style="left: {{ $seg['left'] }}%; width: {{ $seg['width'] }}%; background: var(--tl-{{ $seg['color'] }});">
                                        @if (! empty($seg['label']))
                                            <span class="tl-seg-label">{{ $seg['label'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                                @if (! empty($b['date_display']))
                                    <div class="tl-bar-date" style="left: {{ $b['date_pos'] }}%;">{{ $b['date_display'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Event names — BELOW the bottom markers --}}
                @if (! empty($timeline['events']))
                    <div class="tl-events">
                        <div></div>
                        <div class="tl-events-scale">
                            @foreach ($timeline['events'] as $e)
                                <span class="tl-event-label" style="left: {{ $e['pos'] }}%;">
                                    {{ $e['label'] }}
                                    @if (! empty($e['date_display']))
                                        <span class="tl-event-date">{{ $e['date_display'] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- Stack event labels that would otherwise overlap into separate
             vertical lanes. The labels are positioned by % left, so a fixed
             width alone can't prevent collisions when events sit close together
             (e.g. Genesis); this measures the rendered boxes and bumps any
             overlap downward. Re-runs on resize, since % → px shifts the gaps. --}}
        <script>
        (function () {
            const scale = document.querySelector('.tl-events-scale');
            if (!scale) return;

            const labels = Array.from(scale.querySelectorAll('.tl-event-label'));
            if (labels.length === 0) return;

            // Horizontal breathing room before two labels count as colliding.
            // Read from CSS so it lives next to the other --tl-* knobs.
            const gap = parseFloat(getComputedStyle(scale).getPropertyValue('--tl-event-gap')) || 8;

            function layout() {
                // Lane height = tallest wrapped label + a little vertical gap.
                // Derived, not hard-coded, so changing --tl-event-label-w just works.
                const laneH = Math.max(...labels.map(l => l.offsetHeight)) + 4;

                // Measure each label's rendered left/right edge relative to the
                // track. getBoundingClientRect() already accounts for translateX(-50%).
                const scaleLeft = scale.getBoundingClientRect().left;
                const items = labels
                    .map(el => {
                        const r = el.getBoundingClientRect();
                        return { el, left: r.left - scaleLeft, right: r.right - scaleLeft };
                    })
                    .sort((a, b) => a.left - b.left);

                // Greedy lane packing: each label (left → right) drops into the
                // first lane whose previous occupant ends before this one starts.
                const laneEnds = [];   // rightmost x currently used in each lane
                let lanesUsed = 0;

                items.forEach(item => {
                    let lane = 0;
                    while (lane < laneEnds.length && laneEnds[lane] + gap > item.left) {
                        lane++;
                    }
                    laneEnds[lane] = item.right;
                    item.el.style.top = (lane * laneH) + 'px';
                    lanesUsed = Math.max(lanesUsed, lane + 1);
                });

                // Grow the row so stacked lanes aren't clipped by the content below.
                scale.style.height = (lanesUsed * laneH) + 'px';
            }

            // Re-pack whenever the track width changes (resize, rotate).
            let raf = null;
            new ResizeObserver(function () {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(layout);
            }).observe(scale);
        })();
        </script>

        
        <script>
        (function () {
            const chart = document.querySelector('.tl-chart');
            if (!chart) return;

            const labels = Array.from(chart.querySelectorAll('.tl-seg-label'));
            if (labels.length === 0) return;

            function fit() {
                labels.forEach(el => {
                    el.style.visibility = 'visible';            // reset before measuring
                    if (el.scrollWidth > el.clientWidth + 0.5) {
                        el.style.visibility = 'hidden';        // too narrow → rely on tooltip
                    }
                });
            }

            let raf = null;
            new ResizeObserver(function () {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(fit);
            }).observe(chart);
        })();
        </script>
    @endif

    {{-- Outline --}}
    @if (! empty($book->intro?->outline))
        <h2>Outline</h2>
        <ol class="outline">
            @foreach ($book->intro->outline as $node)
                @include('bible.partials.outline-node', ['node' => $node, 'translation' => $translation, 'book' => $book])
            @endforeach
        </ol>
    @endif

    {{-- Manuscripts --}}
    @if ($book->manuscripts->isNotEmpty())
        <h2>Manuscript witnesses</h2>
        @php
            $labels = ['papyrus' => 'Earliest papyri', 'codex' => 'Major codices',
                       'majuscule' => 'Majuscules', 'minuscule' => 'Minuscules', 'other' => 'Other witnesses'];
            $grouped = $book->manuscripts->groupBy('kind');
        @endphp
        @foreach (['papyrus', 'codex', 'majuscule', 'minuscule', 'other'] as $kind)
            @if ($grouped->has($kind))
                <div class="ms-group">
                    <h3>{{ $labels[$kind] }}</h3>
                    @foreach ($grouped[$kind] as $m)
                        <div class="ms">
                            <span class="siglum">{{ $m->name }}</span>
                            <span class="date">— {{ $m->date_display }}</span>
                            @if ($m->description) <div class="note">{{ $m->description }}</div> @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach
    @endif

    {{-- Sources / Bibliography --}}
    @if ($book->sources->isNotEmpty())
        <h2>Sources</h2>
        <ol class="sources">
            @foreach ($book->sources as $s)
                <li class="source-item" id="source-{{ $s->slug }}">
                    <span class="source-citation">
                        @if ($s->url)
                            <a href="{{ $s->url }}" rel="noopener" target="_blank">{{ $s->citation }}</a>
                        @else
                            {{ $s->citation }}
                        @endif
                    </span>
                    @if ($s->pivot->note)
                        <span class="source-note">{{ $s->pivot->note }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

@endsection
@section('scripts')
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
@endsection