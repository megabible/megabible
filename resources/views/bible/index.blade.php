@extends('layouts.app')

{{-- Sets the page title in the layout. Home keeps the default, but this is
     here so you can see the pattern; other pages set something specific. --}}
@section('title', 'MEGABIBLE.net')

{{-- HOME-PAGE-ONLY CSS. Injected into the layout's head wherever the styles
     yield sits, so it loads after (and can override) the base styles. --}}
@section('styles')
<style>
    /* ---- HERO ------------------------------------------------------- */
    .home-hero{margin:.4rem 0 2.6rem;}
    .home-title{font-size:2.4rem;font-weight:400;line-height:1.15;letter-spacing:-.01em;margin:0 0 .7rem;}
    .home-lead{font-size:1.15rem;line-height:1.6;margin:0;}
    .home-lead a{color:var(--accent);text-decoration:none;}
    .home-lead a:hover{text-decoration:underline;}

    /* ---- HERO DEMO CARD (hp-demo r1) ----------------------------------
       The reader's synthesis card, transplanted. BACK-FACE styles (the
       Hebrew rows, word links, credit line) come from the shared
       bible/partials/interlinear-styles include at the foot of this
       style block — the very file the reader uses. The card SHELL below
       is a MIRROR of chapter.blade's synthesis styles; if the cards are
       restyled there, update here too (post-launch candidate for
       extraction into a shared partial of its own). */
    .home-demo{max-width:680px;margin:1.6rem auto 0;}
    .home-demo-label{
        display:block;font-family:var(--sans);font-size:.72rem;font-weight:600;
        text-transform:uppercase;letter-spacing:.08em;color:var(--muted);
        margin:0 0 .5rem;
    }
    .synthesis-card {
        background: var(--panel);
        border: 1px solid var(--rule);
        border-radius: 10px;
        padding: 1.1rem 1.25rem;
    }
    .synthesis-ref {
        display: flex; align-items: center; gap: .5rem;
        margin-bottom: .5rem;
        font-family: var(--sans); font-size: .78rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .05em;
        color: var(--accent);
    }
    .synthesis-copy {
        margin-left: auto;
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px;
        border: none; border-radius: 6px;
        background: none; color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .synthesis-copy:hover { color: var(--accent); background: var(--bg); }
    .synthesis-copy.is-done { color: var(--accent); }
    .synthesis-copy svg { display: block; }
    .synthesis-text {
        font-family: var(--reading-family);
        font-size: calc(var(--reading-size) * .92);
        line-height: var(--reading-leading);
        white-space: pre-line;
    }
    .synthesis-flip {
        margin-left: auto;
        display: inline-flex; align-items: center; justify-content: center;
        gap: .3rem;
        height: 30px; padding: 0 .5rem;
        border: none; border-radius: 6px;
        background: none; color: var(--muted); cursor: pointer;
        font-family: var(--sans); font-size: .72rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em;
        transition: color .12s, background .12s;
    }
    .synthesis-flip .flip-label { line-height: 1; }
    .synthesis-flip:hover { color: var(--accent); background: var(--bg); }
    .synthesis-flip[aria-pressed="true"] { color: var(--accent); }
    .synthesis-flip[disabled] { opacity: .45; cursor: default; }
    .synthesis-flip svg { display: block; }
    .synthesis-flip + .synthesis-copy { margin-left: 0; }
    .card-faces {
        position: relative;
        overflow: hidden;
        transition: height .45s cubic-bezier(.2,.8,.2,1);
    }
    .card-faces .face {
        width: 100%;
        position: absolute; top: 0; left: 0;
        transition: opacity .32s ease, transform .32s ease;
    }
    .card-faces .face.is-active { position: static; }
    .card-faces .face-front { opacity: 1; transform: none; }
    .card-faces .face-back  { opacity: 0; transform: translateY(8px);  pointer-events: none; }
    .synthesis-card.is-flipped .face-front { opacity: 0; transform: translateY(-8px); pointer-events: none; }
    .synthesis-card.is-flipped .face-back  { opacity: 1; transform: none; pointer-events: auto; }

    /* ---- TOP BOOKS THIS WEEK ----------------------------------------
       Five chips fed by the same book_visits window as the hub pill.
       Each chip is a link to its book hub, carries its canon-section
       colour as --cg, and joins the trace-draw hover below. */
    .top-books{margin:1.5rem 0 0;}
    .top-books-head{display:flex;align-items:baseline;gap:1rem;margin:0 0 .7rem;}
    .top-books-title{color:var(--accent);font-size:1.15rem;font-weight:600;margin:0;letter-spacing:.01em;}
    .top-all{margin-left:auto;font-family:var(--sans);font-size:.82rem;color:var(--muted);text-decoration:none;white-space:nowrap;}
    .top-all:hover{color:var(--accent);}

    .top-list{list-style:none;margin:0;padding:0;display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));}
    .top-chip{
        position:relative;display:flex;flex-direction:column;gap:.05rem;height:100%;
        border:1px solid var(--rule);border-bottom:3px solid var(--cg,var(--tl-clay));
        border-radius:5px;padding:.6rem .8rem .55rem;
        background:var(--bg);text-decoration:none;color:var(--ink);
        transition:transform .16s ease;
    }
    .top-chip:hover,.top-chip:focus-visible{transform:scale(1.07);z-index:2;}
    .tb-rank{font-family:var(--sans);font-size:.7rem;font-weight:700;color:var(--cg);letter-spacing:.1em;}
    .tb-name{font-family:var(--book-font);font-size:1.22rem;line-height:1.25;}
    .tb-count{font-family:var(--sans);font-size:.75rem;color:var(--muted);font-variant-numeric:tabular-nums;margin-top:.15rem;}

    /* ---- TESTAMENT CTA PILLS ----------------------------------------- */
    .testament-ctas{display:flex;gap:.7rem;margin:1.6rem 0 0;}
    .btn-testament{
        flex:1;display:inline-flex;align-items:center;justify-content:center;
        font-family:var(--sans);font-size:1.05rem;font-weight:600;text-decoration:none;
        padding:.8rem 1rem;border-radius:999px;
        border:1px solid var(--accent);background:transparent;color:var(--accent);
        transition:background .12s,color .12s;
    }
    .btn-testament:hover{background:var(--accent);color:#fff;}
    .btn-testament:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.25);}

    /* ---- TESTAMENT / SECTION STRUCTURE (unchanged bones) ------------- */
    .testament{margin-bottom:1rem;}
    /* The ids make each testament the CTA pills' anchor target; the
       scroll-margin keeps a little air above the title on landing. */
    .testament[id]{scroll-margin-top:.9rem;}
    .testament-title{font-size:2.2rem;font-weight:400;letter-spacing:-.01em;margin:1.8rem 0 .3rem;}
    .testament-blurb{font-family:var(--sans);font-size:.82rem;color:var(--muted);margin:0 0 .65rem;letter-spacing:.02em;line-height:1.5;}
    .testament-blurb:last-of-type{margin-bottom:1.4rem;}

    .section-head{color:var(--accent);font-size:1.3rem;font-weight:600;margin:2.3rem 0 .8rem;letter-spacing:.01em;}
    .section-head .sub{font-style:italic;font-weight:400;color:var(--muted);font-size:1rem;margin-left:.45rem;}

    /* hp-ring r1: each section (heading + grids) sits in a .canon-section
       wrapper carrying the deep-link id and the section colour. Landing
       at /#torah draws a thin ring in that colour around the whole group
       via :target — transparent base + transition fades it in; outline
       never reflows the page, and the offset keeps it clear of the
       content (an edge cell's hover scale crosses it briefly — fine).
       scroll-margin keeps air above the heading on landing, as before. */
    .canon-section{
        scroll-margin-top:.9rem;
        border-radius:8px;
        outline:1px solid transparent;
        outline-offset:10px;
        transition:outline-color .35s ease;
    }
    .canon-section:target{outline-color:var(--cg,var(--tl-clay));}

    /* Blurb mentions of each section are live deep links, tinted by that
       section's own colour (--cg set inline by linkifyBlurb). COLOR KNOB:
       the color-mix pulls each palette colour toward --ink for contrast
       at this small size — raise the first percentage for purer colour,
       lower it for darker. The plain declaration above it is the
       no-color-mix fallback. */
    .testament-blurb a{
        color:var(--cg,var(--accent));
        color:color-mix(in srgb, var(--cg, var(--accent)) 78%, var(--ink));
        font-weight:600;text-decoration:none;
    }
    .testament-blurb a:hover{text-decoration:underline;}

    /* Subgroup label — sits below a section-head, above its own book grid. */
    .subgroup-head{font-family:var(--sans);font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:.9rem 0 .65rem;}

    /* ---- BOOK CELLS ---------------------------------------------------
       hp-cell r1: the about page's canon-tile treatment brought down to
       book level. Each grid carries its section colour as --cg (set inline
       on the UL; custom properties inherit to every cell and its trace
       SVG). The colour line sits on the BOTTOM edge; on hover the trace
       script animates it around the whole cell and the cell scales up.
       The old solid-accent hover fill is retired. */
    .book-grid{list-style:none;margin:0;padding:0;display:grid;gap:.5rem;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));}
    .book{
        position:relative;display:block;text-decoration:none;
        border:1px solid var(--rule);
        border-bottom:3px solid var(--cg,var(--tl-clay));
        border-radius:5px;padding:.55rem .8rem .5rem;
        font-family:var(--book-font);
        font-size:1.22rem;              /* SIZE KNOB — Bigshot runs wide */
        line-height:1.3;letter-spacing:.01em;
        background:var(--bg);
        transition:transform .16s ease;
    }
    .book.live{color:var(--ink);}
    .book.live:hover,.book.live:focus-visible{transform:scale(1.07);z-index:2;}
    .book.live:focus-visible{outline:none;}

    /* Not-yet-imported books: dashed chrome, muted text, and a faded
       version of their section colour on the bottom line (the solid-rule
       first declaration is the fallback if color-mix is ever missing). */
    .book.soon{
        color:var(--soon);border-style:dashed;cursor:default;
        border-bottom-style:solid;
        border-bottom-color:var(--rule);
        border-bottom-color:color-mix(in srgb, var(--cg, var(--tl-clay)) 45%, var(--bg));
    }

    /* ---- THE TRACE (draw-around hover) --------------------------------
       The script below drops one SVG into every .trace element: a single
       path that starts at the bottom-RIGHT corner, runs the bottom edge to
       the bottom-left, then up, across the top, and down the right side,
       closing where it began. Resting state: the dash shows only that
       first segment — visually, the bottom colour line. On hover the dash
       length transitions to the full perimeter, so the line GROWS from the
       bottom-left corner, up and around, closing at bottom-right — and
       retreats the same way when the pointer leaves. --trace-rest and
       --trace-full are measured per cell by the script (the bottom edge's
       share of the perimeter varies with cell width, so no constant
       works). The 4000 gap is just "longer than any perimeter here".
       Once the SVG owns the line, trace-on blanks the CSS border's colour
       (the 3px of layout stays, so nothing shifts). No JS → no trace-on →
       the static CSS line and the scale hover still work. */
    .trace .trace-svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;display:block;}
    .trace .trace-svg path{
        fill:none;stroke:var(--cg,var(--tl-clay));stroke-width:3;
        stroke-dasharray:var(--trace-rest,0) 4000;
        transition:stroke-dasharray .45s ease;
    }
    /* Once the SVG owns the colour line, the cell reverts to a NORMAL 1px
       rule on all four sides — so the thin outline runs unbroken around
       the bottom corners (a transparent thick border paints transparent
       corner wedges; that was the hole). The padding grows by the 2px the
       border gave up, so the box never changes size when JS lands. */
    .book.trace-on{border-bottom:1px solid var(--rule);padding-bottom:calc(.5rem + 2px);}
    .top-chip.trace-on{border-bottom:1px solid var(--rule);padding-bottom:calc(.55rem + 2px);}
    a.trace:hover .trace-svg path,
    a.trace:focus-visible .trace-svg path{stroke-dasharray:var(--trace-full,4000) 4000;}

    /* Bigshot runs wide, so any book WITH a short name now uses it at
       EVERY width. Both labels still render in the markup, so flipping
       back to width-based swapping later is a CSS-only change. */
    .bk-short{display:none;}
    .has-short .bk-full {display:none;}
    .has-short .bk-short{display:inline;}

    @media (max-width:420px){
        .btn-testament{font-size:.95rem;}
    }

    @media (max-width:560px){
        .home-title{font-size:1.9rem;}
        .book-grid{grid-template-columns:repeat(auto-fill,minmax(135px,1fr));}
        .top-list{grid-template-columns:repeat(auto-fit,minmax(125px,1fr));}
    }

    @media (prefers-reduced-motion:reduce){
        .book,.top-chip,.trace .trace-svg path,
        .card-faces,.card-faces .face{transition:none;}
    }
    @include('bible.partials.interlinear-styles')
