/* ======================================================================
   PERICOPE BOARD — EDIT MODE                     public/js/pericope-edit.js
   ----------------------------------------------------------------------
   Phase 5 of the board redesign. The scissors button in the sticky head
   toggles EDIT MODE: cards do the app-drawer shimmy, a tap selects a card
   (the reader's selection language: a --rule wash), and an edit FAB rises
   from the bottom with three actions —

     GROUP  — put the selected cards into a new group: a small sheet asks
              for a label and a theme colour, then MBPericope.createGroup
              writes membership (cards already in a group are stolen; an
              emptied group dissolves). The board renders each group as a
              derived bounding-box outline + label chip.
     TRASH  — mbConfirm, then the selected cards are removed from the board.
     CANCEL — leave edit mode (Escape and the scissors button also work).

   Group chips: in edit mode, tapping a chip selects/deselects the WHOLE
   group; the small pencil inside the chip opens manage (rename / recolor /
   ungroup — cards survive an ungroup).

   TYPED SELECTIONS (Phase 5b): a selection is always HOMOGENEOUS — either
   all UNGROUPED cards (mode 'group': the pill creates a group) or all
   GROUPED cards, from any mix of groups (mode 'ungroup': the pill strips
   them instantly, no confirm — non-destructive). Tapping a card of the
   other kind DROPS the old selection and starts a fresh one of the other
   mode with that card. A chip tap selects its whole group, so it always
   lands in (or switches to) ungroup mode. Consequence, by design: edit
   mode can no longer EXTEND a group — hover-to-adopt while dragging
   (pericope-drag.js) is the one door into an existing group.

   MODE MATRIX (who owns a tap/drag):
     normal        tap = expand/collapse · grip = drag
     zoom          whole card = drag, everything else inert
     edit          tap = select · grip = drag (shimmy pauses while placing)
     edit + zoom   tap = select, NO dragging — zoom is edit's survey view

   The selection tap is a CAPTURE-phase listener on the grid, so in edit
   mode it wins before the board's own click handler (which also bails via
   MBPericopeEdit.active()). The grip is skipped so the click that tails a
   drag never toggles a selection.

   Attaches through window.MBPericopeBoard (mb:pericope-board-ready), like
   pericope-drag.js. Publishes window.MBPericopeEdit = {active, toggle,
   enter, exit}. Vanilla ES5, matching the other shared scripts.
   ====================================================================== */
