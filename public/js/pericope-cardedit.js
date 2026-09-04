/* ======================================================================
   PERICOPE BOARD — CARD EDIT                  public/js/pericope-cardedit.js
   ----------------------------------------------------------------------
   Phase 1 of the card-edit work. Every EXPANDED verse card carries a
   scissors button in its top-right corner (it replaced the trash there;
   notes and headings keep their trash). Pressing it puts THAT ONE card
   into CARD-EDIT: the button tints, the card wears a ring, and a menu
   overlays the verse text with the card's actions —

     COPY         MBPericope.duplicateCard — a twin lands to the right.
     INTERLINEAR  (Phase 3) spawns the original-language child card.
                  Rendered disabled until then.
     DELETE       mbConfirm, then MBPericope.removeCard. Undoable anyway.

   Pressing the tinted scissors again, Escape, or ANY render (copy and
   delete both end in one) leaves card-edit. The state is DOM-only and
   SINGULAR: one card at a time, never stored, never shared.

   Two "edit" ideas share the scissors glyph, so there is a hard wall:
   while the BOARD's edit mode (pericope-edit.js — shimmy, multi-select)
   is live, the per-card scissors are inert (CSS) and this module ignores
   them; entering board edit mode closes an open card-edit first. ZOOMED
   OUT the scissors (and any open menu) are GONE entirely — display:none —
   and the board's setZoom closes an open card-edit on the way out, so the
   state can never linger invisibly behind the survey view.

   NAMING: the DOM state is `is-card-editing` / `card-edit` — distinct from
   `is-editing` (board mode) and from the rename pencil's `pb-edit` class.

   Attaches through window.MBPericopeBoard (mb:pericope-board-ready), like
   the drag, resize and edit modules. Publishes window.MBPericopeCardEdit =
   {active, open, close}. Vanilla ES5.
   ====================================================================== */
