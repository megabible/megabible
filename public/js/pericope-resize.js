/* ======================================================================
   PERICOPE BOARD — HOLD-TO-RESIZE              public/js/pericope-resize.js
   ----------------------------------------------------------------------
   Phase C of the exact-fill plan. PRESS AND HOLD an expanded verse card's
   collapse button (bottom-right corner) for HOLD_MS and the card enters
   free-form resize mode: dragging from that corner resizes it in whole
   CELLS, live, within the rules the content budget allows —

     • width  is EARNED: resizeBudget().maxCw stops a one-verse card from
       going four columns wide (its text couldn't fill them);
     • height may stretch to maxRhAt(cw) and no further (no dead space),
       and may always shrink to minRh — the in-card scroll absorbs it.

   A quick CLICK on the button still collapses, exactly as before: the
   hold timer only fires after HOLD_MS with the pointer still (≤ SLOP px).
   Once resize mode is entered the interaction is CONSUMED — releasing
   without a size change exits the mode without collapsing (nobody wants a
   fizzled resize to slam the card shut), and the trailing click is
   swallowed through the board's usual swallowNextClick.

   LIVE PREVIEW: every candidate size runs the store's previewMove() with
   the would-be span; neighbours FLIP to their pushed rows the same way
   the drag module animates them, so a growing card visibly shoves the
   column below it before anything is written. Group outlines stay where
   they are during the gesture (they're derived from the STORE) and snap
   to truth on commit — same behaviour drag has.

   COMMIT is MBPericope.resizeCard(): a real edit (bumps updated, syncs
   the index) that also remembers the size (ew/eh), so collapse → expand
   restores it, reflow leaves it alone (data-manual), and the share codec
   carries it (the p1 h flag). Escape or pointercancel abandons cleanly.

   Guards: never while the drag module owns the pointer (and drag returns
   the favour via MBPericopeResize.active()); zoomed and edit-mode boards
   never get here at all — their CSS already makes the button inert.

   Attaches through window.MBPericopeBoard on mb:pericope-board-ready,
   like the drag, edit and share modules. Vanilla ES5.
   ====================================================================== */