(function () {
    'use strict';

    // Shimmy strength dial (prefs.shimmy 0..3, console-set for now via
    // MBPericope.setPrefs({shimmy: n})): rotation amplitude per level.
    var SHIMMY_DEG = [0, 0.35, 0.7, 1.15];

    var B = null, grid = null, boardEl = null, editBtn = null;
    var editing = false;
    var sel = {};            // cardId -> true
    var selMode = null;      // null | 'group' (ungrouped cards) | 'ungroup' (grouped cards)
    var fab = null, fabCountEl = null, fabGroupBtn = null, fabTrashBtn = null;
    var sheet = null;        // the group label/colour sheet, built lazily

    function active() { return editing; }
    function closest(el, s) { return (el && el.closest) ? el.closest(s) : null; }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }
    // ONE groupOf for the whole system (Phase 3): the store's groupOfCard
    // honours the interlinear tether — a CHILD answers with its parent's
    // group — so selection homogeneity treats a grouped parent's child as
    // a grouped card. Group/ungroup actions stay safe regardless: the
    // store silently drops child ids from membership lists.
    function groupOf(cardId) {
        var board = B.board();
        return board ? window.MBPericope.groupOfCard(board, cardId) : null;
    }

    function selIds() {
        var out = [], k;
        for (k in sel) { if (sel.hasOwnProperty(k) && sel[k]) { out.push(k); } }
        return out;
    }

    // ---- mode ------------------------------------------------------------

    function applyShimmy() {
        var prefs = (window.MBPericope.getPrefs && window.MBPericope.getPrefs()) || { shimmy: 2 };
        var deg = SHIMMY_DEG[prefs.shimmy] != null ? SHIMMY_DEG[prefs.shimmy] : SHIMMY_DEG[2];
        grid.style.setProperty('--pb-shimmy', deg + 'deg');
        grid.classList.toggle('pb-shimmy-off', deg === 0);
    }

    function enter() {
        if (editing) { return; }
        editing = true;
        // Two edit ideas, one glyph: board mode closes any open card-edit.
        if (window.MBPericopeCardEdit) { window.MBPericopeCardEdit.close(); }
        applyShimmy();
        // 'is-editing', NOT 'pb-edit' — pb-edit is the rename pencil's CLASS,
        // and putting it on #pb-board dressed the whole board as an
        // inline-flex button (the collapsed-layout bug).
        boardEl.classList.add('is-editing');
        grid.classList.add('pb-editing');
        if (editBtn) { editBtn.classList.add('is-active'); editBtn.setAttribute('aria-pressed', 'true'); }
        buildFab();
        fab.classList.add('is-visible');
        updateFab();
    }

    function exit() {
        if (!editing) { return; }
        editing = false;
        clearSelection();
        boardEl.classList.remove('is-editing');
        grid.classList.remove('pb-editing');
        if (editBtn) { editBtn.classList.remove('is-active'); editBtn.setAttribute('aria-pressed', 'false'); }
        if (fab) { fab.classList.remove('is-visible'); }
        closeSheet();
    }

    function toggle() { if (editing) { exit(); } else { enter(); } }

    // ---- selection -------------------------------------------------------

    function setSelected(id, on) {
        var el = grid.querySelector('.peri-card[data-id="' + id + '"]');
        if (on) { sel[id] = true; } else { delete sel[id]; }
        if (el) { el.classList.toggle('is-selected', !!on); }
    }

    function clearSelection() {
        var ids = selIds(), i;
        for (i = 0; i < ids.length; i++) { setSelected(ids[i], false); }
        sel = {};
        selMode = null;
        updateFab();
    }

    // The homogeneity rule: adding a card whose kind doesn't match the
    // current mode DROPS the old selection and starts the other mode.
    function addToSelection(id) {
        var want = groupOf(id) ? 'ungroup' : 'group';
        if (selMode && selMode !== want) { clearSelection(); }
        selMode = want;
        setSelected(id, true);
    }

    // Re-apply selection classes after a render wiped the DOM (the store may
    // also have dropped a card meanwhile — prune those ids).
    function reapplySelection() {
        if (!editing) { return; }
        // The board may have changed under us (a card removed, a group
        // dissolved) — drop ids that vanished or whose KIND no longer
        // matches the selection's mode, then re-derive the mode.
        var ids = selIds(), i, el, kind;
        for (i = 0; i < ids.length; i++) {
            el = grid.querySelector('.peri-card[data-id="' + ids[i] + '"]');
            kind = groupOf(ids[i]) ? 'ungroup' : 'group';
            if (!el || (selMode && kind !== selMode)) { delete sel[ids[i]]; continue; }
            el.classList.add('is-selected');
        }
        if (!selIds().length) { selMode = null; }
        updateFab();
    }

    // Tap a group chip: all members in -> all out; otherwise select all.
    function toggleGroup(groupId) {
        var board = B.board();
        if (!board) { return; }
        var g = null, i;
        for (i = 0; i < board.groups.length; i++) { if (board.groups[i].id === groupId) { g = board.groups[i]; break; } }
        if (!g) { return; }
        // Members are grouped cards → this is an ungroup-mode gesture; a
        // live group-mode selection is dropped first (homogeneity rule).
        if (selMode === 'group') { clearSelection(); }
        var allIn = true;
        for (i = 0; i < g.cards.length; i++) { if (!sel[g.cards[i]]) { allIn = false; break; } }
        for (i = 0; i < g.cards.length; i++) { setSelected(g.cards[i], !allIn); }
        selMode = selIds().length ? 'ungroup' : null;
        updateFab();
    }

    function onGridClick(e) {
        if (!editing) { return; }
        var chip = closest(e.target, '.pb-group-label');
        var grip = closest(e.target, '.peri-grip');
        var card = closest(e.target, '.peri-card');
        if (card && card.classList.contains('pb-ghost')) { card = null; }
        if (!chip && !card) { return; }              // empty grid: let it pass (nothing to do)
        e.preventDefault();
        e.stopPropagation();
        if (chip) {
            var gid = chip.getAttribute('data-group');
            if (closest(e.target, '.pb-group-label-edit')) { openSheet('manage', gid); }
            else { toggleGroup(gid); }
            return;
        }
        if (grip) { return; }                        // the click tailing a grip drag
        var id = card.getAttribute('data-id');
        if (!id) { return; }
        if (sel[id]) {
            setSelected(id, false);
            if (!selIds().length) { selMode = null; }
        } else {
            addToSelection(id);
        }
        updateFab();
    }

    // ---- the edit FAB ----------------------------------------------------

    var ICON_TRASH = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
    var ICON_X = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var ICON_GROUP = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>';

    function buildFab() {
        if (fab) { return; }
        fab = document.createElement('div');
        fab.className = 'fab pbe-fab';
        fab.innerHTML =
            '<button type="button" class="fab-pill" id="pbe-group">' + ICON_GROUP +
                '<span id="pbe-mode">Group</span><span class="fab-pill-suffix" id="pbe-count"></span></button>' +
            '<button type="button" class="fab-icon" id="pbe-trash" aria-label="Remove selected cards" title="Remove selected">' + ICON_TRASH + '</button>' +
            '<button type="button" class="fab-icon" id="pbe-cancel" aria-label="Done editing" title="Done">' + ICON_X + '</button>';
        document.body.appendChild(fab);
        fabGroupBtn = document.getElementById('pbe-group');
        fabTrashBtn = document.getElementById('pbe-trash');
        fabCountEl  = document.getElementById('pbe-count');
        fabGroupBtn.addEventListener('click', function () {
            var ids = selIds();
            if (!ids.length) { return; }
            if (selMode === 'ungroup') {
                // Instant, no confirm: nothing is destroyed, and regrouping
                // is one selection away.
                window.MBPericope.ungroupCards(B.boardId(), ids);
                clearSelection();
                B.render();
            } else {
                openSheet('create', null);
            }
        });
        fabTrashBtn.addEventListener('click', trashSelected);
        document.getElementById('pbe-cancel').addEventListener('click', exit);
    }

    function updateFab() {
        if (!fab) { return; }
        var n = selIds().length;
        document.getElementById('pbe-mode').textContent = (selMode === 'ungroup') ? 'Ungroup' : 'Group';
        fabCountEl.textContent = n ? '· ' + n : '';
        fabGroupBtn.classList.toggle('is-disabled', !n);
        fabTrashBtn.classList.toggle('is-disabled', !n);
    }

    function trashSelected() {
        var ids = selIds();
        if (!ids.length) { return; }
        var msg = ids.length === 1 ? 'Remove this card?' : 'Remove ' + ids.length + ' cards?';
        var ask = window.mbConfirm
            ? window.mbConfirm([msg, 'This only takes them off this pericope — nothing in your reading history is touched.'],
                               { confirmLabel: 'Remove' })
            : Promise.resolve(window.confirm(msg));
        ask.then(function (yes) {
            if (!yes) { return; }
            var i;
            for (i = 0; i < ids.length; i++) { window.MBPericope.removeCard(B.boardId(), ids[i]); }
            sel = {};
            B.render();
            reapplySelection();
        });
    }

    // ---- the group sheet (create / manage) -------------------------------

    function buildSheet() {
        if (sheet) { return; }
        var colors = window.MBPericope.GROUP_COLORS || [];
        var sw = '', i;
        for (i = 0; i < colors.length; i++) {
            sw += '<button type="button" class="pbe-swatch" data-color="' + esc(colors[i]) + '"' +
                  ' style="--sw: var(--tl-' + esc(colors[i]) + ')" aria-label="' + esc(colors[i]) + '"' +
                  ' title="' + esc(colors[i]) + '"></button>';
        }
        sheet = document.createElement('div');
        sheet.className = 'pbe-sheet-scrim';
        sheet.hidden = true;
        sheet.innerHTML =
            '<div class="pbe-sheet" role="dialog" aria-modal="true" aria-label="Group">' +
                '<p class="pbe-sheet-title" id="pbe-sheet-title">New group</p>' +
                '<input type="text" class="pbe-sheet-input" id="pbe-sheet-label" maxlength="40" placeholder="Label" autocomplete="off">' +
                '<div class="pbe-swatches" id="pbe-swatches">' + sw + '</div>' +
                '<div class="pbe-sheet-btns" id="pbe-sheet-btns"></div>' +
            '</div>';
        document.body.appendChild(sheet);
        // Backdrop click = the safe exit, same rule as mbDialog.
        sheet.addEventListener('click', function (e) { if (e.target === sheet) { closeSheet(); } });
        sheet.querySelector('#pbe-swatches').addEventListener('click', function (e) {
            var b = closest(e.target, '.pbe-swatch');
            if (!b) { return; }
            var all = sheet.querySelectorAll('.pbe-swatch'), i;
            for (i = 0; i < all.length; i++) { all[i].classList.remove('is-picked'); }
            b.classList.add('is-picked');
        });
    }

    function pickedColor() {
        var b = sheet.querySelector('.pbe-swatch.is-picked');
        return b ? b.getAttribute('data-color') : (window.MBPericope.GROUP_COLORS || ['gold'])[0];
    }

    function setPicked(color) {
        var all = sheet.querySelectorAll('.pbe-swatch'), i;
        for (i = 0; i < all.length; i++) {
            all[i].classList.toggle('is-picked', all[i].getAttribute('data-color') === color);
        }
    }

    // mode: 'create' (from the FAB, uses the selection) or 'manage' (from a
    // chip's pencil, edits/dissolves an existing group).
    function openSheet(mode, groupId) {
        buildSheet();
        var titleEl = sheet.querySelector('#pbe-sheet-title');
        var inputEl = sheet.querySelector('#pbe-sheet-label');
        var btnsEl  = sheet.querySelector('#pbe-sheet-btns');
        var g = null, i;
        if (mode === 'manage') {
            var board = B.board();
            if (!board) { return; }
            for (i = 0; i < board.groups.length; i++) { if (board.groups[i].id === groupId) { g = board.groups[i]; break; } }
            if (!g) { return; }
        }
        titleEl.textContent = g ? 'Edit group' : 'New group';
        inputEl.value = g ? (g.label || '') : '';
        setPicked(g ? g.color : (window.MBPericope.GROUP_COLORS || ['gold'])[0]);

        btnsEl.innerHTML = '';
        function btn(label, cls, fn) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'pbe-sheet-btn' + (cls ? ' ' + cls : '');
            b.textContent = label;
            b.addEventListener('click', fn);
            btnsEl.appendChild(b);
        }
        if (g) {
            btn('Ungroup', 'is-quiet', function () {
                window.MBPericope.removeGroup(B.boardId(), g.id);
                closeSheet(); B.render(); reapplySelection();
            });
            btn('Cancel', 'is-quiet', closeSheet);
            btn('Save', 'is-primary', function () {
                window.MBPericope.updateGroup(B.boardId(), g.id, { label: inputEl.value, color: pickedColor() });
                closeSheet(); B.render(); reapplySelection();
            });
        } else {
            btn('Cancel', 'is-quiet', closeSheet);
            btn('Create', 'is-primary', function () {
                var ok = window.MBPericope.createGroup(B.boardId(), selIds(), inputEl.value, pickedColor());
                closeSheet();
                if (ok) { clearSelection(); B.render(); }
            });
        }
        sheet.hidden = false;
        requestAnimationFrame(function () { sheet.classList.add('is-open'); try { inputEl.focus(); } catch (_) {} });
    }

    function closeSheet() {
        if (!sheet || sheet.hidden) { return; }
        sheet.classList.remove('is-open');
        sheet.hidden = true;
    }

    // ---- wiring ----------------------------------------------------------

    function init() {
        B = window.MBPericopeBoard;
        if (!B || !B.grid || !window.MBPericope) { return; }
        grid = B.grid;
        boardEl = B.boardEl;
        editBtn = document.getElementById('pb-editmode');

        if (editBtn) { editBtn.addEventListener('click', toggle); }
        grid.addEventListener('click', onGridClick, true);   // CAPTURE: wins over the board's handler
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
            if (sheet && !sheet.hidden) { closeSheet(); }
            else if (editing) { exit(); }
        });
        // Any render wipes the cards' DOM (and the selection classes on it).
        document.addEventListener('mb:pericope-rendered', reapplySelection);
        // Prefs dial can change mid-session (console for now).
        document.addEventListener('mb:pericope-prefs', function () { if (editing) { applyShimmy(); } });
    }

    window.MBPericopeEdit = { active: active, toggle: toggle, enter: enter, exit: exit };

    if (window.MBPericopeBoard) { init(); }
    else { document.addEventListener('mb:pericope-board-ready', init); }
})();
