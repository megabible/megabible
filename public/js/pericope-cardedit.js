/* ======================================================================
   PERICOPE BOARD — CARD EDIT                  public/js/pericope-cardedit.js
   ----------------------------------------------------------------------
   Phase 1 of the card-edit work. Every EXPANDED verse card carries a
   scissors button in its top-right corner (it replaced the trash there;
   notes and headings keep their trash). Pressing it puts THAT ONE card
   into CARD-EDIT: the button tints, the card wears a ring, and a menu
   overlays the verse text with the card's actions —

     DUPLICATE    MBPericope.duplicateCard — a twin lands to the right.
     INTERLINEAR  Coverage is PROBED THE MOMENT THE MENU OPENS, through the
                  board's session token cache (B.fetchInterlinear): the
                  button starts disabled ("checking…"), then enables, or
                  settles on "no source text" for a passage outside
                  TAHOT/TAGNT coverage. Pressing it spawns the child via
                  MBPericope.addInterlinearCard. A parent that already has
                  its child shows "added".
     DELETE       mbConfirm, then MBPericope.removeCard. Undoable anyway.
                  (Deleting the PARENT cascades to its child — the store's
                  tether rule.)

   The scissors lives on every expanded card of these two kinds: a VERSE
   card gets all three actions; an interlinear CHILD gets a delete-only
   menu (a child neither duplicates nor nests).

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
    function findCard(board, id) {
        if (!board) { return null; }
        for (var i = 0; i < board.cards.length; i++) { if (board.cards[i].id === id) { return board.cards[i]; } }
        return null;
    }

    var DELETE_BTN = '<button type="button" class="pce-btn is-danger" data-act="delete">' + ICON_TRASH +
                         '<span class="pce-label">Delete</span></button>';

    function menuHtml(cardId) {
        var board = B.board(), card = findCard(board, cardId);
        if (card && card.type === 'interlinear') { return DELETE_BTN; }   // a child's one action
        var child = board && window.MBPericope.interlinearChild
            ? window.MBPericope.interlinearChild(board, cardId) : null;
        // Without a child the button opens DISABLED with a data-probe mark:
        // probeCoverage() settles it right after the menu is in the DOM.
        var il = child
            ? '<button type="button" class="pce-btn" data-act="interlinear" disabled aria-disabled="true">' + ICON_INTERLINEAR +
                  '<span class="pce-label">Interlinear</span><span class="pce-hint">added</span></button>'
            : '<button type="button" class="pce-btn" data-act="interlinear" data-probe="1" disabled aria-disabled="true">' + ICON_INTERLINEAR +
                  '<span class="pce-label">Interlinear</span><span class="pce-hint">checking\u2026</span></button>';
        return '<button type="button" class="pce-btn" data-act="copy">' + ICON_COPY +
                   '<span class="pce-label">Duplicate</span></button>' +
               il + DELETE_BTN;
    }

    // Ask the board's token cache whether the parent's verses have original-
    // language coverage, and settle the Interlinear button: enabled, or
    // "no source text". Cached answers resolve on the next microtask, so a
    // second open of the same card never visibly says "checking".
    function probeCoverage(id, menu) {
        var btn = menu.querySelector('[data-act="interlinear"][data-probe]');
        if (!btn || !B.fetchInterlinear) { return; }
        var parent = findCard(B.board(), id);
        if (!parent) { return; }
        B.fetchInterlinear(parent).then(function (covered) {
            if (current !== id || !menu.contains(btn)) { return; }   // menu closed / rebuilt meanwhile
            var hint = btn.querySelector('.pce-hint');
            btn.removeAttribute('data-probe');
            if (covered) {
                btn.disabled = false;
                btn.removeAttribute('aria-disabled');
                if (hint) { hint.parentNode.removeChild(hint); }
            } else if (hint) {
                hint.textContent = 'no source text';
            }
        });
    }

    // ---- open / close ----------------------------------------------------

    function open(id) {
        var el = cardEl(id);
        if (!el || el.getAttribute('aria-expanded') !== 'true') { return; }   // expanded cards only
        if (current && current !== id) { close(); }
        if (current === id) { return; }
        current = id;

        var menu = document.createElement('div');
        menu.className = 'peri-card-menu';
        menu.setAttribute('role', 'group');
        menu.setAttribute('aria-label', 'Card actions');
        menu.innerHTML = menuHtml(id);
        // The menu is a SHEET anchored beside the scissors: a direct child
        // of the card, pinned to the top-right by CSS (left of the button),
        // so on a wide card it follows the button and on a 1-column card it
        // simply paints over the reference header. All positioning is CSS —
        // nothing is measured here.
        el.appendChild(menu);
        el.classList.add('is-card-editing');
        probeCoverage(id, menu);

        var btn = el.querySelector('.peri-card-edit');
        if (btn) {
            btn.classList.add('is-active');
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('aria-expanded', 'true');   // the menu it controls is open
        }
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
        if (btn) {
            btn.classList.remove('is-active');
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-expanded', 'false');
            btn.blur();
        }
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

    // Spawn the child. probeCoverage already warmed the cache when the menu
    // opened, so the fetch here answers at once; it stays the source of
    // truth in case the probe was still in flight when the user tapped.
    function doInterlinear(id, btn) {
        var parent = findCard(B.board(), id);
        if (!parent || !B.fetchInterlinear) { return; }
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        B.fetchInterlinear(parent).then(function (covered) {
            if (current !== id) { return; }        // menu closed / moved on meanwhile
            if (!covered) {
                var hint = btn.querySelector('.pce-hint');
                if (!hint) { hint = document.createElement('span'); hint.className = 'pce-hint'; btn.appendChild(hint); }
                hint.textContent = 'no source text';
                return;
            }
            var res = window.MBPericope.addInterlinearCard(B.boardId(), id);
            close();
            if (!res) { return; }
            B.render();
            reveal(res.card.id);
        });
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
            if (a === 'copy')             { doCopy(current); }
            else if (a === 'interlinear') { doInterlinear(current, act); }
            else if (a === 'delete')      { doDelete(current); }
            return;
        }
        // Any other click on the menu's surface (its padding) goes nowhere.
        if (closest(e.target, '.peri-card-menu')) { e.preventDefault(); e.stopPropagation(); }
    }

    // Keyboard on the open menu (Phase 5): Escape leaves card-edit — caught
    // in CAPTURE so the board's own Escape (which collapses the card)
    // doesn't also fire — and Up/Down/Home/End rove focus through the
    // enabled actions, wrapping at the ends, so the menu drives from the
    // keyboard the way it reads.
    function onGridKey(e) {
        if (!current) { return; }
        var card = closest(e.target, '.peri-card');
        if (!card || card.getAttribute('data-id') !== current) { return; }
        var k = e.key;
        if (k === 'Escape' || k === 'Esc') {
            e.preventDefault(); e.stopPropagation();
            var btn = card.querySelector('.peri-card-edit');
            close();
            if (btn) { try { btn.focus({ preventScroll: true }); } catch (_) { btn.focus(); } }
            return;
        }
        if (k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'Home' && k !== 'End') { return; }
        var menu = card.querySelector('.peri-card-menu');
        if (!menu || !menu.contains(e.target)) { return; }
        var all = menu.querySelectorAll('.pce-btn:not([disabled])');
        if (!all.length) { return; }
        e.preventDefault(); e.stopPropagation();
        var at = -1, i;
        for (i = 0; i < all.length; i++) { if (all[i] === e.target) { at = i; break; } }
        if (k === 'Home')           { at = 0; }
        else if (k === 'End')       { at = all.length - 1; }
        else if (k === 'ArrowDown') { at = (at + 1) % all.length; }
        else                        { at = (at - 1 + all.length) % all.length; }
        try { all[at].focus({ preventScroll: true }); } catch (_) { all[at].focus(); }
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