(function () {
    'use strict';

    var HOLD_MS = 450;   // KNOB: press-and-hold time to enter resize mode
    var SLOP    = 6;     // KNOB: px of pre-hold wiggle before it's a "move"
    var FLIP_MS = 150;   // neighbour push animation (matches drag's feel)
    var MOBILE_MAX_CW = 2;  // KNOB: widest the GESTURE allows on a phone — a
                            // 3-wide card is most of the viewport there.
                            // Existing wider cards still render; they just
                            // can't be made wider FROM a phone.
    var MOBILE_BP = 520;    // matches the blade's column breakpoint (px)

    var B = null, P = null, grid = null, boardEl = null;

    var arm = null;      // pointer is down on the button, timer running
    var rz  = null;      // resize mode is LIVE

    function closest(el, sel) { return (el && el.closest) ? el.closest(sel) : null; }
    function active() { return !!rz; }

    /* =================== arming (hold vs click) =================== */

    function onDown(e) {
        if (rz || arm) { return; }
        if (e.button != null && e.button !== 0) { return; }
        if (window.MBPericopeDrag && window.MBPericopeDrag.active()) { return; }
        var btn = closest(e.target, '.peri-card-min');
        if (!btn) { return; }
        var card = closest(btn, '.peri-card');
        if (!card || card.getAttribute('aria-expanded') !== 'true') { return; }
        // NO preventDefault here — a quick tap must stay a normal click so
        // the board's existing handler can collapse the card.
        arm = {
            id: card.getAttribute('data-id'),
            el: card,
            pointerId: e.pointerId,
            x: e.clientX, y: e.clientY,
            timer: setTimeout(enter, HOLD_MS)
        };
        document.addEventListener('pointermove', armMove);
        document.addEventListener('pointerup', armUp);
        document.addEventListener('pointercancel', armUp);
    }

    function disarm() {
        if (!arm) { return; }
        clearTimeout(arm.timer);
        document.removeEventListener('pointermove', armMove);
        document.removeEventListener('pointerup', armUp);
        document.removeEventListener('pointercancel', armUp);
        arm = null;
    }

    function armMove(e) {
        if (!arm) { return; }
        var dx = e.clientX - arm.x, dy = e.clientY - arm.y;
        if (dx * dx + dy * dy > SLOP * SLOP) { disarm(); }   // moved: it's not a hold
    }

    function armUp() { disarm(); }   // released early: the click collapses as ever

    /* =================== resize mode =================== */

    function enter() {
        if (!arm) { return; }
        var id = arm.id, el = arm.el, pid = arm.pointerId;
        disarm();

        var board  = B.board();
        var budget = B.resizeBudget(id);
        if (!board || !budget) { return; }   // legacy blob mid-heal etc. — quietly stand down

        var card = null, i;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === id) { card = board.cards[i]; break; }
        }
        if (!card) { return; }

        var m = B.gridMetrics();
        var rect = el.getBoundingClientRect();   // the corner anchor: top-left stays put

        // The card's grid-column START track, read off the inline style that
        // placeStyle wrote — the preview keeps it and only changes the span.
        var colStart = parseInt(el.style.gridColumnStart, 10) || 1;

        // The width ceiling for THIS gesture: the content budget, tightened
        // to MOBILE_MAX_CW on a phone viewport (checked once at entry).
        var gestureMaxCw = budget.maxCw;
        if ((window.innerWidth || document.documentElement.clientWidth) <= MOBILE_BP) {
            gestureMaxCw = Math.min(gestureMaxCw, MOBILE_MAX_CW);
        }

        rz = {
            id: id, el: el, board: board, budget: budget, m: m,
            maxCw: gestureMaxCw,
            pointerId: pid,
            left: rect.left, top: rect.top,
            colStart: colStart,
            rowStart: card.row || 1,
            origCw: card.cw || 1, origRh: card.rh || 1,
            cw: card.cw || 1, rh: card.rh || 1,
            col: (typeof card.col === 'number') ? card.col : 1,
            row: card.row || 1,
            spans: {},        // cardId -> rh, for neighbour gridRow spans
            cancelled: false
        };
        for (i = 0; i < board.cards.length; i++) {
            rz.spans[board.cards[i].id] = board.cards[i].rh || 1;
        }

        el.classList.add('pb-resizing');
        boardEl.classList.add('pb-resize-active');

        document.addEventListener('pointermove', rzMove);
        document.addEventListener('pointerup', rzUp);
        document.addEventListener('pointercancel', rzCancel);
        document.addEventListener('keydown', rzKey);
        document.addEventListener('contextmenu', eatMenu, true);
    }

    function eatMenu(e) { if (rz) { e.preventDefault(); } }

    function rzKey(e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && rz) { rzCancel(); }
    }

    // Pointer position → candidate cell size, clamped by the budget. The
    // top-left corner is the anchor: the pointer describes the bottom-right.
    function rzMove(e) {
        if (!rz) { return; }
        e.preventDefault();   // resize owns the pointer now: no scroll, no select
        var m = rz.m;
        var w = e.clientX - rz.left;
        var h = e.clientY - rz.top;
        var cw = Math.round((w + m.colGap) / (m.colW + m.colGap));
        var rh = Math.round((h + m.rowGap) / (m.rowUnit + m.rowGap));
        if (cw < 1) { cw = 1; }
        if (cw > rz.maxCw) { cw = rz.maxCw; }
        if (rh < rz.budget.minRh) { rh = rz.budget.minRh; }
        var maxRh = rz.budget.maxRhAt(cw);
        if (rh > maxRh) { rh = maxRh; }
        if (cw === rz.cw && rh === rz.rh) { return; }
        rz.cw = cw; rz.rh = rh;
        preview(cw, rh);
    }

    // Apply a candidate size: the target gets its would-be span inline; every
    // pushed neighbour FLIPs to its previewed row (the drag module's exact
    // technique — set the real gridRow, then play the inverse transform).
    function preview(cw, rh) {
        var rows = P.previewMove(rz.board, rz.id, rz.col, rz.row, cw, rh);
        var els = grid.querySelectorAll('.peri-card'), i, el, id, before = {}, after, delta;
        var z = B.zoom ? (B.zoom() || 1) : 1;

        for (i = 0; i < els.length; i++) {
            id = els[i].getAttribute('data-id');
            if (rows[id] != null) { before[id] = els[i].getBoundingClientRect().top; }
        }

        rz.el.style.gridColumn = rz.colStart + ' / span ' + cw;
        rz.el.style.gridRow    = (rows[rz.id] != null ? rows[rz.id] : rz.rowStart) + ' / span ' + rh;

        for (i = 0; i < els.length; i++) {
            el = els[i]; id = el.getAttribute('data-id');
            if (id === rz.id || rows[id] == null) { continue; }
            el.style.transition = 'none';
            el.style.transform  = '';
            el.style.gridRow    = rows[id] + ' / span ' + (rz.spans[id] || 1);
            after = el.getBoundingClientRect().top;
            delta = (before[id] - after) / z;
            if (delta) { el.style.transform = 'translate3d(0,' + delta + 'px,0)'; }
        }
        void grid.offsetWidth;   // flush the inverse transforms
        for (i = 0; i < els.length; i++) {
            el = els[i]; id = el.getAttribute('data-id');
            if (id === rz.id || rows[id] == null) { continue; }
            el.style.transition = 'transform ' + FLIP_MS + 'ms ease';
            el.style.transform  = '';
        }
    }

    function rzEnd() {
        if (!rz) { return; }
        document.removeEventListener('pointermove', rzMove);
        document.removeEventListener('pointerup', rzUp);
        document.removeEventListener('pointercancel', rzCancel);
        document.removeEventListener('keydown', rzKey);
        document.removeEventListener('contextmenu', eatMenu, true);
        rz.el.classList.remove('pb-resizing');
        boardEl.classList.remove('pb-resize-active');
        // The trailing click (pointerup on the button) must NOT collapse the
        // card — entering resize mode consumed this interaction.
        B.swallowNextClick();
        rz = null;
    }

    function rzUp() {
        if (!rz) { return; }
        var changed = !rz.cancelled && (rz.cw !== rz.origCw || rz.rh !== rz.origRh);
        if (changed) {
            P.resizeCard(B.boardId(), rz.id, rz.cw, rz.rh);
        }
        rzEnd();
        B.render();   // truth repaint either way: commit lands, or preview unwinds
    }

    function rzCancel() {
        if (!rz) { return; }
        rz.cancelled = true;
        rzEnd();
        B.render();
    }

    /* =================== wiring =================== */

    function init() {
        B = window.MBPericopeBoard;
        P = window.MBPericope;
        if (!B || !P || !P.resizeCard || !B.resizeBudget) { return; }
        grid = B.grid;
        boardEl = B.boardEl;
        grid.addEventListener('pointerdown', onDown);
    }

    window.MBPericopeResize = { active: active };

    function boot() {
        if (window.MBPericopeBoard) { init(); }
        else { document.addEventListener('mb:pericope-board-ready', init); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
})();
