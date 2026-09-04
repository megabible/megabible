/* ======================================================================
   PERICOPE BOARD                                 public/js/pericope-board.js
   ----------------------------------------------------------------------
   The client half of resources/views/extras/pericope/board.blade.php: renders
   one board's cards from window.MBPericope (localStorage) onto the coordinate
   grid, and owns expand/collapse, the per-card translation switcher, paging,
   rename and the header anchor. Drag-to-place lives in pericope-drag.js
   (Phase 3), which talks to this file through window.MBPericopeBoard —
   published at the end of init(), followed by a `mb:pericope-board-ready`
   event on document.

   Phase 0 of the board redesign: this is the former inline <script> block,
   moved here VERBATIM (behaviour unchanged) so the next phases have a real
   file to grow in. The only difference is the config hand-off: the Blade
   shell publishes one object BEFORE this file runs —

       window.MBPericopeBoardConfig = {
           slug, bookMeta, readerUrlPattern, hubUrl, cardTxUrl
       };

   — built in PericopeController::board() as a single array (one @json, so
   Blade's comma-splitting can never truncate it).

   Load order (all `defer`, in document order): pericope-store.js first (from
   app.blade), then this file, then pericope-drag.js, from the page's
   @section('scripts').

   Vanilla ES5 (var / function), matching the other shared scripts.
   ====================================================================== */
