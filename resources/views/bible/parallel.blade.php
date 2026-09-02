@extends('layouts.app')

@section('title', $refBook . ($refChapter !== null ? ' ' . $refChapter : '') . ' — Parallel — MEGABIBLE.net')

@section('styles')
{{-- Canonical = the clean parallel URL. url() keeps the comma in the slug list
     literal (route() would encode it to %2C). --}}
<link rel="canonical" href="{{ url('/parallel/' . $slugCsv . '/' . $book->slug . '/' . $chapter) }}">
<style>
    {{-- Shared reading typography (verse numbers, poetry, headings, etc.). --}}
    @include('bible.partials.reading-styles')

    /* The full-bleed columns are sized in vw, which counts the vertical scrollbar
        gutter — so on a tall page they overshoot the real content width by the
        scrollbar's width and spawn a horizontal scrollbar. Clip that sliver at the
        root. It must be on <html>, not <body>: body overflow gets propagated to the
        viewport and `clip` doesn't survive that hop. `clip` (not `hidden`) is
        deliberate — it doesn't create a scroll container, so the sticky header still
        pins to the viewport. */
    html { overflow-x: clip; }

    /* ---- Full-bleed break-out + shared column geometry -----------------------
       The custom properties below are the single source of truth for the column
       layout. The sticky header's label grid and the body's reading grid BOTH
       read them, so the two stay aligned no matter the screen width.

       --pbreak-pad is the side padding of the full-bleed block. It's a variable
       (not a literal) so the title row below can cancel it exactly and re-align
       itself to the 820px site-header container instead of the viewport. */
    .parallel-break {
        --pcol-max: 550px;                       /* per-column cap at wide sizes */
        --pcol-gap: clamp(1.25rem, 4vw, 3rem);   /* gutter between columns       */
        --pcol-pad: clamp(1.25rem, 4vw, 3rem);   /* 2nd column's divider padding */
        --pbreak-pad: clamp(1.5rem, 3vw, 2.5rem);  /* full-bleed block side padding */

        margin-left:  calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        padding-inline: var(--pbreak-pad);
    }

    @include('bible.partials.sticky-head')

    /* ---- Parallel-specific head geometry ---------------------------------
       The head sits inside the full-bleed .parallel-break rather than the
       820px .container, so the bleed points at the break's own side padding.
       That makes the head's background span the true viewport width — which
       is also exactly what the shadow strip wants, so the mobile ::after
       override that used to cancel --pbreak-pad by hand is gone. ---------- */
    .chapter-head {
        --mb-head-bleed:       var(--pbreak-pad);
        --mb-head-reserve:     7rem;
        --mb-head-actions-top: 0;
    }

    /* Title row mirrors .container (820px + auto margins + 1.5rem padding) so
       the book link sits under the logo and the buttons under the search at
       every width, even though the reading columns break out wider. Keeping
       it position:relative re-anchors the corner cluster here instead of to
       the full-bleed head. padding-left only — the partial owns padding-right
       as the reserve. */
    .chapter-head-top {
        position: relative;
        max-width: 820px;
        margin-inline: auto;
        margin-bottom: 0;          /* .parallel-head-row owns the gap below */
        padding-left: 1.5rem;
    }

    /* Spans the full viewport by cancelling the head's bleed padding; the
       inner .chapter-head-top then re-centres on the 820px container. */
    .parallel-head-row {
        margin-inline: calc(var(--pbreak-pad) * -1);
        margin-bottom: .7rem;
    }

    /* Desktop: cap the shadow strip to the width of the two columns and
       centre it, rather than letting it run the full viewport. min() = full
       width until the columns hit their cap, then locked to the column
       block. The feathering mask comes from the partial unchanged. */
    @media (min-width: 821px) {
        .chapter-head::after {
            left: 50%; right: auto;
            transform: translateX(-50%);
            width: min(100%, calc(2 * var(--pcol-max) + var(--pcol-gap)));
        }
    }

    /* Hub back link. Lives BELOW the head now, on the scrolling surface, so
       it slides up under the sticky header with the columns. Same 820px
       centring as the title row, since everything around it is full-bleed. */
    .hub-back-row {
        max-width: 820px;
        margin-inline: auto;
        margin-top: 0; margin-bottom: 1.2rem;
        padding-inline: 1.5rem;
        font-family: var(--sans); font-size: .82rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    /* ---- The two grids share identical geometry, so labels align with columns. */
    .parallel-head-cols,
    .parallel-cols {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, var(--pcol-max)));
        justify-content: center;
        gap: var(--pcol-gap);
    }

    /* Column divider runs down the reading body only. The 2nd header label keeps
    the same left padding so it stays aligned with its column, but draws no
    border — so the divider now begins below the header instead of through it. */
    .pcol + .pcol {
        border-left: 1px solid var(--rule);
        padding-left: var(--pcol-pad);
    }
    .pcol-label + .pcol-label {
        padding-left: var(--pcol-pad);
    }

    /* Per-column label. The code links to the single-translation reading. */
    .pcol-abbr {
        font-family: var(--sans); font-weight: 700; font-size: 1rem;
        color: var(--ink); text-decoration: none; letter-spacing: .02em;
    }
    .pcol-abbr:hover { color: var(--accent); }
    .pcol-name {
        display: block; font-family: var(--sans); font-size: .9rem;
        color: var(--muted); margin-top: .1rem;
    }

    /* ---- Cross-column verse highlight (carriers mirror the chapter view) ------ */
    .parallel-cols .verse { cursor: pointer; touch-action: pan-y; }
    .parallel-cols .reading p:not(.poetry) .verse,
    .parallel-cols .reading p.poetry .vt {
        border-radius: 4px; padding: 0 .1em; margin: 0 -.1em;
        transition: background-color .15s ease;
        -webkit-box-decoration-break: clone; box-decoration-break: clone;
    }
    /* Hover preview comes from verse-hover.js (.is-hover), which paints across
       BOTH columns via the data-verse-hover-group on .parallel-cols. */
    .parallel-cols .reading p:not(.poetry) .verse.is-hover:not(.vp-lock),
    .parallel-cols .reading p.poetry.verse.is-hover:not(.vp-lock) .vt { background: var(--panel); }
    .parallel-cols .reading p:not(.poetry) .verse.vp-lock,
    .parallel-cols .reading p.poetry.verse.vp-lock .vt { background: var(--rule); }

    /* Hug the arrows to the edges of the two-column block, the same way they
       hug the 820px column in the single chapter view — rather than pinning
       them to the viewport edge. Reach = half the column block + the arrow's
       own 48px + an 8px gap; app.blade.php's max(1rem, …) then tucks them to
       the viewport edge once the columns get wide enough to leave no room,
       exactly as the single view does on narrow screens.

       2 × 550px col cap + 48px max gap = 1148px block → 574px half.
       574 + 48 + 8 = 630px. */
    .chapter-nav { --nav-reach: 630px; }

    @media (prefers-reduced-motion: reduce) {
        .parallel-cols .verse { transition: none; }
    }
