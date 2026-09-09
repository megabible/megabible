/* ======================================================================
   PERICOPE BOARD — PAN GIZMO + BACKGROUND PAN     public/js/pericope-pan.js
   ----------------------------------------------------------------------
   Nav upgrade Phase 3. Two ways to move the view, one file:

   THE GIZMO — a floating pill at bottom-centre (the shared .fab chrome
   from bible/partials/fab-styles) that replaces the strip's native
   horizontal scrollbar. A track holds one accent THUMB sized
   proportionally (clientWidth / scrollWidth, floored at MIN_THUMB so a
   finger can always land on it) and positioned from scrollLeft. It exists
   ONLY while the strip actually overflows (> SETTLE px), so a board that
   fits never shows it — most phones will, most desktop boards won't.
     • Drag the thumb: pointer dx maps to scrollLeft through the
       track-play/scroll-range ratio. Mouse and touch, one path.
     • Press the empty track: the thumb jumps to centre on the pointer and
       the SAME gesture keeps dragging — no second grab needed.
     • Keyboard (role=scrollbar, tabindex=0): arrows nudge one column,
       Home/End go to the ends. aria-valuenow tracks 0–100.
   Stand-downs are CSS-only (board.blade): the gizmo hides while the edit
   FAB owns bottom-centre (#pb-board.is-editing) and while a card drag is
   live (body.pb-dragging).

   BACKGROUND PAN — press-and-drag on the EMPTY grid (e.target === grid:
   cards are children, and the group/tether SVG layers are
   pointer-events:none so they pass through) moves the board with the
   pointer: horizontal into grid.scrollLeft (screen px ÷ zoom — scrollLeft
   lives in the strip's unscaled units), vertical into the page scroll.
   MOUSE ONLY, deliberately: touch already pans the strip natively with
   momentum, and claiming the pointer would kill exactly that. A THRESH px
   dead zone keeps a background CLICK a click; once a pan engages, the
   tail click is swallowed (capture phase, one tick) so edit mode's
   background-deselect can't misfire off a pan.

   SINGLE SOURCE OF TRUTH: every gesture here only writes grid.scrollLeft.
   The thumb, the fades, panDelta and the home button all follow from the
   grid's own scroll event (pericope-board.js onScroll) — this file never
   duplicates that bookkeeping.

   Attaches through window.MBPericopeBoard (grid / boardEl / zoom /
   gridMetrics) on mb:pericope-board-ready, like every companion script.
   Vanilla ES5 (var / function), matching the rest of the board suite.
   ====================================================================== */
