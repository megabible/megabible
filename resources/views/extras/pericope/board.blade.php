@extends('layouts.app')

@section('title', 'Pericope — MEGABIBLE.net')

@section('styles')
<style>
    /* ================================================================
       PERICOPE BOARD  ·  /extras/pericope/{slug}
       A client-rendered view of one board's cards (grid layout, add order).
       The server can't know the slug's contents (they live in localStorage),
       so it renders this shell for ANY slug; the script resolves it against
       window.MBPericope and shows the board, an empty state, or "not found".
       Like acts-of-the-user, content runs in app.blade's .container (no page
       wrapper of its own).

       STICKY HEAD (Phase 1)
       ---------------------
       The title row is the site's shared sticky head (bible.partials.sticky-head +
       sticky-head.js): board name (click to rename), "23 verses from 3
       books" subtitle, and the apps folder in the corner — home / undo /
       redo, zoom / edit / share, and Aa all ride in its pill
       (components/head-folder). Two board-
       specific overrides on .chapter-head below: the head bleeds to the
       VIEWPORT edge (not the container edge) so cards on the full-bleed strip
       never scroll past its sides, and the corner cluster is pushed back in by
       the same amount. Both read --pb-gutter, published by the script.

       CARD DISPLAY
       ------------
       Each verse card has two states, toggled by a click on its body:
         • COLLAPSED (default) — exactly ONE SLOT tall (see GRID MODEL): a
           QuickNav-style coloured cell holding the book's short label +
           chapter:verse, and the first ~48 characters of the verse on one
           line.
         • EXPANDED — the fully spelled-out reference with a thick colour
           underline instead of the cell, the translation line under it, and
           the whole verse. A round "collapse" button in the corner shrinks it
           again.
       In BOTH states the reference (cell or spelled-out) plus a small ↗ is one
       link to the reader — and it is the ONLY link on the card, so grabbing
       the grip or tapping the body can never accidentally navigate (Phase 2).
       Expanded state persists to the store as `exp` (a quiet write).

       EDIT MODE (Phase 5 — public/js/pericope-edit.js)
       ------------------------------------------------
       The scissors button toggles edit mode: cards shimmy (amplitude from
       prefs.shimmy via --pb-shimmy; stagger by nth-child; off entirely under
       prefers-reduced-motion), a tap SELECTS (the reader's --rule wash), and
       the edit FAB (Group / Trash / Done) rises from the bottom — its chrome
       is the shared bible.partials.fab-styles, the same bar the chapter
       reader uses. Groups render as derived bounding-box outlines behind
       their member cards with a colour-chip label above; in edit mode the
       chip selects the whole group and its pencil opens rename / recolor /
       ungroup. Mode matrix: normal tap=expand grip=drag · zoom whole-card
       drag · edit tap=select grip=drag · edit+zoom tap=select, NO drag (zoom
       as edit's survey view).

       ZOOM (Phase 4)
       --------------
       One fixed level (--pb-zoom, 0.6 from the script's ZOOM_OUT knob),
       toggled by the head's magnifier or — on touch — automatically for the
       length of a drag. Mechanically: .pb-zoomwrap is the full-bleed box the
       strip used to be; when #pb-board.is-zoomed it scales its contents from
       its top-left, the strip inside is laid out 1/zoom wider so it still
       spans the viewport (showing more columns), and the script sets the
       wrapper's height to the scaled height so nothing dead is left below.
       The script keeps the point under the pointer fixed across the change
       by compensating scrollLeft and the page scroll. Zoomed cards are
       INERT: drag only, no expand/collapse/switch/delete/link.

       GRID MODEL (Phase 2)
       --------------------
       One row unit = one SLOT = the height of a collapsed card. A collapsed
       card is rh:1; an expanded or tall card spans ceil rows (reflow() measures
       and writes `rh` back). The dot grid marks slot corners and is always
       faintly present (a preference; see DOT GRID below), strengthening while
       a card is being placed.

       DRAG (Phase 3 — public/js/pericope-drag.js)
       -------------------------------------------
       Pointer-Events based (mouse + touch, one code path), and ONLY starts
       from the grip. A faint ghost of the card rides the pointer, one
       dashed indicator marks the target cell, and the other cards shuffle
       LIVE to show where the push-down rule would put them, snapping back as
       the target moves on. Nothing is written until the drop.
       ================================================================ */

    @include('bible.partials.sticky-head')
    @include('bible.partials.fab-styles')
    @include('bible.partials.present-styles')

    /* ---- Board head overrides ------------------------------------------
       --pb-gutter is the distance from the viewport's left edge to the
       container's content edge, in px, set by the script (fallback 1.5rem =
       the container's own padding, i.e. the partial's default). Using it as
       the bleed stretches the head's background to the viewport on both
       sides; using it as the cluster's `right` cancels that bleed so the
       buttons still sit on the content edge, exactly where every other page
       puts them. */
    .chapter-head {
        --mb-head-bleed: var(--pb-gutter, 1.5rem);
        /* The corner cluster is just the folder circle when shut: 40px +
           the cluster's right offset ≈ 4.5rem. (Was 15.5rem with four loose
           tool circles; a long board name gets that room back.) The OPEN
           pill grows left over the title — by design. */
        --mb-head-reserve: 4.5rem;
    }
    .chapter-head .head-actions { right: var(--pb-gutter, 1.5rem); }
    /* The open pill may grow left to within .5rem of the viewport edge;
       the cluster's own right offset is the gutter, so tell the folder. */
    .chapter-head .head-folder { --fld-edge: var(--pb-gutter, 1.5rem); }

    /* Toolbar buttons live in the head folder (components/head-folder —
       .fld-app supplies the chrome). Only the zoom glyph's state rule is
       page-specific: the magnifier reads "−" (zoom out) at rest; the extra
       vertical stroke turns it into "+" (zoom back in) while zoomed. */
    #pb-zoom .pb-zoomv { display: none; }
    #pb-zoom.is-active .pb-zoomv { display: block; }

    /* Title row — the h1 + rename pencil. Type size comes from the partial's
       --mb-head-title / -stuck knobs, so the name AND the inline rename input
       shrink together when the head pins. */
    .pb-head { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .pb-name { color: var(--ink); cursor: text; }
    .pb-name-input {
        font-family: var(--serif); font-size: var(--mb-head-title); font-weight: 400; letter-spacing: -.01em;
        color: var(--ink);
        border: none; border-bottom: 2px solid var(--accent); background: none;
        padding: 0; min-width: 8rem;
    }
    .chapter-head.is-stuck .pb-name-input { font-size: var(--mb-head-title-stuck); }
    .pb-name-input:focus { outline: none; }
    .pb-edit {
        border: none; background: none; cursor: pointer; color: var(--muted);
        padding: .2rem; display: inline-flex; align-items: center;
    }
    .pb-edit:hover { color: var(--accent); }
    .pb-edit svg { width: 16px; height: 16px; }

    /* Flavour line — "23 verses from 3 books" — is the partial's .subtitle;
       it stays pinned with the head. */

    /* Back link lives BELOW the head, on the scrolling surface (the vigil-book
       pattern), so it slides up under the sticky head with the cards. */
    .pb-back {
        display: inline-flex; align-items: center; gap: .35rem;
        font-family: var(--sans); font-size: .85rem; color: var(--muted);
        text-decoration: none; margin: .3rem 0 1.4rem;
    }
    .pb-back:hover { color: var(--accent); }

    /* THE COORDINATE GRID (Phase 4b).
       Fixed-width columns and a uniform row unit; each card is PLACED by its
       stored col/row/cw/rh (mapped to explicit grid-column / grid-row) rather
       than flowed in array order. The strip scrolls horizontally (native pan on
       mobile, decision 5) while the page scrolls vertically. Standardized sizes
       live in these three knobs:
         --pb-col  fixed column width  (the "standardized verse container" width)
         --pb-row  uniform row unit    (a collapsed card ≈ one row)
         --pb-gap  gutter between cells
       A card spans ceil((height + gap) / (row + gap)) rows — computed live in
       reflow() and persisted as `rh` — so tall cards claim more rows and the
       store can push neighbours down. */
    /* Horizontal-pan strip (4c). The wrapper is a positioning context for the
       edge fades; the grid inside is the actual scroller. FULL-BLEED: it breaks
       out of the readable .container to span the whole window, so on widescreen
       the canvas reaches the viewport edges and pans beyond them — the header /
       subtitle / back-link stay container-width. --pb-vw is the true visible
       width (document.clientWidth, set by the script; excludes the scrollbar, so
       no phantom overflow) with a 100vw fallback before the script runs; the
       padding cancels out of the centering math, and html{scrollbar-gutter:
       stable} keeps --pb-vw from jumping when the page gains/loses a scrollbar. */
    /* ZOOM WRAPPER (Phase 4) — owns the full-bleed now; the strip inside is
       plain-flow, 100% wide at zoom 1 and 1/zoom wide when zoomed (so the
       scaled result is exactly the viewport width again). transform-origin
       0 0: the wrapper's top-left IS the viewport's left edge, so the strip's
       left never moves. overflow:hidden only while zoomed — it clips the
       unscaled layout overflow that would otherwise give the page a
       horizontal scrollbar and dead space below the board. */
    /* Geometry note (r3): the wrapper's and strip's widths are AUTHORITATIVELY
       written as inline px by the script (setViewportVar) — the declarations
       here are only the pre-script frame. They deliberately avoid two things
       that burned us: var(--pb-vw) (observed stale/unresolved on real boards)
       and calc() division by a var() (Firefox drops the declaration). Plain
       100vw overshoots by the scrollbar for one frame; the script corrects. */
    .pb-zoomwrap {
        --pb-zoom: 1;
        position: relative;
        width: 100vw;
        margin-left:  calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
    }
    /* THE CLIP AND THE SCALE LIVE ON DIFFERENT ELEMENTS — the Phase 4 bug:
       overflow:hidden on a TRANSFORMED element clips in the element's own
       (pre-scale) coordinates, so the clip region itself rendered at 60% and
       amputated the right and bottom of every zoomed board. The wrapper is
       now only the UNSCALED clipper (its box stays viewport-true; height set
       by the script to the scaled strip height); the strip below carries the
       transform. */
    #pb-board.is-zoomed .pb-zoomwrap {
        overflow: hidden;
    }
    #pb-board.is-zoomed .pb-scroll {
        transform: scale(var(--pb-zoom));
        transform-origin: 0 0;
    }
    .pb-scroll {
        position: relative;
        width: 100vw;               /* pre-script frame only; script writes px */
    }
    /* Zoomed cards are inert: the WHOLE card is the drag handle (the grip is
       tiny at 60%), so it grabs like one. touch-action:none because a touch
       drag can start anywhere on it now — which also means a zoomed board is
       panned from the empty grid, not from a card. The corner buttons,
       switcher, pager and reference link neither react nor hit-test. */
    #pb-board.is-zoomed .peri-card { cursor: grab; touch-action: none; }
    #pb-board.is-zoomed .peri-card-text,
    #pb-board.is-zoomed .peri-card-min,
    #pb-board.is-zoomed .peri-card-del,
    #pb-board.is-zoomed .peri-card-tx,
    #pb-board.is-zoomed .peri-ref { pointer-events: none; opacity: .45; }
    .pb-scroll::before, .pb-scroll::after {
        content: ""; position: absolute; top: 0; bottom: 0; width: 1.75rem;
        pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 2;
    }
    .pb-scroll::before { left: 0;  background: linear-gradient(to right, var(--bg), transparent); }
    .pb-scroll::after  { right: 0; background: linear-gradient(to left,  var(--bg), transparent); }
    .pb-scroll.can-left::before  { opacity: 1; }
    .pb-scroll.can-right::after  { opacity: 1; }

    /* The three grid knobs and the text sizes live on #pb-board rather than
       .pb-grid so the drag GHOST — a card clone in a fixed layer outside the
       grid — inherits them and renders at the same size as the original. */
    #pb-board {
        /* ┌─ GRID KNOBS ─────────────────────────────────────────────────┐
           │ --pb-col  column width.                                       │
           │ --pb-row  the SLOT: one collapsed card, exactly. Derived from  │
           │           the snippet's text size so an Aa change resizes the │
           │           slot with the card: one snippet line (×1.4 leading) │
           │           plus 4.25rem of card chrome (padding, header row,   │
           │           gap, borders). ≈ 89px at the default text size.     │
           │ --pb-gap  gutter between cells (both axes).                   │
           └───────────────────────────────────────────────────────────────┘ */
        --pb-col: 15rem;
        --pb-row: calc(var(--pb-snip-size) * 1.4 + 4.25rem);
        --pb-gap: 1rem;
    }
    .pb-grid {
        --pb-pad-l: 0px;                    /* left anchor pad (home → header), set by JS */
        --pb-pad-r: 0px;                    /* right pad (drag reach), set by JS */
        position: relative;                 /* positioning context for .pb-drop.is-free */
        display: grid;
        grid-auto-rows: var(--pb-row);
        /* A resize PREVIEW (Phase C) may span past the rendered tracks for a
           beat before commit re-renders the template; implicit columns must
           be real-sized or the previewed card collapses to auto width. */
        grid-auto-columns: var(--pb-col);
        gap: var(--pb-gap);
        /* Collapsed cards sit at their slot top; an EXPANDED card overrides to
           align-self:stretch (below) so its rendered box is EXACTLY its claimed
           row span — footprint and pixels can never disagree, and group
           outlines (derived from the same span) always fit. (Phase A) */
        align-items: start;
        justify-content: start;
        padding-left: var(--pb-pad-l);
        padding-right: var(--pb-pad-r);
        overflow-x: auto;                   /* horizontal pan; page owns vertical scroll */
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;  /* momentum panning on iOS */
        padding-bottom: 14px;               /* room for the horizontal scrollbar (no stray v-scroll) */
        /* grid-template-columns is set per-render from the column count. */
    }

    /* Horizontal scrollbar (item 3) — a simple accent pill with an ↔ glyph.
       Only shows when the strip actually overflows (item 2); the container is
       sized to content so a board that fits never renders a track. WebKit only;
       Firefox falls back to a thin accent bar via scrollbar-color. */
    .pb-grid { scrollbar-width: thin; scrollbar-color: var(--accent) transparent; }
    .pb-grid::-webkit-scrollbar { height: 12px; }
    .pb-grid::-webkit-scrollbar-track { background: transparent; }
    .pb-grid::-webkit-scrollbar-thumb {
        background-color: var(--accent);
        border-radius: 999px;
        background-repeat: no-repeat;
        background-position: center;
        /* ↔ arrows, white to match text-on-accent. */
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='12' viewBox='0 0 24 12'%3E%3Cg fill='none' stroke='white' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 3 3 6l3 3'/%3E%3Cpath d='M18 3l3 3-3 3'/%3E%3Cpath d='M3 6h18'/%3E%3C/g%3E%3C/svg%3E");
    }
    .pb-grid::-webkit-scrollbar-thumb:hover { background-color: color-mix(in srgb, var(--accent) 85%, var(--ink)); }

    /* DOT GRID (Phase 2). One dot at every slot corner — the top-left of each
       cell — so a card's own corner sits exactly on a dot. Two layers on a
       two-slot vertical pitch: layer A on the even slot rows, layer B (fainter)
       on the odd ones, for the alternating rhythm. --pb-cell-x/y are the cell
       pitch in whole px (set by the script), and the x offset includes the
       left anchor pad so the dots stay pinned to the tracks no matter how the
       header gutter changes with the window (the old drift bug).
       ┌─ DOT KNOBS ────────────────────────────────────────────────────┐
       │ --pb-dot-r     dot radius (px). The soft outer stop keeps it   │
       │                round; a hard 1px stop is what looked square.   │
       │ --pb-dot-a/b   strength of layer A / B as a % of --muted.      │
       │                Three states below: resting, placing, off.      │
       └────────────────────────────────────────────────────────────────┘
       Dots mark every OTHER slot row (rows 1, 3, 5…) — cards still pin to
       every slot, the in-between rows just aren't drawn. --pb-top pads the
       grid so the top dot row (and the cards with it) clears the grid's own
       clip edge instead of losing its upper halves.
       PREFERENCE (mbPericopePrefs.v1 → grid): the script sets one of
       .pb-dots-always / .pb-dots-drag / .pb-dots-off on the grid. */
    .pb-grid {
        --pb-dot-r: 1.7px;
        --pb-dot-a: 0%;
        --pb-top: 36px;                          /* headroom above row 1 — enough for a
                                                    top-row GROUP's label chip to float
                                                    ABOVE its box (Phase 5b) */
        padding-top: var(--pb-top);
        background-attachment: local;            /* dots scroll WITH the cells */
        background-image:
            radial-gradient(circle,
                color-mix(in srgb, var(--muted) var(--pb-dot-a), transparent) var(--pb-dot-r),
                transparent calc(var(--pb-dot-r) + .8px));
        background-size: var(--pb-cell-x, 256px) calc(var(--pb-cell-y, 105px) * 2);
        /* The tile's dot is at its centre; offset by half a tile each way so a
           dot lands on row 1's top-left corner (content top = --pb-top below
           the padding-box origin), then every second slot row after it. */
        background-position:
            calc(var(--pb-pad-l) - var(--pb-cell-x, 256px) / 2)
            calc(var(--pb-top) - var(--pb-cell-y, 105px));
    }
    .pb-grid.pb-dots-always { --pb-dot-a: 22%; }                          /* resting */
    .pb-grid.pb-placing:not(.pb-dots-off) { --pb-dot-a: 60%; }            /* placing */

    /* EDIT MODE (Phase 5) ------------------------------------------------ */

    /* Member cards wear their group's colour (--gp set inline by the paint).
       Declared BEFORE .is-selected so a selected member shows the selection
       wash — same specificity, later rule wins. */
    .peri-card.in-group {
        border-color: color-mix(in srgb, var(--gp) 55%, var(--rule));
        box-shadow: 0 0 0 1px color-mix(in srgb, var(--gp) 22%, transparent);
    }

    /* Selection: the reader's language (a --rule wash) plus an accent ring so
       it reads on a card shape. */
    .peri-card.is-selected {
        background: var(--rule);
        border-color: color-mix(in srgb, var(--accent) 45%, var(--rule));
        box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--accent) 30%, transparent);
    }

    /* Shimmy — the app-drawer wiggle. Amplitude is --pb-shimmy (set from
       prefs by the script; 0 adds .pb-shimmy-off). Stagger by DOM position so
       the cards don't march in step. Paused while a card is being PLACED —
       the drag preview animates cards with inline transforms, and a running
       keyframe would override them. */
    @keyframes pb-shimmy {
        0%   { transform: rotate(calc(var(--pb-shimmy, .7deg) * -1)); }
        50%  { transform: rotate(var(--pb-shimmy, .7deg)); }
        100% { transform: rotate(calc(var(--pb-shimmy, .7deg) * -1)); }
    }
    .pb-editing .peri-card { animation: pb-shimmy .36s ease-in-out infinite; cursor: pointer; }
    .pb-editing .peri-card:nth-child(2n) { animation-delay: -.18s; }
    .pb-editing .peri-card:nth-child(3n) { animation-delay: -.09s; }
    .pb-editing .peri-card:nth-child(5n) { animation-delay: -.27s; }
    .pb-editing.pb-placing .peri-card,
    .pb-editing.pb-shimmy-off .peri-card { animation: none; }
    @media (prefers-reduced-motion: reduce) {
        .pb-editing .peri-card { animation: none !important; }
    }

    /* In edit mode a card's inner controls stand down — the tap selects. */
    .pb-editing .peri-card-min,
    .pb-editing .peri-card-del,
    .pb-editing .peri-card-tx,
    .pb-editing .peri-ref { pointer-events: none; }

    /* GROUPS — pure derivations: the box is positioned in px by the script
       (positionGroups) around its member cells; --gp carries the group's
       theme colour. Behind the cards at rest (z −1); in edit mode raised to
       0 so the tint reads over the cards and the chip takes taps. */
    /* ┌─ GROUP OUTLINE KNOBS ─────────────────────────────────────────┐
       │ border width + the two color-mix strengths set how loudly a    │
       │ group reads; GROUP_HALO (script) sets how far it reaches.      │
       └────────────────────────────────────────────────────────────────┘ */
    .pb-group {
        position: absolute; z-index: -1; pointer-events: none;
        border: 3px solid color-mix(in srgb, var(--gp) 65%, transparent);
        background: color-mix(in srgb, var(--gp) 7%, transparent);
        border-radius: 14px;
    }
    /* Hover-to-adopt (Phase 5b): while a held card is being adopted, the
       courting group brightens, the drop indicator wears its colour, and
       the ghost gains the member ring. */
    .pb-group.is-adopting {
        border-color: var(--gp);
        background: color-mix(in srgb, var(--gp) 14%, transparent);
    }
    .pb-drop.is-adopting {
        border-style: solid;
        border-color: var(--gp);
        background: color-mix(in srgb, var(--gp) 12%, transparent);
    }
    .peri-card.pb-ghost.is-adopting {
        border-color: var(--gp);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--gp) 35%, transparent),
                    0 10px 28px rgba(0,0,0,.18);
    }
    .pb-editing .pb-group { z-index: 0; }
    /* The chip is a SIBLING of its shell (r11), so it escapes the shell's
       z −1 stacking context and paints over the cards — stretched cards fill
       their full rows now, so anything behind them is invisible. left/top are
       set in px by positionGroups(); translateY lifts it above the box top. */
    .pb-group-label {
        position: absolute; transform: translateY(-100%); z-index: 2;
        display: inline-flex; align-items: center; gap: .3rem;
        font-family: var(--sans); font-size: .72rem; font-weight: 700; letter-spacing: .02em;
        color: #fff; background: var(--gp);
        padding: .18rem .6rem; border-radius: 999px;
        max-width: 15rem; white-space: nowrap;
    }
    .pb-group-label[hidden] { display: none; }   /* inline-flex above would beat [hidden] otherwise */
    .pb-group-label-text { overflow: hidden; text-overflow: ellipsis; }
    .pb-group-label-edit { display: none; width: 11px; height: 11px; flex: 0 0 auto; }
    .pb-editing .pb-group-label { pointer-events: auto; cursor: pointer; }
    .pb-editing .pb-group-label:hover { filter: brightness(1.12); }
    .pb-editing .pb-group-label-edit { display: block; }

    /* The edit FAB (shared .fab chrome above). Disabled actions fade but stay
       in place so the bar never reflows. */
    .pbe-fab .fab-pill svg { flex: 0 0 auto; }
    .pbe-fab .is-disabled { opacity: .45; }

    /* The group sheet — label + colour, over its own scrim. Self-contained
       (pbe-*) but visually the mb-dialog family. */
    /* The hidden attribute MUST beat the class's display:flex — the same
       trap the shared fab notes for .fab-icon[hidden]. Without this the
       closed sheet leaves an invisible full-screen scrim that eats every
       click (the "stuck in edit mode" bug). */
    .pbe-sheet-scrim[hidden] { display: none !important; }
    .pbe-sheet-scrim {
        position: fixed; inset: 0; z-index: 300;
        display: flex; align-items: center; justify-content: center;
        background: rgba(20, 14, 10, .45);
        opacity: 0; transition: opacity .16s ease;
    }
    .pbe-sheet-scrim.is-open { opacity: 1; }
    .pbe-sheet {
        width: min(92vw, 22rem);
        background: var(--bg); border: 1px solid var(--rule); border-radius: 14px;
        box-shadow: 0 18px 48px rgba(0,0,0,.28);
        padding: 1.1rem 1.2rem 1rem;
    }
    .pbe-sheet-title { font-family: var(--serif); font-size: 1.15rem; color: var(--ink); margin: 0 0 .7rem; }
    .pbe-sheet-input {
        width: 100%; box-sizing: border-box;
        font-family: var(--sans); font-size: .95rem; color: var(--ink);
        background: none; border: none; border-bottom: 2px solid var(--accent);
        padding: .25rem 0 .35rem; margin-bottom: .85rem;
    }
    .pbe-sheet-input:focus { outline: none; }
    .pbe-swatches { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .pbe-swatch {
        width: 26px; height: 26px; border-radius: 50%; cursor: pointer;
        background: var(--sw); border: 2px solid transparent; padding: 0;
        transition: transform .1s;
    }
    .pbe-swatch:hover { transform: scale(1.12); }
    .pbe-swatch.is-picked { border-color: var(--ink); box-shadow: 0 0 0 2px var(--bg) inset; }
    .pbe-sheet-btns { display: flex; justify-content: flex-end; gap: .5rem; }
    .pbe-sheet-btn {
        font-family: var(--sans); font-size: .88rem; font-weight: 600; cursor: pointer;
        border-radius: 999px; padding: .45rem 1rem; border: 1px solid var(--rule);
        background: none; color: var(--muted);
        transition: color .12s, background .12s, filter .12s;
    }
    .pbe-sheet-btn.is-quiet:hover { color: var(--ink); background: var(--panel); }
    .pbe-sheet-btn.is-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .pbe-sheet-btn.is-primary:hover { filter: brightness(1.12); }

    /* SHARE PANEL (S1) — a <details> in the head folder; its panel mirrors
       .ts-panel (same width, chrome, z-index) and hangs beneath the pill
       (the folder makes inner details position:static). */
    .pb-share > summary { list-style: none; }
    .pb-share > summary::-webkit-details-marker { display: none; }
    .pb-share[open] > .fld-app { color: var(--bg); background: var(--accent); }
    .pbs-panel {
        position: absolute; right: 0; top: calc(100% + 10px); z-index: 80;
        width: 300px; padding: 1rem;
        background: var(--bg); border: 1px solid var(--rule); border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
        text-align: left; cursor: default;
    }
    /* The red Presentation pill holds two buttons: the main one and, on its
       far right INSIDE the pill, the round gear that flips the panel to
       presentation settings. */
    .pbs-present-row {
        display: flex; align-items: center; gap: .3rem;
        margin: 0 0 1rem; padding: .28rem .28rem .28rem 0;
        background: var(--accent); border: 1px solid var(--accent); border-radius: 999px;
    }
    .pbs-present {
        flex: 1 1 auto; display: flex; align-items: center; justify-content: center; gap: .55rem;
        min-width: 0; padding: .45rem .6rem .45rem 1rem; border: none; border-radius: 999px;
        font-family: var(--sans); font-size: .92rem; font-weight: 600;
        background: none; color: #fff; cursor: pointer;
        transition: filter .12s;
    }
    .pbs-present:hover { filter: brightness(1.15); }
    .pbs-present:disabled { opacity: .45; cursor: default; filter: none; }
    .pbs-present svg { width: 18px; height: 18px; display: block; pointer-events: none; }
    .pbs-gear {
        flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; padding: 0; border-radius: 50%; cursor: pointer;
        border: 1px solid rgba(255,255,255,.55); background: none; color: #fff;
        transition: color .12s, background .12s, transform .25s;
    }
    .pbs-gear svg { width: 18px; height: 18px; display: block; pointer-events: none; }
    .pbs-gear:hover { background: rgba(255,255,255,.14); }
    .pbs-gear.is-on { background: #fff; color: var(--accent); border-color: #fff; transform: rotate(60deg); }

    /* Presentation settings — the Aa panel's control grammar. */
    .pps-title { font-family: var(--serif); font-weight: 700; font-size: 1.05rem; color: var(--ink); margin: 0 0 .7rem; }
    .pps-row { display: grid; gap: .5rem; margin-bottom: .55rem; }
    .pps-row[hidden] { display: none; }
    /* The board's name in the chosen slide face, above the font picker. */
    .pps-preview {
        min-height: 3.2rem; display: flex; align-items: center; justify-content: center;
        padding: .5rem .8rem; margin-bottom: .55rem;
        font-size: 1.5rem; line-height: 1.15; color: var(--ink); text-align: center;
        background: var(--panel); border: 1px solid var(--rule); border-radius: 8px;
        overflow: hidden; text-overflow: ellipsis;
    }
    .pps-select, .pps-input {
        width: 100%; box-sizing: border-box; min-height: 40px;
        font-family: var(--sans); font-size: .85rem; color: var(--ink);
        background: var(--panel); border: 1px solid var(--rule); border-radius: 8px; padding: .4rem .6rem;
    }
    .pps-select:focus, .pps-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .pps-seg { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: .4rem; }
    .pps-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-height: 40px; padding: .35rem .4rem; border-radius: 8px; cursor: pointer;
        font-family: var(--sans); font-size: .78rem; color: var(--ink);
        background: var(--panel); border: 1px solid var(--rule);
        transition: color .12s, background .12s, border-color .12s;
    }
    .pps-btn svg { width: 20px; height: 20px; display: block; pointer-events: none; }
    .pps-btn:hover { border-color: var(--accent); }
    .pps-btn.is-on { color: var(--bg); background: var(--accent); border-color: var(--accent); }
    .pps-swatches { display: flex; flex-wrap: wrap; gap: .45rem; }
    .pps-swatch {
        width: 26px; height: 26px; border-radius: 50%; cursor: pointer; padding: 0;
        background: var(--sw); border: 2px solid transparent; transition: transform .1s;
    }
    .pps-swatch:hover { transform: scale(1.12); }
    .pps-swatch.is-picked { border-color: var(--ink); box-shadow: 0 0 0 2px var(--bg) inset; }
    .pps-swatch.is-none {
        background: var(--bg); border-color: var(--rule);
        background-image: linear-gradient(135deg, transparent 45%, var(--rule) 45%, var(--rule) 55%, transparent 55%);
    }
    .pps-swatch.is-none.is-picked { border-color: var(--ink); }
    .pps-custom { display: grid; grid-template-columns: 1fr auto; gap: .4rem; margin: -.1rem 0 .6rem; }
    .pps-custom[hidden] { display: none; }
    .pps-hint { grid-column: 1 / -1; margin: 0; font-family: var(--sans); font-size: .72rem; color: var(--muted); line-height: 1.4; }
    .pps-hint.is-error { color: var(--accent); }

    .pbs-title { font-family: var(--serif); font-size: 1.05rem; color: var(--ink); margin: 0 0 .5rem; }
    .pbs-blurb { font-family: var(--sans); font-size: .82rem; color: var(--muted); margin: 0 0 .8rem; line-height: 1.5; }
    .pbs-url {
        width: 100%; box-sizing: border-box; resize: none;
        font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .74rem; line-height: 1.45;
        color: var(--ink); background: var(--panel);
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .5rem .6rem; word-break: break-all;
    }
    .pbs-url:focus { outline: none; border-color: var(--accent); }
    .pbs-size { font-family: var(--sans); font-size: .78rem; color: var(--muted); margin: .5rem 0 .9rem; }
    .pbs-size.is-over { color: var(--accent); }
    /* Always dark-on-white in its own backing box, whatever the theme —
       phone cameras want contrast. The svg carries its own quiet margin. */
    .pbs-qr { display: flex; justify-content: center; margin: 0 0 1rem; }
    .pbs-qr svg {
        width: min(58vw, 13rem); height: auto; display: block;
        background: #fff; border-radius: 10px;
        box-shadow: 0 1px 6px rgba(0,0,0,.12);
    }
    .pbs-btns { display: flex; justify-content: flex-end; gap: .5rem; }
    .pbs-btn {
        font-family: var(--sans); font-size: .88rem; font-weight: 600; cursor: pointer;
        border-radius: 999px; padding: .45rem 1rem; border: 1px solid var(--rule);
        background: none; color: var(--muted);
        transition: color .12s, background .12s, filter .12s;
    }
    .pbs-btn.is-quiet:hover { color: var(--ink); background: var(--panel); }
    .pbs-btn.is-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .pbs-btn.is-primary:hover { filter: brightness(1.12); }

    /* DRAG-TO-PLACE (Phase 3). While a card is dragged from its grip the grid
       gains spare columns on both sides (left as padding, right as the
       1px .pb-extent spacer, which also holds the vertical drop room) and
       the dot grid strengthens. .pb-drop is the target indicator — always
       pixel-positioned inside the grid, so it reads identically over any
       cell, occupied or not, existing column or new. */
    .pb-drop {
        position: absolute;
        border: 2px dashed color-mix(in srgb, var(--accent) 60%, var(--rule));
        background: color-mix(in srgb, var(--accent) 10%, transparent);
        border-radius: 12px; pointer-events: none; z-index: 1;
    }
    /* The breathing-room extent: a 1px marker one blank column past the
       rightmost cards, owned by the board (applyAnchor) — it extends the
       strip's pannable range at rest and IS the drag's rightmost drop room. */
    .pb-extent { position: absolute; width: 1px; height: 1px; pointer-events: none; visibility: hidden; }

    /* The GHOST — a clone of the grabbed card in a fixed layer on <body>,
       moved with a transform from the script (transform-origin 0 0 so the
       Phase 4 zoom scale keeps the grab point under the pointer). Above the
       sticky head (30) and the site's overlays (40). */
    .peri-card.pb-ghost {                 /* two classes: outranks .peri-card's own position/transition */
        position: fixed; left: 0; top: 0; z-index: 200;
        margin: 0; pointer-events: none;
        transform-origin: 0 0;
        opacity: .62;
        box-shadow: 0 10px 28px rgba(0,0,0,.18);
        border-color: color-mix(in srgb, var(--accent) 40%, var(--rule));
        transition: none;
    }
    @media (prefers-reduced-motion: reduce) {
        .pb-grid .peri-card { transition: none !important; }   /* no FLIP shuffle */
    }

    .peri-card {
        position: relative;
        display: flex; flex-direction: column; gap: .5rem;
        padding: .9rem 1rem 1rem;
        border: 1px solid var(--rule); border-radius: 12px;
        background: var(--bg);
        transition: border-color .12s, box-shadow .12s, opacity .12s;
    }
    /* A collapsed verse card is EXACTLY one slot tall (box-sizing is
       border-box site-wide), so it fills its cell edge to edge and reflow()
       always measures it as rh:1. Overflow is clipped in case a text setting
       ever pushes a line past the slot. cursor:pointer is both the affordance
       and what makes iOS deliver a single tap as a click on a non-interactive
       element (the card no longer carries role="button", because it now holds
       a real link — see .peri-ref). */
    .peri-card[aria-expanded="false"] {
        height: var(--pb-row);
        overflow: hidden;
        cursor: pointer;
    }
    /* An EXPANDED card fills its claimed row span exactly (Phase A). It's a
       flex column: the header/switcher take their natural height at the top and
       .peri-card-text takes the rest and scrolls. The card itself stays
       overflow:visible so the translation menu can pop outside the fixed box. */
    .peri-card.is-expanded {
        align-self: stretch;
        overflow: visible;
        min-height: 0;   /* let the flex child shrink so its own overflow drives the scroll */
    }
    .peri-card:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--rule)); }
    .peri-card:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
    /* The grabbed card: invisible but still in layout (and still the pointer
       capture target), while its ghost does the moving. */
    .peri-card.is-dragging { opacity: 0; }

    /* TOP ROW — the drag grip beside a "titles" column (reference header +, when
       expanded, the translation line). Because the grip is centered on this
       whole column, it sits on the header alone when collapsed, and drops to the
       divider between the header and the switcher when the card is expanded
       (item 2). The column also gives the switcher the reference's exact left
       edge for free, so no manual indent is needed. */
    .peri-card-top { display: flex; align-items: center; gap: .5rem; }
    .peri-card-titles { flex: 1 1 auto; min-width: 0; }
    /* Only an expanded card shows a top-right delete; reserve room so the
       reference never runs under it. Collapsed cards have no corner button. */
    .peri-card.is-expanded .peri-card-top { padding-right: 2.2rem; }

    .peri-card-head { display: flex; align-items: center; gap: .5rem; }

    /* Drag grip — the ONLY place a drag begins. Faintly visible always (so it's
       discoverable on touch, which has no hover). Three columns of dots: a
       9-dot form on a collapsed card, a 12-dot form (an extra row, a little
       larger) on an expanded one.

       HIT AREA (Phase 2): the dots stay small but the TARGET is ≥44px square.
       Padding grows the box; equal negative margins hand the space back so
       the card's layout doesn't change — the extra left margin reaches out
       over the card's own padding to its edge. z-index keeps the hit box on
       top of the reference link and snippet it overlaps.
       ┌─ GRIP KNOBS ───────────────────────────────────────────────────┐
       │ --grip-h   svg height (dots scale with it; width follows).      │
       │ --grip-py  vertical hit padding (each side): height = svg + 2×. │
       │ --grip-px  horizontal hit padding (each side).                  │
       │ Dot radius is r= in the GRIP9 / GRIP12 constants (script).      │
       └────────────────────────────────────────────────────────────────┘ */
    .peri-grip {
        --grip-h: 18px; --grip-py: 13px; --grip-px: .75rem;
        position: relative; z-index: 2;
        flex: 0 0 auto;
        display: inline-flex; align-items: center; justify-content: center;
        padding: var(--grip-py) var(--grip-px);
        margin: calc(var(--grip-py) * -1) calc(var(--grip-px) * -1);
        border-radius: 8px;
        color: var(--muted); opacity: .55; cursor: grab;
        touch-action: none;                /* we own the gesture from the handle */
        transition: opacity .12s, color .12s, background .12s;
    }
    .peri-grip:hover { opacity: 1; color: var(--accent); background: var(--panel); }
    .peri-grip svg { height: var(--grip-h); width: auto; display: block; }
    .peri-card.is-expanded .peri-grip { --grip-h: 26px; --grip-py: 9px; }   /* bigger group when expanded */
    .peri-grip .grip-12 { display: none; }
    .peri-card.is-expanded .peri-grip .grip-9 { display: none; }
    .peri-card.is-expanded .peri-grip .grip-12 { display: block; }

    /* THE REFERENCE LINK (Phase 2). One <a class="peri-ref"> wraps the
       collapsed cell, the expanded spelled-out reference and a small ↗ — the
       card's only link. A card with no resolvable reader URL renders the same
       shape as a <span> with no arrow. */
    .peri-ref {
        display: inline-flex; align-items: center; gap: .3rem;
        min-width: 0; text-decoration: none; color: inherit;
        border-radius: 6px;
    }
    .peri-ref:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
    /* ↗ — the "this goes somewhere" cue. Muted beside the cell, accent on hover. */
    .peri-ref-arrow {
        flex: 0 0 auto; width: 12px; height: 12px; display: block;
        color: var(--muted); transition: color .12s;
    }
    a.peri-ref:hover .peri-ref-arrow { color: var(--accent); }
    .peri-card.is-expanded .peri-ref-arrow { width: 14px; height: 14px; align-self: flex-start; margin-top: .15rem; }

    /* COLLAPSED reference — the QuickNav cell, tinted by --bk (set inline per
       card). Mirrors .qn-book: white text on the section colour. */
    .peri-cell {
        flex: 0 0 auto;
        font-family: var(--sans); font-size: .72rem; font-weight: 700;
        letter-spacing: .01em; white-space: nowrap;
        color: #fff; background: var(--bk);
        border: 1px solid rgba(0,0,0,.14); border-radius: 6px;
        padding: .26rem .5rem;
    }
    /* EXPANDED reference — fully spelled out, with the cell colour reborn as
       a thick underline. Hidden until expanded. */
    .peri-ref-full {
        display: none;
        font-family: var(--sans); font-size: .9rem; font-weight: 700;
        letter-spacing: .01em; color: var(--ink);
        border-bottom: 3px solid var(--bk); padding-bottom: 1px;
    }
    /* Mobile-only short form of the EXPANDED reference (r14): the chip's
       short book labels ("Gen 1:21", "Pr Man 15") in ref-full's clothes. The
       520px media block below flips which span shows. */
    .peri-ref-short {
        display: none;
        font-family: var(--sans); font-size: .9rem; font-weight: 700;
        letter-spacing: .01em; color: var(--ink);
        border-bottom: 3px solid var(--bk); padding-bottom: 1px;
    }
    a.peri-ref:hover .peri-ref-full { color: var(--accent); }
    a.peri-ref:hover .peri-ref-short { color: var(--accent); }
    a.peri-ref:hover .peri-cell { filter: brightness(1.12); }

    .peri-card.is-expanded .peri-cell { display: none; }
    .peri-card.is-expanded .peri-ref-full { display: inline; }

    /* Translation line — GONE from the collapsed card (item 2); on an expanded
       card it sits just under the reference (item 3a), sharing the reference's
       left edge naturally now that both live in .peri-card-titles. Kept faint
       and tight so it never competes with the header above it (item 3). Only
       wired up as a switcher when >1 translation exists (item 4); otherwise it
       stays a static, caret-less code. */
    .peri-card-tx {
        display: none;
        margin-top: .02rem;
    }
    .peri-card.is-expanded .peri-card-tx { display: block; }

    /* Trigger — pill-less, and deliberately fainter/lighter than the header. */
    .tx-mini {
        display: inline-flex; align-items: center; gap: .3rem;
        list-style: none; cursor: pointer; user-select: none;
        font-family: var(--sans); font-size: .78rem; font-weight: 500;
        color: var(--muted);
    }
    .tx-mini::-webkit-details-marker { display: none; }
    .peri-txsw .tx-mini:hover { color: var(--accent); }
    .tx-mini.is-static { cursor: default; }

    /* Menu — identical to the reader's .tx-menu/.tx-option, with two changes:
       the rows are <button>s (JS actions, not links), so restore the sans font
       the global rule assumes and strip button chrome; and the grid drops its
       year column so each row is just check + abbreviation. */
    .peri-txsw .tx-menu { min-width: 7rem; }
    .peri-txsw .tx-option {
        grid-template-columns: 1.4rem 1fr;
        width: 100%; border: none; background: none; cursor: pointer; text-align: left;
        font-family: var(--sans); font-size: .9rem;
    }
    .peri-txsw button.tx-option:hover { background: var(--panel); }
    .peri-txsw .tx-option.is-current { cursor: default; }

    /* TEXT SETTINGS (Phase 1). Verse text follows the Aa panel: the reader's
       --reading-size / --reading-family / --reading-leading (set on <html>
       from mb.reader). The snippet and expanded text are FRACTIONS of the
       reading size: .8 → 15.2px at the default 19px (the snippet went up a
       step in Phase 2 to fill the taller slot) and .83 → 15.8px for the
       expanded text. References, the translation code and card chrome
       deliberately stay fixed. The script re-renders on mb:reader-change so
       row spans track the new heights — and --pb-row itself is derived from
       --pb-snip-size, so the slot grows with the text. */
    #pb-board {
        --pb-snip-size: calc(var(--reading-size, 19px) * .8);
        --pb-text-size: calc(var(--reading-size, 19px) * .83);
    }

    /* COLLAPSED text — one line, ellipsised (the JS already caps the string).
       The auto vertical margins centre it in whatever the fixed-height slot
       leaves under the header row. */
    .peri-card-snip {
        font-family: var(--reading-family, var(--serif));
        font-size: var(--pb-snip-size); line-height: 1.4; color: var(--ink);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        margin-top: auto; margin-bottom: auto;
    }
    /* EXPANDED text — a container for the verse paragraphs (numbered) and, for
       cards of more than two verses, a pager (item 6). Hidden until expanded,
       EXCEPT the static form used by notes/headings, and a legacy blob form for
       any pre-per-verse card that hasn't self-healed yet. */
    .peri-card-text {
        display: none;
        font-family: var(--reading-family, var(--serif));
        font-size: var(--pb-text-size); line-height: var(--reading-leading, 1.55); color: var(--ink);
    }
    .peri-card-text.is-static,
    .peri-card-text.is-legacy { white-space: pre-line; }   /* blob forms keep line breaks */
    .peri-card-text.is-static { display: block; }
    .peri-card.is-expanded .peri-card-snip { display: none; }
    /* On an expanded card the text is the flex child that fills the leftover
       box and SCROLLS (Phase A) — pagination is gone; a run longer than the
       card's rows scrolls inside it. overscroll-contain stops an inner scroll
       from dragging the page/strip on touch; the thin scrollbar matches the
       board's. position:relative anchors the bottom fade. */
    .peri-card.is-expanded .peri-card-text {
        display: block;
        position: relative;
        flex: 1 1 auto;
        min-height: 0;
        /* hidden until markScrollCues() flags a REAL overflow (>2px) — so a
           pixel of padding slop never draws a scrollbar on a card that fits
           (the r10 single-verse-with-scrollbar bug). */
        overflow-y: hidden;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        scrollbar-color: color-mix(in srgb, var(--muted) 55%, transparent) transparent;
    }
    .peri-card.is-expanded .peri-card-text.is-overflowing { overflow-y: auto; }
    .peri-card.is-expanded .peri-card-text::-webkit-scrollbar { width: 9px; }
    .peri-card.is-expanded .peri-card-text::-webkit-scrollbar-track { background: transparent; }
    .peri-card.is-expanded .peri-card-text::-webkit-scrollbar-thumb {
        background-color: color-mix(in srgb, var(--muted) 55%, transparent);
        border-radius: 999px;
        border: 2px solid transparent; background-clip: padding-box;
    }
    /* BOTTOM FADE — shown only when the text actually overflows its box
       (JS toggles .is-overflowing after spans settle / after a resize). A
       gradient sits over the last few pixels so clipped text reads as
       "there's more, scroll" instead of a hard cut. Individual mask longhands
       only — the `mask` shorthand silently resets mask-image on higher-
       specificity rules (documented CSS trap). pointer-events:none so it never
       eats a scroll. */
    .peri-card.is-expanded .peri-card-text.is-overflowing::after {
        content: ""; position: absolute; left: 0; right: 0; bottom: 0;
        height: 1.6rem; pointer-events: none;
        background: linear-gradient(to bottom, transparent, var(--bg));
    }

    .peri-card.is-heading .peri-card-text { font-family: var(--sans); font-weight: 700; font-size: 1.05rem; }
    .peri-card.is-note .peri-card-text { color: var(--muted); font-style: italic; }

    /* A numbered verse paragraph. The number is a small superscript in the sans
       face; the verse text keeps poetry line breaks (pre-line). */
    .peri-v { margin: 0 0 .5rem; white-space: pre-line; }
    .peri-v:last-child { margin-bottom: 0; }
    .peri-vn {
        font-family: var(--sans); font-weight: 700; font-size: .62em;
        color: var(--muted); vertical-align: .35em; margin-right: .3em;
        font-variant-numeric: tabular-nums;
    }
    /* The scroll content clears the bottom fade and the corner collapse button:
       a little tail padding, extra on the right for the button's column. */
    .peri-card.is-expanded .peri-verses { padding: 0 2rem .4rem 0; }

    /* Corner buttons — BOTH appear ONLY on an expanded card. A collapsed
       (succinct) card therefore has no delete target to mis-tap, and no
       hover-revealed control at all: removing that reveal is also what fixes
       the mobile "first tap only hovers, second tap clicks" double-tap.
       Delete sits top-right; collapse sits bottom-right, where an expanded
       card almost always has spare room below the last line of verse text. */
    .peri-card-min, .peri-card-del {
        position: absolute;
        display: none; align-items: center; justify-content: center;
        width: 26px; height: 26px; padding: 0;
        border: none; border-radius: 50%;
        background: color-mix(in srgb, var(--bg) 80%, transparent);
        color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .peri-card-del { top: .5rem; right: .5rem; }
    .peri-card-min {
        bottom: .5rem; right: .5rem;
        /* It doubles as the HOLD-to-resize handle (Phase C): the browser must
           not claim the pointer for scrolling, long-press callouts or text
           selection while we time the hold. */
        touch-action: none;
        -webkit-touch-callout: none;
        -webkit-user-select: none; user-select: none;
    }
    .peri-card.is-expanded .peri-card-min,
    .peri-card.is-expanded .peri-card-del { display: inline-flex; }
    .peri-card-min:hover, .peri-card-del:hover { color: var(--accent); background: var(--panel); }
    /* Rotated 90° so the corners-in arrows point into the bottom-right corner. */
    .peri-card-min svg { width: 14px; height: 14px; display: block; transform: rotate(90deg); }
    .peri-card-del svg { width: 13px; height: 13px; display: block; }

    /* While a drag is live, kill text selection and show the grabbing cursor. */
    .pb-dragging { user-select: none; -webkit-user-select: none; cursor: grabbing; }

    /* MOBILE — columns stay their fixed width; the strip pans horizontally
       (decision 5) instead of reflowing to fewer columns, so a layout authored
       at 3 columns is the SAME layout on a phone. The grip is a touch stronger
       so it's discoverable without hover. (Edge fade + column peek land in 4c.) */
    @media (max-width: 520px) {
        .pb-grid {
            /* KNOB — mobile column width (r14): sized so TWO columns sit
               fully in view with a sliver of column three peeking, so the
               board reads as "there's more →". Tune the 2.15 divisor
               (bigger = narrower columns = more peek) and the .8rem page
               allowance. 100vw is safe here — phone scrollbars are overlays,
               so the usual scrollbar-width caveat doesn't bite, and the
               sliver is decorative anyway. */
            --pb-col: calc((100vw - 2 * var(--pb-gap) - .8rem) / 2.15);
        }
        .peri-grip { opacity: .85; }
        /* Expanded cards use the SHORT reference on phones (r14) — the same
           short book names as the collapsed cell chip; a spelled-out
           "Prayer of Manasseh" eats half a narrow column. */
        .peri-card.is-expanded .peri-ref-full  { display: none; }
        .peri-card.is-expanded .peri-ref-short { display: inline; }
    }

    /* MEASURING PROBE (Phase B) — a real card shell, parked invisibly on the
       board root so the budget can measure verse text at candidate widths
       with the exact live styles. Outside the grid (paint never wipes it) and
       outside the zoom transform (never scaled). */
    .pb-probe {
        position: absolute; left: 0; top: 0;
        visibility: hidden; pointer-events: none; z-index: -1;
    }

    /* RESIZE MODE (Phase C) — the held card wears a dashed accent ring and a
       corner cursor; the board suppresses text selection while the pointer is
       resizing so a fast drag can't paint a selection through the verses. */
    .peri-card.pb-resizing {
        border-style: dashed;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent);
        cursor: nwse-resize;
        z-index: 3;
    }
    .pb-resize-active { -webkit-user-select: none; user-select: none; }
    .pb-resize-active .peri-card { cursor: nwse-resize; }

    .pb-empty, .pb-missing {
        max-width: 30rem; margin: 3rem auto; text-align: center;
        font-family: var(--sans); color: var(--muted);
    }
    .pb-empty h2, .pb-missing h2 { margin: 0 0 .3rem; color: var(--ink); font-size: 1.25rem; font-weight: 400; }
    .pb-empty p, .pb-missing p { margin: 0 0 1rem; font-size: .9rem; line-height: 1.55; }
    .pb-missing a, .pb-empty a { color: var(--accent); }