</style>
@endsection

@section('content')
    <div class="parallel-break">
        {{-- 1px marker just above the header → tells the script when it's stuck. --}}
        <div class="chapter-head-sentinel"></div>

        <header class="chapter-head">
            <div class="parallel-head-row">
                <div class="chapter-head-top">
                    @if ($maxChapter > 1)
                        {{-- Multi-chapter: book title opens the QuickNav to this
                             book's chapter grid. Chapters stay in PARALLEL mode
                             (/parallel/{slugs}/{book}/{n}); the title links to the
                             single-view hub, since there is no parallel hub. --}}
                        <details class="qn show-chapters"
                                 data-open-name="{{ $book->name }}"
                                 data-open-title-url="{{ route('bible.book', ['translation' => $columns[0]['slug'], 'book' => $book->slug]) }}"
                                 data-open-base="{{ url('/parallel/' . $slugCsv . '/' . $book->slug) }}"
                                 data-open-chapters="{{ $maxChapter }}"
                                 data-open-chapter-offset="{{ $book->chapterCellOffset() }}">
                            <summary class="qn-book-trigger" aria-label="Jump to another chapter of {{ $book->name }}">
                                <h1><span class="book-link">{{ $refBook }} {{ $refChapter }}</span></h1>
                            </summary>
                            @include('bible.partials.quicknav-panel', [
                                'openName'     => $book->name,
                                'openTitleUrl' => route('bible.book', ['translation' => $columns[0]['slug'], 'book' => $book->slug]),
                                'openBase'     => url('/parallel/' . $slugCsv . '/' . $book->slug),
                                'openChapters' => $maxChapter,
                                'openChapterOffset' => $book->chapterCellOffset(),
                            ])
                        </details>
                    @else
                        {{-- Single-chapter book: keep the plain hub link. --}}
                        <h1>
                            <a class="book-link"
                               href="{{ route('bible.book', ['translation' => $columns[0]['slug'], 'book' => $book->slug]) }}">{{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</a>
                        </h1>
                    @endif

                    {{-- Corner cluster: parallel toggle + Aa, anchored to the
                         title row's top-right so a wrapping title never moves
                         them. --}}
                    <div class="head-actions">
                        @include('bible.partials.parallel-toggle', [
                            'href'   => route('bible.chapter', ['translation' => $columns[0]['slug'], 'book' => $book->slug, 'chapter' => $chapter]),
                            'active' => true,
                            'label'  => 'Switch to single view',
                        ])
                        @include('bible.partials.text-settings')
                    </div>
                </div>

        <div class="parallel-head-cols">
            @foreach ($columns as $col)
                @php $t = $col['translation']; @endphp
                <div class="pcol-label">
                    <span class="pcol-name">{{ $t->name }}</span>
                </div>
            @endforeach
        </div>
    </header>

    {{-- Breadcrumb sits on the scrolling surface, below the column labels,
         so it slides up under the sticky header with the columns. --}}
    <p class="hub-back-row"><a class="hub-back"
        href="{{ route('bible.book', ['translation' => $columns[0]['slug'], 'book' => $book->slug]) }}">&larr; Back to {{ $book->name }} Hub</a></p>

    <div class="parallel-cols" data-verse-hover-group>
            @foreach ($columns as $col)
                <section class="pcol">
                    <div class="reading" data-verse-hover=".verse, .footnote-row">
                        @include('bible.partials.reading-flow', [
                            'layout'         => $col['layout'],
                            'idPrefix'       => $col['slug'] . '-',
                            'linkTranslation' => $col['slug'],
                        ])
                        {{-- Each column's own notes, ids prefixed to match its
                             markers ("web-fn-a") so columns never collide. --}}
                        @include('bible.partials.footnotes-list', [
                            'footnotes' => $col['chapterFootnotes'] ?? [],
                            'idPrefix'  => $col['slug'] . '-',
                        ])
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    @include('bible.partials.chapter-nav')
@endsection

@section('scripts')
<script src="{{ asset('js/verse-hover.js') }}?v={{ filemtime(public_path('js/verse-hover.js')) }}" defer></script>
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
@include('bible.partials.footnote-popover')
@endsection

@section('footer-colophon')
    @php
        // Dedupe heading attribution across columns by source key: when every
        // column draws from the same source (KJV + WEB both use BSB) it prints
        // once. Different sources (KJV + RV1909 later) each keep their own line.
        $headingCredits = collect($columns)
            ->flatMap(fn ($col) => $col['headingCredits'] ?? [])
            ->unique('key')
            ->values();

        // Footnote credits, deduped across columns. Keyed source_keys dedupe
        // on the key; NULL-key credits (notes attributed to the translation
        // itself) dedupe on full identity instead, so two translations'
        // own-notes lines never collapse into one by accident.
        $footnoteCredits = collect($columns)
            ->flatMap(fn ($col) => $col['footnoteCredits'] ?? [])
            ->unique(fn ($c) => $c['key'] !== ''
                ? $c['key']
                : $c['name'] . '|' . ($c['license'] ?? '') . '|' . ($c['source_url'] ?? ''))
            ->values();    

        // One verse line per column, in column order.
        $editions = collect($columns)->map(fn ($col) => [
            'name'       => $col['translation']->name,
            'license'    => $col['translation']->license,
            'source_url' => $col['translation']->source_url,
            'verseCount' => $col['verseCount'],
        ]);
    @endphp

    @include('bible.partials.colophon', [
        'headingCredits'  => $headingCredits,
        'footnoteCredits' => $footnoteCredits,
        'editions'        => $editions,
    ])
@endsection