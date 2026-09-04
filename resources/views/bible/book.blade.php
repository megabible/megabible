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

    /* hub-fold r1: corner cluster is now the apps folder — one 44px circle
       when shut, so the title only needs 4.5rem kept clear (chapter.blade's
       number). The switcher is gone, so the head-top margin override went
       with it; sticky-head's default .25rem owns that gap now. */
    .chapter-head {
        --mb-head-title:       2.8rem;
        --mb-head-title-stuck: 1.8rem;
        --mb-head-reserve:     4.5rem;
    }

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
    /* LEGACY numbered markers — old "[1](#source-…)" markdown links. Books
       not yet migrated to (a)-letters still render with these. DELETE this
       block (three rules) once every hub JSON has been reshaped. */
    .prose a[href^="#source-"]:not(.src-marker) {
        font-size: 0.7em; vertical-align: super; line-height: 0;
        font-weight: 600; text-decoration: none; padding: 0 1px;
    }
    .prose a[href^="#source-"]:not(.src-marker)::before { content: "["; }
    .prose a[href^="#source-"]:not(.src-marker)::after  { content: "]"; }

    /* =========================================================
       hub-src r2 — SOURCE MARKERS + POPOVER + EXCERPT + PLACEHOLDER
       Marker and .fn-pop blocks are copied from reading-styles (the
       reader's footnote system) so the two feel identical; if you retune
       one, retune the other.
       ========================================================= */

    /* Superscript letters — the reader's .fn-marker, verbatim. */
    .fn-markers { line-height: 0; }
    .fn-marker {
        font-family: var(--sans);
        font-size: .78rem;             /* ← marker size — tweak me */
        font-weight: 600; font-style: normal;
        color: var(--muted);
        text-decoration: none;
        padding-left: .12rem;
        transition: color .12s;
    }
    .fn-marker:hover { color: var(--accent); }

    /* Hover popover — the reader's .fn-pop, verbatim. */
    .fn-pop {
        position: absolute;
        z-index: 90;
        padding: .55rem .7rem;
        background: var(--bg);
        border: 1px solid var(--rule);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,.16);
        font-family: var(--sans);
        font-size: .85rem;
        line-height: 1.45;
        color: var(--muted);
        cursor: pointer;
        transition: transform .14s ease, background .14s ease,
                    border-color .14s ease, box-shadow .14s ease;
        transform-origin: var(--chev-x, 50%) bottom;
    }
    .fn-pop.is-below { transform-origin: var(--chev-x, 50%) top; }
    .fn-pop:hover {
        transform: scale(1.04);
        background: var(--panel);
        border-color: var(--muted);
        box-shadow: 0 10px 28px rgba(0,0,0,.22);
    }
    .fn-pop:hover::after { background: var(--panel); }
    .fn-pop::after {
        content: "";
        position: absolute;
        left: var(--chev-x, 50%);
        bottom: -5.5px;
        width: 10px; height: 10px;
        transform: translateX(-50%) rotate(45deg);
        background: var(--bg);
        border-right: 1px solid var(--rule);
        border-bottom: 1px solid var(--rule);
    }
    .fn-pop.is-below::after {
        bottom: auto;
        top: -5.5px;
        border: none;
        border-left: 1px solid var(--rule);
        border-top: 1px solid var(--rule);
    }
    /* Inside the panel the cloned bibliography line keeps its own note
       styling; the citation link just reads as text (the whole panel is
       one click surface). */
    .fn-pop .source-citation a { color: var(--ink); text-decoration: none; }
    .fn-pop .source-note { display: block; font-style: italic; font-size: .8rem; margin-top: .15rem; }

    /* Excerpt — a quotation clearly pulled from another text: accent-ruled
       blockquote, italic, attribution line pointing into the bibliography. */
    .excerpt { margin: 0 0 1.5rem; }
    .excerpt blockquote {
        margin: 0;
        padding: .2rem 0 .2rem 1.1rem;
        border-left: 3px solid var(--accent);
        font-style: italic;
    }
    .excerpt blockquote em { font-style: normal; }   /* nested emphasis flips back */
    /* hub-src r2.1: last excerpt paragraph sheds its trailing margin so the
       quote ends flush against its own bottom edge. */
    .excerpt blockquote p:last-child { margin-bottom: 0; }

    /* Attribution — left-justified stack under the quote: author / title /
       year, pulled from the sources table. The whole stack is one link into
       the bibliography. Indented to the quote's own text edge (the 3px rule
       + 1.1rem padding above), so the two read as one object. */
    .excerpt-src {
        font-family: var(--sans);
        font-size: .85rem;
        margin: .6rem 0 0 calc(3px + 1.1rem);
        text-align: left;
    }
    .excerpt-src a { text-decoration: none; }
    .excerpt-src a:hover .ex-src-title { text-decoration: underline; }
    .excerpt-src span { display: block; line-height: 1.4; }
    .ex-src-author { color: var(--ink); font-weight: 600; }
    .ex-src-title  { color: var(--accent); font-style: italic; }
    .ex-src-year   { color: var(--muted); font-size: .8rem; }

    /* hub-src r2.1: Placeholder — shown when a book has neither overview nor
       excerpt. flex does double duty here: it vertically centres the two
       blurbs AND establishes a block formatting context, which is what stops
       the box from sliding underneath the floated infobox on desktop — a
       BFC's border box never intrudes into a float, it sits beside it and
       takes the remaining width. The inline script below the markup matches
       its height to the infobox at the float breakpoint. */
    .overview-placeholder {
        display: flex; flex-direction: column;
        justify-content: center; align-items: center;
        gap: .9rem;
        border: 3px dashed var(--rule);
        border-radius: 8px;
        padding: 2.4rem 1.5rem;
        margin: 0 0 1.5rem;
        text-align: center;
    }
    /* Line 1 — the script voice. Serif italic keeps it on-brand without
       loading a display face; if you ever vendor a proper script webfont,
       this font-family is the one knob to point at it. */
    .ph-script {
        font-family: var(--serif);
        font-style: italic;
        font-size: 1.2rem; line-height: 1.4;
        color: var(--ink);
    }
    /* Line 2 — the block voice: the no-AI / public-domain statement. */
    .ph-block {
        font-family: var(--sans);
        font-size: .8rem; line-height: 1.55;
        color: var(--muted);
        max-width: 34rem;
    }
    .overview-placeholder p { margin: 0; }   /* flex gap owns the spacing */

    /* tl-part r3: timeline CSS extracted to its own partial, paired with
       bible/partials/timeline (the markup). */
    @include('bible.partials.timeline-styles')

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

    /* hub-src r2: the marker letter, echoed at its destination — the
       reader's .fn-letter treatment. */
    .src-letter { color: var(--accent); font-weight: 700; margin-right: .45rem; }    

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
        {{-- hub-fold r1: corner cluster is the apps folder (components/
             head-folder), same as the chapter reader. Pill order, left to
             right: candle / Aa, then the folder circle. persist="reader"
             shares the reader's open/shut key (mb.fold.reader), so hub →
             chapter feels like one continuous surface. Aa suppresses the
             visibility checkboxes — this page has no verse text.

             The translation switcher is gone from this page: no text is
             visible until the reader, so the choice belongs there. (The
             vigil book hub keeps its switcher — progress is per-edition.) --}}
        <div class="head-actions">
            <x-head-folder persist="reader">
                @include('bible.partials.vigil-sheet', [
                    'mode'        => 'enter',
                    'href'        => route('typing.vigil.book', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug]),
                    'lead'        => 'Type this book, chapter by chapter. Progress is saved on this device.',
                    'actionLabel' => 'Begin Typing Vigil',
                ])
                <details class="pericope-app" id="app-pericope">
                    <summary class="fld-app" aria-label="Pericopes" title="Pericopes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
                    </summary>
                    <div class="ps-panel" role="group" aria-label="Pericopes"></div>
                </details>                
                @include('bible.partials.text-settings', ['tsChecks' => false])
            </x-head-folder>
        </div>

        <div class="chapter-head-top">
            <h1>{{ $book->name }}</h1>
        </div>
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
                {{-- hub-src r2: every field runs through SourceMarkers::inline,
                     which escapes the text and then turns registered "(a)"
                     tokens into the superscript markers. Unregistered
                     parentheses pass through untouched. --}}
                @php $sm = fn ($v) => \App\Support\SourceMarkers::inline($v, $sourceLetters); @endphp
                @if ($intro->traditional_author) <dt>Traditional author</dt><dd>{!! $sm($intro->traditional_author) !!}</dd> @endif
                @if ($intro->scholarly_view)     <dt>Scholarly view</dt><dd>{!! $sm($intro->scholarly_view) !!}</dd> @endif
                @if ($intro->dating)             <dt>Date written</dt><dd>{!! $sm($intro->dating) !!}</dd> @endif
                @if ($intro->language)           <dt>Language</dt><dd>{!! $sm($intro->language) !!}</dd> @endif
                @if ($intro->genre)              <dt>Genre</dt><dd>{!! $sm($intro->genre) !!}</dd> @endif
                @if ($intro->place_written)      <dt>Place</dt><dd>{!! $sm($intro->place_written) !!}</dd> @endif
            </dl>
        </aside>

        {{-- hub-src r2: Overview when we have one; otherwise an Excerpt
             (quoted matter + attribution into the bibliography); otherwise
             the dotted work-in-progress placeholder. Exactly one of the
             three renders. --}}
        @if ($intro->summary)
            <h2 class="no-clear">Overview</h2>
            <div class="prose reader-text">{!! \App\Support\SourceMarkers::markdown($intro->summary, $sourceLetters) !!}</div>
        @elseif ($intro->excerpt)
            <h2 class="no-clear">Excerpt</h2>
            <figure class="excerpt">
                <blockquote class="prose reader-text">{!! \App\Support\SourceMarkers::markdown($intro->excerpt, $sourceLetters) !!}</blockquote>
                {{-- hub-src r2.1: author / title / year as a left-justified
                     stack, each from its own sources-table column. A source
                     entered only as a citation string still renders (the
                     fallback line), so nothing ever silently vanishes. --}}
                @if ($excerptSource)
                    <figcaption class="excerpt-src">
                        <a href="#source-{{ $excerptSource->slug }}">
                            @if ($excerptSource->author)<span class="ex-src-author">{{ $excerptSource->author }}</span>@endif
                            @if ($excerptSource->title)<span class="ex-src-title">{{ $excerptSource->title }}</span>@endif
                            @if ($excerptSource->year)<span class="ex-src-year">{{ $excerptSource->year }}</span>@endif
                            @if (! $excerptSource->author && ! $excerptSource->title)
                                <span class="ex-src-title">{{ $excerptSource->citation }}</span>
                            @endif
                        </a>
                    </figcaption>
                @endif
            </figure>
        @else
            <h2 class="no-clear">Overview</h2>
            <div class="overview-placeholder">
                <p class="ph-script">We are working on finding the best excerpt for this book. Please return soon.</p>
                <p class="ph-block">MEGABIBLE.net does not use AI generated copy. We strive to present free and easily accessible scholarly Bible knowledge and information from the Public Domain.</p>
            </div>

            {{-- hub-src r2.1: at the float breakpoint, stretch the dotted box
                 so its bottom edge lines up with the infobox beside it —
                 the two read as one balanced row instead of a short box next
                 to a tall one. Below the breakpoint (infobox not floated)
                 the min-height is cleared and content height rules. The
                 ResizeObserver re-fits when the infobox reflows (fonts
                 arriving, orientation change). --}}
            <script>
            (function () {
                const ph  = document.querySelector('.overview-placeholder');
                const box = document.querySelector('.infobox');
                if (!ph || !box) return;

                const mq = window.matchMedia('(min-width: 720px)');

                function fit() {
                    if (!mq.matches) { ph.style.minHeight = ''; return; }
                    // Align bottoms: the infobox's bottom edge minus the
                    // placeholder's own top edge (both viewport-relative,
                    // so the difference is scroll-proof).
                    const want = box.getBoundingClientRect().bottom
                               - ph.getBoundingClientRect().top;
                    ph.style.minHeight = want > 0 ? want + 'px' : '';
                }

                let raf = null;
                function queue() {
                    if (raf) cancelAnimationFrame(raf);
                    raf = requestAnimationFrame(fit);
                }
                new ResizeObserver(queue).observe(box);
                window.addEventListener('resize', queue);
                fit();
            })();
            </script>
        @endif

        @if ($intro->authorship_note)
            <h2>Authorship</h2>
            <div class="prose reader-text">{!! \App\Support\SourceMarkers::markdown($intro->authorship_note, $sourceLetters) !!}</div>
        @endif
    @endif

    {{-- tl-part r3: timeline extracted — markup + lane-packing/label-fit
         scripts live in the partial; the null-guard does too, so this
         include is unconditional. --}}
    @include('bible.partials.timeline')    

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
                {{-- hub-src r2: .src-line is the popover's clone source (the
                     reader's .fn-line pattern); the letter badge is stripped
                     from the clone, so it renders only here. --}}
                <li class="source-item" id="source-{{ $s->slug }}">
                    <span class="src-line">
                        @if ($s->pivot->letter)
                            <span class="src-letter">{{ $s->pivot->letter }}</span>
                        @endif
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
                    </span>
                </li>
            @endforeach
        </ol>
    @endif

@endsection
@section('scripts')
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
@include('bible.partials.source-popover')
@endsection