(function () {
    'use strict';

    var B = null, grid = null;
    var current = null;   // cardId in card-edit, or null

    function closest(el, s) { return (el && el.closest) ? el.closest(s) : null; }
    function active() { return current; }
    function boardEditing() { return !!(window.MBPericopeEdit && window.MBPericopeEdit.active()); }
    function dragging()     { return !!(window.MBPericopeDrag && window.MBPericopeDrag.active()); }

    var ICON_COPY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    // Two stacked text rows, a smaller one beneath — "the original under the verse".
    var ICON_INTERLINEAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 11h10"/><path d="M4 17h16" stroke-dasharray="2 2.5"/></svg>';
    var ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';

    function cardEl(id) { return grid.querySelector('.peri-card[data-id="' + id + '"]'); }

    function menuHtml() {
        return '<button type="button" class="pce-btn" data-act="copy">' + ICON_COPY +
                   '<span class="pce-label">Copy</span></button>' +
               '<button type="button" class="pce-btn" data-act="interlinear" disabled aria-disabled="true" title="Coming soon">' + ICON_INTERLINEAR +
                   '<span class="pce-label">Interlinear</span><span class="pce-hint">soon</span></button>' +
               '<button type="button" class="pce-btn is-danger" data-act="delete">' + ICON_TRASH +
                   '<span class="pce-label">Delete</span></button>';
    }

    // ---- open / close ----------------------------------------------------

    function open(id) {
        var el = cardEl(id);
        if (!el || el.getAttribute('aria-expanded') !== 'true') { return; }   // expanded verse cards only
        if (current && current !== id) { close(); }
        if (current === id) { return; }
        current = id;

        var menu = document.createElement('div');
        menu.className = 'peri-card-menu';
        menu.setAttribute('role', 'group');
        menu.setAttribute('aria-label', 'Card actions');
        menu.innerHTML = menuHtml();
        // The menu is a SHEET anchored beside the scissors: a direct child
        // of the card, pinned to the top-right by CSS (left of the button),
        // so on a wide card it follows the button and on a 1-column card it
        // simply paints over the reference header. All positioning is CSS —
        // nothing is measured here.
        el.appendChild(menu);
        el.classList.add('is-card-editing');

        var btn = el.querySelector('.peri-card-edit');
        if (btn) { btn.classList.add('is-active'); btn.setAttribute('aria-pressed', 'true'); }
        var first = menu.querySelector('.pce-btn:not([disabled])');
        if (first) { try { first.focus({ preventScroll: true }); } catch (_) { first.focus(); } }
    }

    function close() {
        if (!current) { return; }
        var el = cardEl(current);
        current = null;
        if (!el) { return; }   // the render already took the DOM with it
        var menu = el.querySelector('.peri-card-menu');
        if (menu && menu.parentNode) { menu.parentNode.removeChild(menu); }
        el.classList.remove('is-card-editing');
        var btn = el.querySelector('.peri-card-edit');
        if (btn) { btn.classList.remove('is-active'); btn.setAttribute('aria-pressed', 'false'); btn.blur(); }
    }

    function toggle(id) { if (current === id) { close(); } else { open(id); } }

    // ---- actions -----------------------------------------------------------

    // If the new card landed cut off past the strip's right edge, nudge the
    // pan just enough to reveal it (same courtesy the drag's drop does).
    function reveal(id) {
        var el = cardEl(id);
        if (!el) { return; }
        var cr = el.offsetLeft + el.offsetWidth, viewR = grid.scrollLeft + grid.clientWidth;
        if (cr > viewR) { B.setPan(B.getPan() + (cr - viewR) + 16); B.applyAnchor(); }
    }

    function doCopy(id) {
        var res = window.MBPericope.duplicateCard(B.boardId(), id);
        close();
        if (!res) { return; }
        B.render();
        reveal(res.card.id);
    }

    function doDelete(id) {
        var el = cardEl(id), ref = el ? (el.getAttribute('data-ref') || 'this card') : 'this card';
        var msg = 'Remove ' + ref + '?';
        var ask = window.mbConfirm
            ? window.mbConfirm([msg, 'This only takes it off this pericope \u2014 and undo brings it back.'],
                               { confirmLabel: 'Remove' })
            : Promise.resolve(window.confirm(msg));
        ask.then(function (yes) {
            if (!yes) { return; }
            window.MBPericope.removeCard(B.boardId(), id);
            close();
            B.render();
        });
    }

    // ---- wiring ------------------------------------------------------------

    // CAPTURE phase: wins over the board's click handler (which would treat
    // a click anywhere on the card as expand/collapse, and swallows every
    // click while zoomed).
    function onGridClick(e) {
        if (boardEditing() || dragging()) { return; }
        var btn = closest(e.target, '.peri-card-edit');
        if (btn) {
            e.preventDefault(); e.stopPropagation();
            var card = closest(btn, '.peri-card');
            if (card) { toggle(card.getAttribute('data-id')); }
            return;
        }
        var act = closest(e.target, '.peri-card-menu .pce-btn');
        if (act) {
            e.preventDefault(); e.stopPropagation();
            if (act.disabled || !current) { return; }
            var a = act.getAttribute('data-act');
            if (a === 'copy')        { doCopy(current); }
            else if (a === 'delete') { doDelete(current); }
            return;
        }
        // Any other click on the menu's surface (its padding) goes nowhere.
        if (closest(e.target, '.peri-card-menu')) { e.preventDefault(); e.stopPropagation(); }
    }

    // Escape leaves card-edit — caught here in CAPTURE so the board's own
    // Escape (which collapses the card) doesn't also fire.
    function onGridKey(e) {
        if (!current || (e.key !== 'Escape' && e.key !== 'Esc')) { return; }
        var card = closest(e.target, '.peri-card');
        if (!card || card.getAttribute('data-id') !== current) { return; }
        e.preventDefault(); e.stopPropagation();
        var btn = card.querySelector('.peri-card-edit');
        close();
        if (btn) { try { btn.focus({ preventScroll: true }); } catch (_) { btn.focus(); } }
    }

    function init() {
        B = window.MBPericopeBoard;
        if (!B || !B.grid || !window.MBPericope) { return; }
        grid = B.grid;
        grid.addEventListener('click', onGridClick, true);
        grid.addEventListener('keydown', onGridKey, true);
        // A render rebuilds every card's DOM — the menu and ring go with it,
        // so the state simply ends.
        document.addEventListener('mb:pericope-rendered', function () { current = null; });
    }

    window.MBPericopeCardEdit = { active: active, open: open, close: close };

    if (window.MBPericopeBoard) { init(); }
    else { document.addEventListener('mb:pericope-board-ready', init); }
})();
