/* ======================================================================
   PERICOPE STORE                                  public/js/pericope-store.js
   ----------------------------------------------------------------------
   The one owner of Pericope's localStorage. Everything else — the FAB
   "Add to Pericope" sheet, the hub, the board editor, export/import, the
   shared-URL page — reads and writes boards THROUGH this module, so the
   schema has a single definition and can evolve in one place. This is the
   analogue of window.MBActs being "one writer for every kind of act."

   Phase 0: PURE DATA. No DOM, no fetch, no Acts logging, no UI. Loading
   this file only defines window.MBPericope and does nothing else.

   Vanilla ES5 (var / function — no arrow functions, no let/const), matching
   the other shared scripts. UMD-wrapped so the Node schema harness can load
   the exact browser file and exercise it against an in-memory storage shim.

   ----------------------------------------------------------------------
   STORAGE LAYOUT  (two-tier, so a write to one big board never rewrites
   the others):

     mbPericope.v1.index        { v, boards: [ {id,slug,name,cards,created,updated} ] }
                                 cards is a COUNT, so listing the hub never
                                 parses a single full board.

     mbPericope.v1.board.<id>   the full board document:

       {
         v: 1,
         id: "k7x2m9",          short random, stable, NEVER shown in a URL
         slug: "paul",          the URL segment; unique within the index
         name: "Paul",
         layout: "grid",        "grid" now; "canvas" | "slides" later
         created: 1756200000000,
         updated: 1756200000000,
         cards: [
           { id, type:"verse", osis, ch, v1, v2, tx, text, exp, vv, col, row, cw, rh, x, y, w },
           { id, type:"heading", text, col, row, cw, rh, x, y, w },
           { id, type:"note",    text, col, row, cw, rh, x, y, w }
         ],   // col/row/cw/rh = fixed-column grid; x/y/w = canvas (later)
         links:  [ { from, to } ],                     Phase 3
         groups: [ { id, label, color, cards: [cardIds] } ]  Phase 5 — MEMBERSHIP,
                   not geometry: the outline and label position are derived at
                   render time from the member cards' grid cells, exactly like
                   references are derived from raw osis+chapter. One group per
                   card; grouping an already-grouped card STEALS it (its old
                   group dissolves if emptied).
       }

   DESIGN NOTES baked in:
     - A verse card carries its OWN `tx`. Boards are never translation-gated;
       per-card translation switching (later) is just rewriting this field and
       re-hydrating `text`. A KJV card and a WEB card coexist with no special
       handling.
     - The ref (osis + ch + v1 + v2) is the IDENTITY; `text` is a cached
       snapshot captured from the reader at add-time so the board paints
       instantly offline. Anything arriving without text (a shared URL, an old
       file) is hydrated later. A contiguous run (Rom 8:28-30) is ONE card
       (v1:28, v2:30), matching the FAB's existing runs().
     - x / y / w exist but are IGNORED in grid layout (cards flow in array
       order). Canvas mode seeds them later — same document, two layouts, no
       migration.
     - NO display strings are ever stored. "Romans 8:28-30" / "Psalm 151:3"
       are always derived at render time from bookMeta.
   ====================================================================== */