</style>
@endsection

{{-- THE PAGE BODY. Injected into the layout between the shared header and
     the shared footer. --}}
@section('content')
    @php
        // Per-book display overrides and short labels (config/canon.php).
        $homeShortNames = config('canon.home_short_names', []);
        $homeNames      = config('canon.home_names', []);
        // hp-cell r1: section key → palette name, the same map the QuickNav
        // and the About tiles tint from. One source of truth.
        $sectionColors  = config('canon.section_colors', []);
    @endphp

    {{-- ============ HERO ============ --}}
    <section class="home-hero">
        <h1 class="home-title">Read and study the 91 books of the Bible</h1>
        <p class="home-lead">
            We host every Bible book from Genesis to the Shepherd of Hermas, with the
            original Hebrew, Greek, and Aramaic a click away.
            <a href="{{ route('about') }}">Learn more here.</a>
        </p>

        {{-- hp-demo r1: a live facsimile of the reader's synthesis card —
             Genesis 1:1 with the real flip / word-link / copy behaviour,
             fed by the SAME /interlinear endpoint the reader uses. The
             null-guard keeps this blade safe even before the controller
             starts passing $demo, and hides the card if the verse (or
             KJV) ever goes missing from the database. --}}
        @if ($demo ?? null)
            <div class="home-demo">
                <span class="home-demo-label">Click {{ $demo['lang'] }}, then tap a word</span>
                <article class="synthesis-card" id="hp-demo">
                    <div class="synthesis-ref">
                        <span>{{ $demo['ref'] }} &middot; {{ $demo['tx'] }}</span>
                        <button type="button" class="synthesis-flip" id="hp-demo-flip"
                                aria-pressed="false" title="Show {{ $demo['lang'] }}"
                                aria-label="Show {{ $demo['lang'] }} for {{ $demo['ref'] }}">
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2.1l4 4-4 4"/><path d="M3 12.2v-2a4 4 0 0 1 4-4h14"/><path d="M7 21.9l-4-4 4-4"/><path d="M21 11.8v2a4 4 0 0 1-4 4H3"/></svg><span class="flip-label">{{ $demo['lang'] }}</span>
                        </button>
                        <button type="button" class="synthesis-copy" id="hp-demo-copy"
                                aria-label="Copy {{ $demo['ref'] }}" title="Copy">
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        </button>
                    </div>
                    <div class="card-faces">
                        <div class="face face-front is-active">
                            <div class="synthesis-text">{{ $demo['text'] }}</div>
                        </div>
                        <div class="face face-back iface"></div>
                    </div>
                </article>
            </div>
        @endif

        @if (count($topBooks))
            <div class="top-books">
                <div class="top-books-head">
                    <h2 class="top-books-title">Top books this week</h2>
                    <a class="top-all" href="{{ route('extras.topbooks') }}">Full rankings <span aria-hidden="true">&rarr;</span></a>
                </div>
                <ol class="top-list">
                    @foreach ($topBooks as $tb)
                        <li>
                            <a class="top-chip trace{{ $tb['short'] ? ' has-short' : '' }}"
                               href="{{ $tb['href'] }}"
                               style="--cg:var(--tl-{{ $tb['color'] }})">
                                <span class="tb-rank">#{{ $loop->iteration }}</span>
                                <span class="tb-name">
                                    @if ($tb['short'])
                                        <span class="bk-full">{{ $tb['name'] }}</span><span class="bk-short">{{ $tb['short'] }}</span>
                                    @else
                                        {{ $tb['name'] }}
                                    @endif
                                </span>
                                <span class="tb-count">{{ number_format($tb['hits']) }} {{ $tb['word'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- The two big anchors into the canon below. --}}
        <nav class="testament-ctas" aria-label="Jump to a testament">
            @foreach ($testaments as $tKey => $t)
                <a class="btn-testament" href="#{{ $tKey }}-testament">{{ $t['label'] }}</a>
            @endforeach
        </nav>
    </section>

    @foreach ($testaments as $tKey => $testament)
        <section class="testament" id="{{ $tKey }}-testament">
            <h2 class="testament-title">{{ $testament['label'] }}</h2>
            @php
                // A blurb may be a single string OR an array of paragraphs.
                // Casting to an array lets both shapes render identically, so
                // any section still using a plain string keeps working.
                $blurbs = array_filter(
                    (array) ($testament['blurb'] ?? []),
                    fn ($p) => trim((string) $p) !== ''
                );
            @endphp
            @foreach ($blurbs as $para)
                {{-- Blurb HTML comes from linkifyBlurb() in the controller,
                     which escapes everything BEFORE building its anchor
                     tags — the one reason raw echo is safe here. --}}
                <p class="testament-blurb">{!! $para !!}</p>
            @endforeach

            @foreach ($testament['sections'] as $sectionKey)
                @php $section = $sections[$sectionKey] ?? null; @endphp
                @continue (! $section)

                @php
                    // hp-ring r1: this section's palette colour, hoisted so
                    // the wrapper (ring), its cells, and their trace SVGs
                    // all inherit one --cg from one place.
                    $cg = $sectionColors[$sectionKey] ?? 'clay';
                @endphp

                {{-- hp-ring r1: the deep-link id moved from the h3 to this
                     wrapper, so /#torah (the About tiles, the blurb links)
                     targets the WHOLE group — heading and grids — and the
                     :target ring draws around all of it. Same URLs. --}}
                <div class="canon-section" id="{{ $sectionKey }}" style="--cg:var(--tl-{{ $cg }})">
                <h3 class="section-head">
                    {{ $section['label'] }}
                    @if (!empty($section['subtitle']))
                        <span class="sub">{{ $section['subtitle'] }}</span>
                    @endif
                </h3>

                @php
                    // Normalize: a flat section ('books') becomes a single unlabelled
                    // group, so the markup below can treat every section identically.
                    $groups = $section['subgroups'] ?? [
                        ['label' => null, 'books' => $section['books'] ?? []],
                    ];
                @endphp

                @foreach ($groups as $group)
                    @if (!empty($group['label']))
                        <h4 class="subgroup-head">{{ $group['label'] }}</h4>
                    @endif

                    <ul class="book-grid" style="--cg:var(--tl-{{ $cg }})">
                        @foreach ($group['books'] as $slug)
                            @php $book = $books->get($slug); @endphp
                            @if ($book)
                                @php
                                    // If a short name is defined for this book, render BOTH
                                    // labels and let CSS choose by width; otherwise just the
                                    // full name. e() escapes the text exactly like the echo
                                    // braces do.
                                    $full  = $homeNames[$book->slug] ?? $book->name;
                                    $short = $homeShortNames[$book->slug] ?? null;
                                    $label = $short
                                        ? '<span class="bk-full">'.e($full).'</span><span class="bk-short">'.e($short).'</span>'
                                        : e($full);

                                    // A book links wherever it actually exists. $linkTranslation
                                    // holds the chosen translation slug per book_id: the reader's
                                    // current translation if it has the book, otherwise the best
                                    // available fallback (e.g. KJV reader clicking Psalm 151 lands
                                    // in WEB). No key here = no verses anywhere yet = "soon".
                                    $linkTo = $linkTranslation[$book->id] ?? null;
                                @endphp
                                @if ($linkTo)
                                    <li><a class="book live trace{{ $short ? ' has-short' : '' }}"
                                           href="{{ route('bible.book', [$linkTo, $book->slug]) }}">{!! $label !!}</a></li>
                                @else
                                    <li><span class="book soon{{ $short ? ' has-short' : '' }}">{!! $label !!}</span></li>
                                @endif
                            @endif
                        @endforeach
                    </ul>
                @endforeach
                </div>
            @endforeach
        </section>
    @endforeach
@endsection

@section('scripts')
<script>
    /* =====================================================================
       hp-trace r1 — THE TRACE ENGINE (homepage only, for now)
       ---------------------------------------------------------------------
       Drops one SVG path into every .trace element and keeps it measured.

       Path geometry (clockwise, matching the design spec): START at the
       bottom-right corner, travel the bottom edge to the bottom-LEFT
       corner, round it, up the left side, round the top-left, across the
       top, round the top-right, down the right side, and round the
       bottom-right corner home. Rounded corners track the element's own
       border-radius, so the line hugs the cell shape exactly.

       Resting dash = the bottom edge's length (the colour line). Hover
       flips the dash to the full perimeter; the CSS transition does the
       drawing and the retreat. Both numbers land in --trace-rest /
       --trace-full per element, because the bottom edge's share of the
       perimeter changes with cell width.

       No ResizeObserver (ancient browser) → bail entirely: the CSS
       fallback (static border line + scale hover) is already on screen.
       ===================================================================== */
    (function () {
        'use strict';
        if (!('ResizeObserver' in window)) return;

        var els = document.querySelectorAll('.trace');
        if (!els.length) return;

        var SVGNS  = 'http://www.w3.org/2000/svg';
        var STROKE = 3;   // keep in step with the CSS stroke-width

        function measure(el) {
            var path = el._tracePath;
            if (!path) return;

            var w = el.offsetWidth, h = el.offsetHeight;
            if (!w || !h) return;

            var cs = getComputedStyle(el);
            var m  = STROKE / 2;   // centre the stroke on the border zone
            var r  = parseFloat(cs.borderTopLeftRadius) || 0;
            r = Math.max(0, Math.min(r, Math.min(w, h) / 2) - m);

            // The SVG's origin is the PADDING box (absolute positioning
            // resolves against it), but w/h are BORDER-box numbers — so
            // every coordinate must shift up-left by the border widths,
            // or the whole trace rides 1px low-right of the chrome.
            var bl = parseFloat(cs.borderLeftWidth) || 0;
            var bt = parseFloat(cs.borderTopWidth)  || 0;

            function X(v) { return (v - bl).toFixed(2); }
            function Y(v) { return (v - bt).toFixed(2); }

            var d = 'M' + X(w - m - r) + ' ' + Y(h - m) +
                    'L' + X(m + r)     + ' ' + Y(h - m) +
                    'A' + r + ' ' + r + ' 0 0 1 ' + X(m) + ' ' + Y(h - m - r) +
                    'L' + X(m)         + ' ' + Y(m + r) +
                    'A' + r + ' ' + r + ' 0 0 1 ' + X(m + r) + ' ' + Y(m) +
                    'L' + X(w - m - r) + ' ' + Y(m) +
                    'A' + r + ' ' + r + ' 0 0 1 ' + X(w - m) + ' ' + Y(m + r) +
                    'L' + X(w - m)     + ' ' + Y(h - m - r) +
                    'A' + r + ' ' + r + ' 0 0 1 ' + X(w - m - r) + ' ' + Y(h - m) +
                    'Z';
            path.setAttribute('d', d);

            el.style.setProperty('--trace-rest', Math.max(0, w - 2 * m - 2 * r).toFixed(1));
            el.style.setProperty('--trace-full', (path.getTotalLength() + 2).toFixed(1));
        }

        // One observer, rAF-batched: font swap, grid reflow, rotation —
        // any size change re-measures only the cells that moved.
        var raf = null, dirty = [];
        var ro = new ResizeObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                if (dirty.indexOf(entries[i].target) === -1) dirty.push(entries[i].target);
            }
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(function () {
                raf = null;
                for (var j = 0; j < dirty.length; j++) measure(dirty[j]);
                dirty.length = 0;
            });
        });

        Array.prototype.forEach.call(els, function (el) {
            var svg  = document.createElementNS(SVGNS, 'svg');
            svg.setAttribute('class', 'trace-svg');
            svg.setAttribute('aria-hidden', 'true');
            var path = document.createElementNS(SVGNS, 'path');
            svg.appendChild(path);
            el.appendChild(svg);
            el._tracePath = path;

            measure(el);                    // dash vars set BEFORE the swap…
            el.classList.add('trace-on');   // …so the line never blinks out
            ro.observe(el);
        });
    })();