</style>
@endsection

@section('content')
    {{-- The board (shown when the slug resolves). --}}
    <div id="pb-board" hidden>
        {{-- Sticky head: sentinel first, then the head (markup contract in
             bible.partials.sticky-head). The corner cluster is absolutely
             anchored to the head so a wrapping name never moves it. --}}
        <div class="chapter-head-sentinel"></div>

        <div class="chapter-head">
            <div class="head-actions">
                {{-- The apps folder IS the corner cluster (components/head-folder).
                     Pill order, left to right: home / undo / redo, zoom /
                     edit / share, Aa, then the folder circle — one group. Existing ids
                     are unchanged — the three scripts still find their buttons
                     by id. Home, undo and redo start `disabled` — that's
                     their true initial state (at rest, no history) — and
                     pericope-board.js flips the attribute from then on. --}}
                <x-head-folder>
                    <button type="button" class="fld-app" id="pb-home" aria-label="Back to the home block" title="Home" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/></svg>
                    </button>
                    <button type="button" class="fld-app" id="pb-undo" aria-label="Undo" title="Undo" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg>
                    </button>
                    <button type="button" class="fld-app" id="pb-redo" aria-label="Redo" title="Redo" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 15-6.7L21 13"/></svg>
                    </button>
                    <button type="button" class="fld-app" id="pb-zoom" aria-label="Zoom out" title="Zoom out" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.2" y2="16.2"/><line x1="8" y1="11" x2="14" y2="11"/><line class="pb-zoomv" x1="11" y1="8" x2="11" y2="14"/></svg>
                    </button>
                    <button type="button" class="fld-app" id="pb-editmode" aria-label="Edit cards" title="Edit cards" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
                    </button>
                    {{-- Share (S1): a panel beneath the toolbar, like Aa.
                         pericope-share.js fills .pbs-panel on open; the
                         Presentation button at its top opens the deck. --}}
                    <details class="pb-share" id="pb-share">
                        <summary class="fld-app" aria-label="Share this pericope" title="Share">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="14"/></svg>
                        </summary>
                        <div class="pbs-panel" role="group" aria-label="Share this pericope"></div>
                    </details>
                    {{-- Aa rides in the folder like every other page control. No
                         visibility checkboxes: there are no headings or
                         footnotes to toggle on a board. --}}
                    @include('bible.partials.text-settings', ['tsChecks' => false])
                </x-head-folder>
            </div>

            <div class="chapter-head-top">
                <div class="pb-head">
                    <h1 class="pb-name" id="pb-name"></h1>
                    <button type="button" class="pb-edit" id="pb-edit" aria-label="Rename pericope" title="Rename">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </button>
                </div>
            </div>

            <p class="subtitle" id="pb-sub"></p>
        </div>

        <a class="pb-back" href="{{ $hubUrl }}">&larr; All pericopae</a>

        <div class="pb-zoomwrap" id="pb-zoomwrap">
            <div class="pb-scroll" id="pb-scroll">
                <div class="pb-grid" id="pb-grid"></div>
            </div>
        </div>

        <div class="pb-empty" id="pb-empty" hidden>
            <h2>This pericope is empty</h2>
            <p>Add verses while reading: select a verse, open the folder, and choose the scissors.</p>
        </div>
    </div>

    {{-- Shown when the slug doesn't resolve on this device. --}}
    <div class="pb-missing" id="pb-missing" hidden>
        <h2>Pericope not found</h2>
        <p>It may have been deleted, or the link was made in a different browser. Pericopes live only on the device that created them.</p>
        <a href="{{ $hubUrl }}">&larr; Back to your pericopes</a>
    </div>
@endsection

@section('scripts')
{{-- The board's script lives in public/js/pericope-board.js (Phase 0 of the
     board redesign moved it out of this file). It reads ONE config object
     published here; $boardConfig is a single array built by the controller so
     the JSON directive below receives one plain variable (never a comma-bearing
     expression, which Blade would split and silently truncate). --}}
<script>window.MBPericopeBoardConfig = @json($boardConfig);</script>
{{-- defer, and yielded AFTER app.blade's deferred pericope-store.js, so the
     store exists when this runs. The filemtime cache-bust means the file MUST
     exist on disk before this renders. --}}
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
<script src="{{ asset('js/pericope-board.js') }}?v={{ filemtime(public_path('js/pericope-board.js')) }}" defer></script>
<script src="{{ asset('js/pericope-drag.js') }}?v={{ filemtime(public_path('js/pericope-drag.js')) }}" defer></script>
<script src="{{ asset('js/pericope-resize.js') }}?v={{ filemtime(public_path('js/pericope-resize.js')) }}" defer></script>
<script src="{{ asset('js/pericope-edit.js') }}?v={{ filemtime(public_path('js/pericope-edit.js')) }}" defer></script>
<script src="{{ asset('js/pericope-share.js') }}?v={{ filemtime(public_path('js/pericope-share.js')) }}" defer></script>
<script src="{{ asset('js/pericope-present.js') }}?v={{ filemtime(public_path('js/pericope-present.js')) }}" defer></script>
@endsection
