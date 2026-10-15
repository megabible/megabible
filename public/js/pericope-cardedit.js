/* ======================================================================
   PERICOPE BOARD — CARD EDIT                  public/js/pericope-cardedit.js
   ----------------------------------------------------------------------
   Phase 1 of the card-edit work. Every EXPANDED verse card carries a
   scissors button in its top-right corner (it replaced the trash there;
   notes and headings keep their trash). Pressing it puts THAT ONE card
   into CARD-EDIT: the button tints, the card wears a ring, and a menu
   overlays the verse text with the card's actions —

     DUPLICATE    MBPericope.duplicateCard — a twin lands to the right.
     INTERLINEAR  Rendered ONLY when it can act (r2): coverage is read
                  synchronously from the board's session token cache
                  (B.interlinearData — the board warms it on expand), so a
                  passage outside TAHOT/TAGNT coverage, or a parent whose
                  child already exists, simply shows no row — no gray
                  button, no "checking…" flash. The one rare in-flight
                  case renders without the row and probeCoverage() slots
                  it in when the fetch lands covered. Pressing it spawns
                  the child via MBPericope.addInterlinearCard.
     DELETE       mbConfirm, then MBPericope.removeCard. Undoable anyway.
                  (Deleting the PARENT cascades to its child — the store's
                  tether rule.)

   All three rows are TEXT-ONLY (r2) — no icons.

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

    function cardEl(id) { return grid.querySelector('.peri-card[data-id="' + id + '"]'); }
    function findCard(board, id) {
        if (!board) { return null; }
        for (var i = 0; i < board.cards.length; i++) { if (board.cards[i].id === id) { return board.cards[i]; } }
        return null;
    }

    // Text-only rows (r2): the icons are gone by design — three words read
    // faster than three glyphs in a menu this small.
    var COPY_BTN   = '<button type="button" class="pce-btn" data-act="copy"><span class="pce-label">Duplicate</span></button>';
    var IL_BTN     = '<button type="button" class="pce-btn" data-act="interlinear"><span class="pce-label">Interlinear</span></button>';
    var DELETE_BTN = '<button type="button" class="pce-btn is-danger" data-act="delete"><span class="pce-label">Delete</span></button>';

    // Is the Interlinear action offerable RIGHT NOW? Answered synchronously
    // from the board's session token cache (r2 / #5b):
    //   'yes'     covered, no child yet          → render the button
    //   'no'      child exists / uncovered / N/A → render NOTHING. A parent
    //             with a child needs no row (the child on the board is the
    //             signal), and an uncovered passage offers no action, so it
    //             shows no button either — never a disabled gray row.
    //   'pending' fetch still in flight (rare — the board warms coverage
    //             on expand) → render nothing; probeCoverage() slots the
    //             button in if the answer lands "covered" while this menu
    //             is still open.
    function ilOffer(board, cardId) {
        var card = findCard(board, cardId);
        if (!card || card.type !== 'verse') { return 'no'; }
        if (window.MBPericope.interlinearChild &&
            window.MBPericope.interlinearChild(board, cardId)) { return 'no'; }
        var state = B.interlinearData ? B.interlinearData(card).state : 'none';
        return state === 'ready' ? 'yes' : (state === 'pending' ? 'pending' : 'no');
    }

    function menuHtml(cardId) {
        var board = B.board(), card = findCard(board, cardId);
        if (card && card.type === 'interlinear') { return DELETE_BTN; }   // a child's one action
        return COPY_BTN + (ilOffer(board, cardId) === 'yes' ? IL_BTN : '') + DELETE_BTN;
    }

    // The in-flight tail of #5b: coverage was still being fetched when the
    // menu rendered, so it opened without an Interlinear row. Ask again; if
    // the passage turns out covered while this same menu is still open, the
    // button slots in above Delete. An uncovered answer changes nothing —
    // the row simply never appears.
    function probeCoverage(id, menu) {
        if (!B.fetchInterlinear) { return; }
        if (menu.querySelector('[data-act="interlinear"]')) { return; }   // already offered
        var board = B.board(), parent = findCard(board, id);
        if (!parent || ilOffer(board, id) !== 'pending') { return; }
        B.fetchInterlinear(parent).then(function (covered) {
            if (!covered || current !== id) { return; }   // uncovered / menu moved on
            var del = menu.querySelector('[data-act="delete"]');
            if (del) { del.insertAdjacentHTML('beforebegin', IL_BTN); }
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

    // Spawn the child. The button only renders for covered passages, so the
    // fetch here answers from cache at once; it stays the source of truth
    // for the one race left (a cache entry that failed to refetch) — an
    // uncovered answer just drops the button instead of hinting at it.
    function doInterlinear(id, btn) {
        var parent = findCard(B.board(), id);
        if (!parent || !B.fetchInterlinear) { return; }
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        B.fetchInterlinear(parent).then(function (covered) {
            if (current !== id) { return; }        // menu closed / moved on meanwhile
            if (!covered) {
                if (btn.parentNode) { btn.parentNode.removeChild(btn); }
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