</script>

{{-- hp-demo r1: the hero card's engine — a faithful port of
     focus-synthesis.js's card behaviour for this one fixed verse. If the
     reader's card behaviour changes there, revisit this port. Wrapped in
     the same guard as the markup so no dead script ships without it. --}}
@if ($demo ?? null)
<script>
    (function () {
        'use strict';
        const card = document.getElementById('hp-demo');
        if (!card) return;

        const DEMO  = @json($demo);
        const stage = card.querySelector('.card-faces');
        const front = stage.querySelector('.face-front');
        const back  = stage.querySelector('.face-back');
        const flip  = document.getElementById('hp-demo-flip');
        const copy  = document.getElementById('hp-demo-copy');

        const IL = { langs: {}, credits: {}, verse: null };
        const pinned = new Set();

        const el = (tag, cls) => {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            return n;
        };

        /* ---- copy plumbing (ported) ---------------------------------- */
        const copyToClipboard = async (text) => {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }
            } catch (_) { /* fall through to the legacy path */ }
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch (_) { return false; }
        };

        const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        const flashDone = (btn) => {
            const original = btn._icon || (btn._icon = btn.innerHTML);
            btn.classList.add('is-done');
            btn.innerHTML = ICON_CHECK;
            clearTimeout(btn._doneTimer);
            btn._doneTimer = setTimeout(() => {
                btn.classList.remove('is-done');
                btn.innerHTML = original;
            }, 1400);
        };

        /* ---- the back face (ported buildBack, single verse) ----------- */
        const fillTranslit = (w, val) => {
            val.split('.').forEach((syl, si) => {
                if (si > 0) {
                    const sep = el('span', 'syl-sep');
                    sep.textContent = '\u00B7';
                    w.appendChild(sep);
                }
                if (syl) w.appendChild(document.createTextNode(syl));
            });
        };

        const group = (k) => back.querySelectorAll('.w[data-k="' + k + '"]');
        const repaintPins = () => {
            back.querySelectorAll('.w.pin').forEach(x => x.classList.remove('pin'));
            pinned.forEach(k => group(k).forEach(x => x.classList.add('pin')));
        };

        const buildBack = (v) => {
            back.innerHTML = '';
            const lang = IL.langs[v.lang] || { name: 'Original', rtl: false };
            const block = el('div', 'iface-verse');
            const rows = [
                [lang.name,         'row-original', !!lang.rtl, 0],
                ['Transliteration', 'row-translit', false,      1],
                ['Literal',         'row-gloss',    false,      2],
            ];
            rows.forEach(([label, cls, rtl, col]) => {
                const wrap = el('div', 'iface-row');
                const lab = el('span', 'iface-label');
                lab.textContent = label;
                wrap.appendChild(lab);
                const line = el('div', cls);
                if (rtl) line.setAttribute('dir', 'rtl');
                v.tokens.forEach((tok, i) => {
                    const w = el('span', 'w');
                    w.dataset.k = '1:' + i;
                    const val = tok[col] || '\u00B7';
                    if (cls === 'row-translit' && val.includes('.')) {
                        fillTranslit(w, val);
                    } else {
                        w.textContent = val;
                    }
                    line.appendChild(w);
                    if (i < v.tokens.length - 1) {
                        line.appendChild(document.createTextNode(' '));
                    }
                });
                wrap.appendChild(line);
                block.appendChild(wrap);
            });
            back.appendChild(block);

            // Attribution, CC BY (required) — the reader's exact format.
            const credit = el('div', 'iface-credit');
            const c = IL.credits[v.source] || {};
            const n = v.tokens.length;
            credit.appendChild(document.createTextNode(
                n + ' ' + lang.name + ' ' + (n === 1 ? 'word' : 'words') + ' from ' +
                (c.provider ? c.provider + ' ' : '') + (c.short || v.source)));
            if (c.license) {
                credit.appendChild(document.createTextNode(' \u00B7 ' + c.license));
            }
            if (c.url) {
                credit.appendChild(document.createTextNode(' \u00B7 Source: '));
                const a = el('a');
                a.href = c.url; a.target = '_blank'; a.rel = 'noopener';
                a.textContent = c.url.replace(/^https?:\/\/(www\.)?/, '').split('/')[0];
                credit.appendChild(a);
            }
            back.appendChild(credit);

            // Word-group highlight: hover previews, click pins (toggle).
            back.addEventListener('mouseover', (e) => {
                const w = e.target.closest('.w');
                if (w) group(w.dataset.k).forEach(x => x.classList.add('hl'));
            });
            back.addEventListener('mouseout', (e) => {
                const w = e.target.closest('.w');
                if (w) group(w.dataset.k).forEach(x => x.classList.remove('hl'));
            });
            back.addEventListener('click', (e) => {
                const w = e.target.closest('.w');
                if (!w) return;
                pinned.has(w.dataset.k) ? pinned.delete(w.dataset.k) : pinned.add(w.dataset.k);
                repaintPins();
            });
        };

        /* ---- flip (ported: lazy fetch, frozen-height glide) ----------- */
        const syncFaces = () => {
            const active = card.classList.contains('is-flipped') ? back : front;
            stage.style.height = active.scrollHeight + 'px';
        };

        const onFlip = async () => {
            const goingToBack = !card.classList.contains('is-flipped');
            if (goingToBack && !back.dataset.ready) {
                flip.disabled = true;
                try {
                    const res = await fetch(DEMO.url, { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) throw new Error('interlinear ' + res.status);
                    const data = await res.json();
                    Object.assign(IL.langs,   data.langs   || {});
                    Object.assign(IL.credits, data.credits || {});
                    IL.verse = (data.verses || {})['1'];
                    if (!IL.verse) throw new Error('no tokens');
                    buildBack(IL.verse);
                    back.dataset.ready = '1';
                } catch (_) {
                    back.textContent = 'Could not load the original text. Try again.';
                } finally {
                    flip.disabled = false;
                }
            }

            // Freeze the current height so auto-to-px doesn't skip the
            // animation, then swap faces and glide to the new height.
            stage.style.height = stage.offsetHeight + 'px';
            void stage.offsetHeight;

            card.classList.toggle('is-flipped');
            const flipped = card.classList.contains('is-flipped');
            flip.setAttribute('aria-pressed', flipped ? 'true' : 'false');
            flip.setAttribute('title', flipped ? 'Show translation' : 'Show ' + DEMO.lang);
            front.classList.toggle('is-active', !flipped);
            back.classList.toggle('is-active', flipped);
            syncFaces();
        };
        flip.addEventListener('click', onFlip);

        /* ---- side-aware copy (the reader's two text formats) ----------- */
        copy.addEventListener('click', async () => {
            let text;
            if (card.classList.contains('is-flipped') && IL.verse) {
                const line = (col) => IL.verse.tokens.map(t => t[col] || '\u00B7').join(' ');
                const lang = (IL.langs[IL.verse.lang] || {}).name || 'Original';
                text = DEMO.ref + ' (' + lang + ')\n' +
                       line(0) + '\n' + line(1) + '\n' + line(2) + '\n\n';
            } else {
                text = DEMO.ref + '\n' + front.textContent.trim() + '\n\n\u2014 ' + DEMO.tx;
            }
            const ok = await copyToClipboard(text);
            if (ok) flashDone(copy);
        });

        /* Clicking outside the card clears pinned words — the reader's
           global click-to-clear habit. */
        document.addEventListener('click', (e) => {
            if (!card.contains(e.target) && pinned.size) {
                pinned.clear();
                repaintPins();
            }
        });

        /* Wrapping changes both faces' natural heights. */
        let hpResizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(hpResizeTimer);
            hpResizeTimer = setTimeout(() => {
                if (stage.style.height) syncFaces();
            }, 120);
        });
    })();
</script>
@endif
@endsection