(function () {
    'use strict';

    var CFG = window.MBPericopeBoardConfig || {};

    var SLUG        = CFG.slug || '';
    var BOOK_META   = CFG.bookMeta || {};          // osis => {name, slug, off, single, short, abbr, color}
    var READER      = CFG.readerUrlPattern || '';  // /bible/__TX__/__BOOK__/__CH__
    var HUB         = CFG.hubUrl || '';
    var CARD_TX_URL = CFG.cardTxUrl || '';         // JSON: this ref across every translation
    var SNIP_CAP = 48;                            // collapsed snippet character cap (tweakable)

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function closest(el, sel) { return (el && el.closest) ? el.closest(sel) : null; }

    // Only lowercase-letter palette names are valid (--tl-gold, --tl-crimson…);
    // sanitise so a bad value can never smuggle anything into the style attr.
    function paletteColor(c) {
        c = String(c == null ? '' : c).replace(/[^a-z]/g, '');
        return c || 'clay';
    }

    // GRIP DOTS — three columns (Phase 2). Size levers: the r= radius here
    // sets dot thickness; --grip-h in .peri-grip CSS sets overall size. Both
    // forms are rendered into every grip; CSS shows the 9-dot on a collapsed
    // card and the 12-dot (one extra row) on an expanded one.
    function gripSvg(cls, rows) {
        var h = rows * 6, svg = '<svg class="' + cls + '" viewBox="0 0 18 ' + h + '" fill="currentColor" aria-hidden="true">', r, c;
        for (r = 0; r < rows; r++) {
            for (c = 0; c < 3; c++) { svg += '<circle cx="' + (3 + c * 6) + '" cy="' + (3 + r * 6) + '" r="1.9"/>'; }
        }
        return svg + '</svg>';
    }
    var GRIP = gripSvg('grip-9', 3) + gripSvg('grip-12', 4);
    // ↗ for the reference link — "this opens the reader".
    var ARROW = '<svg class="peri-ref-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg>';
    // Corners-in "collapse" glyph. Swap for a window-square if you prefer that read.
    var MINIMIZE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
    var TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
    // Scissors — the card-edit button (pericope-cardedit.js). Same glyph as
    // the head-folder's board edit-mode button.
    var SCISSORS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>';
    var CARET = '\u25BE';   // ▾  (rotates to ▴ via the global .tx[open] rule)

    // ---- reference derivation (all from the RAW card + bookMeta) ----------

    // The verbose, fully-spelled reference: "Romans 8:28–30" / "Psalm 151:3" /
    // "Jude 5". Used for the expanded header and every aria-label.
    function displayRef(card) {
        var d = '\u2013';
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + d + card.v2);
        var meta = BOOK_META[card.osis];
        if (!meta) { return (card.osis || '?') + ' ' + card.ch + ':' + vparam; }
        if (meta.single) { return meta.name + ' ' + vparam; }
        return meta.name + ' ' + (card.ch + (meta.off || 0)) + ':' + vparam;
    }

    // The compact reference INSIDE the coloured cell: same shape as displayRef
    // but with the short label ("Rom 8:28–30", "Psalm 151:3", "Jude 5").
    function cellRef(card) {
        var d = '\u2013';
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + d + card.v2);
        var meta = BOOK_META[card.osis];
        if (!meta) { return (card.osis || '?') + ' ' + card.ch + ':' + vparam; }
        var label = meta.abbr || meta.name;
        if (meta.single) { return label + ' ' + vparam; }
        return label + ' ' + (card.ch + (meta.off || 0)) + ':' + vparam;
    }

    // A link back to the reader with the verse(s) pre-selected (?v=), or null
    // if we can't resolve the book slug (unknown osis).
    function readerLink(card) {
        var meta = BOOK_META[card.osis];
        if (!meta || !meta.slug || !card.tx) { return null; }
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + '-' + card.v2);
        return READER.replace('__TX__', encodeURIComponent(card.tx))
                     .replace('__BOOK__', encodeURIComponent(meta.slug))
                     .replace('__CH__', encodeURIComponent(card.ch)) +
               '?v=' + encodeURIComponent(vparam);
    }

    // First ~cap characters of the verse, trimmed at a word boundary when that
    // doesn't cost too much, with an ellipsis.
    function snippet(text, cap) {
        var t = (text || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        if (t.length <= cap) { return t; }
        var cut = t.slice(0, cap);
        var sp = cut.lastIndexOf(' ');
        if (sp > cap * 0.6) { cut = cut.slice(0, sp); }
        return cut + '\u2026';
    }

    // "23 verses from 3 books" — derived by the store (MBPericope.summarize)
    // so the hub's tile subtitle and this header can never disagree.
    function subtitleLabel(cards) {
        return window.MBPericope.summarize(cards).label;
    }

    // The per-card translation LINE, shown only on an expanded card. It renders
    // as a static, caret-less code first; hydrateSwitcher() (called on expand)
    // fetches this ref across translations and, ONLY if more than one exists,
    // upgrades it to an interactive <details> switcher (item 4). The fetch
    // params ride on the line as data-* so hydrate/pick can read them; if we
    // can't build a book slug (unknown osis) there's nothing to fetch and it
    // simply stays static.
    function txLineHtml(card, txUpper) {
        var meta = BOOK_META[card.osis];
        var slug = (meta && meta.slug) ? meta.slug : '';
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + '-' + card.v2);
        return '<div class="peri-card-tx"' +
                   ' data-book="' + esc(slug) + '"' +
                   ' data-ch="' + esc(card.ch) + '"' +
                   ' data-v="' + esc(vparam) + '"' +
                   ' data-tx="' + esc(card.tx || '') + '">' +
                   '<span class="tx-mini is-static">' + esc(txUpper) + '</span>' +
               '</div>';
    }

    // ---- per-verse rendering (items 5 & 6) --------------------------------
    // A verse card carries `vv` = [[num, text], ...] once captured or self-
    // healed. Numbers show only when a card holds more than one verse (a lone
    // verse is already named by the header). Cards of >2 verses page two at a
    // time so a long run never makes a giant card.

    // ALL numbered verse paragraphs (Phase A: pagination is gone — the
    // expanded card is a fixed cell box and any overflow SCROLLS inside
    // .peri-card-text, so a 40-verse run and a 1-verse card both render whole
    // and the box height is governed by the card's row span, not the content).
    // Numbers show only when a card holds more than one verse.
    function versesHtml(vv) {
        var showNums = vv.length > 1, html = '', i;
        for (i = 0; i < vv.length; i++) {
            html += '<p class="peri-v">' +
                        (showNums ? '<span class="peri-vn">' + esc(vv[i][0]) + '</span>' : '') +
                        esc(vv[i][1]) +
                    '</p>';
        }
        return html;
    }

    // The expanded body: every numbered verse in one scrollable container, or
    // — for a legacy blob card that hasn't self-healed yet — the raw snapshot
    // text. A .peri-scrollcue span rides along so the CSS can show a bottom
    // fade when the text overflows its box (toggled by markScrollCues).
    function verseBodyHtml(card) {
        var vv = card.vv;
        if (vv && vv.length) {
            return '<div class="peri-card-text">' +
                       '<div class="peri-verses">' + versesHtml(vv) + '</div>' +
                   '</div>';
        }
        // Legacy blob (no vv yet). Self-heals to numbered verses on expand.
        return '<div class="peri-card-text is-legacy">' + esc(card.text || '') + '</div>';
    }

    // Build the rendered column range: ONLY the columns that hold cards, seeded
    // with the home block (1..GRID_COLS) so a fresh board shows its working
    // columns. No resting pad columns — so a board that fits never overflows
    // (no stray scrollbar, no panning into blank space). Drag adds its spare
    // cells as PADDING at grab time, not as tracks. Logical columns (nonzero
    // ints, negatives allowed) map to contiguous CSS tracks (1..K); the occupied
    // span + home column are reported for the header anchor.
    var GRID_COLS = 3;   // home block width (mirrors store CAPS.gridCols)

    // A card's column, defaulting ONLY on absence. NEVER `c.col || 1`:
    // column 0 is legal and falsy, so || would silently misread it as home.
    function colOf(c) { return (typeof c.col === 'number') ? c.col : 1; }

    function columnLayout(cards) {
        var minC = 1, maxC = GRID_COLS, i, c, r;   // seed with the home block
        for (i = 0; i < cards.length; i++) {
            c = cards[i];
            minC = Math.min(minC, colOf(c));
            r = colOf(c) + (c.cw || 1) - 1;
            maxC = Math.max(maxC, r);
        }

        var cols = [], v, map = {};
        for (v = minC; v <= maxC; v++) { cols.push(v); }   // contiguous integers, 0 included
        for (i = 0; i < cols.length; i++) { map[cols[i]] = i + 1; }         // logical col -> CSS track
        return {
            cols: cols, map: map, count: cols.length,
            occMin: minC, occMax: maxC,
            homeTrack: map[1] || 1               // CSS track of logical home column
        };
    }

    // Explicit grid placement for one card, from its stored col/row/cw/rh.
    function placeStyle(card, colMap) {
        var track = colMap[card.col] || colMap[1] || 1;
        var cw = card.cw || 1, rh = card.rh || 1, row = card.row || 1;
        return 'grid-column:' + track + ' / span ' + cw + ';grid-row:' + row + ' / span ' + rh + ';';
    }

    function cardHtml(card, isExpanded, colMap) {
        if (card.type === 'verse') {
            var color   = paletteColor(BOOK_META[card.osis] && BOOK_META[card.osis].color);
            var full    = displayRef(card);
            var link    = readerLink(card);
            var tx      = (card.tx || '').toUpperCase();
            var hasVv   = !!(card.vv && card.vv.length);
            var snipSrc = hasVv ? card.vv[0][1] : (card.text || '');   // clean, no number
            // The reference LINK (Phase 2): cell (collapsed) or spelled-out
            // reference (expanded) plus ↗, as one <a>. It's the card's only
            // link — the body's click expands, the grip drags, neither can
            // navigate by accident. No reader URL → same shape, as a span.
            var refInner = '<span class="peri-cell">' + esc(cellRef(card)) + '</span>' +
                           '<span class="peri-ref-full">' + esc(full) + '</span>' +
                           // Phones swap the spelled-out name for the chip's
                           // short form on EXPANDED cards too (r14) — CSS
                           // flips which span shows at the 520px breakpoint.
                           '<span class="peri-ref-short">' + esc(cellRef(card)) + '</span>';
            var refLink = link
                ? '<a class="peri-ref" href="' + esc(link) + '" aria-label="Open ' + esc(full) + ' in the reader">' + refInner + ARROW + '</a>'
                : '<span class="peri-ref">' + refInner + '</span>';

            // A collapsed card is focusable and announces its expand action;
            // it does NOT carry role="button" because it holds a real link
            // (nested interactive content is invalid inside a button). The
            // single-tap-as-click behaviour on iOS comes from cursor:pointer.
            var open = isExpanded
                ? ' class="peri-card is-expanded" aria-expanded="true"'
                : ' class="peri-card" tabindex="0" aria-expanded="false" aria-label="Expand ' + esc(full) + '"';

            return '<article' + open + ' data-id="' + esc(card.id) + '" data-ref="' + esc(full) +
                       '" data-hasvv="' + (hasVv ? '1' : '0') +
                       '" data-cw="' + (card.cw || 1) + '" data-rh="' + (card.rh || 1) +
                       (isExpanded && card.ew != null && card.eh != null ? '" data-manual="1' : '') +
                       '" style="--bk:var(--tl-' + color + ');' + placeStyle(card, colMap) + '">' +
                       '<div class="peri-card-top">' +
                           '<span class="peri-grip" aria-hidden="true" title="Drag to move">' + GRIP + '</span>' +
                           '<div class="peri-card-titles">' +
                               '<div class="peri-card-head">' +
                                   refLink +
                               '</div>' +
                               txLineHtml(card, tx) +
                           '</div>' +
                       '</div>' +
                       '<div class="peri-card-snip">' + esc(snippet(snipSrc, SNIP_CAP)) + '</div>' +
                       verseBodyHtml(card) +
                       '<button type="button" class="peri-card-min" aria-label="Collapse ' + esc(full) + ' — hold to resize" title="Collapse — hold to resize">' + MINIMIZE + '</button>' +
                       '<button type="button" class="peri-card-edit" data-id="' + esc(card.id) + '" aria-label="Edit ' + esc(full) + '" title="Edit card" aria-pressed="false">' + SCISSORS + '</button>' +
                   '</article>';
        }
        // note / heading (Phase 3) — text-only, draggable, not expandable.
        var cls = card.type === 'heading' ? 'peri-card is-heading' : 'peri-card is-note';
        return '<article class="' + cls + '" data-id="' + esc(card.id) +
                   '" data-cw="' + (card.cw || 1) + '" data-rh="' + (card.rh || 1) +
                   '" style="' + placeStyle(card, colMap) + '">' +
                   '<div class="peri-card-top">' +
                       '<span class="peri-grip" aria-hidden="true" title="Drag to move">' + GRIP + '</span>' +
                   '</div>' +
                   '<div class="peri-card-text is-static">' + esc(card.text || '') + '</div>' +
                   '<button type="button" class="peri-card-del" data-id="' + esc(card.id) + '" aria-label="Remove card">' + TRASH + '</button>' +
               '</article>';
    }

    function init() {
        // Re-entry guard: if this file is somehow evaluated twice (a stale
        // script tag, a cached shell, a manual include), a second closure
        // would re-render and keep writing zoom-1 geometry over the live
        // one's. One board, one owner.
        if (window.MBPericopeBoard) {
            if (window.console && console.warn) {
                console.warn('[pericope] duplicate board script ignored — check for two pericope-board.js tags.');
            }
            return;
        }
        var boardEl = document.getElementById('pb-board');
        var missing = document.getElementById('pb-missing');
        var nameEl  = document.getElementById('pb-name');
        var subEl   = document.getElementById('pb-sub');
        var grid    = document.getElementById('pb-grid');
        var scroll  = document.getElementById('pb-scroll');
        var empty   = document.getElementById('pb-empty');
        var editBtn = document.getElementById('pb-edit');
        if (!boardEl) return;

        if (!window.MBPericope) {
            console.warn('[pericope] store not loaded on the board page.');
            missing.hidden = false;
            return;
        }

        var BOARD_ID = null;    // resolved on first render; mutations key off it
        var justDragged = false;
        // (Phase A) The pager is gone; verse text is no longer indexed per
        // render. vvMap is retired — removed with handlePager below.

        var currentLayout = { cols: [1, 2, 3], map: { 1: 1, 2: 2, 3: 3 }, count: 3 };  // last painted column layout

        var panDelta = 0;       // user's horizontal pan, measured from the anchored rest view
        var anchorRest = 0;     // rest scrollLeft that puts home under the header (last applyAnchor)
        var maxRow = 1;         // bottom-most occupied row after the last paint (for the resting height)
        var lastBoard = null;   // the board doc as of the last paint (group geometry derives from it)

        // Is edit mode live? (pericope-edit.js owns the state; Phase 5.)
        function editingNow() {
            return !!(window.MBPericopeEdit && window.MBPericopeEdit.active());
        }

        // ZOOM (Phase 4). One fixed level; 1 = off. The wrapper scales, the
        // strip is laid out 1/zoom wider (CSS), and every measurement below
        // that mixes screen px with grid px divides by zoomLevel.
        var ZOOM_OUT  = 0.6;    // KNOB: the zoomed-out scale
        var AUTO_MAX_RH = 4;    // KNOB: tallest an AUTO-expanded card may snap
                                // (rows). Replaces pagination's job — a long
                                // run never makes a giant card; past this the
                                // text scrolls inside the card. Manual resize
                                // (Phase C) may exceed it.
        var MIN_RH_EXPANDED = 2; // KNOB: shortest any expanded card may be
                                // (header + at least a line of text). Floor
                                // for auto-snap AND the resize budget.
        var RESIZE_MAX_CW = 4;  // KNOB: hard width ceiling for the Phase C
                                // gesture (store allows 6; 4 is the practical
                                // cap — wider than the home block reads odd).
        var RESIZE_SLACK = 0;   // KNOB (decision 5): rows of stretch
                                // forgiveness past content. 0 = a card may
                                // never grow past what its text can fill;
                                // raise to 1 for a row of air.
        var WIDTH_MIN_LINES = 2; // KNOB: a width is EARNED only while the
                                // text still wraps to at least this many
                                // lines there. Rows can't carry this test —
                                // chrome alone nearly buys the 2-row minimum
                                // at any width, which let a one-verse card
                                // stretch to 4 columns (the Philemon 17 bug).
        var EDGE_BREATHE = 24;  // KNOB: mobile pan margin past the outermost cards (px)
        // Same breakpoint as the CSS column-width switch.
        function isMobile() {
            return !!(window.matchMedia && window.matchMedia('(max-width: 520px)').matches);
        }
        var zoomLevel = 1;
        var zoomWrap  = document.getElementById('pb-zoomwrap');
        var zoomBtn   = document.getElementById('pb-zoom');

        // Bottom-most occupied row of a card list (row + span − 1), min 1.
        function bottomRow(cards) {
            var m = 1, i, c;
            for (i = 0; i < cards.length; i++) { c = cards[i]; m = Math.max(m, (c.row || 1) + (c.rh || 1) - 1); }
            return m;
        }

        // Build the DOM from the store and place every card by its coordinates.
        // Does NOT measure or position the view — reflow() measures spans and
        // applyAnchor() sets the padding + scroll (home under the header).
        function paint() {
            var board = window.MBPericope.get(SLUG);
            if (!board) { boardEl.hidden = true; missing.hidden = false; return null; }
            missing.hidden = true; boardEl.hidden = false;

            BOARD_ID = board.id;
            nameEl.textContent = board.name;
            document.title = board.name + ' — Pericope — MEGABIBLE.net';

            if (!board.cards.length) {
                subEl.textContent = '';
                grid.hidden = true; empty.hidden = false; grid.innerHTML = '';
                grid.style.gridTemplateColumns = '';
                return board;
            }
            subEl.textContent = subtitleLabel(board.cards);
            empty.hidden = true; grid.hidden = false;

            var layout = columnLayout(board.cards);
            currentLayout = layout;
            lastBoard = board;
            maxRow = bottomRow(board.cards);
            grid.style.gridTemplateColumns = 'repeat(' + layout.count + ', var(--pb-col))';

            var html = '';
            for (var i = 0; i < board.cards.length; i++) {
                html += cardHtml(board.cards[i], board.cards[i].exp === true, layout.map);
            }
            // Group shells (Phase 5) go AFTER the cards in the DOM; each
            // LABEL is a separate SIBLING of its shell (r11): the shell sits
            // at z −1 behind the cards, and a child can never escape its
            // parent's stacking context — with stretched cards filling their
            // full claimed rows, a label inside the shell vanished under any
            // card in the row above. As a sibling with its own z-index the
            // chip paints over cards again. Both are positioned in px by
            // positionGroups() (applyAnchor calls it once the pad is known);
            // here they only get identity and colour.
            for (var gi = 0; gi < (board.groups || []).length; gi++) {
                var g = board.groups[gi];
                html += '<div class="pb-group" data-group="' + esc(g.id) + '"' +
                        ' style="--gp: var(--tl-' + esc(g.color) + ')"></div>' +
                        '<span class="pb-group-label" data-group="' + esc(g.id) + '"' +
                        ' style="--gp: var(--tl-' + esc(g.color) + ')">' +
                        '<span class="pb-group-label-text">' + esc(g.label || 'Group') + '</span>' +
                        '<svg class="pb-group-label-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>' +
                        '</span>';
            }
            grid.innerHTML = html;
            budgetCache = {};   // any re-render can change text, tx or metrics

            // Member cards wear their group's colour as a border tint (the
            // is-selected wash still wins — it's declared later in the sheet).
            for (gi = 0; gi < (board.groups || []).length; gi++) {
                var grp = board.groups[gi];
                for (var mi = 0; mi < grp.cards.length; mi++) {
                    var mel = grid.querySelector('.peri-card[data-id="' + grp.cards[mi] + '"]');
                    if (mel) {
                        mel.classList.add('in-group');
                        mel.style.setProperty('--gp', 'var(--tl-' + grp.color + ')');
                    }
                }
            }

            // Any card that renders already expanded (e.g. after a tx switch)
            // needs its switcher hydrated; cached refs apply with no network.
            var exp = grid.querySelectorAll('.peri-card.is-expanded'), k;
            for (k = 0; k < exp.length; k++) { hydrateSwitcher(exp[k]); }
            return board;
        }

        // AUTO-SNAP each card's ROW SPAN to its state (Phase A, r11 fix round).
        // The span is authoritative — an expanded card is align-self:stretch,
        // so its rendered box is exactly its claimed rows — and this function
        // is the ONE writer that keeps every card's claim honest:
        //
        //   COLLAPSED verse   → rh 1, always. (The r10 regression skipped
        //     these, so a card collapsed from 3 rows kept a phantom 3-row
        //     claim — drag outlines, collisions and group boxes all honoured
        //     rows that weren't painted.)
        //   EXPANDED verse    → ROUND the content's natural height to the
        //     nearest row edge, clamped to [2 .. AUTO_MAX_RH]. Natural height
        //     is chrome + the .peri-verses block's own height — NOT the text
        //     container's scrollHeight, which floors at the container's box
        //     and so could grow but never shrink (the r10 "extra row that
        //     never goes away" ratchet). Content past the snapped box scrolls.
        //   NOTE / HEADING    → CEIL of rendered height (they carry no
        //     aria-expanded, don't stretch and don't clip, so a round-down
        //     would paint over the card below — the pre-A rule, kept).
        //   MANUAL (Phase C)  → untouched.
        //
        // setCardSpan pushes overlapping neighbours down; one re-paint settles
        // the stretch. Guarded; converges in one or two passes, no flash.
        function reflow(depth) {
            depth = depth || 0;
            if (depth > 4 || !BOARD_ID) { return; }
            var cards = grid.querySelectorAll('.peri-card');
            if (!cards.length) { return; }
            var cs = getComputedStyle(grid);
            var rowUnit = parseFloat(cs.gridAutoRows) || 36;
            var gutter  = parseFloat(cs.rowGap) || 16;
            var pitch   = rowUnit + gutter;
            var changed = false, i, el, exp, natural, needRh, needCw, curRh, curCw, textEl, versesEl;
            for (i = 0; i < cards.length; i++) {
                el = cards[i];
                exp = el.getAttribute('aria-expanded');   // 'true'/'false' = verse; null = note/heading
                needCw = null;                            // null = keep current cw

                if (exp === 'false') {
                    // Collapsed: exactly one 1×1 slot — checked BEFORE the
                    // manual skip, because a manually-sized card PARKS its
                    // remembered span (ew/eh in the store) while collapsed;
                    // its live footprint must still shrink with its pixels.
                    needRh = 1; needCw = 1;
                } else if (el.getAttribute('data-manual') === '1') {
                    // Expanded manual card (Phase C): the user's span is law.
                    continue;
                } else if (exp === 'true') {
                    textEl   = el.querySelector('.peri-card-text');
                    versesEl = el.querySelector('.peri-verses');
                    if (textEl && versesEl) {
                        // chrome (box minus the flexed text container — the
                        // container absorbs all stretch slack, so this is
                        // invariant to the current span) + TRUE content height
                        // (the verses block lays out at natural height no
                        // matter how its scroll parent is clipped).
                        natural = (el.getBoundingClientRect().height -
                                   textEl.getBoundingClientRect().height) +
                                  versesEl.getBoundingClientRect().height;
                    } else if (textEl) {
                        // Legacy blob (no .peri-verses yet): scrollHeight is
                        // the best available; it self-heals to per-verse on
                        // this very expand, and the next pass re-measures.
                        natural = (el.getBoundingClientRect().height -
                                   textEl.getBoundingClientRect().height) +
                                  textEl.scrollHeight;
                    } else {
                        natural = el.getBoundingClientRect().height;
                    }
                    // Round to the NEAREST edge — except a SINGLE-verse card,
                    // which CEILs: on a card that small, a scrollbar for half
                    // a hidden line is worse than a little air at the bottom
                    // (r12 decision: short cards prefer extra space).
                    if (versesEl && versesEl.children.length === 1) {
                        needRh = Math.ceil((natural + gutter) / pitch - 0.02);
                    } else {
                        needRh = Math.round((natural + gutter) / pitch);
                    }
                    if (needRh < MIN_RH_EXPANDED) { needRh = MIN_RH_EXPANDED; }
                    if (needRh > AUTO_MAX_RH) { needRh = AUTO_MAX_RH; }   // long runs scroll (see KNOB)
                } else {
                    // Note / heading: natural height, ceil with the exact-fit
                    // tolerance (sub-pixel rounding must not ceil 1.0 to 2).
                    natural = el.getBoundingClientRect().height;
                    needRh = Math.max(1, Math.ceil((natural + gutter) / pitch - 0.02));
                }

                curRh = parseInt(el.getAttribute('data-rh') || '1', 10);
                curCw = parseInt(el.getAttribute('data-cw') || '1', 10);
                if (needCw === null) { needCw = curCw; }
                if (needRh !== curRh || needCw !== curCw) {
                    window.MBPericope.setCardSpan(BOARD_ID, el.getAttribute('data-id'), needCw, needRh);
                    changed = true;
                }
            }
            if (changed) { paint(); reflow(depth + 1); }
            else { markScrollCues(); }   // spans settled — flag any card whose text still overflows
        }

        // Show the bottom fade only on cards whose text truly overflows its
        // (now fixed) box — a round-DOWN card, or a manually-shrunk one. Pure
        // class toggle; no writes. Re-run after spans settle and after a manual
        // resize.
        function markScrollCues() {
            var texts = grid.querySelectorAll('.peri-card.is-expanded .peri-card-text'), i, t;
            for (i = 0; i < texts.length; i++) {
                t = texts[i];
                t.classList.toggle('is-overflowing', t.scrollHeight - t.clientHeight > 2);
            }
        }

        // Publish the true visible width (excludes the scrollbar) for the strip's
        // full-bleed. scrollbar-gutter:stable keeps it steady; we also refresh it
        // per render as a fallback for browsers without that support.
        // Alongside it, --pb-gutter: the container's content-left in viewport
        // px (Phase 1) — the sticky head reads it to bleed to the viewport edge
        // and to keep its corner cluster on the content edge. Measured from
        // .container directly (not via the grid) so it's right even when the
        // grid is hidden on an empty board.
        // The full-bleed geometry is written as INLINE PX here rather than
        // through the --pb-vw custom property: on real boards the CSS var was
        // observed falling back to 100vw in the strip's width rule (the
        // zoom-mismatch console warning proved it — 100vw overshoots the true
        // visible width by the scrollbar, which is also what the old
        // "spurious scrollbar" came from). Inline px can't silently fall back.
        // --pb-vw is still published for anything cosmetic that reads it.
        function setViewportVar() {
            var root = document.documentElement;
            var vw = root.clientWidth;
            root.style.setProperty('--pb-vw', vw + 'px');
            if (zoomWrap) {
                zoomWrap.style.setProperty('width', vw + 'px', 'important');
                zoomWrap.style.setProperty('margin-left',  'calc(50% - ' + (vw / 2) + 'px)', 'important');
                zoomWrap.style.setProperty('margin-right', 'calc(50% - ' + (vw / 2) + 'px)', 'important');
            }
            if (scroll) {
                // Zoomed, the strip is laid out 1/zoom wider so the scaled
                // result spans exactly the visible viewport again. Written as
                // inline !important: nothing in any stylesheet can outrank it.
                scroll.style.setProperty('width',
                    (zoomLevel === 1 ? vw : vw / zoomLevel) + 'px', 'important');
            }
            var container = (boardEl.closest && boardEl.closest('.container')) ||
                            document.querySelector('.container');
            if (container) {
                var g = container.getBoundingClientRect().left +
                        (parseFloat(getComputedStyle(container).paddingLeft) || 0);
                root.style.setProperty('--pb-gutter', (g > 0 ? g : 0) + 'px');
            }
        }

        // The grid's RESTING height: the occupied rows plus REST_ROWS spare
        // slots (Phase 2), so the always-on dot grid has some open canvas
        // below the last card and a drop below the board has somewhere to
        // land. The drag uses the SAME allowance, so the page height doesn't
        // change on grab or drop.
        var REST_ROWS = 2;
        function setRestHeight() {
            var m = gridMetrics();
            grid.style.minHeight = (m.padT + (maxRow + REST_ROWS) * (m.rowUnit + m.rowGap) - m.rowGap) + 'px';
        }

        // Paint, settle spans, publish cell pitch + resting height, then anchor.
        function render() {
            setViewportVar(); paint(); reflow(0); setCellVars(); setRestHeight(); applyAnchor(); updateZoomBox();
            // Companions (edit mode's selection classes, Phase 5) re-apply
            // their DOM state after any repaint wiped it.
            try { document.dispatchEvent(new CustomEvent('mb:pericope-rendered')); } catch (_) {}
        }

        // ---- zoom (Phase 4) ---------------------------------------------
        // The wrapper's LAYOUT height stays the unscaled strip height, so when
        // zoomed we pin it to the scaled height (and its overflow:hidden clips
        // the rest). Re-run whenever the strip's height changes.
        function updateZoomBox() {
            if (!zoomWrap || !scroll) { return; }
            if (zoomLevel === 1) { zoomWrap.style.height = ''; return; }
            // Measure the strip's SCALED rect — ground truth, not arithmetic —
            // so the clip box can never disagree with what the transform
            // actually produced.
            var r = scroll.getBoundingClientRect();
            zoomWrap.style.height = r.height + 'px';
            // The scaled strip should render exactly viewport-wide. If not,
            // first try to heal it once (a scrollbar may have toggled between
            // the width write and this measure); only a mismatch that
            // SURVIVES the rewrite is worth reporting — and then report
            // everything needed to convict the culprit in one line.
            var vw = document.documentElement.clientWidth;
            if (Math.abs(r.width - vw) > 2) {
                setViewportVar();
                r = scroll.getBoundingClientRect();
                zoomWrap.style.height = r.height + 'px';
                vw = document.documentElement.clientWidth;
            }
            if (Math.abs(r.width - vw) > 2 && window.console && console.warn) {
                var tags = [], list = document.querySelectorAll('script[src*="pericope-board"]'), i;
                for (i = 0; i < list.length; i++) { tags.push(list[i].getAttribute('src')); }
                console.warn('[pericope] zoom mismatch persists: rendered=' + Math.round(r.width) +
                    ' viewport=' + vw + ' zoom=' + zoomLevel +
                    ' | strip inline="' + (scroll.getAttribute('style') || '') + '"' +
                    ' | strip computed=' + getComputedStyle(scroll).width +
                    ' | strip transform=' + getComputedStyle(scroll).transform +
                    ' | wrap computed=' + getComputedStyle(zoomWrap).width +
                    ' | board script tags: ' + tags.join(' , '));
            }
        }

        // Change the zoom level keeping the SCREEN point (fx, fy) over the same
        // spot on the board — the finger on touch, the viewport centre from
        // the button. Horizontal compensation goes to the strip's scrollLeft,
        // vertical to the page scroll; both clamp at zero, in which case the
        // board slides toward the top-left by the remainder, which is the
        // least jarring failure (that corner was already in view). Mid-drag
        // the drag owns the grid padding, so the anchor is skipped and the
        // pan is left for the drag to hand back on drop.
        function setZoom(z, fx, fy) {
            if (!zoomWrap || !grid || z === zoomLevel) { return; }
            // Zooming (either direction, including the drag's auto-zoom)
            // closes an open card-edit: the scissors and menu are
            // display:none while zoomed, and a state with no visible UI is
            // a state the user can't leave.
            if (window.MBPericopeCardEdit) { window.MBPericopeCardEdit.close(); }
            var R = zoomWrap.getBoundingClientRect();
            if (fx == null) { fx = document.documentElement.clientWidth / 2; }
            if (fy == null) { fy = (window.innerHeight || document.documentElement.clientHeight) / 2; }

            var z0 = zoomLevel, mid = dragging();
            var padL0 = parseFloat(getComputedStyle(grid).paddingLeft) || 0;
            var gxT = (fx - R.left) / z0 + grid.scrollLeft - padL0;   // grid px from track 1
            var gy  = (fy - R.top)  / z0;                              // grid px from the top
            var docTop = R.top + (window.pageYOffset || 0);

            zoomLevel = z;
            boardEl.classList.toggle('is-zoomed', z !== 1);
            zoomWrap.style.setProperty('--pb-zoom', String(z));
            if (zoomBtn) {
                zoomBtn.classList.toggle('is-active', z !== 1);
                zoomBtn.setAttribute('aria-pressed', z !== 1 ? 'true' : 'false');
            }

            if (!mid) { applyAnchor(); }            // new pad for the new scale
            updateZoomBox();

            var padL1 = parseFloat(getComputedStyle(grid).paddingLeft) || 0;
            var maxScroll = grid.scrollWidth - grid.clientWidth;
            grid.scrollLeft = Math.max(0, Math.min(maxScroll, gxT + padL1 - (fx - R.left) / z));
            window.scrollTo(window.pageXOffset || 0, Math.max(0, docTop - fy + z * gy));
            if (!mid) { panDelta = grid.scrollLeft - anchorRest; }
            setViewportVar();       // the page scrollbar may have come or gone with the height change
            updateZoomBox();
            updateFades();
            // One frame later the scrollbar state has settled — re-measure so
            // a width taken mid-toggle (the stale-clientWidth trap) corrects
            // itself instead of persisting as a sliver of cutoff.
            if (window.requestAnimationFrame) {
                requestAnimationFrame(function () { setViewportVar(); updateZoomBox(); });
            }
        }

        if (zoomBtn) {
            zoomBtn.addEventListener('click', function () {
                if (dragging()) { return; }
                // Two-state toggle, and BOTH directions keep the viewport
                // centre over the same spot on the board (setZoom's default
                // anchor), so repeated presses zoom in and out of where the
                // user is. Going home is the home button's job now.
                setZoom(zoomLevel === 1 ? ZOOM_OUT : 1);
                // iOS keeps a tapped button focused (and :hover-stuck) until
                // the next touch elsewhere, which left this button painted as
                // pressed after un-zooming. Hover paint is gated behind
                // @media (hover:hover) in the CSS; the focus ring dies here.
                zoomBtn.blur();
            });
        }

        // ---- home -----------------------------------------------------------
        // Back to the rest view: home column under the header, page at the
        // top. Zoom is left alone. Disabled while already there, so the
        // button doubles as an "am I home?" indicator.
        var homeBtn = document.getElementById('pb-home');
        function atHome() {
            return panDelta === 0 && (window.pageYOffset || 0) === 0;
        }
        function updateHomeBtn() {
            if (homeBtn) { homeBtn.disabled = atHome(); }
        }
        if (homeBtn) {
            homeBtn.addEventListener('click', function () {
                if (dragging()) { return; }
                panDelta = 0;
                applyAnchor();
                try { window.scrollTo({ left: window.pageXOffset || 0, top: 0, behavior: 'smooth' }); }
                catch (_) { window.scrollTo(window.pageXOffset || 0, 0); }
                homeBtn.blur();
                updateHomeBtn();
            });
            window.addEventListener('scroll', updateHomeBtn, { passive: true });
            if (grid) { grid.addEventListener('scroll', updateHomeBtn, { passive: true }); }
        }

        // ---- undo / redo --------------------------------------------------
        // The store keeps the stacks (session-only, see recordHistory in
        // pericope-store.js); this just presses the buttons and repaints.
        var undoBtn = document.getElementById('pb-undo');
        var redoBtn = document.getElementById('pb-redo');
        function updateHistoryBtns() {
            if (!BOARD_ID || !window.MBPericope.history) { return; }
            var h = window.MBPericope.history(BOARD_ID);
            if (undoBtn) { undoBtn.disabled = !h.undo; }
            if (redoBtn) { redoBtn.disabled = !h.redo; }
        }
        function stepHistory(fn) {
            if (dragging() || !BOARD_ID) { return; }
            if (fn(BOARD_ID)) { render(); }   // render() re-reads the store and fires mb:pericope-rendered
            updateHistoryBtns();
        }
        if (undoBtn) { undoBtn.addEventListener('click', function () { stepHistory(window.MBPericope.undo); undoBtn.blur(); }); }
        if (redoBtn) { redoBtn.addEventListener('click', function () { stepHistory(window.MBPericope.redo); redoBtn.blur(); }); }
        // Every repaint (any edit path ends in one) refreshes the counts;
        // the store's own event covers undo/redo triggered from elsewhere.
        document.addEventListener('mb:pericope-rendered', function () { updateHistoryBtns(); updateHomeBtn(); });
        document.addEventListener('mb:pericope-history', updateHistoryBtns);
        // Ctrl/Cmd+Z and Ctrl/Cmd+Shift+Z (or Ctrl+Y) — skipped while typing
        // in a rename / text-card field, where the browser's own undo wins.
        document.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) { return; }
            var t = e.target, tag = t && t.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || (t && t.isContentEditable)) { return; }
            var k = (e.key || '').toLowerCase();
            if (k === 'z' && !e.shiftKey) { e.preventDefault(); stepHistory(window.MBPericope.undo); }
            else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); stepHistory(window.MBPericope.redo); }
        });
        // The strip grows/shrinks with renders and drag previews; keep the
        // zoomed wrapper's height in step without every caller remembering.
        if (window.ResizeObserver && scroll) {
            new ResizeObserver(function () { if (zoomLevel !== 1) { updateZoomBox(); } }).observe(scroll);
        }

        // Toggle the strip's edge fades: left once panned right of the rest view,
        // right while more columns lie off the right edge (4c).
        function updateFades() {
            if (!scroll || !grid) { return; }
            var maxLeft = grid.scrollWidth - grid.clientWidth;
            scroll.classList.toggle('can-left',  grid.scrollLeft > 1);
            scroll.classList.toggle('can-right', grid.scrollLeft < maxLeft - 1);
        }

        // Publish the cell pitch (colW+gap, rowUnit+gap) so the drag dot-grid
        // aligns to the tracks. Read after paint so the track widths are settled.
        function setCellVars() {
            if (!grid) { return; }
            // Whole px: a fractional background-size tile is what rasterised
            // the dots into squares.
            var m = gridMetrics();
            grid.style.setProperty('--pb-cell-x', Math.round(m.colW + m.colGap) + 'px');
            grid.style.setProperty('--pb-cell-y', Math.round(m.rowUnit + m.rowGap) + 'px');
        }

        // The container's content-left in the grid's own x — i.e. where the
        // header text starts. Home column is anchored to this.
        function headerGutter() {
            var container = (boardEl.closest && boardEl.closest('.container')) ||
                            document.querySelector('.container');
            if (!container) { return 0; }
            var g = (container.getBoundingClientRect().left +
                     (parseFloat(getComputedStyle(container).paddingLeft) || 0)) -
                    grid.getBoundingClientRect().left;
            g = g / zoomLevel;          // screen px → grid px (the pad is inside the scaled box)
            return g > 0 ? g : 0;
        }

        // Anchor the view so logical home (column 1) sits under the header, using
        // grid PADDING (not scroll) when the negatives fit in the gutter — so a
        // board that fits never overflows (no scrollbar, no blank panning) and
        // cards travel with the header on resize. The user's pan is preserved as
        // panDelta (px from the rest view). Not called during a drag (the drag
        // manages its own padding).
        function applyAnchor() {
            if (!grid || dragging()) { return; }
            if (!currentLayout.count) { return; }
            var m = gridMetrics(), cellX = m.colW + m.colGap;
            var homeOffset = (currentLayout.homeTrack - 1) * cellX;   // width of columns left of home
            var raw = headerGutter() - homeOffset;

            // BREATHING ROOM: the pan range always ends a little past the
            // outermost cards. On DESKTOP that's one full blank column each
            // side (the drag's new-column targets physically exist in it).
            // On MOBILE a whole blank column read as dead space, so the
            // margin is a slim EDGE_BREATHE instead — the −1 drop column
            // still works, its indicator just clamps to a sliver at the
            // screen edge (pericope-drag.js). Home stays anchored under the
            // header via restScroll.
            var breathe = isMobile() ? EDGE_BREATHE : cellX;
            var padL, restScroll;
            if (raw >= breathe) { padL = raw; restScroll = 0; }
            else { padL = breathe; restScroll = breathe - raw; }

            grid.style.setProperty('--pb-pad-l', padL + 'px');
            grid.style.setProperty('--pb-pad-r', '0px');

            // …and one blank column on the right, as a 1px extent element
            // (absolutely positioned children extend a scroller's scrollable
            // range in every browser; padding-right doesn't in WebKit). The
            // drag drops into it; panning right stops at it.
            var ext = grid.querySelector('.pb-extent');
            if (!ext) {
                ext = document.createElement('div');
                ext.className = 'pb-extent';
                grid.appendChild(ext);
            }
            ext.style.left = (padL + currentLayout.count * cellX - m.colGap +
                              (isMobile() ? EDGE_BREATHE : cellX)) + 'px';
            ext.style.top = '0px';

            positionGroups(padL, m);

            var maxScroll = grid.scrollWidth - grid.clientWidth;
            anchorRest = Math.max(0, Math.min(maxScroll, restScroll));
            var target = anchorRest + panDelta;
            grid.scrollLeft = Math.max(0, Math.min(maxScroll, target));
            updateFades();
        }

        // GROUP OUTLINES (Phase 5). Pure derivation: each group's box is the
        // bounding rectangle of its member cards' grid cells (plus a small
        // halo), computed fresh every anchor pass — nothing is stored. A
        // group whose members are all missing this paint renders nothing.
        var GROUP_HALO = 7;   // px the outline extends beyond the member cells
        var lastGroupPad = null;   // { padL, m } from the latest positionGroups

        function positionGroups(padL, m) {
            lastGroupPad = { padL: padL, m: m };
            var shells = grid.querySelectorAll('.pb-group');
            if (!shells.length || !lastBoard) { return; }
            var cellX = m.colW + m.colGap, cellY = m.rowUnit + m.rowGap;
            var byId = {}, i, c, sh, g, gi, tMin, tMax, rMin, rMax, mm, t;
            for (i = 0; i < lastBoard.cards.length; i++) { byId[lastBoard.cards[i].id] = lastBoard.cards[i]; }
            var groups = {};
            for (gi = 0; gi < (lastBoard.groups || []).length; gi++) { groups[lastBoard.groups[gi].id] = lastBoard.groups[gi]; }
            for (i = 0; i < shells.length; i++) {
                sh = shells[i];
                g = groups[sh.getAttribute('data-group')];
                tMin = Infinity; tMax = -Infinity; rMin = Infinity; rMax = -Infinity;
                if (g) {
                    for (mm = 0; mm < g.cards.length; mm++) {
                        c = byId[g.cards[mm]];
                        if (!c) { continue; }
                        t = (currentLayout.map[colOf(c)] || 1) - 1;        // 0-based track
                        tMin = Math.min(tMin, t);
                        tMax = Math.max(tMax, t + (c.cw || 1) - 1);
                        rMin = Math.min(rMin, c.row || 1);
                        rMax = Math.max(rMax, (c.row || 1) + (c.rh || 1) - 1);
                    }
                }
                var lab = grid.querySelector('.pb-group-label[data-group="' + sh.getAttribute('data-group') + '"]');
                if (tMin === Infinity) { sh.hidden = true; if (lab) { lab.hidden = true; } continue; }
                sh.hidden = false;
                // --pb-top gives every row-1 box enough headroom that the
                // chip ALWAYS floats above it (the old inside-clamp is gone).
                var boxL = padL + tMin * cellX - GROUP_HALO;
                var boxT = m.padT + (rMin - 1) * cellY - GROUP_HALO;
                sh.style.left   = boxL + 'px';
                sh.style.top    = boxT + 'px';
                sh.style.width  = ((tMax - tMin + 1) * cellX - m.colGap + 2 * GROUP_HALO) + 'px';
                sh.style.height = ((rMax - rMin + 1) * cellY - m.rowGap + 2 * GROUP_HALO) + 'px';
                // The chip: same offsets it had as a shell child (left+10,
                // 6px above the box top; its own translateY(-100%) lifts it
                // clear), now applied here because it's a sibling.
                if (lab) {
                    lab.hidden = false;
                    lab.style.left = (boxL + 10) + 'px';
                    lab.style.top  = (boxT - 6) + 'px';
                }
            }
        }

        // GROUP-FOLLOW preview (r14): reposition ONE group's shell (and chip)
        // to an arbitrary set of member cells — the drag module calls this on
        // every target change while a MEMBER card is held, so the outline
        // visibly stretches and travels with the hover. Same box maths as
        // positionGroups; tracks come from plain arithmetic on the contiguous
        // column line (a hover in a not-yet-rendered column still previews —
        // the box just reaches into the pad). Truth returns on the next
        // render's positionGroups.
        function previewGroupCells(gid, cells) {
            if (!lastGroupPad || !cells || !cells.length) { return; }
            var padL = lastGroupPad.padL, m = lastGroupPad.m;
            var cellX = m.colW + m.colGap, cellY = m.rowUnit + m.rowGap;
            var sh  = grid.querySelector('.pb-group[data-group="' + gid + '"]');
            var lab = grid.querySelector('.pb-group-label[data-group="' + gid + '"]');
            if (!sh) { return; }
            var col0 = currentLayout.cols.length ? currentLayout.cols[0] : 1;
            var tMin = Infinity, tMax = -Infinity, rMin = Infinity, rMax = -Infinity, i, c, t;
            for (i = 0; i < cells.length; i++) {
                c = cells[i];
                t = c.col - col0;                                  // 0-based track
                tMin = Math.min(tMin, t);
                tMax = Math.max(tMax, t + (c.cw || 1) - 1);
                rMin = Math.min(rMin, c.row || 1);
                rMax = Math.max(rMax, (c.row || 1) + (c.rh || 1) - 1);
            }
            var boxL = padL + tMin * cellX - GROUP_HALO;
            var boxT = m.padT + (rMin - 1) * cellY - GROUP_HALO;
            sh.hidden = false;
            sh.style.left   = boxL + 'px';
            sh.style.top    = boxT + 'px';
            sh.style.width  = ((tMax - tMin + 1) * cellX - m.colGap + 2 * GROUP_HALO) + 'px';
            sh.style.height = ((rMax - rMin + 1) * cellY - m.rowGap + 2 * GROUP_HALO) + 'px';
            if (lab) {
                lab.hidden = false;
                lab.style.left = (boxL + 10) + 'px';
                lab.style.top  = (boxT - 6) + 'px';
            }
        }

        // Logical column at a (possibly out-of-range) 0-based track index.
        // Columns are a contiguous integer line, so extrapolating past the
        // rendered tracks (drops into new columns) is plain arithmetic —
        // there is no seam to hop.
        function colAtTrack(trackIdx) {
            var cols = currentLayout.cols;
            return (cols.length ? cols[0] : 1) + trackIdx;
        }

        // Expand/collapse a card. On the coordinate grid a height change must
        // re-settle the card's row span and push neighbours, so we persist the
        // state (quiet write — never bumps "updated", item 4) and re-render;
        // reflow() then reconciles spans. The pre-toggle class flip keeps the
        // switcher hydration path intact and avoids a flash before the re-render.
        function setExpanded(card, on) {
            var id = card.getAttribute('data-id');
            if (on) {
                card.classList.add('is-expanded');
                card.setAttribute('aria-expanded', 'true');
                card.removeAttribute('tabindex');
                card.removeAttribute('aria-label');
                hydrateSwitcher(card);
            } else {
                card.classList.remove('is-expanded');
                card.setAttribute('aria-expanded', 'false');
                card.setAttribute('tabindex', '0');
                card.setAttribute('aria-label', 'Expand ' + (card.getAttribute('data-ref') || ''));
            }
            if (BOARD_ID) {
                window.MBPericope.setCardExpanded(BOARD_ID, id, on);
                render();   // re-place + settle spans (grow/shrink the card, push neighbours)
            }
        }

        function isVerseCard(card) {
            return card && !card.classList.contains('is-note') && !card.classList.contains('is-heading');
        }

        // ---- per-card translation switcher (3b / 3c / item 4) --------------
        // On expand we fetch THIS ref across translations (once per ref, cached
        // in txCache so it survives re-renders and never re-fetches). Only when
        // more than one translation exists do we upgrade the static code into an
        // interactive <details>; a single-translation verse stays a plain code
        // with no caret and no menu. On pick we rewrite the card's tx + text.

        var txCache = {};   // "book|ch|v" => [ {abbr, short, name, year, text}, ... ]

        function txKey(line) {
            return line.getAttribute('data-book') + '|' +
                   line.getAttribute('data-ch') + '|' +
                   line.getAttribute('data-v');
        }
        function shortFor(abbr, list) {
            for (var i = 0; i < list.length; i++) { if (list[i].abbr === abbr) { return list[i].short; } }
            return (abbr || '').toUpperCase();
        }

        // Fetch (or reuse) availability for an expanded card's switcher line and
        // render the appropriate state. Cheap and idempotent: cached refs apply
        // synchronously with no network and no flicker.
        function hydrateSwitcher(cardEl) {
            var line = cardEl.querySelector('.peri-card-tx');
            if (!line) { return; }                          // notes/headings have none
            var book = line.getAttribute('data-book');
            if (!book) { return; }                          // unknown osis → stays static
            var key = txKey(line);
            if (txCache[key]) { applySwitcher(line, txCache[key]); return; }
            if (line._loading) { return; }
            line._loading = true;

            var url = CARD_TX_URL +
                '?book=' + encodeURIComponent(book) +
                '&chapter=' + encodeURIComponent(line.getAttribute('data-ch')) +
                '&v=' + encodeURIComponent(line.getAttribute('data-v'));

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (data) {
                    txCache[key] = (data && data.translations) || [];
                    line._loading = false;
                    applySwitcher(line, txCache[key]);
                    maybeSelfHeal(cardEl, line, txCache[key]);
                })
                .catch(function () { line._loading = false; });   // leave the static code in place
        }

        // A legacy card stored only a blob `text`. The switcher fetch already
        // returns every translation's verses INCLUDING this card's own, so we
        // can silently upgrade it to per-verse `vv` with no extra request — a
        // quiet write (no "updated" bump), then a re-render so numbers/paging
        // appear. Runs once per legacy card; new cards skip it.
        function maybeSelfHeal(cardEl, line, list) {
            if (cardEl.getAttribute('data-hasvv') !== '0') { return; }   // already per-verse
            var cur = line.getAttribute('data-tx'), entry = null, i;
            for (i = 0; i < list.length; i++) { if (list[i].abbr === cur) { entry = list[i]; break; } }
            if (!entry || !entry.verses || !entry.verses.length) { return; }
            if (!BOARD_ID) { return; }
            var res = window.MBPericope.setCardVerses(BOARD_ID, cardEl.getAttribute('data-id'), entry.verses, entry.text);
            if (res) { render(); }
        }

        function applySwitcher(line, list) {
            var cur = line.getAttribute('data-tx');
            var curShort = shortFor(cur, list);
            // Item 4: only a verse present in MORE THAN ONE translation gets a
            // real switcher; otherwise there's nothing to switch to.
            if (list.length <= 1) {
                line.innerHTML = '<span class="tx-mini is-static">' + esc(curShort) + '</span>';
                return;
            }
            var opts = '', i, t;
            for (i = 0; i < list.length; i++) {
                t = list[i];
                if (t.abbr === cur) {
                    opts += '<span class="tx-option is-current" aria-current="true">' +
                                '<span class="tx-check">\u2713</span>' +
                                '<span class="tx-name">' + esc(t.short) + '</span>' +
                            '</span>';
                } else {
                    opts += '<button type="button" class="tx-option" data-abbr="' + esc(t.abbr) + '">' +
                                '<span class="tx-check"></span>' +
                                '<span class="tx-name">' + esc(t.short) + '</span>' +
                            '</button>';
                }
            }
            line.innerHTML =
                '<details class="tx peri-txsw">' +
                    '<summary class="tx-mini">' + esc(curShort) + ' <span class="tx-caret">' + CARET + '</span></summary>' +
                    '<div class="tx-menu" role="menu">' + opts + '</div>' +
                '</details>';
        }

        function handleTxPick(opt) {
            var line = closest(opt, '.peri-card-tx');
            var card = closest(opt, '.peri-card');
            if (!line || !card) { return; }
            var details = closest(opt, 'details.peri-txsw');
            if (details) { details.removeAttribute('open'); }      // close the menu
            var abbr = opt.getAttribute('data-abbr');
            if (!abbr || abbr === line.getAttribute('data-tx')) { return; }

            var list = txCache[txKey(line)] || [], entry = null, i;
            for (i = 0; i < list.length; i++) { if (list[i].abbr === abbr) { entry = list[i]; break; } }
            if (!entry) { return; }

            if (BOARD_ID) {
                // updateCard re-validates (slugifies tx, clamps text + vv) and
                // bumps the board's updated stamp + hub index. 3c: persisted
                // here; vv rides along so the new translation is per-verse too.
                var res = window.MBPericope.updateCard(BOARD_ID, card.getAttribute('data-id'),
                                                       { tx: abbr, text: entry.text, vv: entry.verses });
                if (res) { render(); }   // card stays expanded; re-hydrates from cache (new tx checked)
            }
        }

        // ---- click: delete / collapse / expand (delegated) -----------------
        grid.addEventListener('click', function (e) {
            // A click fired at the tail of a drag shouldn't also expand.
            if (justDragged) { justDragged = false; return; }
            // Edit mode (Phase 5): taps select — pericope-edit.js owns them
            // (its capture-phase listener normally stops them reaching here).
            if (editingNow()) { return; }
            // Zoomed (Phase 4): cards are inert — drag only.
            if (zoomLevel !== 1) { e.preventDefault(); return; }

            // Card-edit (pericope-cardedit.js) owns its button and menu in
            // CAPTURE; this is the belt in case a click ever gets through.
            if (closest(e.target, '.peri-card-edit') || closest(e.target, '.peri-card-menu')) { return; }

            var del = closest(e.target, '.peri-card-del');
            if (del) {
                e.preventDefault();
                if (BOARD_ID) { window.MBPericope.removeCard(BOARD_ID, del.getAttribute('data-id')); }
                render();
                return;
            }
            var min = closest(e.target, '.peri-card-min');
            if (min) {
                e.preventDefault();
                var mc = closest(min, '.peri-card');
                if (mc) { setExpanded(mc, false); }
                return;
            }
            if (closest(e.target, '.peri-grip')) { return; }       // handle only drags
            if (closest(e.target, 'a.peri-ref')) { return; }       // let the reader link work

            // Translation switcher (lives on an expanded card). Catch an option
            // pick; otherwise swallow switcher-area clicks so the summary's
            // native <details> toggle runs without the card expanding/collapsing.
            var opt = closest(e.target, '.peri-card-tx .tx-option');
            if (opt && opt.tagName === 'BUTTON') { handleTxPick(opt); return; }
            if (closest(e.target, '.peri-card-tx')) { return; }

            var card = closest(e.target, '.peri-card');
            if (!card || !isVerseCard(card)) { return; }
            if (card.getAttribute('aria-expanded') === 'false') { setExpanded(card, true); }
        });

        // ---- keyboard: Enter/Space expands, Escape collapses ---------------
        grid.addEventListener('keydown', function (e) {
            if (zoomLevel !== 1 || editingNow()) { return; }       // inert while zoomed / editing
            var card = closest(e.target, '.peri-card');
            if (!card || !isVerseCard(card)) { return; }
            if ((e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') &&
                card.getAttribute('aria-expanded') === 'false') {
                e.preventDefault();
                setExpanded(card, true);
                card.focus();
            } else if (e.key === 'Escape' && card.classList.contains('is-expanded')) {
                setExpanded(card, false);
                card.focus();
            }
        });

        // ---- drag to place -------------------------------------------------
        // Lives in public/js/pericope-drag.js (Phase 3). It attaches to this
        // grid through window.MBPericopeBoard (published at the end of init)
        // and reports back through render() / setPan() / swallowNextClick().

        // Cell pitch and gutters, read from the grid's computed styles after
        // paint (track widths are settled by then).
        function gridMetrics() {
            var cs = getComputedStyle(grid);
            var tracks = (cs.gridTemplateColumns || '').split(' ')
                .map(function (s) { return parseFloat(s); })
                .filter(function (n) { return !isNaN(n); });
            return {
                colW:    tracks[0] || 240,
                colGap:  parseFloat(cs.columnGap) || 16,
                rowUnit: parseFloat(cs.gridAutoRows) || 36,
                rowGap:  parseFloat(cs.rowGap) || 16,
                padT:    parseFloat(cs.paddingTop) || 0   // --pb-top headroom above row 1
            };
        }

        /* ---- CONTENT BUDGET (Phase B) --------------------------------------
           The rulebook the Phase C resize gesture will consult: how many rows
           this card's content NEEDS at each candidate width, and therefore
           which sizes are allowed.

             WIDTH RULE   a width is only allowed while the content still
                          fills at least MIN_RH_EXPANDED rows there — a one-
                          verse card can't go four columns wide, a twenty-
                          verse card can (content earns width).
             HEIGHT RULE  a card may stretch to rowsAt(cw) + RESIZE_SLACK and
                          no further (no dead space), and may always shrink to
                          MIN_RH_EXPANDED — the in-card scroll absorbs it.

           Measurement is a hidden PROBE: a real .peri-card.is-expanded shell
           parked on the board root (OUTSIDE the zoom transform and outside
           the grid, so paint() never wipes it and zoom never scales it). The
           card's verse markup is cloned in at the candidate pixel width and
           the .peri-verses block is measured — same classes, same fonts, same
           padding as the live card, so the number is exact, not estimated.
           Chrome (header/padding — width-invariant) is measured off the LIVE
           card and un-scaled by the current zoom, since rects inside the
           zoomwrap come back multiplied by the transform.

           Results are cached per card×width; paint() clears the cache, so a
           resize gesture probing on every pointermove pays for each width
           once. Verse cards only (decision 3). */
        var probeEl = null, probeVerses = null, budgetCache = {};

        function ensureProbe() {
            if (probeEl) { return; }
            probeEl = document.createElement('div');
            probeEl.className = 'peri-card is-expanded pb-probe';
            probeEl.setAttribute('aria-hidden', 'true');
            probeEl.innerHTML = '<div class="peri-card-text"><div class="peri-verses"></div></div>';
            boardEl.appendChild(probeEl);
            probeVerses = probeEl.querySelector('.peri-verses');
        }

        // What this card's content does at cw columns: { rows, lines }.
        //   rows  — grid rows to fully contain it (chrome + probed text,
        //           CEILed). Drives the HEIGHT rule. Raw, no MIN floor.
        //   lines — how many text lines the verses wrap to at that width.
        //           Drives the WIDTH rule: content earns width only while it
        //           still fills WIDTH_MIN_LINES lines (rows can't carry this
        //           test — chrome alone nearly buys 2 rows at any width).
        // null when the card isn't an expanded verse card in the DOM.
        function probeCard(cardId, cw) {
            var key = cardId + '|' + cw;
            if (budgetCache[key] != null) { return budgetCache[key]; }
            var el = grid.querySelector('.peri-card[data-id="' + cardId + '"]');
            if (!el || el.getAttribute('aria-expanded') !== 'true') { return null; }
            var textEl   = el.querySelector('.peri-card-text');
            var versesEl = el.querySelector('.peri-verses');
            if (!textEl || !versesEl) { return null; }   // legacy blob: no budget until self-heal
            var m = gridMetrics();
            var pitch = m.rowUnit + m.rowGap;
            var z = (zoomLevel > 0) ? zoomLevel : 1;
            var chrome = (el.getBoundingClientRect().height -
                          textEl.getBoundingClientRect().height) / z;
            ensureProbe();
            probeEl.style.width = (cw * m.colW + (cw - 1) * m.colGap) + 'px';
            probeVerses.innerHTML = versesEl.innerHTML;
            var textH = probeVerses.getBoundingClientRect().height;
            var pcs = getComputedStyle(probeVerses);
            var lineH = parseFloat(pcs.lineHeight);
            if (!lineH || isNaN(lineH)) { lineH = (parseFloat(pcs.fontSize) || 16) * 1.55; }
            var out = {
                rows:  Math.max(1, Math.ceil((chrome + textH + m.rowGap) / pitch - 0.02)),
                lines: Math.max(1, Math.round(textH / lineH))
            };
            probeVerses.innerHTML = '';   // never leave stale scripture in the probe
            budgetCache[key] = out;
            return out;
        }

        // The whole rulebook for one card, or null (not a verse card / not
        // expanded / not found). rowsAt and maxRhAt are live functions so the
        // gesture can query widths lazily; maxCw is precomputed by walking
        // widths until the content stops filling the minimum height.
        function resizeBudget(cardId) {
            var b = lastBoard, card = null, i;
            if (!b) { return null; }
            for (i = 0; i < b.cards.length; i++) {
                if (b.cards[i].id === cardId) { card = b.cards[i]; break; }
            }
            if (!card || card.type !== 'verse' || card.exp !== true) { return null; }
            var maxCw = 1, w, p;
            for (w = 2; w <= RESIZE_MAX_CW; w++) {
                p = probeCard(cardId, w);
                if (p == null || p.lines < WIDTH_MIN_LINES) { break; }   // width no longer earned
                maxCw = w;
            }
            return {
                minCw: 1,
                maxCw: maxCw,
                minRh: MIN_RH_EXPANDED,
                rowsAt: function (cw) { var q = probeCard(cardId, cw); return q ? q.rows : null; },
                maxRhAt: function (cw) {
                    var q = probeCard(cardId, cw);
                    if (q == null) { return MIN_RH_EXPANDED; }
                    return Math.min(40,   // store gridRowSpanMax
                        Math.max(MIN_RH_EXPANDED, q.rows + RESIZE_SLACK));
                }
            };
        }

        // Is a drag live? Asked before any render that a drag shouldn't
        // interrupt (anchor, text settings, pan tracking).
        function dragging() {
            return !!(window.MBPericopeDrag && window.MBPericopeDrag.active());
        }

        // ---- rename (inline; re-slugs and updates the URL) -----------------
        function beginRename() {
            if (!BOARD_ID) return;
            var input = document.createElement('input');
            input.className = 'pb-name-input';
            input.value = nameEl.textContent;
            input.maxLength = 80;
            nameEl.replaceWith(input);
            input.focus(); input.select();

            var done = false;
            function commit(save) {
                if (done) return; done = true;
                var newName = save ? input.value.replace(/^\s+|\s+$/g, '') : nameEl.textContent;
                if (save && newName) {
                    var res = window.MBPericope.rename(BOARD_ID, newName);
                    if (res) {
                        nameEl.textContent = res.name;
                        document.title = res.name + ' — Pericope — MEGABIBLE.net';
                        if (res.slug !== SLUG) {
                            SLUG = res.slug;
                            lastBoard = res;   // keep board() fresh — the share sheet reads it (the stale-name bug)
                            try { history.replaceState(null, '', HUB + '/' + encodeURIComponent(res.slug)); } catch (_) {}
                        }
                    }
                }
                input.replaceWith(nameEl);
            }
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); commit(true); }
                else if (e.key === 'Escape') { e.preventDefault(); commit(false); }
            });
            input.addEventListener('blur', function () { commit(true); });
        }
        editBtn.addEventListener('click', beginRename);
        nameEl.addEventListener('click', beginRename);

        // Track the user's pan as an offset from the anchored rest view, so it
        // survives re-renders and resizes. Ignore scroll events fired while a
        // drag manipulates scrollLeft directly.
        var fadeRaf = 0;
        function scheduleFades() {
            if (fadeRaf) { return; }
            fadeRaf = requestAnimationFrame(function () { fadeRaf = 0; updateFades(); });
        }
        function onScroll() {
            if (!dragging()) { panDelta = grid.scrollLeft - anchorRest; }
            scheduleFades();
        }
        function onResize() { setViewportVar(); applyAnchor(); updateZoomBox(); }   // re-anchor: cards travel with the header
        if (grid) { grid.addEventListener('scroll', onScroll, { passive: true }); }
        window.addEventListener('resize', onResize);

        // Text settings (Phase 1): a size / font / spacing change from the Aa
        // panel changes card heights, so re-render to re-settle row spans (and
        // push neighbours if a card grew). Never mid-drag — the drag owns the
        // grid until it drops.
        document.addEventListener('mb:reader-change', function () { if (!dragging()) { render(); } });

        // Dot-grid preference (Phase 2): always / drag / off, from the store's
        // prefs (mbPericopePrefs.v1). A settings panel later just calls
        // MBPericope.setPrefs({grid: ...}) and applyPrefs() again.
        function applyPrefs() {
            var prefs = (window.MBPericope.getPrefs && window.MBPericope.getPrefs()) || { grid: 'always' };
            grid.classList.remove('pb-dots-always', 'pb-dots-drag', 'pb-dots-off');
            grid.classList.add('pb-dots-' + prefs.grid);
        }
        document.addEventListener('mb:pericope-prefs', applyPrefs);

        applyPrefs();
        setViewportVar();   // publish the full-bleed width before the first paint
        render();           // paints, settles spans, and anchors home under the header
        // Deployment marker: one line so a stale cached script is instantly
        // visible. Bump when the geometry code changes.
        if (window.console && console.info) { console.info('[pericope] board geometry r17'); }

        // ---- public surface for the companion scripts ---------------------
        // pericope-drag.js (Phase 3) and pericope-edit.js (Phase 5) attach to
        // the board through this — never by reaching into the DOM on their
        // own. Everything here is a live getter or a callback into this file.
        window.MBPericopeBoard = {
            grid:     grid,
            scroll:   scroll,
            boardEl:  boardEl,
            slug:     function () { return SLUG; },
            boardId:  function () { return BOARD_ID; },
            layout:   function () { return currentLayout; },
            maxRow:   function () { return maxRow; },
            REST_ROWS: REST_ROWS,
            colAtTrack:  colAtTrack,
            gridMetrics: gridMetrics,
            // Phase B: the content budget the resize gesture consults.
            resizeBudget: resizeBudget,
            // r14: drag's live group-follow outline while a member is held.
            previewGroupCells: previewGroupCells,
            render:      render,
            applyAnchor: applyAnchor,
            anchorRest:  function () { return anchorRest; },
            getPan:      function () { return panDelta; },
            setPan:      function (px) { panDelta = px; },
            // zoom (Phase 4)
            ZOOM_OUT: ZOOM_OUT,
            zoom:     function () { return zoomLevel; },
            setZoom:  setZoom,
            // edit mode (Phase 5)
            board:    function () { return lastBoard; },
            // presentation: label a card the way the board does
            displayRef: displayRef,
            // A click fired at the tail of a drag shouldn't expand the card.
            swallowNextClick: function () {
                justDragged = true;
                setTimeout(function () { justDragged = false; }, 0);
            }
        };
        try { document.dispatchEvent(new CustomEvent('mb:pericope-board-ready')); } catch (_) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
