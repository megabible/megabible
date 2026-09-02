/* ======================================================================
   PERICOPE BOARD — DRAG TO PLACE                 public/js/pericope-drag.js
   ----------------------------------------------------------------------
   Phase 3 of the board redesign. Grab a card by its grip and drop it into
   a grid cell. Companion to pericope-board.js, which publishes
   window.MBPericopeBoard (grid, metrics, layout, render, pan) at the end of
   its init and fires `mb:pericope-board-ready`; this file attaches then.

   WHAT A DRAG DOES
     • GHOST — a faint clone of the card rides the pointer (mouse or finger)
       in a position:fixed layer on <body>, OUTSIDE the board wrapper, so a
       transformed (zoomed, Phase 4) board can never trap it. The original
       card goes transparent but stays in layout.
     • REACH — drop targets extend ONE column beyond the outermost occupied
       columns. Nothing changes at grab time: the board's resting layout
       already keeps one blank column's width on each side (the anchor's
       breathing-room floor on the left, its .pb-extent element on the
       right), so both edge columns physically exist before the drag starts.
       No padding shuffle, no scroll compensation — the two things that used
       to shift the view on grab.
     • DROP INDICATOR — one dashed outline, always pixel-positioned inside the
       grid (never grid-placed), so it looks the same over an occupied cell,
       an empty one, or a column that doesn't exist yet.
     • LIVE PREVIEW — on every target change the store's previewMove() says
       where every other card would land under the push-down rule; cards that
       move are animated to their preview rows with FLIP (measure, re-place,
       inverse transform, transition to none) and animate back when the
       target moves on. Nothing is written until the drop.
     • HOVER-TO-ADOPT (Phase 5b) — hold the target cell inside a FOREIGN
       group's box for ADOPT_MS and the card is staged for adoption: the
       indicator and ghost take the group's colour and the group brightens.
       Dropping while still inside the box commits it (addToGroup runs
       BEFORE moveCard, so the territory rule sees the card as a member and
       leaves it in peace); dragging away first cancels silently. A card's
       own group never courts it, and this drag is the ONLY way to grow an
       existing group — edit mode's typed selections only create and strip.
     • DROP — moveCard() commits, then the board re-renders. The pan the user
       had (including any edge auto-scroll during the drag) is handed back
       first, so the view doesn't snap. Drops onto the card's own cell are a
       no-op (no write, no "updated" bump).
     • CANCEL — Escape, a window resize, or pointercancel abandons the drag
       and the preview reverts.

   Coordinates: everything is computed in the grid's own (unscaled) space
   through toGrid(), which divides by the board's zoom level (Phase 4). The
   zoom itself is the board's (MBPericopeBoard.zoom / setZoom); this file
   only reads it — except for AUTO-ZOOM: a drag that starts from a TOUCH
   pointer on an unzoomed board zooms out first (around the finger) so the
   phone shows much more of the board while the card is in hand, and zooms
   back in around the drop point when it lets go.

   Vanilla ES5 (var / function), matching the other shared scripts.
   ====================================================================== */
