@extends('layouts.app')

@section('title', 'Why 91 Books? — MEGABIBLE.net')

{{-- ============================================================
     WHY 91 BOOKS?  ·  /extras/why-91-books  ·  w91 r2
     ------------------------------------------------------------
     The answer to the question every visitor asks. Anatomy mirrors
     About/Support — hero, accent section heads, prose — and then
     the centerpiece: a vertical, scrollable timeline from 1000 BC
     to 450 AD.

     r2 layout: ALL group cards stack in one column LEFT of the
     dated spine; ALL composition-window bars (whiskers) cluster
     RIGHT of it — visible at every width, mobile included. The
     spine carries the witnesses and milestones as chips. As each
     group's whisker climbs past the middle of the screen, its
     books are "collected" into the sticky totals, which land on
     57 + 34 = 91. Scrolling back down un-collects them — the
     tally is a pure function of scroll.

     r2 popovers: the book hub's panel chrome (chevron, hover
     lift, poke-on-click), ported from book.blade. Group panels
     list their books in the book font, linked into the reader
     wherever the book has verses. A panel click does nothing but
     pulse — the hub's terminal-panel contract.

     Everything positional arrives pre-computed from
     WhyBooksController (dates, lanes, pre-packed card tops); the
     markup below is a dumb painter. The page script re-packs
     cards with real rendered heights, draws the card-to-whisker
     connectors, runs the tally, and opens the popovers.

     Tripwires: --w91-rev token below, and the console line
     "[w91] r2.1" from the script block.
     ============================================================ --}}