(function (root) {
    'use strict';

    /* ---- keys & schema -------------------------------------------------- */

    var SCHEMA_VERSION = 1;
    var INDEX_KEY      = 'mbPericope.v1.index';
    var BOARD_PREFIX   = 'mbPericope.v1.board.';

    // Slugs that are real routes under /extras/pericope and so can never be
    // assigned to a board (would shadow the hub's own pages).
    var RESERVED_SLUGS = ['shared', 'verses'];

    // Caps. Card caps are your answer to open-question #3 (150 soft / 300
    // hard); tweakable from here. Text caps stop one note from eating storage.
    var CAPS = {
        groupHard:  40,      // groups per board (runaway guard)
        groupLabelMax: 40,   // group label characters
        cardSoft:   150,     // UI warns past this; store still accepts
        cardHard:   300,     // store REFUSES past this
        boardHard:  500,     // runaway guard on number of boards
        noteMax:    2000,    // note body characters
        headingMax: 200,     // heading characters
        nameMax:    80,      // board name characters
        verseTextMax: 20000, // cached verse snapshot (a whole long chapter run)
        coordMax:   100000,  // |x|,|y| clamp for canvas positions
        widthMax:   2000,    // card width clamp (canvas pixels)

        // Fixed-column GRID layout (Phase 4). Columns are a CONTIGUOUS
        // integer line: column 1 is "home"; 0 and negatives sit left of it
        // (…-2,-1,0,1,2…). Rows start at 1 (never 0 or negative). cw/rh are
        // a card's cell span. "Unplaced" is signalled ONLY by an ABSENT col
        // — never by 0, which is a real column (and falsy: never default a
        // column with `c.col || 1`).
        gridCols:       3,   // default home-block width (columns 1..gridCols)
        gridColLimit:   50,  // |col| ≤ this
        gridRowLimit:   500, // 1 ≤ row ≤ this
        gridColSpanMax: 6,   // max cw (columns a card may span)
        gridRowSpanMax: 40   // max rh (rows a card may span)
    };

    var CARD_TYPES  = ['verse', 'heading', 'note', 'interlinear'];
    var LAYOUTS     = ['grid', 'canvas', 'slides'];
    // The theme palette (--tl-* custom properties in app.blade). Stored as
    // NAMES; the client renders var(--tl-<name>) so groups repaint with the
    // active theme like everything else.
    var GROUP_COLORS = ['gold', 'terracotta', 'moss', 'teal', 'olive', 'navy',
                        'crimson', 'royal', 'plum', 'indigo', 'clay'];

    var DEFAULT_WIDTH = { verse: 280, note: 220, heading: 0, interlinear: 280 };   // 0 = auto

    /* ---- storage access (safe, injectable) ------------------------------ */

    // Resolve a Storage object. In the browser this is window.localStorage; in
    // the Node harness it's an in-memory shim placed on the sandbox global. If
    // storage is missing or blocked (private mode, disabled), everything below
    // fails soft: reads return sane empties, writes return false.
    function ls() {
        try { if (root && root.localStorage) { return root.localStorage; } } catch (e) {}
        try { if (typeof localStorage !== 'undefined') { return localStorage; } } catch (e) {}
        return null;
    }

    function readRaw(key) {
        try { var s = ls(); return s ? s.getItem(key) : null; } catch (e) { return null; }
    }
    function writeRaw(key, value) {
        try { var s = ls(); if (!s) { return false; } s.setItem(key, value); return true; }
        catch (e) { return false; }   // quota exceeded / blocked — caller decides
    }
    function removeRaw(key) {
        try { var s = ls(); if (s) { s.removeItem(key); } } catch (e) {}
    }

    function now() { return Date.now(); }

    function isArray(x) { return Object.prototype.toString.call(x) === '[object Array]'; }
    function isObj(x)   { return x && typeof x === 'object' && !isArray(x); }
    function isStr(x)   { return typeof x === 'string'; }
    function isNum(x)   { return typeof x === 'number' && isFinite(x); }

    /* ---- small value helpers -------------------------------------------- */

    function clampInt(v, lo, hi, dflt) {
        var n = parseInt(v, 10);
        if (isNaN(n)) { return dflt; }
        if (n < lo) { return lo; }
        if (n > hi) { return hi; }
        return n;
    }
    function clampNum(v, lo, hi, dflt) {
        var n = typeof v === 'number' ? v : parseFloat(v);
        if (!isFinite(n)) { return dflt; }
        if (n < lo) { return lo; }
        if (n > hi) { return hi; }
        return n;
    }
    // Normalize a grid column: any integer, clamped to ±gridColLimit.
    // 0 and negatives are legal (column 1 is "home"; 0 sits just left of it).
    function normCol(v, limit) {
        var n = parseInt(v, 10);
        if (isNaN(n)) { return 1; }
        if (n < -limit) { return -limit; }
        if (n > limit) { return limit; }
        return n;
    }
    // Trim, collapse internal whitespace runs, strip control chars, clamp.
    function cleanText(v, max) {
        if (!isStr(v)) { v = v == null ? '' : String(v); }
        v = v.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');
        if (v.length > max) { v = v.slice(0, max); }
        return v;
    }

    // Validate a per-verse array [[num, text], ...]: sane ints, cleaned text,
    // capped in count and TOTAL size (shared budget with the blob snapshot).
    // Returns a clean array or null. Used by validateCard and setCardVerses.
    function sanitizeVerses(input) {
        if (!isArray(input)) { return null; }
        var vv = [], budget = CAPS.verseTextMax, j, row, vn, vt;
        for (j = 0; j < input.length && vv.length < 500; j++) {
            row = input[j];
            if (!isArray(row) || row.length < 2) { continue; }
            vn = clampInt(row[0], 1, 999, 0);
            if (!vn) { continue; }
            vt = cleanText(row[1], budget > 0 ? budget : 0);
            budget -= vt.length;
            vv.push([vn, vt]);
            if (budget <= 0) { break; }
        }
        return vv.length ? vv : null;
    }    

    // A board name: single-line, collapsed, clamped, with a fallback.
    function sanitizeName(v) {
        v = cleanText(v, CAPS.nameMax).replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        return v === '' ? 'Untitled' : v;
    }

    // "Paul & Grace!" -> "paul-grace". Diacritics collapse to hyphens in v1
    // (good enough; a phrase-URL/Latin scheme can refine this later).
    function slugify(v) {
        v = isStr(v) ? v.toLowerCase() : '';
        v = v.replace(/[^a-z0-9]+/g, '-').replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');
        return v;
    }

    /* ---- id & slug allocation ------------------------------------------- */

    // Short base36 id, collision-checked against a set of taken ids.
    function genId(taken) {
        var id, tries = 0;
        do {
            id = Math.random().toString(36).slice(2, 8);
            if (id.length < 6) { id = (id + '000000').slice(0, 6); }
            tries++;
        } while (taken && taken[id] && tries < 50);
        return id;
    }

    // A slug unique within the index (and never a reserved word). exceptId
    // lets rename keep its own slug. Falls back to a generated base if the
    // name slugified to empty.
    function uniqueSlug(index, base, exceptId) {
        base = base || 'pericope';
        var taken = {};
        var i;
        for (i = 0; i < index.boards.length; i++) {
            if (index.boards[i].id !== exceptId) { taken[index.boards[i].slug] = true; }
        }
        for (i = 0; i < RESERVED_SLUGS.length; i++) { taken[RESERVED_SLUGS[i]] = true; }

        if (!taken[base]) { return base; }
        var n = 2;
        while (taken[base + '-' + n]) { n++; }
        return base + '-' + n;
    }

    function takenIdMap(index) {
        var m = {}, i;
        for (i = 0; i < index.boards.length; i++) { m[index.boards[i].id] = true; }
        return m;
    }

    /* ---- index read / write --------------------------------------------- */

    function emptyIndex() { return { v: SCHEMA_VERSION, boards: [] }; }

    function readIndex() {
        var raw = readRaw(INDEX_KEY);
        if (!raw) { return emptyIndex(); }
        try {
            var o = JSON.parse(raw);
            if (!isObj(o) || !isArray(o.boards)) { return emptyIndex(); }
            o.v = SCHEMA_VERSION;
            return o;
        } catch (e) { return emptyIndex(); }
    }
    function writeIndex(index) {
        index.v = SCHEMA_VERSION;
        return writeRaw(INDEX_KEY, JSON.stringify(index));
    }

    // The hub's card COUNT excludes interlinear children (card-edit
    // Phase 2) — they're annotations riding on verses, not verses.
    function countedCards(cards) {
        if (!isArray(cards)) { return 0; }
        var n = 0, i;
        for (i = 0; i < cards.length; i++) { if (cards[i].type !== 'interlinear') { n++; } }
        return n;
    }

    function indexEntryOf(board) {
        return {
            id:      board.id,
            slug:    board.slug,
            name:    board.name,
            cards:   countedCards(board.cards),
            created: board.created,
            updated: board.updated,
            imported: isNum(board.imported) ? board.imported : null
        };
    }

    // Insert or update this board's lightweight entry in the index.
    function syncIndexEntry(board) {
        var index = readIndex(), i, found = false;
        for (i = 0; i < index.boards.length; i++) {
            if (index.boards[i].id === board.id) {
                index.boards[i] = indexEntryOf(board);
                found = true;
                break;
            }
        }
        if (!found) { index.boards.push(indexEntryOf(board)); }
        return writeIndex(index);
    }

    /* ---- board read / write --------------------------------------------- */

    function boardKey(id) { return BOARD_PREFIX + id; }

    function readBoard(id) {
        var raw = readRaw(boardKey(id));
        if (!raw) { return null; }
        try { var o = JSON.parse(raw); return isObj(o) ? o : null; } catch (e) { return null; }
    }
    // `quiet` — a view-derived write (span, expand flag, self-healing
    // verse data, tx fallback) that is NOT an edit: invisible to history.
    function writeBoard(board, quiet) {
        if (!quiet) { recordHistory(board); }
        return writeRaw(boardKey(board.id), JSON.stringify(board));
    }

    /* ---- history (undo / redo) ------------------------------------------
       Session-only, per board, in memory: a stack of the board's previous
       JSON strings. Nothing is ever written to storage, so it costs no
       quota, can't go stale across tabs, and vanishes on reload — that IS
       the retention policy.

       ONE HOOK. Every mutation in this file funnels through writeBoard().
       Real edits call it plainly and the OLD stored string goes on the undo
       stack; the four view-derived writers (setCardSpan, setCardExpanded,
       setCardVerses, setCardTx) pass quiet=true and are invisible to
       history. (Comparing `updated` stamps was tried and rejected: two
       commits in the same millisecond look identical.)

       COALESCING. One gesture can be several commits (a drop into a group
       is addToGroup + moveCard; an import is a burst). Commits landing
       within HISTORY.coalesceMs of the last recorded one join it, so one
       gesture is one undo step.

       undo()/redo() write the neighbouring snapshot back raw (bypassing the
       hook), restamp `updated` — undoing is an edit — and resync the index
       so a rename or a card-count change shows on the hub. */
    var HISTORY = { max: 10, coalesceMs: 300 };
    var history = {};   // boardId -> { undo: [json…], redo: [json…], last: ts }

    function histOf(id) { return history[id] || (history[id] = { undo: [], redo: [], last: 0 }); }

    function recordHistory(board) {
        var prevRaw = readRaw(boardKey(board.id));
        if (!prevRaw) { return; }                        // brand-new board: nothing to go back to
        var h = histOf(board.id), t = now();
        if (h.undo.length && (t - h.last) < HISTORY.coalesceMs) { h.last = t; return; }   // same gesture
        h.undo.push(prevRaw);
        while (h.undo.length > HISTORY.max) { h.undo.shift(); }
        h.redo.length = 0;
        h.last = t;
    }

    // Shared by undo and redo: swap the stored document for `raw`, moving the
    // current one onto `stack`. Returns the restored board or null.
    function restoreSnapshot(id, raw, stack) {
        var curRaw = readRaw(boardKey(id)), board;
        try { board = JSON.parse(raw); } catch (e) { return null; }
        if (!isObj(board)) { return null; }
        if (curRaw) { stack.push(curRaw); }
        board.updated = now();
        if (!writeRaw(boardKey(id), JSON.stringify(board))) { return null; }
        syncIndexEntry(board);
        histOf(id).last = 0;                             // the next edit is its own step
        if (typeof document !== 'undefined' && document.dispatchEvent && typeof CustomEvent === 'function') {
            try { document.dispatchEvent(new CustomEvent('mb:pericope-history', { detail: { id: id } })); } catch (_) {}
        }
        return board;
    }

    function undo(id) {
        var h = histOf(id);
        if (!h.undo.length) { return null; }
        return restoreSnapshot(id, h.undo.pop(), h.redo);
    }
    function redo(id) {
        var h = histOf(id);
        if (!h.redo.length) { return null; }
        return restoreSnapshot(id, h.redo.pop(), h.undo);
    }
    // -> { undo: n, redo: n } — the counts the buttons enable from.
    function historyCounts(id) {
        var h = history[id];
        return { undo: h ? h.undo.length : 0, redo: h ? h.redo.length : 0 };
    }
    function clearHistory(id) { delete history[id]; }

    // Resolve a slug OR an id to its index entry.
    function entryOf(slugOrId) {
        var index = readIndex(), i;
        for (i = 0; i < index.boards.length; i++) {
            if (index.boards[i].id === slugOrId || index.boards[i].slug === slugOrId) {
                return index.boards[i];
            }
        }
        return null;
    }

    /* ---- card / board validation (the untrusted-input gate) -------------
       validateBoard() is what EVERY externally-sourced board must pass
       through before it touches storage — imported files (Phase 4) and
       shared URLs (Phase 4). It produces a structurally valid board or
       admits failure, clamping numbers, dropping unknown fields, whitelisting
       enums, and regenerating duplicate/absent ids. It does NOT dedupe the
       slug against the index (that's an insertion-time concern the import
       flow owns) — it only guarantees the slug is a valid slug STRING.

       NOTE ON TEXT SAFETY: card text (notes, headings, verse snapshots) is
       stored RAW here, only length-clamped and stripped of control chars.
       It is NEVER treated as HTML. The render layer MUST inject it via
       textContent, never innerHTML — stripping "<script>" here would corrupt
       legitimate prose ("if a < b"), so the defense lives at render time.
       -------------------------------------------------------------------- */

    function validateCard(input, seenIds) {
        if (!isObj(input)) { return { card: null, reason: 'not an object' }; }
        var type = input.type;
        if (CARD_TYPES.indexOf(type) === -1) {
            return { card: null, reason: 'unknown type ' + JSON.stringify(input.type) };
        }

        // id: string, unique within this board; regenerate if missing/dupe.
        var id = isStr(input.id) && input.id ? input.id : genId(seenIds);
        if (seenIds[id]) { id = genId(seenIds); }
        seenIds[id] = true;

        var card = {
            id:   id,
            type: type,
            x:    clampNum(input.x, -CAPS.coordMax, CAPS.coordMax, 0),
            y:    clampNum(input.y, -CAPS.coordMax, CAPS.coordMax, 0),
            w:    clampInt(input.w, 0, CAPS.widthMax, DEFAULT_WIDTH[type])
        };

        // Grid coordinates (Phase 4). Carried only when already valid; an
        // unplaced card (col ABSENT — 0 is a real column) is positioned by
        // ensureGridPlacement on read/add. cw/rh are the card's cell
        // footprint, default 1×1.
        if (isNum(input.col) && isNum(input.row) && input.row >= 1) {
            card.col = normCol(input.col, CAPS.gridColLimit);
            card.row = clampInt(input.row, 1, CAPS.gridRowLimit, 1);
        }
        card.cw = clampInt(input.cw, 1, CAPS.gridColSpanMax, 1);
        card.rh = clampInt(input.rh, 1, CAPS.gridRowSpanMax, 1);

        if (type === 'verse') {
            if (!isStr(input.osis) || input.osis === '') {
                return { card: null, reason: 'verse card missing osis' };
            }
            var v1 = clampInt(input.v1, 1, 999, 1);
            var v2 = clampInt(input.v2, 1, 999, v1);
            // A reversed range READS as its ascending self ("30-28" -> "28-30"),
            // the same rule ReferenceResolver::verseRanges() applies to "15-9".
            // Swap — never collapse — so no verse is silently lost.
            if (v2 < v1) { var _t = v1; v1 = v2; v2 = _t; }
            card.osis = cleanText(input.osis, 32);
            card.ch   = clampInt(input.ch, 1, 999, 1);
            card.v1   = v1;
            card.v2   = v2;
            card.tx   = slugify(input.tx) || 'unknown';
            card.text = cleanText(input.text, CAPS.verseTextMax);   // may be ''
            card.exp  = input.exp === true;   // persisted expanded/collapsed view state
            var vv = sanitizeVerses(input.vv);   // optional per-verse text
            if (vv) { card.vv = vv; }            // absent on legacy cards → self-heal
            // MANUAL expanded size (Phase C): the span the user chose with the
            // hold-to-resize gesture, remembered even while collapsed.
            if (isNum(input.ew) && isNum(input.eh)) {
                card.ew = clampInt(input.ew, 1, CAPS.gridColSpanMax, 1);
                card.eh = clampInt(input.eh, 1, CAPS.gridRowSpanMax, 1);
            }
            // FOOTPRINT INVARIANTS, enforced on every validate so imports and
            // stale documents self-heal (reflow keeps them honest at runtime):
            // a COLLAPSED verse card is always a 1×1 slot — the p1 w flag on a
            // manual card carries the REMEMBERED width, never a collapsed
            // card's live footprint — and an EXPANDED manual card's footprint
            // IS its remembered span.
            if (!card.exp) { card.cw = 1; card.rh = 1; }
            else if (card.ew != null) { card.cw = card.ew; card.rh = card.eh; }
        } else if (type === 'interlinear') {
            // The original-language CHILD card (card-edit Phase 2). Its whole
            // identity is `parent` — the id of a VERSE card on this board;
            // the ref, the language, the tokens all DERIVE from it at render
            // time, and tokens are never persisted, so this card is nothing
            // but the tether plus geometry. Parent RESOLUTION happens in
            // validateBoard and the mutators (which can see the card list) —
            // an orphan is dropped there, not here. Expand/collapse, the
            // manual size (ew/eh, hold-to-resize) and the collapsed-is-1×1
            // invariant all work exactly like a verse card's.
            if (!isStr(input.parent) || !input.parent) {
                return { card: null, reason: 'interlinear card missing parent' };
            }
            card.parent = cleanText(input.parent, 32);
            card.exp = input.exp === true;
            if (isNum(input.ew) && isNum(input.eh)) {
                card.ew = clampInt(input.ew, 1, CAPS.gridColSpanMax, 1);
                card.eh = clampInt(input.eh, 1, CAPS.gridRowSpanMax, 1);
            }
            if (!card.exp) { card.cw = 1; card.rh = 1; }
            else if (card.ew != null) { card.cw = card.ew; card.rh = card.eh; }
        } else if (type === 'heading') {
            card.text = cleanText(input.text, CAPS.headingMax);
        } else { // note
            card.text = cleanText(input.text, CAPS.noteMax);
        }

        return { card: card, reason: null };
    }

    function validateBoard(input) {
        var dropped = [];
        if (!isObj(input)) {
            return { ok: false, board: null, dropped: ['input is not an object'] };
        }

        var created = isNum(input.created) ? input.created : now();
        var updated = isNum(input.updated) ? input.updated : now();
        var imported = isNum(input.imported) ? input.imported : null;   // set by importShared; hub shows "Imported" instead of "Created"
        var name    = sanitizeName(input.name);
        var layout  = LAYOUTS.indexOf(input.layout) !== -1 ? input.layout : 'grid';

        var board = {
            v:       SCHEMA_VERSION,
            id:      isStr(input.id) && input.id ? input.id : genId({}),
            slug:    slugify(input.slug) || slugify(name) || 'pericope',
            name:    name,
            layout:  layout,
            created: created,
            updated: updated,
            imported: imported,
            cards:   [],
            links:   [],
            groups:  []
        };

        // Cards — validate each, drop the invalid, enforce the hard cap.
        var seen = {}, i, res;
        var inCards = isArray(input.cards) ? input.cards : [];
        for (i = 0; i < inCards.length; i++) {
            if (board.cards.length >= CAPS.cardHard) {
                dropped.push('card[' + i + ']: over hard cap (' + CAPS.cardHard + ')');
                continue;
            }
            res = validateCard(inCards[i], seen);
            if (res.card) { board.cards.push(res.card); }
            else { dropped.push('card[' + i + ']: ' + res.reason); }
        }

        // TETHER pass (card-edit Phase 2): an interlinear child must point
        // at a surviving VERSE card, and a parent carries at most ONE child
        // (first in array order wins). Orphans and extras are dropped here,
        // so imports and stale documents self-heal on every validate.
        var tKind = {}, tKept = [], tChild = {}, tc;
        for (i = 0; i < board.cards.length; i++) { tKind[board.cards[i].id] = board.cards[i].type; }
        for (i = 0; i < board.cards.length; i++) {
            tc = board.cards[i];
            if (tc.type === 'interlinear') {
                if (tKind[tc.parent] !== 'verse') { dropped.push('card ' + tc.id + ': interlinear orphan (no verse parent)'); continue; }
                if (tChild[tc.parent]) { dropped.push('card ' + tc.id + ': parent already has an interlinear child'); continue; }
                tChild[tc.parent] = true;
            }
            tKept.push(tc);
        }
        board.cards = tKept;

        var idSet = {};
        for (i = 0; i < board.cards.length; i++) { idSet[board.cards[i].id] = true; }

        // Links — keep only well-formed refs to cards that survived.
        var inLinks = isArray(input.links) ? input.links : [];
        for (i = 0; i < inLinks.length; i++) {
            var lk = inLinks[i];
            if (isObj(lk) && idSet[lk.from] && idSet[lk.to] && lk.from !== lk.to) {
                board.links.push({ from: lk.from, to: lk.to });
            } else {
                dropped.push('link[' + i + ']: dangling or malformed');
            }
        }

        // Groups — minimal validation (fuller shape lands with Phase 3).
        // Groups (Phase 5): membership only. Legacy canvas-era rectangles
        // (x/y/w/h) are DROPPED with a note — pre-launch sandbox, no
        // migration by decision. Membership is validated against the cards
        // that survived above; one group per card (first group wins); a
        // group with no surviving members is dropped.
        var cardIdSet = {};
        for (i = 0; i < board.cards.length; i++) { cardIdSet[board.cards[i].id] = true; }
        var inGroups = isArray(input.groups) ? input.groups : [];
        var gseen = {}, claimed = {};
        for (i = 0; i < inGroups.length; i++) {
            var g = inGroups[i];
            if (!isObj(g)) { dropped.push('group[' + i + ']: not an object'); continue; }
            if (!isArray(g.cards)) { dropped.push('group[' + i + ']: legacy shape (no cards list)'); continue; }
            if (board.groups.length >= CAPS.groupHard) { dropped.push('group[' + i + ']: over group cap'); continue; }
            var gid = isStr(g.id) && g.id ? g.id : genId(gseen);
            if (gseen[gid]) { gid = genId(gseen); }
            gseen[gid] = true;
            var members = [], m, cid;
            for (m = 0; m < g.cards.length; m++) {
                cid = g.cards[m];
                if (isStr(cid) && cardIdSet[cid] && !claimed[cid]) { claimed[cid] = true; members.push(cid); }
            }
            if (!members.length) { dropped.push('group[' + i + ']: no surviving member cards'); continue; }
            board.groups.push({
                id:    gid,
                label: cleanText(g.label, CAPS.groupLabelMax),
                color: GROUP_COLORS.indexOf(g.color) !== -1 ? g.color : GROUP_COLORS[0],
                cards: members
            });
        }

        return { ok: true, board: board, dropped: dropped };
    }

    /* ---- default-position seeding for incoming cards -------------------- */

    // Cards added from the reader arrive as partial specs; normalize them,
    // (re)assign fresh in-board ids, and default their geometry. Grid mode
    // ignores x/y, so 0/0 is fine until canvas mode seeds real positions.
    function normalizeIncoming(cards, existingIdSet) {
        var out = [], i, res;
        var seen = {};
        var k;
        for (k in existingIdSet) { if (existingIdSet.hasOwnProperty(k)) { seen[k] = true; } }
        for (i = 0; i < cards.length; i++) {
            res = validateCard(cards[i], seen);
            if (res.card) { out.push(res.card); }
        }
        return out;
    }

    /* ---- grid placement (fixed-column coordinate layout) ----------------
       All in-memory: these shape a board's card coordinates; callers persist.
       A card occupies a cw×rh block of cells anchored at (col,row). Placement
       is FREE (gaps allowed, nothing is pulled up); drops/growth push
       overlapping cards DOWN only. ----------------------------------------- */

    function cardCw(c) { return clampInt(c.cw, 1, CAPS.gridColSpanMax, 1); }
    function cardRh(c) { return clampInt(c.rh, 1, CAPS.gridRowSpanMax, 1); }

    // A card is "placed" once it carries a col (any integer — 0 included)
    // and a row ≥ 1. Absence of col is the ONLY "unplaced" signal.
    function hasPos(c) {
        return isNum(c.col) && isNum(c.row) && c.row >= 1;
    }

    // Do two placed cards' cell rectangles overlap?
    function cardsOverlap(a, b) {
        return a.col < b.col + cardCw(b) && b.col < a.col + cardCw(a) &&
               a.row < b.row + cardRh(b) && b.row < a.row + cardRh(a);
    }

    // Occupancy set { "col,row": true } for the placed cards (excluding one).
    function occupancyOf(cards, exceptId) {
        var occ = {}, i, c, dc, dr;
        for (i = 0; i < cards.length; i++) {
            c = cards[i];
            if (c.id === exceptId || !hasPos(c)) { continue; }
            for (dc = 0; dc < cardCw(c); dc++) {
                for (dr = 0; dr < cardRh(c); dr++) {
                    occ[(c.col + dc) + ',' + (c.row + dr)] = true;
                }
            }
        }
        return occ;
    }

    // Is a cw×rh block free at (col,row)?
    function slotFree(occ, col, row, cw, rh) {
        var dc, dr;
        for (dc = 0; dc < cw; dc++) {
            for (dr = 0; dr < rh; dr++) {
                if (occ[(col + dc) + ',' + (row + dr)]) { return false; }
            }
        }
        return true;
    }

    // The next free slot for a cw×rh card, packing the home block (cols
    // 1..gridCols) row-major from (1,1). This mirrors the old flow order, so a
    // migrated board looks exactly as it did before coordinates existed.
    function nextFreeSlot(occ, cw, rh) {
        var cols = CAPS.gridCols, row, col;
        for (row = 1; row <= CAPS.gridRowLimit; row++) {
            for (col = 1; col + cw - 1 <= cols; col++) {
                if (slotFree(occ, col, row, cw, rh)) { return { col: col, row: row }; }
            }
        }
        return { col: 1, row: CAPS.gridRowLimit };   // board effectively full
    }

    // Give any UNplaced cards a home-block slot. Returns true if it changed
    // anything. Runs on read (get) and after add, so callers always see a fully
    // placed grid; the result is deterministic and persists on the next write.
    function ensureGridPlacement(board) {
        if (!board || board.layout !== 'grid' || !isArray(board.cards)) { return false; }
        var placed = [], unplaced = [], i, c, dc, dr, slot;
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            if (hasPos(c)) { placed.push(c); } else { unplaced.push(c); }
        }
        if (!unplaced.length) { return false; }
        var occ = occupancyOf(placed);
        for (i = 0; i < unplaced.length; i++) {
            c = unplaced[i];
            c.cw = cardCw(c); c.rh = cardRh(c);
            slot = nextFreeSlot(occ, c.cw, c.rh);
            c.col = slot.col; c.row = slot.row;
            for (dc = 0; dc < c.cw; dc++) {
                for (dr = 0; dr < c.rh; dr++) { occ[(c.col + dc) + ',' + (c.row + dr)] = true; }
            }
        }
        return true;
    }

    // Push cards down until none overlap the anchor (cascading). The anchor
    // never moves and rows only increase, so this always converges. In-memory.
    // GROUP TERRITORY (Phase 5): a group's bounding box belongs to its
    // members. Any non-member sitting inside it is EXPELLED to just below
    // the box, then the order-preserving sweep settles the fallout. Multi-
    // pass because expelling from one group can push a card into another's
    // box. Runs after createGroup and after every committed move, so a
    // foreign card dropped inside a group bounces out rather than squatting.
    function expelForeigners(cards, groups) {
        if (!isArray(groups) || !groups.length) { return; }
        var pass, changed, gi, g, mem, i, c, cMin, cMax, rMin, rMax, m, cardL, cardR, cardT, cardB;
        // DERIVED membership (card-edit Phase 2): an interlinear CHILD is
        // always a member of its parent's group and never listed in any
        // group.cards. Fold the children in per group below, so a child
        // sitting in (or stretching) its parent's box is at home there and
        // is a foreigner everywhere else — the child-can't-leave-the-
        // parent's-group rule IS this fold plus the expulsion that follows.
        var par = {}, pi;
        for (pi = 0; pi < cards.length; pi++) {
            if (cards[pi].type === 'interlinear') { par[cards[pi].id] = cards[pi].parent; }
        }
        for (pass = 0; pass < 5; pass++) {
            changed = false;
            for (gi = 0; gi < groups.length; gi++) {
                g = groups[gi];
                mem = {}; cMin = Infinity; cMax = -Infinity; rMin = Infinity; rMax = -Infinity;
                for (m = 0; m < g.cards.length; m++) { mem[g.cards[m]] = true; }
                for (pi = 0; pi < cards.length; pi++) {
                    if (par[cards[pi].id] && mem[par[cards[pi].id]]) { mem[cards[pi].id] = true; }
                }
                for (i = 0; i < cards.length; i++) {
                    c = cards[i];
                    if (!mem[c.id] || !hasPos(c)) { continue; }
                    cMin = Math.min(cMin, c.col);
                    cMax = Math.max(cMax, c.col + cardCw(c) - 1);
                    rMin = Math.min(rMin, c.row);
                    rMax = Math.max(rMax, c.row + cardRh(c) - 1);
                }
                if (cMin === Infinity) { continue; }
                for (i = 0; i < cards.length; i++) {
                    c = cards[i];
                    if (mem[c.id] || !hasPos(c)) { continue; }
                    cardL = c.col; cardR = c.col + cardCw(c) - 1;
                    cardT = c.row; cardB = c.row + cardRh(c) - 1;
                    if (cardL <= cMax && cardR >= cMin && cardT <= rMax && cardB >= rMin) {
                        c.row = Math.min(rMax + 1, CAPS.gridRowLimit);
                        changed = true;
                    }
                }
            }
            if (!changed) { break; }
            resolveCollisions(cards, null);
        }
    }

    // Push-down that PRESERVES the cards' original top-to-bottom order.
    //
    // The old pairwise cascade could leapfrog: pushing the top card of a
    // column below the anchor made it overlap the next card, and the tie-break
    // then shoved the ALREADY-MOVED card again — past neighbours it used to
    // sit above. Instead: the anchor is fixed; every other card is visited in
    // its ORIGINAL reading order (row, then col, then id) and dropped to the
    // first row at-or-below its own that clears everything placed before it.
    // Earlier cards always land first, so an overlap chain keeps its order.
    function resolveCollisions(cards, anchorId) {
        var anchor = null, rest = [], placed = [], i, j, c, moved, guard;
        for (i = 0; i < cards.length; i++) {
            c = cards[i];
            if (!hasPos(c)) { continue; }
            if (c.id === anchorId) { anchor = c; } else { rest.push(c); }
        }
        rest.sort(function (a, b) {
            return (a.row - b.row) || (a.col - b.col) || (a.id < b.id ? -1 : (a.id > b.id ? 1 : 0));
        });
        if (anchor) { placed.push(anchor); }
        for (i = 0; i < rest.length; i++) {
            c = rest[i];
            moved = true; guard = 0;
            while (moved && guard < 1000) {           // re-check: dropping past one card can meet another
                moved = false; guard++;
                for (j = 0; j < placed.length; j++) {
                    if (cardsOverlap(c, placed[j])) {
                        c.row = placed[j].row + cardRh(placed[j]);
                        if (c.row > CAPS.gridRowLimit) { c.row = CAPS.gridRowLimit; }
                        moved = true;
                    }
                }
            }
            placed.push(c);
        }
    }

    /* ====================================================================
       PUBLIC API
       Every read parses fresh from storage, so returned objects are already
       private copies — callers may mutate them freely without corrupting
       anything until they hand them back to save()/update*().
       ==================================================================== */

    // List the hub's board entries (lightweight; no full boards parsed).
    // -> [ {id, slug, name, cards, created, updated} ]  (may be empty)
    function list() {
        return readIndex().boards.slice();
    }

    // Full board document for a slug OR id, or null if unknown/corrupt.
    function get(slugOrId) {
        var e = entryOf(slugOrId);
        if (!e) { return null; }
        var board = readBoard(e.id);
        // Fill coordinates for any not-yet-placed cards (legacy boards, fresh
        // captures, imports) so callers always see a fully-placed grid. In
        // memory only; deterministic, and persists on the next edit.
        if (board) { ensureGridPlacement(board); }
        return board;
    }

    // Create an empty board. -> board doc, or null on hard failure
    // (board cap reached, or storage write blocked).
    function create(name) {
        name = sanitizeName(name);
        var index = readIndex();
        if (index.boards.length >= CAPS.boardHard) { return null; }

        var id = genId(takenIdMap(index));
        var slug = uniqueSlug(index, slugify(name) || ('pericope-' + id), null);
        var t = now();

        var board = {
            v: SCHEMA_VERSION, id: id, slug: slug, name: name, layout: 'grid',
            created: t, updated: t, cards: [], links: [], groups: []
        };

        if (!writeBoard(board)) { return null; }
        index.boards.push(indexEntryOf(board));
        if (!writeIndex(index)) { removeRaw(boardKey(id)); return null; }   // roll back
        return board;
    }

    // Rename a board (and re-slug if the new slug is free). -> board | null.
    function rename(id, name) {
        var board = get(id);
        if (!board) { return null; }
        name = sanitizeName(name);
        var index = readIndex();
        board.name = name;
        board.slug = uniqueSlug(index, slugify(name) || board.slug, board.id);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Delete a board entirely. -> true (always; missing is success).
    function remove(id) {
        var e = entryOf(id);
        var index = readIndex(), i, kept = [];
        var realId = e ? e.id : id;
        for (i = 0; i < index.boards.length; i++) {
            if (index.boards[i].id !== realId) { kept.push(index.boards[i]); }
        }
        index.boards = kept;
        writeIndex(index);
        removeRaw(boardKey(realId));
        clearHistory(realId);
        return true;
    }

    // Append cards from the reader. Enforces the hard cap.
    // -> { board: doc|null, added: int, rejected: int }
    //    rejected > 0 means the cap was hit; the caller surfaces the message.
    function addCards(id, cards) {
        var board = get(id);
        if (!board) { return { board: null, added: 0, rejected: isArray(cards) ? cards.length : 0 }; }
        if (!isArray(cards)) { cards = []; }

        var existing = {};
        var i;
        for (i = 0; i < board.cards.length; i++) { existing[board.cards[i].id] = true; }

        var incoming = normalizeIncoming(cards, existing);
        var room = CAPS.cardHard - board.cards.length;
        if (room < 0) { room = 0; }

        var toAdd = incoming.slice(0, room);
        var rejected = incoming.length - toAdd.length;

        for (i = 0; i < toAdd.length; i++) { board.cards.push(toAdd[i]); }
        ensureGridPlacement(board);   // give the new (unplaced) cards home-block slots
        board.updated = now();

        if (!writeBoard(board)) {
            return { board: null, added: 0, rejected: incoming.length };
        }
        syncIndexEntry(board);
        return { board: board, added: toAdd.length, rejected: rejected };
    }

    // Patch one card's whitelisted fields, re-validating the result.
    // -> board | null.
    function updateCard(id, cardId, patch) {
        var board = get(id);
        if (!board || !isObj(patch)) { return null; }
        var i, idx = -1;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { idx = i; break; }
        }
        if (idx === -1) { return null; }

        // Merge patch over the existing card, keep id/type, re-validate.
        var merged = {}, k;
        for (k in board.cards[idx]) { if (board.cards[idx].hasOwnProperty(k)) { merged[k] = board.cards[idx][k]; } }
        for (k in patch) {
            if (patch.hasOwnProperty(k) && k !== 'id' && k !== 'type') { merged[k] = patch[k]; }
        }
        var seen = {};
        for (i = 0; i < board.cards.length; i++) { if (i !== idx) { seen[board.cards[i].id] = true; } }
        merged.id = board.cards[idx].id;   // preserve id through validation
        var res = validateCard(merged, seen);
        if (!res.card) { return null; }
        res.card.id = board.cards[idx].id;

        board.cards[idx] = res.card;
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Persist a verse card's expanded/collapsed VIEW state. This is a view
    // preference, not an edit, so unlike updateCard it deliberately does NOT
    // bump `updated` or re-sync the index — opening a card must never reorder
    // the hub's "recently edited" list. Writes the board doc directly (the
    // board is already valid; flipping one boolean keeps it valid), the same
    // trust reorder() extends to its own in-place shuffle. -> board | null.
    function setCardExpanded(id, cardId, on) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card || (card.type !== 'verse' && card.type !== 'interlinear')) { return board; }   // verses + interlinear children expand
        card.exp = on === true;
        if (card.exp) {
            // A manually-sized card (Phase C) re-opens at its remembered span
            // — applied NOW so the very first paint is right (reflow skips
            // manual cards, so nobody else would set it). Auto cards keep
            // their span and let reflow snap it.
            if (isNum(card.ew) && isNum(card.eh)) {
                card.cw = card.ew; card.rh = card.eh;
                resolveCollisions(board.cards, cardId);
            }
        } else {
            // Collapsed is ALWAYS a 1×1 slot — the footprint shrinks with the
            // pixels (shrinking can't overlap anything, so no resolve).
            card.cw = 1; card.rh = 1;
        }
        if (!writeBoard(board, true)) { return null; }
        return board;
    }

    // Silently upgrade a legacy blob card to per-verse `vv` (and refresh its
    // fallback `text`). Like setCardExpanded this is NOT an edit — it writes
    // the board without bumping `updated` or re-syncing the index, so a card
    // healing itself on first expand never reorders the hub. -> board | null.
    function setCardVerses(id, cardId, vv, text) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card || card.type !== 'verse') { return board; }
        var clean = sanitizeVerses(vv);
        if (!clean) { return board; }
        card.vv = clean;
        if (isStr(text) && text) { card.text = cleanText(text, CAPS.verseTextMax); }
        if (!writeBoard(board, true)) { return null; }
        return board;
    }    

    // Place a card at (col,row), optionally resizing (cw,rh). Free placement:
    // gaps are allowed and nothing is pulled up; any cards the move overlaps are
    // pushed DOWN (decision 2). A deliberate move IS an edit → bumps updated.
    // -> board | null.
    function moveCard(id, cardId, col, row, cw, rh) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card) { return null; }
        card.col = normCol(col, CAPS.gridColLimit);
        card.row = clampInt(row, 1, CAPS.gridRowLimit, 1);
        if (cw != null) { card.cw = clampInt(cw, 1, CAPS.gridColSpanMax, cardCw(card)); }
        if (rh != null) { card.rh = clampInt(rh, 1, CAPS.gridRowSpanMax, cardRh(card)); }
        resolveCollisions(board.cards, cardId);
        expelForeigners(board.cards, board.groups);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // PREVIEW a move without writing anything (Phase 3): the rows every card
    // would end up on if `cardId` were placed at (col,row) and the same
    // push-down rule ran. Works on a private copy of the caller's board doc,
    // so the live drag can ask this on every target change and paint the
    // result, and the store stays untouched until moveCard commits.
    // -> { cardId: row, ... } for EVERY card (unchanged ones included).
    function previewMove(board, cardId, col, row, cw, rh) {
        var copies = [], rows = {}, i, c, k;
        if (!isObj(board) || !isArray(board.cards)) { return rows; }
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            k = { id: c.id, col: c.col, row: c.row, cw: cardCw(c), rh: cardRh(c) };
            if (c.id === cardId) {
                k.col = normCol(col, CAPS.gridColLimit);
                k.row = clampInt(row, 1, CAPS.gridRowLimit, 1);
                // Optional candidate SPAN (Phase C resize preview) — same
                // push-down maths, just with the would-be footprint.
                if (cw != null) { k.cw = clampInt(cw, 1, CAPS.gridColSpanMax, k.cw); }
                if (rh != null) { k.rh = clampInt(rh, 1, CAPS.gridRowSpanMax, k.rh); }
            }
            copies.push(k);
        }
        resolveCollisions(copies, cardId);
        for (i = 0; i < copies.length; i++) { rows[copies[i].id] = copies[i].row; }
        return rows;
    }

    // Update a card's grid SPAN — from a measured render height (expand/collapse)
    // or a future manual resize. Like setCardExpanded this is VIEW-derived, so it
    // does NOT bump updated or re-sync the index; but it DOES push overlapping
    // cards down so a grown card never covers its neighbour. -> board | null.
    function setCardSpan(id, cardId, cw, rh) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card) { return null; }
        var newCw = clampInt(cw, 1, CAPS.gridColSpanMax, cardCw(card));
        var newRh = clampInt(rh, 1, CAPS.gridRowSpanMax, cardRh(card));
        if (newCw === cardCw(card) && newRh === cardRh(card)) { return board; }   // no change
        card.cw = newCw; card.rh = newRh;
        resolveCollisions(board.cards, cardId);
        if (!writeBoard(board, true)) { return null; }   // quiet: no updated bump
        return board;
    }

    // MANUAL resize (Phase C) — the user deliberately chose this expanded
    // size, so unlike the quiet, view-derived setCardSpan this is a real
    // EDIT: it bumps updated, syncs the index, and REMEMBERS the size (ew/eh)
    // so a collapse → expand round-trip restores it and reflow leaves it
    // alone. Verse cards only, and only while expanded (the gesture lives on
    // the expanded card's collapse button). -> board | null.
    function resizeCard(id, cardId, cw, rh) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card || (card.type !== 'verse' && card.type !== 'interlinear') || card.exp !== true) { return null; }
        var newCw = clampInt(cw, 1, CAPS.gridColSpanMax, cardCw(card));
        var newRh = clampInt(rh, 1, CAPS.gridRowSpanMax, cardRh(card));
        card.cw = newCw; card.rh = newRh;
        card.ew = newCw; card.eh = newRh;
        resolveCollisions(board.cards, cardId);
        expelForeigners(board.cards, board.groups);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Forget a card's manual size (Phase C): ew/eh are removed and the next
    // render's reflow re-snaps the span automatically. A real edit (bumps
    // updated). No UI calls this yet — it exists so “reset to auto” is one
    // wire away. -> board | null.
    function resetCardSize(id, cardId) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { card = board.cards[i]; break; }
        }
        if (!card || (card.type !== 'verse' && card.type !== 'interlinear')) { return null; }
        if (card.ew == null && card.eh == null) { return board; }   // nothing to forget
        delete card.ew; delete card.eh;
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // The interlinear CHILD of a verse card in this board doc, or null.
    // Pure lookup on a doc the caller already holds (like previewMove), so
    // the card-edit menu can label its button without a storage read.
    function interlinearChild(board, parentId) {
        if (!isObj(board) || !isArray(board.cards)) { return null; }
        for (var i = 0; i < board.cards.length; i++) {
            if (board.cards[i].type === 'interlinear' && board.cards[i].parent === parentId) { return board.cards[i]; }
        }
        return null;
    }

    // The group holding a card, honouring DERIVED child membership: a child
    // answers with its PARENT's group. -> group | null. The one groupOf the
    // board and edit modules should use from Phase 3 on, so nobody re-derives
    // the tether rule locally.
    function groupOfCard(board, cardId) {
        if (!isObj(board) || !isArray(board.cards)) { return null; }
        var i, c, want = cardId;
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            if (c.id === cardId && c.type === 'interlinear') { want = c.parent; break; }
        }
        var gi, g, gs = isArray(board.groups) ? board.groups : [];
        for (gi = 0; gi < gs.length; gi++) {
            g = gs[gi];
            if (isArray(g.cards) && g.cards.indexOf(want) !== -1) { return g; }
        }
        return null;
    }

    // ADD an interlinear CHILD to a verse card (the card-edit menu's
    // "Interlinear" button). ONE per parent — a second ask returns null and
    // the UI reads interlinearChild() to grey the button instead. The child
    // is born with the PARENT'S SIZE — exp, cw/rh and any manual ew/eh —
    // exactly as a duplicate is, and lands on the parent's row just right
    // of it as the collision anchor; derived membership means a grouped
    // parent's child is born inside the group and never bounced by the
    // territory rule. Tokens are NEVER stored — the board fetches them per
    // session — so the card is only the tether plus geometry. A real edit.
    // -> { board, card } | null.
    function addInterlinearCard(id, parentId) {
        var board = get(id);
        if (!board) { return null; }
        if (board.cards.length >= CAPS.cardHard) { return null; }
        var i, at = -1, parent = null, seen = {}, bc;
        for (i = 0; i < board.cards.length; i++) {
            bc = board.cards[i];
            seen[bc.id] = true;
            if (bc.id === parentId) { at = i; parent = bc; }
            if (bc.type === 'interlinear' && bc.parent === parentId) { return null; }   // one per parent
        }
        if (!parent || parent.type !== 'verse') { return null; }
        var v = validateCard({
            type: 'interlinear', parent: parentId,
            exp: parent.exp === true, cw: cardCw(parent), rh: cardRh(parent),
            ew: parent.ew, eh: parent.eh,
            col: (isNum(parent.col) ? parent.col : 1) + cardCw(parent),
            row: isNum(parent.row) ? parent.row : 1
        }, seen);
        if (!v.card) { return null; }
        board.cards.splice(at + 1, 0, v.card);
        resolveCollisions(board.cards, v.card.id);
        expelForeigners(board.cards, board.groups);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return { board: board, card: v.card };
    }

    // DUPLICATE a card (card-edit "copy", Phase 1 of the card-edit work).
    // A fresh id; every other field carried over — text, vv, exp, tx and a
    // manual ew/eh — and the copy lands on the SAME row just RIGHT of the
    // original (col + cw), as the collision anchor, so whatever sat there
    // is pushed down. It's spliced in right after the original (array
    // order = reading/share order) and JOINS the original's group: a copy
    // made inside a group box would otherwise be bounced straight out of
    // it by the territory rule. Verse, note and heading cards all copy;
    // a future interlinear CHILD never does (Phase 2). A real edit.
    // -> { board, card } | null.
    function duplicateCard(id, cardId) {
        var board = get(id);
        if (!board) { return null; }
        if (board.cards.length >= CAPS.cardHard) { return null; }
        var i, at = -1, src = null, seen = {};
        for (i = 0; i < board.cards.length; i++) {
            seen[board.cards[i].id] = true;
            if (board.cards[i].id === cardId) { at = i; src = board.cards[i]; }
        }
        if (!src || src.type === 'interlinear') { return null; }

        var raw = JSON.parse(JSON.stringify(src));
        delete raw.id;                                   // validateCard mints a new one
        raw.col = (isNum(src.col) ? src.col : 1) + cardCw(src);
        raw.row = isNum(src.row) ? src.row : 1;
        var v = validateCard(raw, seen);
        if (!v.card) { return null; }
        board.cards.splice(at + 1, 0, v.card);

        var gi, g;
        for (gi = 0; gi < board.groups.length; gi++) {
            g = board.groups[gi];
            if (g.cards.indexOf(cardId) !== -1) { g.cards.push(v.card.id); break; }
        }

        resolveCollisions(board.cards, v.card.id);
        expelForeigners(board.cards, board.groups);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return { board: board, card: v.card };
    }

    // Remove a card (and any links touching it). -> board | null.
    function removeCard(id, cardId) {
        var board = get(id);
        if (!board) { return null; }
        // The tether is LIFECYCLE too (card-edit Phase 2): removing a verse
        // card takes its interlinear child with it. Removing a child alone
        // leaves the parent untouched. Undo restores both — one snapshot.
        var gone = [cardId], goneSet = {}, i, kept = [], removed = false;
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].type === 'interlinear' && board.cards[i].parent === cardId) {
                gone.push(board.cards[i].id);
            }
        }
        for (i = 0; i < gone.length; i++) { goneSet[gone[i]] = true; }
        for (i = 0; i < board.cards.length; i++) {
            if (board.cards[i].id === cardId) { removed = true; }
            if (!goneSet[board.cards[i].id]) { kept.push(board.cards[i]); }
        }
        if (!removed) { return board; }
        board.cards = kept;
        var links = [];
        for (i = 0; i < board.links.length; i++) {
            if (!goneSet[board.links[i].from] && !goneSet[board.links[i].to]) {
                links.push(board.links[i]);
            }
        }
        board.links = links;
        pruneGroups(board, gone);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Reorder grid cards to match cardIds; ids not listed keep their relative
    // order at the end; unknown ids are ignored. -> board | null.
    function reorder(id, cardIds) {
        var board = get(id);
        if (!board || !isArray(cardIds)) { return null; }
        var byId = {}, i;
        for (i = 0; i < board.cards.length; i++) { byId[board.cards[i].id] = board.cards[i]; }

        var ordered = [], used = {};
        for (i = 0; i < cardIds.length; i++) {
            var c = byId[cardIds[i]];
            if (c && !used[c.id]) { ordered.push(c); used[c.id] = true; }
        }
        for (i = 0; i < board.cards.length; i++) {
            if (!used[board.cards[i].id]) { ordered.push(board.cards[i]); }
        }
        board.cards = ordered;
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Full write of a board the caller already holds (canvas debounce, etc).
    // Trusts structure (does NOT run the aggressive import repair) but does
    // bump `updated` and keep the index entry in sync. -> board | null.
    function save(board) {
        if (!isObj(board) || !isStr(board.id) || !board.id) { return null; }
        board.v = SCHEMA_VERSION;
        board.updated = now();
        if (!isArray(board.cards)) { board.cards = []; }
        if (!isArray(board.links)) { board.links = []; }
        if (!isArray(board.groups)) { board.groups = []; }
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Public validation entry point for untrusted input (import / shared URL).
    // -> { ok, board, dropped[] }. Does not touch storage; caller inserts.
    function validate(board) { return validateBoard(board); }

    /* ---- share codec (S0) -----------------------------------------------
       A whole board as one URL FRAGMENT — nothing ever reaches the server.

       ┌─ FORMAT p1 — FROZEN ─────────────────────────────────────────────┐
       │ p1!<name>!<card>;<card>;…!<group>;<group>;…                      │
       │                                                                  │
       │ Four !-sections, always present (may be empty). Items joined by  │
       │ ; and fields by . — text fields are percent-escaped (encodeURI-  │
       │ Component plus %2E for . and %21 for !), so every delimiter is   │
       │ unambiguous and the whole string is legal in a URL fragment.     │
       │                                                                  │
       │ VERSE card   <osis>.<ch>.<verses>.<tx>.<col>.<row>[.<flags>]     │
       │   osis, tx   LITERAL ids/slugs (escaped), never indices — new    │
       │              books or translations can never shift old URLs.     │
       │   verses     run-length list: 28 · 3-8 · 5,7-9 (non-contiguous   │
       │              focus captures survive). From vv numbers when       │
       │              present, else the v1-v2 span.                       │
       │   tx         EMPTY field = same as the previous card's; the      │
       │              first verse card must carry it.                     │
       │   col        any integer — 0 and negatives legal. row ≥ 1.       │
       │ TEXT card    ~h.<text>.<col>.<row>[.<flags>]   (heading)         │
       │              ~n.<text>.<col>.<row>[.<flags>]   (note)            │
       │              text is the user's own words, escaped — the one     │
       │              content the server cannot rebuild.                  │
       │   flags      omitted when default: e = expanded (verse only),    │
       │              w<N> = cw N, h<N> = MANUAL row span (Phase C; h     │
       │              marks a user-sized card; its w is the manual        │
       │              col span). Order e-then-w-then-h.                   │
       │ GROUP        <label>.<color>.<m,m,…>  color = literal palette    │
       │              name; members = 0-based indices into the cards      │
       │              section.                                            │
       │                                                                  │
       │ Never encoded: id (re-minted on import), AUTO rh (re-derived —   │
       │ verse TEXT for verse cards (re-fetched by reference), timestamps.│
       │ Any change to this shape is a NEW version prefix (p2), never a   │
       │ mutated p1 — a printed QR must decode forever.                   │
       └──────────────────────────────────────────────────────────────────┘

       encodeShare(board) -> the p1 string.
       decodeShare(str)   -> { ok:true, name, cards, groups } or
                             { ok:false, error } — strict: a malformed card
       fails the WHOLE decode (a board silently missing cards would betray
       the person scanning a QR). Decoded cards are share-shaped (verses as
       a number list, no text for verse cards); the import path turns them
       into store input and runs them through validateBoard like any other
       untrusted blob. */

    function shEsc(v) {
        return encodeURIComponent(String(v)).replace(/\./g, '%2E').replace(/!/g, '%21');
    }
    function shUn(v) {
        try { return decodeURIComponent(v); } catch (e) { return null; }
    }

    // [3,5,6,7,9] -> "3,5-7,9"
    function packRuns(nums) {
        var out = [], i, a, b;
        for (i = 0; i < nums.length; i++) {
            a = b = nums[i];
            while (i + 1 < nums.length && nums[i + 1] === b + 1) { b = nums[++i]; }
            out.push(a === b ? String(a) : a + '-' + b);
        }
        return out.join(',');
    }
    // "3,5-7,9" -> [3,5,6,7,9] or null
    function unpackRuns(str) {
        var parts = String(str).split(','), out = [], i, m, a, b, n;
        for (i = 0; i < parts.length; i++) {
            m = /^(\d+)(?:-(\d+))?$/.exec(parts[i]);
            if (!m) { return null; }
            a = parseInt(m[1], 10);
            b = m[2] ? parseInt(m[2], 10) : a;
            if (b < a) { n = a; a = b; b = n; }         // reversed range reads ascending
            if (b - a > 300) { return null; }            // fan-out guard
            for (n = a; n <= b; n++) { out.push(n); }
        }
        return out.length ? out : null;
    }

    function shareFlags(exp, cw, ew, eh) {
        var f = '';
        if (exp) { f += 'e'; }
        if (isNum(ew) && isNum(eh)) {
            // MANUAL size (Phase C): w carries the manual col span (only when
            // >1), h the manual row span — h's presence is the manual marker,
            // independent of the LIVE cw (a manual card shared while
            // collapsed still travels with its remembered size).
            if (ew > 1) { f += 'w' + ew; }
            f += 'h' + eh;
        } else if (cw > 1) { f += 'w' + cw; }
        return f;
    }

    function encodeShare(board) {
        if (!isObj(board)) { return null; }
        // ENCODED positions first (card-edit Phase 2): a child's parent and
        // a group's members are referenced BY ENCODED INDEX, and an orphan
        // child (its parent isn't a verse card on this board) is SKIPPED —
        // so positions are assigned up front, never assumed equal to the
        // card's index in board.cards.
        var encIdx = {}, skip = {}, typeAt = {}, pos = 0, i, c;
        for (i = 0; i < (board.cards || []).length; i++) { typeAt[board.cards[i].id] = board.cards[i].type; }
        for (i = 0; i < (board.cards || []).length; i++) {
            c = board.cards[i];
            if (c.type === 'interlinear' && typeAt[c.parent] !== 'verse') { skip[c.id] = true; continue; }
            encIdx[c.id] = pos++;
        }
        var cards = [], groups = [], prevTx = null, nums, vi, fields, f;
        for (i = 0; i < (board.cards || []).length; i++) {
            c = board.cards[i];
            if (skip[c.id]) { continue; }
            if (c.type === 'verse') {
                if (isArray(c.vv) && c.vv.length) {
                    nums = [];
                    for (vi = 0; vi < c.vv.length; vi++) { nums.push(c.vv[vi][0]); }
                    nums.sort(function (a, b) { return a - b; });
                } else {
                    nums = []; for (vi = c.v1; vi <= c.v2; vi++) { nums.push(vi); }
                }
                fields = [shEsc(c.osis), c.ch, packRuns(nums),
                          (c.tx === prevTx) ? '' : shEsc(c.tx),
                          c.col, c.row];
                prevTx = c.tx;
                f = shareFlags(c.exp === true, cardCw(c), c.ew, c.eh);
            } else if (c.type === 'interlinear') {
                fields = ['~i', encIdx[c.parent], c.col, c.row];
                f = shareFlags(c.exp === true, cardCw(c), c.ew, c.eh);
            } else {
                fields = [(c.type === 'heading' ? '~h' : '~n'), shEsc(c.text || ''), c.col, c.row];
                f = shareFlags(false, cardCw(c));
            }
            if (f) { fields.push(f); }
            cards.push(fields.join('.'));
        }
        for (i = 0; i < (board.groups || []).length; i++) {
            c = board.groups[i];
            var idx = [], m, at;
            for (m = 0; m < c.cards.length; m++) {
                at = encIdx[c.cards[m]];
                if (at != null) { idx.push(at); }
            }
            if (idx.length) { groups.push([shEsc(c.label || ''), shEsc(c.color), idx.join(',')].join('.')); }
        }
        return 'p1!' + shEsc(board.name || '') + '!' + cards.join(';') + '!' + groups.join(';');
    }

    function decodeShare(str) {
        function fail(err) { return { ok: false, error: err }; }
        if (!isStr(str)) { return fail('not a string'); }
        var raw = str.replace(/^#/, '');
        var sections = raw.split('!');
        if (sections[0] !== 'p1') { return fail('unknown version "' + sections[0].slice(0, 12) + '"'); }
        if (sections.length !== 4) { return fail('expected 4 sections, got ' + sections.length); }

        var name = shUn(sections[1]);
        if (name == null) { return fail('bad name escaping'); }

        var cards = [], groups = [], prevTx = null;
        var items = sections[2] ? sections[2].split(';') : [], i, fld, card, flags, m;
        for (i = 0; i < items.length; i++) {
            fld = items[i].split('.');
            if (fld[0] === '~i') {
                // Interlinear CHILD (card-edit Phase 2): its parent rides as
                // an ENCODED index into this same card list; tether checked
                // after the loop, once every card exists.
                if (fld.length < 4 || fld.length > 5) { return fail('card ' + i + ': bad field count'); }
                card = { type: 'interlinear', parentIdx: parseInt(fld[1], 10),
                         col: parseInt(fld[2], 10), row: parseInt(fld[3], 10),
                         cw: 1, exp: false };
                if (isNaN(card.parentIdx) || card.parentIdx < 0) { return fail('card ' + i + ': bad parent index'); }
                flags = fld.length === 5 ? fld[4] : '';
            } else if (fld[0] === '~h' || fld[0] === '~n') {
                if (fld.length < 4 || fld.length > 5) { return fail('card ' + i + ': bad field count'); }
                var text = shUn(fld[1]);
                if (text == null) { return fail('card ' + i + ': bad text escaping'); }
                card = { type: fld[0] === '~h' ? 'heading' : 'note', text: text,
                         col: parseInt(fld[2], 10), row: parseInt(fld[3], 10), cw: 1 };
                flags = fld.length === 5 ? fld[4] : '';
            } else {
                if (fld.length < 6 || fld.length > 7) { return fail('card ' + i + ': bad field count'); }
                var osis = shUn(fld[0]), verses = unpackRuns(fld[2]);
                var tx = fld[3] === '' ? prevTx : shUn(fld[3]);
                if (osis == null || !osis) { return fail('card ' + i + ': bad osis'); }
                if (!verses) { return fail('card ' + i + ': bad verse list'); }
                if (tx == null || !tx) { return fail('card ' + i + ': no translation (first card must carry one)'); }
                prevTx = tx;
                card = { type: 'verse', osis: osis, ch: parseInt(fld[1], 10), verses: verses,
                         tx: tx, col: parseInt(fld[4], 10), row: parseInt(fld[5], 10),
                         cw: 1, exp: false };
                flags = fld.length === 7 ? fld[6] : '';
            }
            if (isNaN(card.col) || isNaN(card.row) || card.row < 1 ||
                (card.type === 'verse' && (isNaN(card.ch) || card.ch < 1))) {
                return fail('card ' + i + ': bad numbers');
            }
            if (flags) {
                m = /^(e)?(?:w(\d+))?(?:h(\d+))?$/.exec(flags);
                if (!m || (!m[1] && !m[2] && !m[3])) { return fail('card ' + i + ': bad flags "' + flags + '"'); }
                if (m[1] && (card.type === 'verse' || card.type === 'interlinear')) { card.exp = true; }
                if (m[2]) { card.cw = parseInt(m[2], 10); }
                if (m[3] && (card.type === 'verse' || card.type === 'interlinear')) {   // manual size (Phase C)
                    card.ew = card.cw || 1;
                    card.eh = parseInt(m[3], 10);
                }
            }
            cards.push(card);
        }

        // Tether check (card-edit Phase 2): every child must point at a
        // VERSE card in this deck. A doubled child (two ~i at one parent) is
        // left for the importer's validator, which drops the extra.
        for (i = 0; i < cards.length; i++) {
            if (cards[i].type !== 'interlinear') { continue; }
            if (cards[i].parentIdx >= cards.length || cards[cards[i].parentIdx].type !== 'verse') {
                return fail('card ' + i + ': parent is not a verse card');
            }
        }

        items = sections[3] ? sections[3].split(';') : [];
        for (i = 0; i < items.length; i++) {
            fld = items[i].split('.');
            if (fld.length !== 3) { return fail('group ' + i + ': bad field count'); }
            var label = shUn(fld[0]), color = shUn(fld[1]);
            if (label == null || color == null) { return fail('group ' + i + ': bad escaping'); }
            var members = [], parts = fld[2].split(','), p, n;
            for (p = 0; p < parts.length; p++) {
                n = parseInt(parts[p], 10);
                if (isNaN(n) || n < 0 || n >= cards.length) { return fail('group ' + i + ': member index out of range'); }
                members.push(n);
            }
            groups.push({ label: label, color: color, members: members });
        }

        return { ok: true, name: name, cards: cards, groups: groups };
    }

    /* ---- share import (S2) ----------------------------------------------
       Turn decodeShare() output into a real board in THIS browser's store.
       Always CREATES (a re-scanned QR makes "gungle 2", never mutates
       "gungle"): fresh board id, fresh card ids, name deduped by appending
       the next free number, slug deduped by the usual uniqueSlug. Cards
       arrive with verse NUMBERS only (v1/v2 spans, no text) — the import
       page fetches text afterwards; a fetch that fails leaves the card
       vv-less, which the board's own self-heal finishes later.
       -> { board, cardIds } with cardIds aligned to decoded.cards (null
       where the validator dropped one), or null (caps / storage blocked). */
    function importShared(decoded) {
        if (!isObj(decoded) || !isArray(decoded.cards)) { return null; }
        var index = readIndex();
        if (index.boards.length >= CAPS.boardHard) { return null; }

        // Name dedupe: exact-insensitive match against the index.
        var base = sanitizeName(decoded.name), name = base, n = 2, i, taken;
        function nameTaken(candidate) {
            for (var b = 0; b < index.boards.length; b++) {
                if (String(index.boards[b].name).toLowerCase() === candidate.toLowerCase()) { return true; }
            }
            return false;
        }
        while (nameTaken(name)) { name = base + ' ' + (n++); }

        // Mint card ids UP FRONT so group membership can point at them and
        // the validator keeps them (it only re-mints missing/duplicate ids).
        var seen = {}, ids = [], cards = [], dc, card, vmin, vmax, v;
        for (i = 0; i < decoded.cards.length; i++) {
            dc = decoded.cards[i];
            var cid = genId(seen); seen[cid] = true; ids.push(cid);
            if (dc.type === 'verse') {
                vmin = dc.verses[0]; vmax = dc.verses[0];
                for (v = 1; v < dc.verses.length; v++) {
                    vmin = Math.min(vmin, dc.verses[v]);
                    vmax = Math.max(vmax, dc.verses[v]);
                }
                card = { id: cid, type: 'verse', osis: dc.osis, ch: dc.ch,
                         v1: vmin, v2: vmax, tx: dc.tx, exp: dc.exp === true,
                         col: dc.col, row: dc.row, cw: dc.cw,
                         ew: dc.ew, eh: dc.eh };   // manual size (Phase C), if shared
            } else if (dc.type === 'interlinear') {
                card = { id: cid, type: 'interlinear', parent: ids[dc.parentIdx],
                         col: dc.col, row: dc.row, cw: dc.cw, exp: dc.exp === true,
                         ew: dc.ew, eh: dc.eh };
            } else {
                card = { id: cid, type: dc.type, text: dc.text,
                         col: dc.col, row: dc.row, cw: dc.cw };
            }
            cards.push(card);
        }
        var groups = [], gseen = {}, g, mem, m;
        for (i = 0; i < (decoded.groups || []).length; i++) {
            g = decoded.groups[i];
            mem = [];
            for (m = 0; m < g.members.length; m++) {
                if (ids[g.members[m]]) { mem.push(ids[g.members[m]]); }
            }
            var gid = genId(gseen); gseen[gid] = true;
            groups.push({ id: gid, label: g.label, color: g.color, cards: mem });
        }

        var val = validateBoard({ name: name, layout: 'grid', cards: cards, links: [], groups: groups });
        if (!val.ok || !val.board) { return null; }
        var board = val.board;

        taken = takenIdMap(index);
        board.id = genId(taken);
        board.slug = uniqueSlug(index, slugify(name) || ('pericope-' + board.id), null);
        board.created = board.updated = board.imported = now();   // imported: the hub says so

        if (!writeBoard(board)) { return null; }
        index.boards.push(indexEntryOf(board));
        if (!writeIndex(index)) { removeRaw(boardKey(board.id)); return null; }

        // Map decoded order -> surviving card ids (validator may have
        // dropped some; the import page fetches text only for survivors).
        var have = {}, out = [];
        for (i = 0; i < board.cards.length; i++) { have[board.cards[i].id] = true; }
        for (i = 0; i < ids.length; i++) { out.push(have[ids[i]] ? ids[i] : null); }
        return { board: board, cardIds: out };
    }

    // Point a verse card at a different translation (the import page's
    // fallback when a shared translation isn't available here). Quiet-ish:
    // bumps nothing but the board write, like setCardVerses.
    function setCardTx(id, cardId, tx) {
        var board = get(id);
        if (!board) { return null; }
        var i, card = null;
        for (i = 0; i < board.cards.length; i++) { if (board.cards[i].id === cardId) { card = board.cards[i]; break; } }
        if (!card || card.type !== 'verse') { return board; }
        var clean = slugify(tx);
        if (!clean) { return board; }
        card.tx = clean;
        if (!writeBoard(board, true)) { return null; }
        return board;
    }

    /* ---- groups (Phase 5) -----------------------------------------------
       Membership CRUD. Geometry never touches the store: the board derives
       every outline from its members' cells at render time. */

    // Strip the given card ids from every group, then drop emptied groups.
    function pruneGroups(board, cardIds) {
        if (!isArray(board.groups) || !board.groups.length) { return; }
        var gone = {}, i, g, kept = [], members, m;
        for (i = 0; i < (cardIds || []).length; i++) { gone[cardIds[i]] = true; }
        for (i = 0; i < board.groups.length; i++) {
            g = board.groups[i];
            members = [];
            for (m = 0; m < g.cards.length; m++) { if (!gone[g.cards[m]]) { members.push(g.cards[m]); } }
            g.cards = members;
            if (members.length) { kept.push(g); }
        }
        board.groups = kept;
    }

    // Create a group from card ids. Cards already in a group are STOLEN into
    // the new one (their old group dissolves if emptied) — one group per
    // card, no dead ends. -> the updated board, or null (bad ids / caps /
    // write blocked).
    function createGroup(id, cardIds, label, color) {
        var board = get(id);
        if (!board || !isArray(cardIds) || !cardIds.length) { return null; }
        if (board.groups.length >= CAPS.groupHard) { return null; }
        var kind = {}, i, members = [], seenIn = {};
        for (i = 0; i < board.cards.length; i++) { kind[board.cards[i].id] = board.cards[i].type; }
        for (i = 0; i < cardIds.length; i++) {
            // Interlinear children are NEVER stored members — their
            // membership DERIVES from the parent (card-edit Phase 2), so a
            // child id here is silently dropped (a list of only children
            // makes no group).
            if (isStr(cardIds[i]) && kind[cardIds[i]] && kind[cardIds[i]] !== 'interlinear' && !seenIn[cardIds[i]]) {
                seenIn[cardIds[i]] = true; members.push(cardIds[i]);
            }
        }
        if (!members.length) { return null; }
        pruneGroups(board, members);                       // steal from old groups
        var taken = {};
        for (i = 0; i < board.groups.length; i++) { taken[board.groups[i].id] = true; }
        board.groups.push({
            id:    genId(taken),
            label: cleanText(label, CAPS.groupLabelMax) || 'Group',
            color: GROUP_COLORS.indexOf(color) !== -1 ? color : GROUP_COLORS[0],
            cards: members
        });
        expelForeigners(board.cards, board.groups);   // the new territory evicts squatters
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // ADOPT cards into an existing group (the hover-to-adopt drop, Phase 5b).
    // Same steal semantics as createGroup: the cards leave whatever group
    // they were in (which dissolves if emptied) and join this one.
    function addToGroup(id, groupId, cardIds) {
        var board = get(id);
        if (!board || !isArray(cardIds) || !cardIds.length) { return null; }
        var g = null, i, kind = {}, joining = [], seen = {};
        for (i = 0; i < board.groups.length; i++) { if (board.groups[i].id === groupId) { g = board.groups[i]; break; } }
        if (!g) { return null; }
        for (i = 0; i < board.cards.length; i++) { kind[board.cards[i].id] = board.cards[i].type; }
        for (i = 0; i < cardIds.length; i++) {
            // Children never join by id — derived membership only (Phase 2).
            if (isStr(cardIds[i]) && kind[cardIds[i]] && kind[cardIds[i]] !== 'interlinear' && !seen[cardIds[i]]) { seen[cardIds[i]] = true; joining.push(cardIds[i]); }
        }
        if (!joining.length) { return null; }
        // Strip the joiners from every OTHER group (never from the target —
        // stripping there could dissolve the very group we're joining).
        var kept = [], og, members, m;
        for (i = 0; i < board.groups.length; i++) {
            og = board.groups[i];
            if (og.id === groupId) { kept.push(og); continue; }
            members = [];
            for (m = 0; m < og.cards.length; m++) { if (!seen[og.cards[m]]) { members.push(og.cards[m]); } }
            og.cards = members;
            if (members.length) { kept.push(og); }
        }
        board.groups = kept;
        for (i = 0; i < joining.length; i++) {
            if (g.cards.indexOf(joining[i]) === -1) { g.cards.push(joining[i]); }
        }
        expelForeigners(board.cards, board.groups);   // the box may have grown over a bystander
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // UNGROUP cards (edit mode's typed selection, Phase 5b): strip the given
    // ids from whatever groups hold them — any mix of source groups — and
    // dissolve emptied groups. The cards themselves are untouched.
    function ungroupCards(id, cardIds) {
        var board = get(id);
        if (!board || !isArray(cardIds) || !cardIds.length) { return null; }
        pruneGroups(board, cardIds);
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Rename / recolor a group. patch = {label?, color?}.
    function updateGroup(id, groupId, patch) {
        var board = get(id);
        if (!board || !isObj(patch)) { return null; }
        var i, g = null;
        for (i = 0; i < board.groups.length; i++) { if (board.groups[i].id === groupId) { g = board.groups[i]; break; } }
        if (!g) { return null; }
        if (patch.label != null) { g.label = cleanText(patch.label, CAPS.groupLabelMax) || g.label; }
        if (patch.color != null && GROUP_COLORS.indexOf(patch.color) !== -1) { g.color = patch.color; }
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    // Dissolve a group. Member CARDS are untouched — only the outline goes.
    function removeGroup(id, groupId) {
        var board = get(id);
        if (!board) { return null; }
        var i, kept = [], found = false;
        for (i = 0; i < board.groups.length; i++) {
            if (board.groups[i].id === groupId) { found = true; } else { kept.push(board.groups[i]); }
        }
        if (!found) { return board; }
        board.groups = kept;
        board.updated = now();
        if (!writeBoard(board)) { return null; }
        syncIndexEntry(board);
        return board;
    }

    /* ---- preferences (Phase 2) ------------------------------------------
       Board-wide display preferences, one small key. Not per-board and not
       part of any board document, so export/import never carries them.
         grid    'always' | 'drag' | 'off'   dot grid: resting+placing / placing only / never
         shimmy  0..3                        edit-mode wiggle strength (Phase 5)
       Writes fire `mb:pericope-prefs` on document so open boards re-apply. */
    var PREFS_KEY = 'mbPericopePrefs.v1';
    var PREF_DEFAULTS = { grid: 'drag', shimmy: 2 };   // grid: dots only while placing (drag), by default
    var GRID_MODES = ['always', 'drag', 'off'];

    function sanitizePrefs(input) {
        var out = { grid: PREF_DEFAULTS.grid, shimmy: PREF_DEFAULTS.shimmy };
        if (isObj(input)) {
            if (GRID_MODES.indexOf(input.grid) !== -1) { out.grid = input.grid; }
            out.shimmy = clampInt(input.shimmy, 0, 3, PREF_DEFAULTS.shimmy);
        }
        return out;
    }

    // -> {grid, shimmy} (always complete; defaults fill anything missing).
    function getPrefs() {
        var raw = readRaw(PREFS_KEY), parsed = null;
        if (raw) { try { parsed = JSON.parse(raw); } catch (_) { parsed = null; } }
        return sanitizePrefs(parsed);
    }

    // Merge a patch over the stored prefs. -> the new prefs, or null if the
    // write was blocked.
    function setPrefs(patch) {
        var cur = getPrefs(), k;
        if (isObj(patch)) { for (k in patch) { if (patch.hasOwnProperty(k)) { cur[k] = patch[k]; } } }
        var next = sanitizePrefs(cur);
        if (!writeRaw(PREFS_KEY, JSON.stringify(next))) { return null; }
        if (typeof document !== 'undefined' && document.dispatchEvent && typeof CustomEvent === 'function') {
            try { document.dispatchEvent(new CustomEvent('mb:pericope-prefs', { detail: next })); } catch (_) {}
        }
        return next;
    }

    /* ---- capacity & usage introspection (for UI later) ------------------ */

    // Card-capacity view of a board (or a raw count). -> {used, soft, hard,
    // remaining, overSoft}.
    function capacity(boardOrCount) {
        var used = isNum(boardOrCount) ? boardOrCount
                 : (isObj(boardOrCount) && isArray(boardOrCount.cards) ? boardOrCount.cards.length : 0);
        return {
            used: used,
            soft: CAPS.cardSoft,
            hard: CAPS.cardHard,
            remaining: Math.max(0, CAPS.cardHard - used),
            overSoft: used >= CAPS.cardSoft
        };
    }

    // Rough byte usage of Pericope's own keys, plus the whole-origin total, so
    // a hub can show a "storage used" readout later. UTF-16 chars ≈ length; a
    // rough gauge, not an exact quota. -> {pericopeBytes, totalBytes, boards}.
    function usage() {
        var s = ls();
        var pericope = 0, total = 0, boards = 0;
        if (s) {
            try {
                var i, key, val;
                for (i = 0; i < s.length; i++) {
                    key = s.key(i);
                    val = s.getItem(key) || '';
                    var bytes = (key.length + val.length) * 2;
                    total += bytes;
                    if (key.indexOf('mbPericope.') === 0) {
                        pericope += bytes;
                        if (key.indexOf(BOARD_PREFIX) === 0) { boards++; }
                    }
                }
            } catch (e) {}
        }
        return { pericopeBytes: pericope, totalBytes: total, boards: boards };
    }

    /* ---- export / import document shaping (Phase 4 leans on these) ------
       These build/parse the FILE payload shape only; the actual download and
       file-picker wiring is Phase 4 UI. Kept here so the file schema has one
       definition. The envelope mirrors the Acts record file
       (app/kind/version/exported_at) with a pericope-specific kind. --------- */

    function exportBoard(slugOrId) {
        var board = get(slugOrId);
        if (!board) { return null; }
        return {
            app: 'megabible', kind: 'pericope', version: SCHEMA_VERSION,
            exported_at: new Date().toISOString(),
            board: board
        };
    }

    // Turn a parsed file payload into a validated board (NOT yet inserted).
    // Accepts either a full envelope {app,kind,board} or a bare board object.
    // -> { ok, board, dropped[] }.
    function importPayload(payload) {
        var candidate = null;
        if (isObj(payload) && payload.app === 'megabible' && payload.kind === 'pericope' && isObj(payload.board)) {
            candidate = payload.board;
        } else if (isObj(payload) && isArray(payload.cards)) {
            candidate = payload;   // tolerate a bare board doc
        }
        if (!candidate) { return { ok: false, board: null, dropped: ['not a pericope file'] }; }
        return validateBoard(candidate);
    }

    /* ---- derived summaries (board + hub) --------------------------------
       Pure functions over a board document — no storage, no DOM. The board
       page and the hub BOTH paint from these, so the subtitle wording and
       the thumbnail's geometry can never drift between the two pages. */

    // Verse/book tally for the subtitle: "23 verses from 3 books". A range
    // counts each verse. A board with no verse cards (notes/headings only)
    // falls back to a card count; an empty board says so.
    // -> { cards, verses, books, hasVerse, label }
    function summarize(cards) {
        cards = isArray(cards) ? cards : [];
        var verses = 0, books = {}, bk = 0, hasVerse = false, i, k, c, n;
        for (i = 0; i < cards.length; i++) {
            c = cards[i];
            if (!c || c.type !== 'verse') { continue; }
            hasVerse = true;
            n = (c.v2 - c.v1 + 1);
            verses += (isNum(n) && n >= 1) ? n : 1;
            if (c.osis) { books[c.osis] = true; }
        }
        for (k in books) { if (books.hasOwnProperty(k)) { bk++; } }
        var label;
        if (!cards.length)  { label = 'No cards yet'; }
        else if (!hasVerse) { label = cards.length + ' card' + (cards.length === 1 ? '' : 's'); }
        else {
            label = verses + ' verse' + (verses === 1 ? '' : 's') +
                    ' from ' + bk + ' book' + (bk === 1 ? '' : 's');
        }
        return { cards: cards.length, verses: verses, books: bk, hasVerse: hasVerse, label: label };
    }

    // The board's cell geometry in LOGICAL grid units (col 1 = home, rows
    // from 1), resolved the way the board page paints it:
    //   - every card is placed (ensureGridPlacement, same as get());
    //   - a COLLAPSED verse card is always ONE row tall — the board renders
    //     it as exactly one slot whatever rh it remembers from expansion;
    //   - a group's box is the bounding rectangle of its members' cells
    //     (membership, not stored geometry — mirrors positionGroups()).
    // -> { cards:  [ {id, type, osis, exp, col, row, cw, rh} ],
    //      groups: [ {id, color, c0, c1, r0, r1} ],   inclusive cell bounds
    //      colMin, colMax, rowMax }                    occupied extent, seeded
    //                                                   with the home block
    function footprint(board) {
        var out = { cards: [], groups: [], colMin: 1, colMax: CAPS.gridCols, rowMax: 1 };
        if (!isObj(board) || !isArray(board.cards)) { return out; }
        ensureGridPlacement(board);

        // A child cell borrows its PARENT's book colour on the thumbnail
        // (card-edit Phase 3): map children to the parent's osis up front.
        var osisOf = {}, pin;
        for (pin = 0; pin < board.cards.length; pin++) {
            if (board.cards[pin].type === 'verse') { osisOf[board.cards[pin].id] = board.cards[pin].osis; }
        }

        var byId = {}, i, c, f, rh;
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            if (!isObj(c) || !hasPos(c)) { continue; }
            rh = (c.type === 'verse' && c.exp !== true) ? 1 : cardRh(c);
            f = { id: c.id, type: c.type,
                  osis: c.type === 'interlinear' ? (osisOf[c.parent] || null) : (c.osis || null),
                  exp: c.exp === true,
                  col: c.col, row: c.row, cw: cardCw(c), rh: rh };
            out.cards.push(f);
            byId[f.id] = f;
            out.colMin = Math.min(out.colMin, f.col);
            out.colMax = Math.max(out.colMax, f.col + f.cw - 1);
            out.rowMax = Math.max(out.rowMax, f.row + f.rh - 1);
        }

        var groups = isArray(board.groups) ? board.groups : [], g, m, gi;
        for (gi = 0; gi < groups.length; gi++) {
            g = groups[gi];
            if (!isObj(g) || !isArray(g.cards)) { continue; }
            var c0 = Infinity, c1 = -Infinity, r0 = Infinity, r1 = -Infinity;
            // Members plus DERIVED children (card-edit Phase 2): a child's
            // cells stretch its parent's box on the thumbnail exactly as
            // they do on the board.
            var mset = {}, fold = [], fi;
            for (m = 0; m < g.cards.length; m++) { mset[g.cards[m]] = true; fold.push(g.cards[m]); }
            for (fi = 0; fi < board.cards.length; fi++) {
                c = board.cards[fi];
                if (c.type === 'interlinear' && mset[c.parent]) { fold.push(c.id); }
            }
            for (m = 0; m < fold.length; m++) {
                f = byId[fold[m]];
                if (!f) { continue; }
                c0 = Math.min(c0, f.col);
                c1 = Math.max(c1, f.col + f.cw - 1);
                r0 = Math.min(r0, f.row);
                r1 = Math.max(r1, f.row + f.rh - 1);
            }
            if (c0 === Infinity) { continue; }   // no placed members → nothing to draw
            out.groups.push({ id: g.id, color: g.color, c0: c0, c1: c1, r0: r0, r1: r1 });
        }
        return out;
    }

    /* ---- presentation deck ----------------------------------------------
       The board as an ordered list of slides for Presentation mode. Pure:
       reads the document, touches nothing. Reference labels are NOT built
       here (they need bookMeta, which the page has) — each part carries its
       raw card and the presenter labels it.

       ORDER — strict reading order: column ascending, then row ascending.
       A group is one slide, placed where its first member falls in that
       order, its members listed in the same order; members are skipped
       when the walk reaches them singly. Headings become title slides.
       Notes are left out (for now). Unplaced cards are placed first
       (ensureGridPlacement, as everywhere).

       INTERLINEAR (card-edit Phase 4) — a part whose card carries an
       interlinear CHILD is flagged il:true, and the presenter draws the
       original-language trio beside the verse text. The deck itself stays
       token-free (tokens live in the board's session cache); the flag also
       WEIGHTS the part: three rows per verse roughly triples the content,
       so il parts count IL_WEIGHT× against the budget and split at that
       fraction of it.

       BUDGET — a slide holds at most SLIDE.chars of verse text. Set high
       on purpose: keeping a group or a long range on ONE slide wins, and
       the presenter shrinks type / goes two-column to make it fit. Past it the slide continues:
       whole parts move on first; a single long part splits at verse
       boundaries when it has per-verse text (vv), else at a sentence end
       near the budget. Continuations carry cont:true and the same label.

       The deck opens with a COVER: the board's name, with the verse tally
       as its subtitle.

       -> [ { kind:'title', text, sub },
            { kind:'heading', text },
            { kind:'verse'|'group', label, color, cont,
              parts:[ { card, verses:[[n,text]…]|null, text, il?:true } ] } ] */
    var SLIDE = { chars: 1600 };   // one slide first; the presenter fits type and goes two-column

    function cardVerseRows(card) {
        if (isArray(card.vv) && card.vv.length) { return card.vv; }
        return null;
    }
    function partText(part) {
        var i, t = '';
        if (part.verses) { for (i = 0; i < part.verses.length; i++) { t += (i ? ' ' : '') + part.verses[i][1]; } return t; }
        return part.text || '';
    }
    function makePart(card, verses, text) {
        return { card: card, verses: verses || null, text: text || '' };
    }
    function partFromCard(card) {
        var rows = cardVerseRows(card);
        return makePart(card, rows, rows ? null : (card.text || ''));
    }

    // An interlinear pane roughly TRIPLES a part's vertical content (three
    // rows per verse beside the text), so il parts weigh IL_WEIGHT× against
    // the slide budget and split at that fraction of it (Phase 4).
    var IL_WEIGHT = 3;
    function partWeight(p) { return partText(p).length * (p.il ? IL_WEIGHT : 1); }

    // Split one over-budget part into a run of parts, each within budget;
    // the il flag rides onto every piece.
    function splitPart(part, budget) {
        var out = splitPartRaw(part, budget), i;
        if (part.il) { for (i = 0; i < out.length; i++) { out[i].il = true; } }
        return out;
    }
    function splitPartRaw(part, budget) {
        var out = [], i, chunk, len, cut, t;
        if (part.verses) {
            chunk = []; len = 0;
            for (i = 0; i < part.verses.length; i++) {
                var vl = part.verses[i][1].length;
                if (chunk.length && len + vl > budget) { out.push(makePart(part.card, chunk)); chunk = []; len = 0; }
                chunk.push(part.verses[i]); len += vl + 1;
            }
            if (chunk.length) { out.push(makePart(part.card, chunk)); }
            return out;
        }
        t = part.text;
        while (t.length > budget) {
            cut = -1;
            var win = t.slice(0, budget), m = win.match(/[.;:!?]["'\u201d\u2019)]*\s(?!.*[.;:!?]["'\u201d\u2019)]*\s)/);
            if (m) { cut = m.index + m[0].length; }
            if (cut < budget * 0.4) { cut = win.lastIndexOf(' '); }
            if (cut <= 0) { cut = budget; }
            out.push(makePart(part.card, null, t.slice(0, cut).replace(/\s+$/, '')));
            t = t.slice(cut).replace(/^\s+/, '');
        }
        if (t.length || !out.length) { out.push(makePart(part.card, null, t)); }
        return out;
    }

    // Pack a slide's parts into as many slides as the budget needs.
    function packSlide(base, parts) {
        var out = [], cur = [], len = 0, i, j, p, pieces, pl;
        function flush() {
            if (!cur.length) { return; }
            var sl = { kind: base.kind, label: base.label, color: base.color, cont: out.length > 0, parts: cur };
            out.push(sl); cur = []; len = 0;
        }
        for (i = 0; i < parts.length; i++) {
            p = parts[i]; pl = partWeight(p);
            if (pl > SLIDE.chars) {
                pieces = splitPart(p, Math.floor(SLIDE.chars / (p.il ? IL_WEIGHT : 1)));
                for (j = 0; j < pieces.length; j++) {
                    var l2 = partWeight(pieces[j]);
                    if (cur.length && len + l2 > SLIDE.chars) { flush(); }
                    cur.push(pieces[j]); len += l2;
                }
                continue;
            }
            if (cur.length && len + pl > SLIDE.chars) { flush(); }
            cur.push(p); len += pl;
        }
        flush();
        return out;
    }

    function slides(board) {
        var out = [];
        if (!isObj(board) || !isArray(board.cards)) { return out; }
        ensureGridPlacement(board);

        var placed = [], i, c;
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            if (isObj(c) && hasPos(c)) { placed.push(c); }
        }
        placed.sort(function (a, b) { return (a.col - b.col) || (a.row - b.row); });

        var groupOf = {}, groups = isArray(board.groups) ? board.groups : [], g, m;
        for (i = 0; i < groups.length; i++) {
            g = groups[i];
            if (!isObj(g) || !isArray(g.cards)) { continue; }
            for (m = 0; m < g.cards.length; m++) { groupOf[g.cards[m]] = g; }
        }

        // Interlinear flags (Phase 4): a part whose card HAS a child rides
        // with il:true. Pure derivation — no tokens here.
        var hasChild = {};
        for (i = 0; i < board.cards.length; i++) {
            c = board.cards[i];
            if (isObj(c) && c.type === 'interlinear') { hasChild[c.parent] = true; }
        }
        function pf(card) {
            var p = partFromCard(card);
            if (hasChild[card.id]) { p.il = true; }
            return p;
        }

        var emitted = {}, members, k;
        for (i = 0; i < placed.length; i++) {
            c = placed[i];
            if (c.type === 'heading') {
                if (isStr(c.text) && c.text.replace(/\s/g, '')) { out.push({ kind: 'heading', text: c.text }); }
                continue;
            }
            if (c.type !== 'verse') { continue; }   // notes: not in the deck (yet)
            g = groupOf[c.id];
            if (!g) {
                out.push.apply(out, packSlide({ kind: 'verse', label: null, color: null }, [pf(c)]));
                continue;
            }
            if (emitted[g.id]) { continue; }
            emitted[g.id] = true;
            members = [];
            for (k = i; k < placed.length; k++) {
                if (placed[k].type === 'verse' && groupOf[placed[k].id] === g) { members.push(pf(placed[k])); }
            }
            out.push.apply(out, packSlide({ kind: 'group', label: g.label || '', color: g.color || null }, members));
        }
        if (out.length) {
            out.unshift({ kind: 'title', text: isStr(board.name) && board.name ? board.name : 'Pericope', sub: summarize(board.cards).label });
        }
        return out;
    }

    /* ---- assemble & export ---------------------------------------------- */

    var MBPericope = {
        // constants (read-only reference for callers)
        SCHEMA_VERSION: SCHEMA_VERSION,
        INDEX_KEY:      INDEX_KEY,
        BOARD_PREFIX:   BOARD_PREFIX,
        RESERVED_SLUGS: RESERVED_SLUGS.slice(),
        CAPS:           CAPS,
        CARD_TYPES:     CARD_TYPES.slice(),
        LAYOUTS:        LAYOUTS.slice(),

        // CRUD
        list:       list,
        get:        get,
        create:     create,
        rename:     rename,
        remove:     remove,

        // cards
        addCards:   addCards,
        updateCard: updateCard,
        setCardExpanded: setCardExpanded,
        setCardVerses:   setCardVerses,
        moveCard:   moveCard,
        previewMove: previewMove,
        setCardSpan: setCardSpan,
        resizeCard:  resizeCard,      // Phase C: manual size (a real edit)
        resetCardSize: resetCardSize, // Phase C: back to auto-snap
        duplicateCard: duplicateCard, // card-edit copy (Phase 1)
        addInterlinearCard: addInterlinearCard, // card-edit Phase 2: the child card
        interlinearChild:   interlinearChild,   //   its lookup (pure, on a board doc)
        groupOfCard:        groupOfCard,        //   groupOf honouring the tether
        removeCard: removeCard,
        reorder:    reorder,
        save:       save,

        // validation & IO shaping
        validate:       validate,
        exportBoard:    exportBoard,
        importPayload:  importPayload,

        // introspection
        capacity:   capacity,
        usage:      usage,

        // derived summaries (board subtitle, hub thumbnails)
        summarize:  summarize,
        footprint:  footprint,
        slides:     slides,
        SLIDE:      SLIDE,

        // history (session-only undo / redo; see recordHistory)
        undo:       undo,
        redo:       redo,
        history:    historyCounts,
        HISTORY:    HISTORY,

        // share codec (S0) + import (S2)
        encodeShare:  encodeShare,
        decodeShare:  decodeShare,
        importShared: importShared,
        setCardTx:    setCardTx,

        // groups (Phase 5)
        GROUP_COLORS: GROUP_COLORS,
        createGroup:  createGroup,
        addToGroup:   addToGroup,
        ungroupCards: ungroupCards,
        updateGroup:  updateGroup,
        removeGroup:  removeGroup,

        // preferences (Phase 2)
        PREFS_KEY:  PREFS_KEY,
        getPrefs:   getPrefs,
        setPrefs:   setPrefs,

        // low-level helpers exposed for tests / advanced callers
        _slugify:   slugify,
        _sanitizeName: sanitizeName
    };

    root.MBPericope = MBPericope;
    if (typeof module !== 'undefined' && module.exports) { module.exports = MBPericope; }

})(typeof globalThis !== 'undefined' ? globalThis
   : (typeof self !== 'undefined' ? self
   : (typeof window !== 'undefined' ? window : this)));
