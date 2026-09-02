/* ======================================================================
   ADD TO PERICOPE — SHEET                        public/js/pericope-sheet.js
   ----------------------------------------------------------------------
   The overlay the FAB's Pericope (scissors) button opens: pick an existing
   pericope or name a new one, and the selected verse(s) are added to it.

   It reuses the shared .mb-dialog-scrim backdrop + .mb-dialog card base from
   dialog.js (so it dims the site, locks scroll, and repaints with the theme
   exactly like mbNotify/mbConfirm), but renders its own body — a scrollable
   board list plus a "new pericope" field — which mbNotify/mbConfirm can't do.

   All storage goes through window.MBPericope (pericope-store.js); this file
   owns only the UI. Success/cap/error feedback reuses window.mbNotify.

   Public surface:
     MBPericopeSheet.open({ cards, label })
       cards : array of verse-card specs to add, e.g.
               [{ type:'verse', osis, ch, v1, v2, tx, text }, …]
       label : human summary of what's being added ("Genesis 8:14",
               "3 verses") — shown in the sheet and the success notice.

   ES5-compatible (var/function), matching the other shared scripts.
   ====================================================================== */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    // Scroll-lock (the same fixed-body pattern dialog.js uses), kept local so
    // the sheet depends on nothing but MBPericope + the shared scrim CSS. A
    // counter keeps it steady if a notice opens after the sheet closes.
    var lockCount = 0, savedY = 0;
    function lock() {
        if (lockCount++ > 0) { return; }
        savedY = window.scrollY || window.pageYOffset || 0;
        var s = document.body.style;
        s.position = 'fixed'; s.top = (-savedY) + 'px';
        s.left = '0'; s.right = '0'; s.width = '100%';
    }
    function unlock() {
        if (--lockCount > 0) { return; }
        lockCount = 0;
        var s = document.body.style;
        s.position = ''; s.top = ''; s.left = ''; s.right = ''; s.width = '';
        window.scrollTo(0, savedY);
    }

    function notifyOk(lines)  { if (window.mbNotify) { window.mbNotify(lines, { check: true }); } }
    function notifyErr(lines) { if (window.mbNotify) { window.mbNotify(lines); } }

    function open(request) {
        if (!window.MBPericope) { return; }              // store not loaded
        var cards = (request && request.cards) || [];
        if (!cards.length) { return; }
        var label = (request && request.label) ||
                    (cards.length + ' verse' + (cards.length === 1 ? '' : 's'));

        var scrim = document.createElement('div');
        scrim.className = 'mb-dialog-scrim';
        scrim.setAttribute('role', 'dialog');
        scrim.setAttribute('aria-modal', 'true');
        scrim.setAttribute('aria-label', 'Add to Pericope');

        var card = document.createElement('div');
        card.className = 'mb-dialog pericope-sheet';
        scrim.appendChild(card);

        var closed = false;
        // Close the sheet; run `after` once it's gone (used so a success notice
        // opens AFTER the sheet unlocks, never overlapping its scroll-lock).
        function finish(after) {
            if (closed) { return; }
            closed = true;
            document.removeEventListener('keydown', onKey, true);
            scrim.classList.remove('is-open');
            setTimeout(function () {
                if (scrim.parentNode) { scrim.parentNode.removeChild(scrim); }
                unlock();
                if (after) { after(); }
            }, 140);
        }

        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(); }
        }

        // Add `cards` to a board — existing (pass id + name + slug) or new (pass
        // createName). Closes the sheet, then reports via a notice that offers
        // to open the board.
        function addTo(boardId, boardName, boardSlug, createName) {
            var result, name = boardName, slug = boardSlug;

            if (createName != null) {
                var b = window.MBPericope.create(createName);
                if (!b) {
                    finish(function () {
                        notifyErr(['Couldn\u2019t create the pericope',
                                   'Your browser storage may be full, or you\u2019ve reached the limit.']);
                    });
                    return;
                }
                if (window.MBActs) { window.MBActs.log('pericope.create', { id: b.id, slug: b.slug, name: b.name }); }
                result = window.MBPericope.addCards(b.id, cards);
                name = b.name; slug = b.slug;
            } else {
                result = window.MBPericope.addCards(boardId, cards);
            }

            if (!result || !result.board) {
                finish(function () {
                    notifyErr(['Couldn\u2019t add to the pericope',
                               'Your browser storage may be full.']);
                });
                return;
            }

            // Log the add as a tracked deed. Refs are RAW (osis/ch/v1/v2/tx) with
            // NO text snapshot — the feed derives the display ref, and the act
            // log stays lean (it's FIFO-capped). Only the cards that actually
            // landed (result.added) are logged.
            if (window.MBActs) {
                var refs = [];
                for (var ri = 0; ri < result.added && ri < cards.length; ri++) {
                    var rc = cards[ri];
                    if (rc && rc.type === 'verse') {
                        refs.push({ osis: rc.osis, ch: rc.ch, v1: rc.v1, v2: rc.v2, tx: rc.tx });
                    }
                }
                window.MBActs.log('pericope.add', {
                    id: result.board.id, slug: result.board.slug, name: result.board.name,
                    count: result.added, refs: refs
                });
            }

            var added = result.added, rejected = result.rejected, nm = name, sl = slug;
            finish(function () { reportAdded(nm, sl, added, rejected); });
        }

        // Success feedback. If we can build the board's URL (MB_PERICOPE_BASE is
        // set and we know the slug), offer to open it; otherwise a plain notice.
        function reportAdded(name, slug, added, rejected) {
            var base = window.MB_PERICOPE_BASE;
            var head = rejected > 0
                ? 'Added ' + added + ' to \u201c' + name + '\u201d'
                : 'Added to \u201c' + name + '\u201d';
            var body = rejected > 0
                ? rejected + ' verse' + (rejected === 1 ? '' : 's') + ' didn\u2019t fit \u2014 that pericope is full.'
                : label + ' saved to your pericope.';

            if (base && slug && window.mbConfirm) {
                window.mbConfirm([head, body], { confirmLabel: 'Open pericope', cancelLabel: 'Stay' })
                    .then(function (open) {
                        if (open) { window.location.href = base + '/' + encodeURIComponent(slug); }
                    });
            } else {
                notifyOk([head, body]);
            }
        }

        // ---- render ----
        var boards = window.MBPericope.list();
        boards.sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });

        var html = '';
        html += '<div class="pericope-sheet-head">';
        html +=   '<p class="pericope-sheet-title">Add to Pericope</p>';
        html +=   '<p class="pericope-sheet-sub">Adding ' + esc(label) + '</p>';
        html += '</div>';

        if (boards.length) {
            html += '<div class="pericope-sheet-list">';
            for (var i = 0; i < boards.length; i++) {
                var bd = boards[i];
                html += '<button type="button" class="pericope-sheet-board" data-id="' + esc(bd.id) + '" data-slug="' + esc(bd.slug) + '">';
                html +=   '<span class="pericope-sheet-board-name">' + esc(bd.name) + '</span>';
                html +=   '<span class="pericope-sheet-board-count">' + (bd.cards || 0) + '</span>';
                html += '</button>';
            }
            html += '</div>';
        } else {
            html += '<p class="pericope-sheet-empty">No pericopes yet \u2014 name your first below.</p>';
        }

        html += '<div class="pericope-sheet-new">';
        html +=   '<input type="text" class="pericope-sheet-input" placeholder="New pericope name\u2026" maxlength="80" autocomplete="off">';
        html +=   '<button type="button" class="pericope-sheet-create">Create</button>';
        html += '</div>';

        html += '<div class="pericope-sheet-foot">';
        html +=   '<button type="button" class="mb-dialog-btn is-ghost pericope-sheet-cancel">Cancel</button>';
        html += '</div>';

        card.innerHTML = html;

        // ---- wire ----
        var boardBtns = card.querySelectorAll('.pericope-sheet-board');
        for (var j = 0; j < boardBtns.length; j++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var nameEl = btn.querySelector('.pericope-sheet-board-name');
                    addTo(btn.getAttribute('data-id'), nameEl ? nameEl.textContent : '',
                          btn.getAttribute('data-slug'), null);
                });
            })(boardBtns[j]);
        }

        var input     = card.querySelector('.pericope-sheet-input');
        var createBtn = card.querySelector('.pericope-sheet-create');
        function doCreate() {
            var name = (input.value || '').replace(/^\s+|\s+$/g, '');
            if (!name) { input.focus(); return; }
            addTo(null, null, null, name);
        }
        createBtn.addEventListener('click', doCreate);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doCreate(); }
        });

        card.querySelector('.pericope-sheet-cancel').addEventListener('click', function () { finish(); });
        scrim.addEventListener('click', function (e) { if (e.target === scrim) { finish(); } });
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(scrim);
        lock();
        requestAnimationFrame(function () { scrim.classList.add('is-open'); });

        // Focus the newest board if there is one, else the name field.
        var firstBoard = card.querySelector('.pericope-sheet-board');
        if (firstBoard) { firstBoard.focus(); } else if (input) { input.focus(); }
    }

    window.MBPericopeSheet = window.MBPericopeSheet || { open: open };
})();