@section('styles')
<style>
    /* ---- shared with About / Support / How-to ---- */
    .page-hero{margin:.5rem 0 1.6rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.18rem;line-height:1.6;margin:0 0 1rem;}
    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}
    .prose a:not(.btn){color:var(--accent);font-weight:600;text-decoration:none;}
    .prose a:not(.btn):hover{text-decoration:underline;text-underline-offset:.15em;}
    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;
        text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);
        color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}
    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    .w91-note{font-family:var(--sans);font-size:.85rem;color:var(--muted);margin:.4rem 0 0;}

    /* =========================================================
       THE TIMELINE — w91 r2
       ---------------------------------------------------------
       LAYOUT KNOBS (all here):
         --w91-spine-x  the spine's horizontal position
         --w91-gutl     spine → the card column's right edge
         --w91-gutr     spine → the first whisker lane
         --w91-lane     horizontal step per whisker lane
         --w91-whisk    whisker thickness
       The gut values keep the spine chips (max-width below) clear
       of both cards and bars. Vertical geometry (tops/heights) is
       inline from the controller; the era px-per-year scale lives
       there.
       ========================================================= */
    .w91{
        --w91-rev:2.1;            /* deployment tripwire */
        --w91-spine-x:58%;
        --w91-gutl:72px;
        --w91-gutr:68px;
        --w91-lane:16px;
        --w91-whisk:11px;
        position:relative;
        font-family:var(--sans);
        margin:1.6rem 0 0;
    }

    /* ---- The sticky tally bar ----
       Numbers are inline-block so the pulse transform actually
       applies. The collection line itself is mid-viewport (see the
       script's COLLECT_AT knob). */
    .w91-tally{
        position:sticky;top:0;z-index:30;
        background:var(--bg);border-bottom:2px solid var(--accent);
        padding:.5rem 0 .55rem;text-align:center;
    }
    .wt-num{display:inline-block;font-variant-numeric:tabular-nums;}
    .wt-total{line-height:1.1;}
    .wt-total .wt-num{font-size:2rem;font-weight:600;color:var(--accent);}
    .wt-total .wt-word{font-size:.78rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-left:.35rem;}
    .wt-sides{display:flex;margin-top:.2rem;}
    .wt-side{flex:1 1 0;min-width:0;}
    .wt-side .wt-label{display:block;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);}
    .wt-side .wt-num{font-size:1.15rem;font-weight:600;color:var(--ink);}
    .wt-num.tick{animation:w91-pulse .32s ease;}
    @keyframes w91-pulse{
        0%{transform:scale(1);}
        40%{transform:scale(1.3);color:var(--accent);}
        100%{transform:scale(1);}
    }

    /* ---- The body: one tall positioned canvas ---- */
    .w91-body{position:relative;}

    /* Century gridlines + date labels (labels hug the right edge,
       above the whiskers, with a page-coloured backing so bars duck
       behind the text — the timeline partial's event-label move). */
    .w91-gridline{position:absolute;left:0;right:0;height:1px;background:var(--rule);opacity:.45;z-index:0;}
    .w91-gridlabel{
        position:absolute;right:0;transform:translateY(-55%);
        font-size:.72rem;color:var(--muted);
        background:var(--bg);padding:0 4px;z-index:2;
        font-variant-numeric:tabular-nums;
    }

    /* The spine. */
    .w91-spine{position:absolute;left:var(--w91-spine-x);width:2px;margin-left:-1px;background:var(--rule);z-index:0;}

    /* ---- Whiskers: each group's composition window, clustered
       right of the spine, lane 0 nearest it. Always visible. ---- */
    .w91-whisk{
        position:absolute;width:var(--w91-whisk);border-radius:999px;
        left:calc(var(--w91-spine-x) + var(--w91-gutr) + (var(--ln,0) * var(--w91-lane)));
        background:var(--cg,var(--tl-clay));
        box-shadow:inset 0 0 0 1px rgba(42,31,23,.15);
        z-index:1;transition:opacity .25s;
    }
    .w91-whisk.is-got{opacity:.4;}

    /* Connectors (drawn by the script): card edge → its whisker. */
    .w91-conn{position:absolute;z-index:1;opacity:.5;pointer-events:none;}

    /* ---- Group cards: one packed column left of the spine ---- */
    .w91-col{
        position:absolute;top:0;bottom:0;z-index:2;
        right:calc(100% - var(--w91-spine-x) + var(--w91-gutl));
        width:min(240px, calc(var(--w91-spine-x) - var(--w91-gutl)));
    }

    .w91-card{
        position:absolute;left:0;right:0;
        background:var(--bg);color:var(--ink);
        border:1px solid var(--rule);border-top:4px solid var(--cg,var(--tl-clay));
        border-radius:8px;padding:.6rem .75rem .65rem;
        cursor:pointer;
        transition:border-color .12s,transform .14s ease,box-shadow .14s ease;
    }
    @media (hover:hover) and (pointer:fine){
        .w91-card:hover{border-color:var(--cg);transform:scale(1.02);box-shadow:0 4px 14px rgba(0,0,0,.08);z-index:4;}
    }
    .w91-card:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.25);}
    .wg-name{display:block;font-size:.95rem;font-weight:600;line-height:1.25;}
    .wg-count{display:flex;align-items:baseline;gap:.35rem;font-size:1.7rem;font-weight:600;line-height:1.15;color:var(--cg,var(--tl-clay));font-variant-numeric:tabular-nums;}
    .wg-count b{font-weight:400;font-size:.8rem;color:var(--ink);}
    .wg-range{display:block;font-size:.74rem;color:var(--muted);margin:.05rem 0 .45rem;}
    .wg-dots{display:flex;flex-wrap:wrap;gap:3px;}
    .wg-dots i{
        width:7px;height:7px;border-radius:50%;box-sizing:border-box;
        border:1.5px solid var(--cg,var(--tl-clay));background:transparent;
        transition:background .25s;
    }
    /* Collected: the dots fill in — the books have been banked. */
    .w91-card.is-got .wg-dots i{background:var(--cg,var(--tl-clay));}

    /* ---- Witnesses & milestones on the spine ----
       r2.1: the chips are the markers — the little accent dots that
       used to pin each exact year on the spine are gone. */
    .w91-auth{
        position:absolute;left:var(--w91-spine-x);transform:translateX(-50%);
        max-width:124px;text-align:center;
        background:var(--bg);border:1px solid var(--rule);border-radius:8px;
        padding:.3rem .5rem .35rem;cursor:pointer;z-index:3;
        transition:border-color .12s;
    }
    .w91-auth:hover{border-color:var(--accent);}
    .w91-auth:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.25);}
    .wa-name{display:block;font-size:.72rem;font-weight:600;color:var(--accent);line-height:1.25;}
    .wa-year{display:block;font-size:.66rem;color:var(--muted);margin-top:.1rem;}

    /* =========================================================
       POPOVER — the book hub's .fn-pop chrome, ported (chevron,
       hover lift, poke-on-click). If the hub's panels are ever
       retuned, retune this block to match. The panel is a
       terminal surface: clicking it pulses and goes nowhere;
       only the book links inside navigate.
       ========================================================= */
    .w91-pop{
        position:absolute;
        z-index:25;               /* above the page, below the tally (30) */
        padding:.65rem .8rem;
        background:var(--bg);
        border:1px solid var(--rule);
        border-radius:8px;
        box-shadow:0 8px 24px rgba(0,0,0,.16);
        font-family:var(--sans);
        font-size:.85rem;
        line-height:1.45;
        color:var(--muted);
        cursor:default;
        transition:transform .14s ease, background .14s ease,
                    border-color .14s ease, box-shadow .14s ease;
        transform-origin:var(--chev-x,50%) bottom;
    }
    .w91-pop.is-below{transform-origin:var(--chev-x,50%) top;}
    .w91-pop:hover{
        transform:scale(1.04);
        background:var(--panel);
        border-color:var(--muted);
        box-shadow:0 10px 28px rgba(0,0,0,.22);
    }
    .w91-pop:hover::after{background:var(--panel);}
    .w91-pop::after{
        content:"";
        position:absolute;
        left:var(--chev-x,50%);
        bottom:-5.5px;
        width:10px;height:10px;
        transform:translateX(-50%) rotate(45deg);
        background:var(--bg);
        border-right:1px solid var(--rule);
        border-bottom:1px solid var(--rule);
    }
    .w91-pop.is-below::after{
        bottom:auto;
        top:-5.5px;
        border:none;
        border-left:1px solid var(--rule);
        border-top:1px solid var(--rule);
    }
    /* The click acknowledgement — the hub's pop-poke, verbatim:
       endpoint keyframes omitted on purpose so a hovered panel
       pulses 1.04 → .955 → 1.04 with no snap. */
    .w91-pop.is-poked{animation:w91-poke .28s ease;}
    @keyframes w91-poke{
        35%{transform:scale(.955);}
    }

    /* Hub back link. Lives BELOW the head, on the scrolling surface, so it
       slides up under the sticky header with the chapter grid. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 1.2rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    /* Interiors. The long book lists scroll INSIDE .wp-scroll, so
       the panel itself keeps overflow visible and the chevron
       survives (overflow on the panel would clip it off). */
    .wp-title{display:block;font-size:.95rem;font-weight:700;color:var(--accent);}
    .wp-sub{display:block;font-size:.74rem;color:var(--muted);margin:.1rem 0 .5rem;}
    .wp-blurb{margin:0;}
    .wp-scroll{max-height:min(420px,60vh);overflow-y:auto;overscroll-behavior:contain;}
    .wp-list{list-style:none;margin:0;padding:0;}
    .wp-list.two{column-count:2;column-gap:1.2rem;}
    .wp-list li{break-inside:avoid;margin:0 0 .45rem;}
    .wp-list li:last-child{margin-bottom:0;}
    .wpb-name{
        display:block;
        font-family:var(--book-font);font-size:.98rem;line-height:1.3;
        color:var(--ink);text-decoration:none;
    }
    a.wpb-name{cursor:pointer;}
    a.wpb-name:hover{color:var(--accent);text-decoration:underline;}
    .wpb-date{display:block;font-size:.7rem;color:var(--muted);}

    /* ---- Mobile: same anatomy, tighter guts. The bars stay. ---- */
    @media (max-width:640px){
        .w91{--w91-spine-x:56%;--w91-gutl:48px;--w91-gutr:48px;--w91-lane:10px;--w91-whisk:7px;}
        .w91-auth{max-width:88px;padding:.25rem .4rem .3rem;}
        .wa-name{font-size:.66rem;}
        .wa-year{font-size:.6rem;}
        .w91-gridlabel{font-size:.64rem;}
        .w91-gridlabel.minor{display:none;}
        .w91-card{padding:.5rem .55rem .55rem;}
        .wg-name{font-size:.82rem;}
        .wg-count{font-size:1.4rem;}
        .wg-range{font-size:.68rem;}
        .wp-list.two{column-count:1;}
    }

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
    }

    @media (prefers-reduced-motion:reduce){
        .wt-num.tick{animation:none;}
        .w91-card{transition:border-color .12s;}
        .w91-card:hover{transform:none;}
        .wg-dots i,.w91-whisk{transition:none;}
        .w91-pop{transition:background .14s ease,border-color .14s ease;}
        .w91-pop:hover{transform:none;}
        .w91-pop.is-poked{animation:none;}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">Why 91 Books?</h1>
        <p class="hub-back-row"><a class="hub-back" href="{{ route('home') }}">&larr; All books</a></p>
        <p class="lead">
            It took over a millenium for the Bible to become one book with one table of contents. Its books were written
            across fourteen centuries, and the communities that kept them have always kept shelves of
            different sizes: 
            <ul>
                <li>24 books of the Hebrew Bible</li>
                <li>66 books of the Protestant Bible</li>
                <li>73 books of the Catholic Bible</li>
                <li>81 books of the Ethiopian Orthodox Bible</li>
            </ul>
        </p>
        <p>
            With varying traditions and canons one must ask, <strong>who decides which books are part of the Bible?</strong>
        </p>
    </section>

    {{-- ============ WHERE BIBLE? ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Where did the Bible come from?</h2>
        <p>
            In the beginning was the <strong>Torah</strong>. This collection of five holy books was read as authoritative scripture by the Persian period. 
            The <strong>Prophets</strong> came next, achieving canon status by 130 BC. The <strong>Writings</strong> arrived last, with many of the books 
            in this category on the fringes of canon until after the destruction of the Temple in 70 AD.
        </p>
        <p>
            Greek-speaking Jews later carried a wider collection: the books of the First Testament were translated into Greek and contained what we call 
            the <strong>Deuterocanon</strong>. Later still, visionary works like 1 Enoch and
            Jubilees were written and treasured by Jewish Essenes at Qumran and quoted in the New Testament itself. The early church
            added books of its own: Paul's letters, the gospels, and many more writings that would be both disputed and read aloud as scripture.
        </p>
        <p>
            The timeline below shows an approximation of the <x-brand/> canon formation. <strong>Tap a subgroup</strong> on the left to
            see its books. The markers on the spine are the witnesses and
            milestones relevant to the Biblical canon.
        </p>
        <p class="w91-note">Dates are scholarly approximations. Each book's own hub page carries more detailed information.</p>
    </section>

    {{-- ============ THE TIMELINE ============ --}}
    <section class="w91" id="w91">

        {{-- The tally. Server-rendered at the FINAL totals so the page
             reads correctly without JavaScript; the script zeroes it on
             load and recomputes from scroll position every frame. --}}
        <div class="w91-tally" id="w91-tally">
            <div class="wt-total">
                <span class="wt-num" id="wt-total">{{ $w91['total'] }}</span><span class="wt-word">books</span>
            </div>
            <div class="wt-sides">
                @foreach ($w91['sides'] as $sKey => $side)
                    <div class="wt-side">
                        <span class="wt-label">{{ $side['label'] }}</span>
                        <span class="wt-num" id="wt-{{ $sKey }}">{{ $side['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="w91-body" id="w91-body" style="height: {{ $w91['height'] }}px;">

            {{-- Century gridlines + date labels --}}
            @foreach ($w91['ticks'] as $t)
                <div class="w91-gridline" style="top: {{ $t['top'] }}px;"></div>
                <span class="w91-gridlabel {{ $t['major'] ? 'major' : 'minor' }}" style="top: {{ $t['top'] }}px;">{{ $t['label'] }}</span>
            @endforeach

            {{-- The spine --}}
            <div class="w91-spine" style="top: {{ $w91['spine']['top'] }}px; bottom: {{ $w91['spine']['bottom'] }}px;"></div>

            {{-- Whiskers: one per group, lane-packed right of the spine --}}
            @foreach ($w91['groups'] as $g)
                <div class="w91-whisk" id="wk-{{ $g['key'] }}"
                     data-count="{{ $g['count'] }}" data-side="{{ $g['side'] }}" data-card="card-{{ $g['key'] }}"
                     style="--cg: var(--tl-{{ $g['color'] }}); --ln: {{ $g['lane'] }}; top: {{ $g['top'] }}px; height: {{ $g['height'] }}px;"></div>
            @endforeach

            {{-- Group cards: one packed column, left of the spine --}}
            <div class="w91-col">
                @foreach ($w91['groups'] as $g)
                    <div class="w91-card" id="card-{{ $g['key'] }}" tabindex="0" role="button" aria-expanded="false"
                         aria-label="{{ $g['full'] }}: {{ $g['count'] }} books, {{ $g['range'] }}. Opens the book list."
                         data-pop="{{ $g['key'] }}" data-whisk="wk-{{ $g['key'] }}"
                         data-top="{{ $g['card_tgt'] }}"
                         style="--cg: var(--tl-{{ $g['color'] }}); top: {{ $g['card_top'] }}px;">
                        <span class="wg-name">{{ $g['label'] }}</span>
                        <span class="wg-count">{{ $g['count'] }} <b>books</b></span>
                        <span class="wg-range">{{ $g['range'] }}</span>
                        <span class="wg-dots" aria-hidden="true">@for ($i = 0; $i < $g['count']; $i++)<i></i>@endfor</span>
                    </div>
                @endforeach
            </div>

            {{-- Witness chips (packed below their dots by the script) --}}
            @foreach ($w91['witnesses'] as $a)
                <div class="w91-auth" tabindex="0" role="button" aria-expanded="false"
                     aria-label="{{ $a['name'] }}, {{ $a['year'] }}. Opens the testimony."
                     data-pop="auth-{{ $a['key'] }}" data-top="{{ $a['chip_tgt'] }}"
                     style="top: {{ $a['chip_top'] }}px;">
                    <span class="wa-name">{{ $a['name'] }}</span>
                    <span class="wa-year">{{ $a['year'] }}</span>
                </div>
            @endforeach

        </div>
    </section>

    <hr class="divider">

    {{-- ============ CLOSING ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">91 books of scriptural authority and tradition</h2>
        <p>
            Fifty-seven books of the First Testament, thirty-four of the Second Testament. These 91 books are not a 
            demand on the reader's beliefs. The texts are here to read and learn about what ancient Jews and Christians kept and recited as sacred.
        </p>
        <div class="cta-row">
            <a class="btn" href="{{ url('/') }}">Read the Bible</a>
            <a class="btn btn-ghost" href="{{ route('about') }}">About <x-brand/></a>
        </div>
    </section>

@endsection

@section('scripts')
<script>
    /* =====================================================================
       w91 r2 — the page engine. Three cooperating pieces, all vanilla ES5:
         1. LAYOUT  packs cards (and spine chips) by their target tops
                    with real rendered heights, then draws the L-shaped
                    connectors from each card rightward to its whisker.
         2. TALLY   recomputes the three counts from scroll position every
                    frame (rAF-throttled). r2: a group is collected when
                    its whisker's bottom climbs past the COLLECT_AT line
                    (mid-viewport), so the totals complete well before the
                    page runs out of scroll on desktop.
         3. POPOVER the hub's panel contract: hover opens on fine pointers
                    (delay + grace window), tap toggles on touch, Escape
                    and tap-away close. The panel is terminal — clicking
                    it just pokes — but the book links inside navigate.
       ===================================================================== */
    (function () {
        'use strict';
        console.log('[w91] r2.1');

        var body  = document.getElementById('w91-body');
        var tally = document.getElementById('w91-tally');
        if (!body || !tally) return;

        var POP = @json($w91Pop);

        var CARD_GAP   = 12;    // min vertical gap between packed cards
        var CHIP_GAP   = 8;     // min vertical gap between spine chips
        var BRANCH_Y   = 24;    // the connector leaves a card this far below its top
        var COLLECT_AT = 0.5;   // the collection line, as a viewport fraction

        var cards  = [].slice.call(body.querySelectorAll('.w91-card'));
        var chips  = [].slice.call(body.querySelectorAll('.w91-auth'));
        var whisks = [].slice.call(body.querySelectorAll('.w91-whisk'));
        var baseH  = parseFloat(body.style.height) || body.offsetHeight;

        /* ---- 1. LAYOUT -------------------------------------------------- */

        // Push-down packer: sort by target top, keep each element at its
        // target unless the one above needs the room.
        function pack(els, gap) {
            var items = [], i, top, h, bottom = 0, cursor = -1e9;
            for (i = 0; i < els.length; i++) {
                items.push({ el: els[i], tgt: parseFloat(els[i].getAttribute('data-top')) || 0 });
            }
            items.sort(function (a, b) { return a.tgt - b.tgt; });
            for (i = 0; i < items.length; i++) {
                top = Math.max(items[i].tgt, cursor);
                items[i].el.style.top = top + 'px';
                h = items[i].el.offsetHeight;
                cursor = top + h + gap;
                if (top + h > bottom) bottom = top + h;
            }
            return bottom;
        }

        function drawConns() {
            var old = body.querySelectorAll('.w91-conn');
            for (var k = old.length - 1; k >= 0; k--) old[k].parentNode.removeChild(old[k]);

            var bb = body.getBoundingClientRect();

            function seg(x, y, w, h, color) {
                var d = document.createElement('div');
                d.className = 'w91-conn';
                d.style.left = x + 'px';
                d.style.top = y + 'px';
                d.style.width = w + 'px';
                d.style.height = h + 'px';
                d.style.background = color;
                body.appendChild(d);
            }

            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var wk = document.getElementById(card.getAttribute('data-whisk'));
                if (!wk) continue;

                var cr = card.getBoundingClientRect();
                var wr = wk.getBoundingClientRect();
                var color = getComputedStyle(wk).backgroundColor;

                var y   = cr.top - bb.top + BRANCH_Y;
                var wcx = wr.left + wr.width / 2 - bb.left;
                var x1  = cr.right - bb.left;

                // Horizontal branch: card's right edge → whisker center line.
                seg(x1, y, Math.max(0, wcx - x1), 2, color);

                // Vertical riser when the branch leaves the whisker's span
                // (a card packed below its group's window, or above it).
                var wt = wr.top - bb.top, wb = wr.bottom - bb.top;
                if (y < wt)      seg(wcx - 1, y, 2, wt - y, color);
                else if (y > wb) seg(wcx - 1, wb, 2, y - wb + 2, color);
            }
        }

        function layout() {
            var bottom = pack(cards, CARD_GAP);
            var cb = pack(chips, CHIP_GAP);
            if (cb > bottom) bottom = cb;

            // Never let packed content run off the canvas.
            body.style.height = Math.max(baseH, bottom + 72) + 'px';

            drawConns();
        }

        /* ---- 2. TALLY ---------------------------------------------------- */

        var numFt  = document.getElementById('wt-ft');
        var numSt  = document.getElementById('wt-st');
        var numTot = document.getElementById('wt-total');

        function setNum(el, n) {
            if (!el || el.textContent === String(n)) return;
            el.textContent = n;
            el.classList.remove('tick');
            void el.offsetWidth;            // restart the pulse
            el.classList.add('tick');
        }

        function update() {
            // r2: the collection line is mid-viewport — a group banks the
            // moment its bar's lower end climbs past the middle of the
            // screen. (It can never sit above the tally, which hugs the top.)
            var line = document.documentElement.clientHeight * COLLECT_AT;
            var got = { ft: 0, st: 0 };
            for (var i = 0; i < whisks.length; i++) {
                var w = whisks[i];
                var collected = w.getBoundingClientRect().bottom <= line;
                var card = document.getElementById(w.getAttribute('data-card'));
                if (collected) {
                    got[w.getAttribute('data-side')] += parseInt(w.getAttribute('data-count'), 10) || 0;
                    w.classList.add('is-got');
                    if (card) card.classList.add('is-got');
                } else {
                    w.classList.remove('is-got');
                    if (card) card.classList.remove('is-got');
                }
            }
            setNum(numFt, got.ft);
            setNum(numSt, got.st);
            setNum(numTot, got.ft + got.st);
        }

        var ticking = false;
        function onScroll() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () { update(); ticking = false; });
        }

        /* ---- boot + re-layout ------------------------------------------- */

        layout();
        update();

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('load', function () { layout(); update(); });

        if ('ResizeObserver' in window) {
            var raf = null;
            new ResizeObserver(function () {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(function () { layout(); update(); });
            }).observe(body);
        } else {
            window.addEventListener('resize', function () { layout(); update(); });
        }

        /* ---- 3. POPOVER -------------------------------------------------- */

        var SHOW_DELAY = 120, HIDE_GRACE = 250;
        var W_GROUP = 340, W_AUTH = 300;   // panel widths, viewport-clamped
        var hoverFine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        var pop = null, owner = null, showTimer = 0, hideTimer = 0;

        function closePop() {
            clearTimeout(showTimer);
            clearTimeout(hideTimer);
            if (pop && pop.parentNode) pop.parentNode.removeChild(pop);
            pop = null;
            if (owner) { owner.setAttribute('aria-expanded', 'false'); owner = null; }
        }
        function scheduleHide() { clearTimeout(hideTimer); hideTimer = setTimeout(closePop, HIDE_GRACE); }
        function cancelHide() { clearTimeout(hideTimer); }

        document.addEventListener('mb:pop-open', function (e) {
            if (!e.detail || e.detail.source !== 'w91') closePop();
        });

        // The click acknowledgement: restart the poke pulse (remove,
        // reflow, re-add — the reflow read lets it replay every click).
        function poke(el) {
            el.classList.remove('is-poked');
            void el.offsetWidth;
            el.classList.add('is-poked');
        }

        function addSpan(parent, cls, text) {
            var el = document.createElement('span');
            el.className = cls;
            el.textContent = text;
            parent.appendChild(el);
        }

        function buildPanel(d) {
            var el = document.createElement('div');
            el.className = 'w91-pop';
            addSpan(el, 'wp-title', d.t);
            if (d.s) addSpan(el, 'wp-sub', d.s);
            if (d.books) {
                var scroll = document.createElement('div');
                scroll.className = 'wp-scroll';
                var ul = document.createElement('ul');
                ul.className = 'wp-list' + (d.books.length > 8 ? ' two' : '');
                for (var i = 0; i < d.books.length; i++) {
                    var li = document.createElement('li');
                    var name;
                    if (d.books[i][2]) {
                        name = document.createElement('a');
                        name.href = d.books[i][2];
                    } else {
                        name = document.createElement('span');
                    }
                    name.className = 'wpb-name';
                    name.textContent = d.books[i][0];
                    li.appendChild(name);
                    addSpan(li, 'wpb-date', d.books[i][1]);
                    ul.appendChild(li);
                }
                scroll.appendChild(ul);
                el.appendChild(scroll);
            } else if (d.b) {
                var p = document.createElement('p');
                p.className = 'wp-blurb';
                p.textContent = d.b;
                el.appendChild(p);
            }
            el.addEventListener('mouseenter', cancelHide);
            el.addEventListener('mouseleave', scheduleHide);
            return el;
        }

        function openPop(anchor) {
            closePop();
            var d = POP[anchor.getAttribute('data-pop')];
            if (!d) return;
            var el = buildPanel(d);
            document.body.appendChild(el);

            // Position: centred above the anchor, viewport-clamped, flipped
            // below when there's no headroom, chevron tracking the anchor
            // even when the panel is clamped — the source-popover math.
            var vw = document.documentElement.clientWidth;
            var w = Math.min(d.books ? W_GROUP : W_AUTH, vw - 16);
            el.style.width = w + 'px';
            var h = el.offsetHeight;
            var r = anchor.getBoundingClientRect();

            var left = r.left + r.width / 2 - w / 2 + window.scrollX;
            left = Math.max(window.scrollX + 8,
                   Math.min(left, window.scrollX + vw - w - 8));
            var below = r.top < h + 18;
            el.classList.toggle('is-below', below);
            el.style.left = left + 'px';
            el.style.top  = (below
                ? r.bottom + window.scrollY + 10
                : r.top    + window.scrollY - h - 10) + 'px';
            el.style.setProperty('--chev-x',
                (r.left + r.width / 2 + window.scrollX - left) + 'px');

            pop = el;
            owner = anchor;
            anchor.setAttribute('aria-expanded', 'true');
            document.dispatchEvent(new CustomEvent('mb:pop-open', { detail: { source: 'w91' } }));
        }

        if (hoverFine) {
            document.addEventListener('mouseover', function (e) {
                var a = e.target.closest('[data-pop]');
                if (a) {
                    cancelHide();
                    clearTimeout(showTimer);
                    if (owner === a) return;
                    showTimer = setTimeout(function () { openPop(a); }, SHOW_DELAY);
                    return;
                }
                if (e.target.closest('.w91-pop')) return;
                clearTimeout(showTimer);
                if (pop) scheduleHide();
            });
        }

        document.addEventListener('click', function (e) {
            // Inside the panel: book links navigate; anywhere else on the
            // panel is terminal — it pulses and goes nowhere (the hub's
            // definition-panel contract).
            var panel = e.target.closest('.w91-pop');
            if (panel) {
                if (e.target.closest('a')) return;   // let the link do its thing
                poke(panel);
                return;
            }

            var a = e.target.closest('[data-pop]');
            if (a) {
                if (hoverFine) {
                    // Hover owns open/close on desktop; a click on the open
                    // anchor is the same dead end as a click on the panel.
                    if (owner === a && pop) { poke(pop); } else { openPop(a); }
                } else {
                    // Touch: tap toggles.
                    if (owner === a && pop) { closePop(); } else { openPop(a); }
                }
                return;
            }

            if (pop) closePop();   // tap-away (mobile's only close gesture)
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { if (pop) closePop(); return; }
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var a = e.target && e.target.closest && e.target.closest('[data-pop]');
            if (!a) return;
            e.preventDefault();
            if (owner === a) { closePop(); } else { openPop(a); }
        });
    })();
</script>
@endsection