(function () {
    'use strict';

    var THRESH    = 4;     // KNOB: px of mouse travel before a background press becomes a pan
    var MIN_THUMB = 44;    // KNOB: the thumb never shrinks below a finger (px)
    var SETTLE    = 4;     // KNOB: overflow (px) below which the gizmo hides —
                           //       mirrors markScrollCues' "real overflow" tolerance

    var B = null;          // window.MBPericopeBoard
    var grid = null, boardEl = null;
    var fab = null, track = null, thumb = null;
    var visible = false;

    function zoom() { return (B && B.zoom) ? B.zoom() : 1; }
    function dragging() { return document.body.classList.contains('pb-dragging'); }
    function closest(el, sel) { return (el && el.closest) ? el.closest(sel) : null; }
    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    // Horizontal scroll range — how far the strip can pan at all.
    function range() { return Math.max(0, grid.scrollWidth - grid.clientWidth); }

    /* ---- sync: visibility + thumb geometry ---------------------------- */
    // One rAF-throttled pass reads the grid and writes the gizmo. Runs on
    // every grid scroll, window resize, board render, and grid box change
    // (the ResizeObserver below catches the zoom toggle, which re-widths
    // the strip without a render).
    var raf = 0;
    function schedule() {
        if (raf) { return; }
        raf = requestAnimationFrame(function () { raf = 0; sync(); });
    }
    function sync() {
        if (!fab) { return; }
        var r = range();
        var show = r > SETTLE;
        if (show !== visible) {
            visible = show;
            fab.classList.toggle('is-visible', show);
        }
        if (!show) { return; }
        var tw = track.clientWidth;
        if (!tw) { return; }                       // hidden board; nothing to draw
        var thw = Math.max(MIN_THUMB, Math.round(tw * grid.clientWidth / grid.scrollWidth));
        var play = tw - thw;
        var x = play > 0 ? Math.round(play * (grid.scrollLeft / r)) : 0;
        thumb.style.width = thw + 'px';
        thumb.style.transform = 'translateX(' + clamp(x, 0, play) + 'px)';
        track.setAttribute('aria-valuenow', String(Math.round(clamp(grid.scrollLeft / r, 0, 1) * 100)));
    }

    /* ---- shared pan-state chrome --------------------------------------- */
    // body class: grabbing cursor + selection kill (board.blade).
    // grid class: the dot grid strengthens, borrowing .pb-placing's level.
    function panUiOn()  { document.body.classList.add('pbp-panning');    grid.classList.add('pb-panning'); }
    function panUiOff() { document.body.classList.remove('pbp-panning'); grid.classList.remove('pb-panning'); }

    /* ---- the gizmo ------------------------------------------------------ */
    function build() {
        fab = document.createElement('div');
        fab.className = 'fab pbp-fab';
        fab.innerHTML =
            '<div class="pbp-track" role="scrollbar" tabindex="0" aria-controls="pb-grid"' +
                ' aria-orientation="horizontal" aria-label="Pan the board"' +
                ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
                '<div class="pbp-thumb">' +
                    // The old scrollbar thumb's ↔ arrows, reborn inline.
                    '<svg viewBox="0 0 24 12" aria-hidden="true">' +
                        '<g fill="none" stroke="currentColor" stroke-width="1.5"' +
                          ' stroke-linecap="round" stroke-linejoin="round">' +
                            '<path d="M6 3 3 6l3 3"/>' +
                            '<path d="M18 3l3 3-3 3"/>' +
                            '<path d="M3 6h18"/>' +
                        '</g>' +
                    '</svg>' +
                '</div>' +
            '</div>';
        // Inside #pb-board — NOT the transformed .pb-scroll (position:fixed
        // breaks under a transformed ancestor; boardEl never transforms).
        // Living here also lets the edit-mode stand-down be a plain
        // descendant selector, and a missing/hidden board hides it for free.
        boardEl.appendChild(fab);
        track = fab.querySelector('.pbp-track');
        thumb = fab.querySelector('.pbp-thumb');
    }

    // One pointerdown handler covers both gestures: a press on the thumb
    // grabs it where it is; a press on the empty track first JUMPS the
    // thumb's centre to the pointer, then the same drag state takes over.
    var tdrag = null;   // { x, left, ratio } — px at grab, scrollLeft at grab, scroll-px per pointer-px
    function trackDown(e) {
        if (e.button != null && e.button !== 0) { return; }
        var r = range();
        if (r <= 0) { return; }
        var tw = track.clientWidth, thw = thumb.offsetWidth, play = tw - thw;
        if (play <= 0) { return; }
        if (!closest(e.target, '.pbp-thumb')) {
            var rect = track.getBoundingClientRect();
            grid.scrollLeft = clamp(((e.clientX - rect.left - thw / 2) / play) * r, 0, r);
        }
        tdrag = { x: e.clientX, left: grid.scrollLeft, ratio: r / play };
        try { track.setPointerCapture(e.pointerId); } catch (_) {}
        panUiOn();
        e.preventDefault();     // no text selection, no touch scroll from the pill
        try { track.focus(); } catch (_) {}   // arrows work right after; the ring
                                              // stays keyboard-only (focus-visible)
    }
    function trackMove(e) {
        if (!tdrag) { return; }
        // Write scrollLeft only; sync() repositions the thumb from the
        // grid's scroll event — one source of truth, no drift.
        grid.scrollLeft = tdrag.left + (e.clientX - tdrag.x) * tdrag.ratio;
    }
    function trackUp() {
        if (!tdrag) { return; }
        tdrag = null;
        panUiOff();
    }

    // Keyboard: one column per arrow press (live pitch from the board's
    // metrics, so an Aa size change keeps the step honest).
    function trackKey(e) {
        var r = range();
        if (r <= 0) { return; }
        var m = B.gridMetrics ? B.gridMetrics() : null;
        var step = m ? (m.colW + m.colGap) : 200;
        if      (e.key === 'ArrowLeft')  { grid.scrollLeft -= step; }
        else if (e.key === 'ArrowRight') { grid.scrollLeft += step; }
        else if (e.key === 'Home')       { grid.scrollLeft = 0; }
        else if (e.key === 'End')        { grid.scrollLeft = r; }
        else { return; }
        e.preventDefault();
    }

    /* ---- background pan (mouse only) ------------------------------------ */
    var bg = null;   // { x, y, sl, id, engaged }
    function bgDown(e) {
        if (e.pointerType !== 'mouse' || e.button !== 0) { return; }
        if (e.target !== grid) { return; }        // empty background only —
                                                  // cards/chips are children;
                                                  // SVG layers pass through
        if (dragging()) { return; }
        bg = { x: e.clientX, y: e.clientY, sl: grid.scrollLeft, id: e.pointerId, engaged: false };
        document.addEventListener('pointermove', bgMove);
        document.addEventListener('pointerup', bgUp);
        document.addEventListener('pointercancel', bgUp);
    }
    function bgMove(e) {
        if (!bg) { return; }
        var dx = e.clientX - bg.x;
        var dy = e.clientY - bg.y;
        if (!bg.engaged) {
            if (dx < THRESH && dx > -THRESH && dy < THRESH && dy > -THRESH) { return; }
            bg.engaged = true;
            try { grid.setPointerCapture(bg.id); } catch (_) {}
            panUiOn();
        }
        e.preventDefault();
        // Horizontal is ABSOLUTE from the grab (screen px ÷ zoom → strip
        // units; the page can't scroll horizontally, so clientX never
        // drifts). Vertical is INCREMENTAL: scrolling the page shifts
        // where the next clientY lands, so the reference resets each step.
        grid.scrollLeft = bg.sl - dx / zoom();
        if (dy) { window.scrollBy(0, -dy); bg.y = e.clientY; }
    }
    function swallowClick(e) { e.stopPropagation(); e.preventDefault(); }
    function bgUp() {
        if (!bg) { return; }
        var engaged = bg.engaged;
        bg = null;
        document.removeEventListener('pointermove', bgMove);
        document.removeEventListener('pointerup', bgUp);
        document.removeEventListener('pointercancel', bgUp);
        panUiOff();
        if (engaged) {
            // The pan's tail CLICK still fires at the grid; swallow exactly
            // one, in the capture phase, so edit mode's background-deselect
            // (or any future grid click handler) never mistakes a pan for
            // a click. A sub-threshold press never gets here — its click
            // proceeds untouched.
            document.addEventListener('click', swallowClick, true);
            setTimeout(function () {
                document.removeEventListener('click', swallowClick, true);
            }, 0);
        }
    }

    /* ---- attach ---------------------------------------------------------- */
    function attach() {
        B = window.MBPericopeBoard;
        if (!B || !B.grid || !B.boardEl) { return; }
        grid = B.grid;
        boardEl = B.boardEl;

        build();

        track.addEventListener('pointerdown', trackDown);
        track.addEventListener('pointermove', trackMove);   // capture routes moves here
        track.addEventListener('pointerup', trackUp);
        track.addEventListener('pointercancel', trackUp);
        track.addEventListener('keydown', trackKey);

        grid.addEventListener('pointerdown', bgDown);

        grid.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        document.addEventListener('mb:pericope-rendered', schedule);
        // The zoom toggle re-widths the strip WITHOUT a render; the grid's
        // own box change is the one signal that always fires for it.
        if (window.ResizeObserver) {
            new ResizeObserver(schedule).observe(grid);
        }

        sync();
        // Deployment marker — stale-cache tripwire, like the board's.
        if (window.console && console.info) { console.info('[pericope] pan r1'); }

        // Debug surface, matching the suite's habit of small public APIs.
        window.MBPericopePan = { sync: sync, el: function () { return fab; } };
    }

    if (window.MBPericopeBoard) { attach(); }
    else { document.addEventListener('mb:pericope-board-ready', attach); }
})();
