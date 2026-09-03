/* ======================================================================
   PERICOPE PANEL                                 public/js/pericope-sheet.js
   ----------------------------------------------------------------------
   The panel beneath the sticky head's apps folder that the scissors app
   opens. r11: no longer a system dialog — it's a <details> app in the
   folder, like Aa, and its panel hangs beneath the pill.

   Two faces, decided by what's "in hand" (the reader's live selection):

     NOTHING IN HAND   a browse list: every pericope, newest first, each a
                       link to its board. The title links to the hub.
     VERSES IN HAND    the same list, but each row ADDS the hand to that
                       board; a "new pericope" field sits below. After an
                       add, a confirmation line replaces the subtitle and
                       links to the board. No dialogs anywhere.

   THE HAND. focus-synthesis.js publishes the selection two ways on every
   change: window.MBFocusHand (the latest, for a panel that opens later)
   and a document 'mb:focus-change' event (for a panel that is already
   open). Both carry { count, cards, label }. A page with no engine has no
   hand, and the panel is simply the browse list.

   MARKUP CONTRACT (server-rendered by the page, inside <x-head-folder>):

       <details class="pericope-app" id="app-pericope">
           <summary class="fld-app" …>scissors</summary>
           <div class="ps-panel" role="group" aria-label="Pericopes"></div>
       </details>

   All storage goes through window.MBPericope (pericope-store.js); this file
   owns only the UI. Board URLs come from window.MB_PERICOPE_BASE (set in
   layouts/app). Adds are logged as deeds via window.MBActs, unchanged.

   ES5-compatible (var/function), matching the other shared scripts.
   ====================================================================== */