(function () {
    'use strict';

    var REACH   = 1;       // drop targets reach ONE column beyond the outermost cards
    var EDGE    = 48;      // px from a viewport/strip edge that auto-scrolls
    var STEP_X  = 18;      // auto-scroll px per pointermove (horizontal, strip)
    var STEP_Y  = 14;      // auto-scroll px per pointermove (vertical, page)
    var FLIP_MS = 180;     // preview shuffle animation
    var ADOPT_MS = 1500;   // KNOB: hover time over a foreign group before it adopts the card
    var GHOST_OPACITY = 0.62;

    var B = null;          // window.MBPericopeBoard
    var grid = null;
    var drag = null;       // live drag state, or null

    function zoom() { return (B && B.zoom) ? B.zoom() : 1; }   // board scale, 1 = none
    function editing() { return !!(window.MBPericopeEdit && window.MBPericopeEdit.active()); }

    function closest(el, sel) { return (el && el.closest) ? el.closest(sel) : null; }
    function active() { return !!drag; }

    // A card's column, defaulting ONLY on absence. NEVER `c.col || 1`:
    // column 0 is legal and falsy, so || would silently misread it as home.
    function colOf(c) { return (typeof c.col === 'number') ? c.col : 1; }

    // Pointer → grid space: px from the grid's padding-box left edge (scroll
    // included) and top edge, in the grid's own unscaled units.
    function toGrid(e) {
        var rect = grid.getBoundingClientRect();
        var z = zoom();
        return {
            x: (e.clientX - rect.left) / z + grid.scrollLeft,
            y: (e.clientY - rect.top)  / z
        };
    }

    // ---- start -----------------------------------------------------------

    function dragStart(handle, e) {
        var card = closest(handle, '.peri-card');
        var boardId = B.boardId();
        if (!card || !boardId) { return; }
        var board = window.MBPericope.get(B.slug());
        if (!board) { return; }

        var id = card.getAttribute('data-id');
        var me = null, i, c;
        for (i = 0; i < board.cards.length; i++) { if (board.cards[i].id === id) { me = board.cards[i]; break; } }
        if (!me) { return; }

        // AUTO-ZOOM (touch only, and only if the board isn't already zoomed):
        // zoom out around the finger BEFORE any measurement below, so the
        // reach, padding and grab offset are all taken at the drag's scale.
        var autoZoom = false;
        if (e.pointerType === 'touch' && B.setZoom && B.zoom() === 1) {
            B.setZoom(B.ZOOM_OUT, e.clientX, e.clientY);
            autoZoom = (B.zoom() !== 1);
        }

        var m = B.gridMetrics(), cellX = m.colW + m.colGap, cellY = m.rowUnit + m.rowGap;
        var layout = B.layout();
        var basePadL = parseFloat(getComputedStyle(grid).paddingLeft) || 0;

        // Snapshot every card's row and element for the preview.
        var rows = {}, applied = {}, els = {}, spans = {}, cws = {}, list, el, cid;
        for (i = 0; i < board.cards.length; i++) { c = board.cards[i]; rows[c.id] = c.row; applied[c.id] = c.row; }
        list = grid.querySelectorAll('.peri-card');
        for (i = 0; i < list.length; i++) {
            el = list[i]; cid = el.getAttribute('data-id');
            els[cid] = el; spans[cid] = parseInt(el.getAttribute('data-rh') || '1', 10);
            cws[cid] = parseInt(el.getAttribute('data-cw') || '1', 10);
        }

        // Group territory snapshot (Phase 5b): each foreign group's bounding
        // box in track/row space, for the hover-to-adopt test. The dragged
        // card's own group never courts it. Boxes are frozen for the drag —
        // membership can't change mid-hold.
        var groupBoxes = [], own = null, gi, g, mm, mc, byId = {}, t, bx;
        for (i = 0; i < board.cards.length; i++) { byId[board.cards[i].id] = board.cards[i]; }
        for (gi = 0; gi < (board.groups || []).length; gi++) {
            g = board.groups[gi];
            if (g.cards.indexOf(id) !== -1) { own = g.id; continue; }
            bx = { gid: g.id, color: g.color, tMin: Infinity, tMax: -Infinity, rMin: Infinity, rMax: -Infinity };
            for (mm = 0; mm < g.cards.length; mm++) {
                mc = byId[g.cards[mm]];
                if (!mc) { continue; }
                t = (layout.map[colOf(mc)] || 1) - 1;
                bx.tMin = Math.min(bx.tMin, t);
                bx.tMax = Math.max(bx.tMax, t + (mc.cw || 1) - 1);
                bx.rMin = Math.min(bx.rMin, mc.row || 1);
                bx.rMax = Math.max(bx.rMax, (mc.row || 1) + (mc.rh || 1) - 1);
            }
            if (bx.tMin !== Infinity) { groupBoxes.push(bx); }
        }

        var rect0 = card.getBoundingClientRect();

        drag = {
            id: id, boardId: boardId, board: board,
            cw: parseInt(card.getAttribute('data-cw') || '1', 10),
            rh: parseInt(card.getAttribute('data-rh') || '1', 10),
            origCol: me.col, origRow: me.row,
            cellX: cellX, cellY: cellY, colGap: m.colGap, rowGap: m.rowGap,
            padL: basePadL, padT: m.padT || 0, count: layout.count,
            rows: rows, applied: applied, els: els, spans: spans, cws: cws,
            col: null, row: null, trackIdx: 0, moved: false,
            grabX: e.clientX - rect0.left, grabY: e.clientY - rect0.top,
            autoZoom: autoZoom, lastX: e.clientX, lastY: e.clientY,
            groupBoxes: groupBoxes, ownGroup: own,
            adoptTarget: null, adoptTimer: null, adopted: null,
            ghost: null, drop: null
        };

        // The drop indicator (hidden until the first move).
        var drop = document.createElement('div');
        drop.className = 'pb-drop'; drop.hidden = true;
        grid.appendChild(drop);
        drag.drop = drop;

        // The ghost: a clone in a fixed layer on <body>.
        var ghost = card.cloneNode(true);
        ghost.classList.remove('is-dragging');
        ghost.classList.add('pb-ghost');
        ghost.removeAttribute('data-id');
        ghost.removeAttribute('tabindex');
        ghost.setAttribute('aria-hidden', 'true');
        // Only the reference header rides the pointer — the verse text (and
        // switcher / pager / corner buttons) would hide the board underneath —
        // but the ghost keeps the card's full SIZE, so its shape matches the
        // drop outline (and, zoomed, the grab point stays inside it wherever
        // the card was gripped).
        var strip = ghost.querySelectorAll('.peri-card-snip, .peri-card-text, .peri-card-tx, .peri-card-min, .peri-card-del');
        for (i = 0; i < strip.length; i++) {
            if (strip[i].parentNode) { strip[i].parentNode.removeChild(strip[i]); }
        }
        ghost.style.width  = card.offsetWidth + 'px';
        ghost.style.height = card.offsetHeight + 'px';
        document.body.appendChild(ghost);
        drag.ghost = ghost;
        moveGhost(e);

        grid.classList.add('pb-placing');
        document.body.classList.add('pb-dragging');
        card.classList.add('is-dragging');

        try { handle.setPointerCapture(e.pointerId); } catch (_) {}
        document.addEventListener('pointermove', dragMove);
        document.addEventListener('pointerup', dragDrop);
        document.addEventListener('pointercancel', dragCancel);
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', dragCancel);
    }

    function moveGhost(e) {
        if (!drag || !drag.ghost) { return; }
        drag.ghost.style.transform = 'translate3d(' + (e.clientX - drag.grabX) + 'px,' +
                                     (e.clientY - drag.grabY) + 'px,0) scale(' + zoom() + ')';
    }

    // ---- move ------------------------------------------------------------

    function dragMove(e) {
        if (!drag) { return; }
        e.preventDefault();
        drag.moved = true;
        drag.lastX = e.clientX; drag.lastY = e.clientY;
        moveGhost(e);

        var p = toGrid(e);
        // 1-wide cards target the cell UNDER THE POINTER — the tuned feel,
        // unchanged. A MULTI-COLUMN card (Phase C sizes) targets the track
        // nearest its GHOST'S LEFT EDGE instead: the grip spans the card's
        // full width, so a wide card grabbed by its right side would
        // otherwise land up to cw−1 tracks right of where the ghost shows
        // it. grabX is screen px at the drag's scale; /zoom() converts it
        // into grid units (same convention as the FLIP deltas).
        var cx, trackIdx;
        if (drag.cw > 1) {
            cx = p.x - drag.grabX / zoom() - drag.padL;
            trackIdx = Math.round(cx / drag.cellX);
        } else {
            cx = p.x - drag.padL;                        // from track 1's left edge
            trackIdx = Math.floor(cx / drag.cellX);      // 0-based; <0 or >= count is a new column
        }
        var trackRow = Math.floor((p.y - drag.padT) / drag.cellY);
        var lo = -REACH, hi = drag.count - 1 + REACH - (drag.cw - 1);   // one new column each side, max
        if (trackIdx < lo) { trackIdx = lo; }
        if (trackIdx > hi) { trackIdx = hi; }
        if (trackRow < 0) { trackRow = 0; }

        var col = B.colAtTrack(trackIdx), row = trackRow + 1;
        if (col !== drag.col || row !== drag.row) {
            drag.col = col; drag.row = row; drag.trackIdx = trackIdx;
            placeDrop();
            preview();
            courtship();
        }

        autoScroll(e);
    }

    // ---- hover-to-adopt (Phase 5b) --------------------------------------

    // The group box (if any) the current target cell intersects.
    function boxUnderTarget() {
        var cl = drag.trackIdx, cr = drag.trackIdx + drag.cw - 1;
        var ct = drag.row, cb = drag.row + drag.rh - 1;
        var i, b;
        for (i = 0; i < drag.groupBoxes.length; i++) {
            b = drag.groupBoxes[i];
            if (cl <= b.tMax && cr >= b.tMin && ct <= b.rMax && cb >= b.rMin) { return b; }
        }
        return null;
    }

    // Runs on every TARGET CHANGE: manage the courtship timer and any
    // staged adoption. Leaving an adopted group's box cancels the adoption;
    // hovering a different group re-arms the timer for it.
    function courtship() {
        var b = boxUnderTarget();
        if (drag.adopted) {
            if (b && b.gid === drag.adopted.gid) { return; }   // still home
            setAdopted(null);                                   // wandered off — cancel
        }
        if (!b) {
            if (drag.adoptTimer) { clearTimeout(drag.adoptTimer); drag.adoptTimer = null; }
            drag.adoptTarget = null;
            return;
        }
        if (drag.adoptTarget === b.gid) { return; }             // timer already running for it
        if (drag.adoptTimer) { clearTimeout(drag.adoptTimer); }
        drag.adoptTarget = b.gid;
        drag.adoptTimer = setTimeout(function () {
            drag.adoptTimer = null;
            // Confirm the hold is still over the same box when the timer lands.
            var cur = boxUnderTarget();
            if (cur && cur.gid === drag.adoptTarget) { setAdopted(cur); }
        }, ADOPT_MS);
    }

    // Stage / clear an adoption and paint the courtship feedback.
    function setAdopted(b) {
        drag.adopted = b ? { gid: b.gid, color: b.color } : null;
        var gp = b ? 'var(--tl-' + b.color + ')' : '';
        if (drag.drop) {
            drag.drop.classList.toggle('is-adopting', !!b);
            drag.drop.style.setProperty('--gp', gp);
        }
        if (drag.ghost) {
            drag.ghost.classList.toggle('is-adopting', !!b);
            drag.ghost.style.setProperty('--gp', gp);
        }
        var i, sh, list = grid.querySelectorAll('.pb-group.is-adopting');
        for (i = 0; i < list.length; i++) { list[i].classList.remove('is-adopting'); }
        if (b) {
            sh = grid.querySelector('.pb-group[data-group="' + b.gid + '"]');
            if (sh) { sh.classList.add('is-adopting'); }
        }
    }

    function placeDrop() {
        var d = drag.drop;
        var left  = drag.padL + drag.trackIdx * drag.cellX;
        var width = drag.cw * drag.cellX - drag.colGap;
        // Mobile's slim left margin means the new-column drop left of the
        // leftmost cards (column 0 on a fresh board) starts left of the
        // grid's clip edge (content x < 0 is unreachable and unpaintable).
        // Clamp to a sliver pinned at the edge — "a new column goes here" —
        // rather than losing the indicator entirely.
        if (left < 0) {
            width = Math.max(drag.padL - drag.colGap, 12);
            left  = 0;
        }
        d.hidden = false;
        d.style.left   = left + 'px';
        d.style.top    = (drag.padT + (drag.row - 1) * drag.cellY) + 'px';
        d.style.width  = width + 'px';
        d.style.height = (drag.rh * drag.cellY - drag.rowGap) + 'px';
    }

    // Ask the store where everyone would land, move the changed cards to
    // those rows with FLIP, and grow the drop room if the board got taller.
    function preview() {
        var rows = window.MBPericope.previewMove(drag.board, drag.id, drag.col, drag.row);
        var changed = [], before = {}, id, el, after, delta, i;

        for (id in rows) {
            if (!rows.hasOwnProperty(id) || id === drag.id) { continue; }
            el = drag.els[id];
            if (!el || rows[id] === drag.applied[id]) { continue; }
            changed.push(id);
            // Visual position now — including any mid-flight transform from
            // a previous shuffle, so a fast pointer never makes a card jump.
            before[id] = el.getBoundingClientRect().top;
        }

        if (changed.length) {
            for (i = 0; i < changed.length; i++) {
                id = changed[i]; el = drag.els[id];
                el.style.transition = 'none';
                el.style.transform  = '';
                el.style.gridRow    = rows[id] + ' / span ' + drag.spans[id];
                drag.applied[id]    = rows[id];
                after = el.getBoundingClientRect().top;          // new layout position
                delta = (before[id] - after) / zoom();           // transforms are in unscaled units
                el.style.transform = 'translate3d(0,' + delta + 'px,0)';
            }
            void grid.offsetWidth;                                // flush the inverse transforms
            for (i = 0; i < changed.length; i++) {
                el = drag.els[changed[i]];
                el.style.transition = 'transform ' + FLIP_MS + 'ms ease';
                el.style.transform  = '';
            }
        }

        // Drop room: monotonic during a drag, so the page never shrinks under
        // the pointer while the preview is still moving things around. The
        // resting min-height (board's) already covers REST_ROWS below the
        // cards; grow it only if the preview pushed past that.
        var bottom = drag.row + drag.rh - 1;
        for (id in rows) {
            if (rows.hasOwnProperty(id) && id !== drag.id) {
                bottom = Math.max(bottom, rows[id] + (drag.spans[id] || 1) - 1);
            }
        }
        var need = drag.padT + (bottom + B.REST_ROWS) * drag.cellY - drag.rowGap;
        if (need > (parseFloat(grid.style.minHeight) || 0)) {
            grid.style.minHeight = need + 'px';
        }

        previewOwnGroup(rows);
    }

    // GROUP-FOLLOW (r14): while a MEMBER card is dragged, its own group's
    // outline tracks the hover live — the box is recomputed from the other
    // members' PREVIEWED rows (they may be getting pushed too) plus the
    // dragged card's candidate cell, so the person watches the territory
    // reshape before dropping. The board owns the pixel maths
    // (B.previewGroupCells); the drop/cancel render restores truth.
    function previewOwnGroup(rows) {
        if (!drag || !drag.ownGroup || drag.col == null || !B.previewGroupCells) { return; }
        var g = null, i, c, id, cells = [], byId = {};
        for (i = 0; i < (drag.board.groups || []).length; i++) {
            if (drag.board.groups[i].id === drag.ownGroup) { g = drag.board.groups[i]; break; }
        }
        if (!g) { return; }
        for (i = 0; i < drag.board.cards.length; i++) { byId[drag.board.cards[i].id] = drag.board.cards[i]; }
        for (i = 0; i < g.cards.length; i++) {
            id = g.cards[i]; c = byId[id];
            if (!c) { continue; }
            if (id === drag.id) {
                cells.push({ col: drag.col, row: drag.row, cw: drag.cw, rh: drag.rh });
            } else {
                cells.push({
                    col: colOf(c),
                    row: (rows[id] != null ? rows[id] : (c.row || 1)),
                    cw:  (drag.cws[id] || 1),
                    rh:  (drag.spans[id] || 1)
                });
            }
        }
        B.previewGroupCells(drag.ownGroup, cells);
    }

    // Horizontal within the strip, vertical on the page. Screen-space edges.
    function autoScroll(e) {
        var rect = grid.getBoundingClientRect();
        if (e.clientX < rect.left + EDGE)       { grid.scrollLeft -= STEP_X; }
        else if (e.clientX > rect.right - EDGE) { grid.scrollLeft += STEP_X; }
        var vh = window.innerHeight || document.documentElement.clientHeight;
        if (e.clientY < EDGE)           { window.scrollBy(0, -STEP_Y); }
        else if (e.clientY > vh - EDGE) { window.scrollBy(0, STEP_Y); }
    }

    // ---- end -------------------------------------------------------------

    function onKey(e) { if (e.key === 'Escape' || e.key === 'Esc') { dragCancel(); } }
    function dragDrop()   { finish(true); }
    function dragCancel() { finish(false); }

    function finish(commit) {
        if (!drag) { return; }
        document.removeEventListener('pointermove', dragMove);
        document.removeEventListener('pointerup', dragDrop);
        document.removeEventListener('pointercancel', dragCancel);
        document.removeEventListener('keydown', onKey);
        window.removeEventListener('resize', dragCancel);

        var d = drag;
        if (d.adoptTimer) { clearTimeout(d.adoptTimer); d.adoptTimer = null; }

        // Hand the user's CURRENT pan back to the board before it re-anchors
        // (keeps any edge auto-scroll from the drag; this is what used to
        // snap). Nothing was added at grab, so it's a straight difference.
        B.setPan(grid.scrollLeft - B.anchorRest());

        if (d.ghost && d.ghost.parentNode) { d.ghost.parentNode.removeChild(d.ghost); }
        if (d.drop && d.drop.parentNode)   { d.drop.parentNode.removeChild(d.drop); }
        grid.classList.remove('pb-placing');
        document.body.classList.remove('pb-dragging');
        var self = d.els[d.id];
        if (self) { self.classList.remove('is-dragging'); }

        var moved = commit && d.moved && d.col != null;
        var changed = moved && !(d.col === d.origCol && d.row === d.origRow);
        drag = null;   // clear BEFORE render so the board's anchor runs

        if (changed) {
            B.swallowNextClick();
            // Adoption commits only if the card is DROPPED inside the box it
            // was adopted into — and it must land before moveCard, so the
            // territory rule counts the card as a member instead of
            // expelling it from its new home.
            if (d.adopted && dropStillInside(d)) {
                window.MBPericope.addToGroup(d.boardId, d.adopted.gid, [d.id]);
            }
            window.MBPericope.moveCard(d.boardId, d.id, d.col, d.row);
        }
        B.render();   // rebuilds the DOM (clearing every preview transform), restores padding + anchored view

        // If the dropped card landed cut off at a strip edge, nudge the pan
        // just enough to reveal it; otherwise the board stays exactly put.
        if (changed) {
            var el = grid.querySelector('.peri-card[data-id="' + d.id + '"]');
            if (el) {
                var cl = el.offsetLeft, cr = cl + el.offsetWidth;
                var viewL = grid.scrollLeft, viewR = viewL + grid.clientWidth;
                if (cl < viewL)      { B.setPan(B.getPan() - (viewL - cl) - 16); B.applyAnchor(); }
                else if (cr > viewR) { B.setPan(B.getPan() + (cr - viewR) + 16); B.applyAnchor(); }
            }
        }

        // Auto-zoom's other half: back to full size around the drop point.
        if (d.autoZoom && B.setZoom) { B.setZoom(1, d.lastX, d.lastY); }
    }

    // Was the final cell still inside the adopted group's snapshot box?
    function dropStillInside(d) {
        var i, b;
        for (i = 0; i < d.groupBoxes.length; i++) {
            b = d.groupBoxes[i];
            if (b.gid !== d.adopted.gid) { continue; }
            return d.trackIdx <= b.tMax && d.trackIdx + d.cw - 1 >= b.tMin &&
                   d.row <= b.rMax && d.row + d.rh - 1 >= b.rMin;
        }
        return false;
    }

    // ---- wiring ----------------------------------------------------------

    function init() {
        B = window.MBPericopeBoard;
        if (!B || !B.grid || !window.MBPericope) { return; }
        grid = B.grid;

        grid.addEventListener('pointerdown', function (e) {
            // Edit mode × zoom (Phase 5): zoomed-while-editing is the survey
            // view — taps select across the whole board and NOTHING drags.
            if (editing() && zoom() !== 1) { return; }
            // The grip is the handle — except on a ZOOMED (non-edit) board,
            // where the grip is tiny at 60% and cards' inner controls are
            // inert. There the card's TOP region grabs (r14): grip row +
            // reference + translation line, one big handle. The TEXT no
            // longer drags — grabbing a card by its verses moved things by
            // accident too often. The inner elements are pointer-inert while
            // zoomed, so the event target is the CARD itself; the pointer's Y
            // against the translation row's bottom edge decides. Collapsed
            // cards are all header anyway, so the whole card still grabs.
            var handle = closest(e.target, '.peri-grip');
            if (!handle && zoom() !== 1) {
                var zc = closest(e.target, '.peri-card');
                if (zc) {
                    if (zc.getAttribute('aria-expanded') === 'true') {
                        var zEdge = zc.querySelector('.peri-card-tx') || zc.querySelector('.peri-card-top');
                        if (zEdge && e.clientY <= zEdge.getBoundingClientRect().bottom) { handle = zc; }
                    } else {
                        handle = zc;
                    }
                }
            }
            if (!handle) { return; }
            if (e.button != null && e.button !== 0) { return; }   // primary / touch only
            if (drag) { return; }                                  // one drag at a time
            if (window.MBPericopeResize && window.MBPericopeResize.active()) { return; }   // resize owns the pointer (Phase C)
            e.preventDefault();                                    // no text-select / native scroll from the handle
            dragStart(handle, e);
        });
    }

    window.MBPericopeDrag = {
        active:  active,
        cancel:  dragCancel
    };

    if (window.MBPericopeBoard) { init(); }
    else { document.addEventListener('mb:pericope-board-ready', init); }
})();