(function () {
    'use strict';

    var root  = document.getElementById('app-pericope');
    if (!root) { return; }                         // page has no pericope app
    var panel = root.querySelector('.ps-panel');
    if (!panel) { return; }

    var base = window.MB_PERICOPE_BASE || '';

    // One-shot confirmation shown after an add, cleared the next time the
    // hand changes or the panel reopens. { name, slug, added, rejected }
    var lastAdd = null;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function hand() {
        var h = window.MBFocusHand;
        return (h && h.cards && h.cards.length) ? h : null;
    }

    function boardUrl(slug) {
        return base ? base + '/' + encodeURIComponent(slug) : '';
    }

    /* ---- render ---------------------------------------------------------- */

    function render() {
        if (!window.MBPericope) {
            panel.innerHTML = '<p class="ps-empty">Pericopes aren\u2019t available on this page.</p>';
            return;
        }

        var h      = hand();
        var boards = window.MBPericope.list();
        boards.sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });

        // Preserve a half-typed name across a re-render (the hand can change
        // mid-sentence when the reader taps another verse).
        var oldInput = panel.querySelector('.ps-input');
        var draft    = oldInput ? oldInput.value : '';

        var html = '';

        // Title: a link to the hub, so the panel is always one tap from
        // the full pericope page.
        html += '<div class="ps-head">';
        if (base) {
            html += '<a class="ps-title" href="' + esc(base) + '">Pericopes';
            html +=   '<svg class="ps-title-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>';
            html += '</a>';
        } else {
            html += '<span class="ps-title">Pericopes</span>';
        }
        html += '</div>';

        // Subtitle: the confirmation of the last add, or what's in hand, or
        // a plain count. One line, one job.
        if (lastAdd) {
            html += '<p class="ps-sub ps-added">';
            html +=   lastAdd.rejected > 0
                ? 'Added ' + lastAdd.added + ' to \u201c' + esc(lastAdd.name) + '\u201d \u2014 ' +
                  lastAdd.rejected + ' didn\u2019t fit, that pericope is full.'
                : 'Added to \u201c' + esc(lastAdd.name) + '\u201d.';
            var u = boardUrl(lastAdd.slug);
            if (u) { html += ' <a class="ps-open" href="' + esc(u) + '">Open</a>'; }
            html += '</p>';
        } else if (h) {
            html += '<p class="ps-sub">Adding ' + esc(h.label) + '</p>';
        } else if (boards.length) {
            html += '<p class="ps-sub">' + boards.length + ' pericope' + (boards.length === 1 ? '' : 's') + '</p>';
        }

        // The list. Rows are links when browsing, buttons when adding.
        if (boards.length) {
            html += '<div class="ps-list">';
            for (var i = 0; i < boards.length; i++) {
                var bd  = boards[i];
                var row = '<span class="ps-board-name">' + esc(bd.name) + '</span>' +
                          '<span class="ps-board-count">' + (bd.cards || 0) + '</span>';
                if (h) {
                    // The row that just took the hand wears a check beside its
                    // count until the hand changes or the panel reopens.
                    var done = lastAdd && lastAdd.id === bd.id;
                    if (done) {
                        row = '<span class="ps-board-name">' + esc(bd.name) + '</span>' +
                              '<span class="ps-board-count">' +
                                '<svg class="ps-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                                (bd.cards || 0) +
                              '</span>';
                    }
                    html += '<button type="button" class="ps-board' + (done ? ' is-done' : '') + '" data-id="' + esc(bd.id) + '" data-slug="' + esc(bd.slug) + '" data-name="' + esc(bd.name) + '">' + row + '</button>';
                } else {
                    var url = boardUrl(bd.slug);
                    html += url
                        ? '<a class="ps-board" href="' + esc(url) + '">' + row + '</a>'
                        : '<span class="ps-board">' + row + '</span>';
                }
            }
            html += '</div>';
        } else {
            html += '<p class="ps-empty">' + (h
                ? 'No pericopes yet \u2014 name your first below.'
                : 'No pericopes yet. Select verses in the reader to start one.') + '</p>';
        }

        // The new-pericope field exists ONLY while something is in hand.
        if (h) {
            html += '<div class="ps-new">';
            html +=   '<input type="text" class="ps-input" placeholder="New pericope name\u2026" maxlength="80" autocomplete="off" value="' + esc(draft) + '">';
            html +=   '<button type="button" class="ps-create">Create</button>';
            html += '</div>';
        }

        panel.innerHTML = html;
        wire(h);
    }

    /* ---- wiring ---------------------------------------------------------- */

    function wire(h) {
        if (!h) { return; }                        // browse mode: plain links, nothing to wire

        var btns = panel.querySelectorAll('button.ps-board');
        for (var j = 0; j < btns.length; j++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    addTo(h, btn.getAttribute('data-id'), btn.getAttribute('data-name'),
                          btn.getAttribute('data-slug'), null);
                });
            })(btns[j]);
        }

        var input  = panel.querySelector('.ps-input');
        var create = panel.querySelector('.ps-create');
        function doCreate() {
            var name = (input.value || '').replace(/^\s+|\s+$/g, '');
            if (!name) { input.focus(); return; }
            addTo(h, null, null, null, name);
        }
        if (create) { create.addEventListener('click', doCreate); }
        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); doCreate(); }
            });
        }
    }

    // Add the hand's cards to a board — existing (id + name + slug) or new
    // (createName). Reports inline via lastAdd; never opens a dialog.
    function addTo(h, boardId, boardName, boardSlug, createName) {
        var cards = h.cards, result, name = boardName, slug = boardSlug;

        if (createName != null) {
            var b = window.MBPericope.create(createName);
            if (!b) {
                fail('Couldn\u2019t create the pericope \u2014 your browser storage may be full, or you\u2019ve reached the limit.');
                return;
            }
            if (window.MBActs) { window.MBActs.log('pericope.create', { id: b.id, slug: b.slug, name: b.name }); }
            result = window.MBPericope.addCards(b.id, cards);
            name = b.name; slug = b.slug;
        } else {
            result = window.MBPericope.addCards(boardId, cards);
        }

        if (!result || !result.board) {
            fail('Couldn\u2019t add to the pericope \u2014 your browser storage may be full.');
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

        lastAdd = { id: result.board.id, name: name, slug: slug, added: result.added, rejected: result.rejected };

        // Re-render AFTER the click has finished bubbling. Rebuilding the
        // panel synchronously would detach the very button that was clicked,
        // and the document-level "outside click" listeners (this file's and
        // focus-synthesis's) would then read the click as a dismiss.
        setTimeout(function () {
            render();                              // the board floats to the top, checked
            var done = panel.querySelector('.ps-board.is-done');
            if (done) { done.focus(); }
        }, 0);
    }

    // Storage failures land in the subtitle slot, same place as a success.
    function fail(message) {
        setTimeout(function () {                   // same reason as the success path
            render();
            var sub = panel.querySelector('.ps-sub, .ps-empty');
            var p = document.createElement('p');
            p.className = 'ps-sub ps-error';
            p.textContent = message;
            if (sub && sub.parentNode) { sub.parentNode.insertBefore(p, sub); }
            else { panel.insertBefore(p, panel.firstChild); }
        }, 0);
    }

    /* ---- open / close ---------------------------------------------------- */

    // Every open is a fresh render — the hand and the board list may both
    // have changed while the panel was shut. Focus lands on the name field
    // when adding (the common next move), otherwise on the first row.
    root.addEventListener('toggle', function () {
        if (!root.open) { return; }
        lastAdd = null;
        render();
        var first = panel.querySelector('.ps-input') || panel.querySelector('.ps-board');
        if (first && first.focus) { first.focus(); }
    });

    // The hand changed while the panel is open (another verse tapped, or
    // the selection cleared): repaint. A stale confirmation goes with it.
    document.addEventListener('mb:focus-change', function () {
        if (!root.open) { return; }
        lastAdd = null;
        render();
    });

    // Close on outside click or Escape — the Aa panel's behaviour, so the
    // two panels feel like one family. The folder itself stays open.
    document.addEventListener('click', function (e) {
        if (!root.open) { return; }
        if (!document.contains(e.target)) { return; }   // detached by a re-render: not "outside"
        if (!root.contains(e.target)) { root.open = false; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.open) {
            e.preventDefault();
            root.open = false;
            var s = root.querySelector('summary');
            if (s) { s.focus(); }
        }
    });

    // Legacy entry point. Nothing calls it any more (the hand arrives by
    // event), but a page that still does gets the panel opened with that
    // hand rather than a silent no-op.
    window.MBPericopeSheet = {
        open: function (request) {
            if (request && request.cards) {
                window.MBFocusHand = { count: request.cards.length, cards: request.cards, label: request.label || '' };
            }
            root.open = true;
        }
    };
})();
