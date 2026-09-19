/* ======================================================================
   PERICOPE MARKS  (marks r1)                    public/js/pericope-marks.js
   ----------------------------------------------------------------------
   Decorates the chapter reader with the pericope system's footprints:
   every verse already collected into a pericope gets an .in-pericope
   class (the Blade's CSS draws the canon-color underline), and each RUN —
   consecutive verses sharing the same SET of pericopes — gets a small
   dot appended after its last fragment. Hovering the dot (fine pointers)
   or tapping it (any pointer) opens a popover listing the pericopes that
   hold the run, each a link to its board.

   PRINCIPLES
     - RE-SCAN, never diff. Every trigger (load, store event, cross-tab
       storage, bfcache return) rebuilds the whole map and repaints. The
       paint is idempotent: strip everything, redraw everything.
     - Membership is TRANSLATION-AGNOSTIC: a card's tx is ignored, like
       likeKey() in the store. Rom 8:28 collected from the KJV still
       reads as collected while you're in the WEB.
     - A card's real verse set is its vv numbers when present (shared-URL
       imports carry non-contiguous captures like "5,7-9"), else v1..v2.
     - The dot carries NO text content, ever. verseText() and the
       clipboard read textContent off the verse fragments; the dot must
       add nothing to a copy or a card capture.
     - The dot's click listener sits ON the dot (target phase) and stops
       propagation, so focus-synthesis's document-level bubble listener
       never sees it — a dot tap must not toggle verse selection.
     - The Text Settings toggle (data-pericope-marks on <html>) is pure
       CSS. This script paints regardless; "off" just hides the paint.

   NEEDS (all present on the chapter page before this deferred script):
     window.MBPericope       pericope-store.js (layout, earlier in order)
     window.MBFocusContext   the Blade's inline bridge (osis, rawChapter)
     window.MB_PERICOPE_BASE board URLs (layout)

   Vanilla ES5 (var / function), matching the other shared scripts. The
   pure derivation half (cardVerseNums / membershipMap / buildRuns) is
   UMD-exposed so a Node harness can exercise it with fixture boards.
   ====================================================================== */
(function (root) {
    'use strict';

    /* =====================================================================
       PURE HALF — no DOM, no storage. Exercised by the Node harness.
       ===================================================================== */

    function isArray(x) { return Object.prototype.toString.call(x) === '[object Array]'; }

    // The verse numbers a card actually holds: its per-verse vv rows when
    // present (each row is [vn, text]), else the v1..v2 span. Mirrors the
    // store's own derivation (share codec, walkBoard).
    function cardVerseNums(card) {
        var nums = [], i, n;
        if (isArray(card.vv) && card.vv.length) {
            for (i = 0; i < card.vv.length; i++) {
                n = parseInt(card.vv[i] && card.vv[i][0], 10);
                if (!isNaN(n)) { nums.push(n); }
            }
        } else {
            var v1 = parseInt(card.v1, 10), v2 = parseInt(card.v2, 10);
            if (!isNaN(v1)) {
                if (isNaN(v2) || v2 < v1) { v2 = v1; }
                for (n = v1; n <= v2; n++) { nums.push(n); }
            }
        }
        return nums;
    }

    // boards: [{id, name, slug, cards:[…]}] (full board docs).
    // -> { <vn>: { key: 'id1|id2', boards: [{id,name,slug}] } }
    // Only type:'verse' cards matching (osis, ch) count — interlinear
    // children and text cards never grant membership. tx is IGNORED.
    // One board appears once per verse no matter how many of its cards
    // cover that verse; board order (and so popover order) follows the
    // order boards were passed in.
    function membershipMap(boards, osis, ch) {
        var map = {}, b, c, nums, i, j, k, vn, entry;
        ch = parseInt(ch, 10);
        for (i = 0; i < boards.length; i++) {
            b = boards[i];
            if (!b || !isArray(b.cards)) { continue; }
            for (j = 0; j < b.cards.length; j++) {
                c = b.cards[j];
                if (!c || c.type !== 'verse') { continue; }
                if (c.osis !== osis || parseInt(c.ch, 10) !== ch) { continue; }
                nums = cardVerseNums(c);
                for (k = 0; k < nums.length; k++) {
                    vn = nums[k];
                    entry = map[vn] || (map[vn] = { ids: {}, boards: [] });
                    if (!entry.ids[b.id]) {
                        entry.ids[b.id] = true;
                        entry.boards.push({ id: b.id, name: b.name, slug: b.slug });
                    }
                }
            }
        }
        // Freeze each entry's identity key: sorted ids, joined. Two verses
        // belong to the same run iff their keys match exactly.
        var out = {}, ids;
        for (vn in map) {
            if (!map.hasOwnProperty(vn)) { continue; }
            ids = [];
            for (k in map[vn].ids) { if (map[vn].ids.hasOwnProperty(k)) { ids.push(k); } }
            ids.sort();
            out[vn] = { key: ids.join('|'), boards: map[vn].boards };
        }
        return out;
    }

    // map: membershipMap output. presentVns: ascending array of verse
    // numbers that actually exist in the DOM (a card can reference verses
    // the chapter doesn't render — data drift, capped imports — and those
    // must neither paint nor anchor a dot).
    // -> [{ vns: [n, …], boards: [{id,name,slug}] }] — maximal runs of
    // CONSECUTIVE present verses sharing an identical membership key.
    // Verse 5 in {A,B} next to verse 6 in {A} is two runs, two dots.
    function buildRuns(map, presentVns) {
        var runs = [], cur = null, i, vn, m;
        for (i = 0; i < presentVns.length; i++) {
            vn = presentVns[i];
            m = map[vn];
            if (!m) { cur = null; continue; }
            if (cur && m.key === cur.key && vn === cur.vns[cur.vns.length - 1] + 1) {
                cur.vns.push(vn);
            } else {
                cur = { key: m.key, vns: [vn], boards: m.boards };
                runs.push(cur);
            }
        }
        return runs;
    }

    var api = {
        REV: 'marks r1',
        cardVerseNums: cardVerseNums,
        membershipMap: membershipMap,
        buildRuns:     buildRuns
    };
    root.MBPericopeMarks = api;
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }

    /* =====================================================================
       DOM HALF — painting, dots, popover, repaint triggers. Skipped
       entirely under the Node harness.
       ===================================================================== */

    if (typeof document === 'undefined') { return; }

    var ctx     = root.MBFocusContext;
    var reading = document.querySelector('.reading');
    if (!ctx || !ctx.osis || !reading || !root.MBPericope) { return; }

    var BASE = root.MB_PERICOPE_BASE || '';

    // The runs of the current paint, indexed by the dots' data-pm-run.
    var RUNS = [];

    function boardUrl(slug) {
        return BASE ? BASE + '/' + encodeURIComponent(slug) : '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    /* ---- scan: storage -> full board docs -> membership ------------------ */

    function loadBoards() {
        var entries = root.MBPericope.list(), docs = [], i, doc;
        for (i = 0; i < entries.length; i++) {
            doc = root.MBPericope.get(entries[i].id);
            if (doc) { docs.push(doc); }           // a stale index row skips soft
        }
        return docs;
    }

    /* ---- paint ------------------------------------------------------------ */

    // Every DOM fragment of verse n, in document order.
    function nodesFor(vn) {
        return reading.querySelectorAll('.verse[data-verse="' + vn + '"]');
    }

    // The text-hugging carrier of a fragment: poetry's inner .vt, or the
    // prose span itself — the same duo the highlight and underline ride.
    function carrierOf(frag) {
        if (frag.classList.contains('poetry')) {
            return frag.querySelector('.vt') || frag;
        }
        return frag;
    }

    function clearPaint() {
        var i, els;
        els = reading.querySelectorAll('.pm-dot');
        for (i = els.length - 1; i >= 0; i--) { els[i].parentNode.removeChild(els[i]); }
        els = reading.querySelectorAll('.verse.in-pericope');
        for (i = 0; i < els.length; i++) { els[i].classList.remove('in-pericope'); }
        RUNS = [];
    }

    function makeDot(runIndex) {
        var dot = document.createElement('span');
        dot.className = 'pm-dot';
        dot.setAttribute('data-pm-run', String(runIndex));
        dot.setAttribute('role', 'button');
        dot.setAttribute('tabindex', '0');
        dot.setAttribute('aria-label', 'In ' + RUNS[runIndex].boards.length +
            ' pericope' + (RUNS[runIndex].boards.length === 1 ? '' : 's'));
        // Target-phase listener + stopPropagation: focus-synthesis's
        // document-level bubble listener must never see this tap, or the
        // dot would toggle verse selection.
        dot.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePop(dot);
        });
        dot.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                e.stopPropagation();
                togglePop(dot);
            }
        });
        return dot;
    }

    function paint() {
        clearPaint();
        hidePop();

        var docs = loadBoards();
        if (!docs.length) { return; }

        var map = membershipMap(docs, ctx.osis, ctx.rawChapter);

        // Which of those verses actually exist on this page?
        var present = [], vn;
        for (vn in map) {
            if (map.hasOwnProperty(vn) && nodesFor(vn).length) {
                present.push(parseInt(vn, 10));
            }
        }
        if (!present.length) { return; }
        present.sort(function (a, b) { return a - b; });

        RUNS = buildRuns(map, present);

        // Underline every fragment of every member verse.
        var i, j, frags;
        for (i = 0; i < present.length; i++) {
            frags = nodesFor(present[i]);
            for (j = 0; j < frags.length; j++) {
                frags[j].classList.add('in-pericope');
            }
        }

        // One dot per run, inside the LAST fragment of the run's last verse
        // (after the footnote markers), on the text-hugging carrier so it
        // sits flush with the words in prose and poetry alike.
        for (i = 0; i < RUNS.length; i++) {
            frags = nodesFor(RUNS[i].vns[RUNS[i].vns.length - 1]);
            if (!frags.length) { continue; }
            carrierOf(frags[frags.length - 1]).appendChild(makeDot(i));
        }
    }

    /* ---- popover ----------------------------------------------------------
       The fn-pop pattern, list-flavored: floats above the dot (below when
       there's no headroom), chevron tracking the dot even when the panel
       is viewport-clamped. Click a row to open that board. Works by hover
       on fine pointers and by tap everywhere.
       ----------------------------------------------------------------- */

    var pop = null, popDot = null, showTimer = 0, hideTimer = 0;

    function hidePop() {
        clearTimeout(showTimer);
        if (popDot) { popDot.classList.remove('is-open'); }
        if (pop) { pop.parentNode.removeChild(pop); }
        pop = null; popDot = null;
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hidePop, 250);
    }
    function cancelHide() { clearTimeout(hideTimer); }

    function showPop(dot) {
        if (popDot === dot) { cancelHide(); return; }
        hidePop();

        var run = RUNS[parseInt(dot.getAttribute('data-pm-run'), 10)];
        if (!run) { return; }

        var html = '<div class="pm-pop-title">Pericope' +
                   (run.boards.length === 1 ? '' : 's') + '</div>';
        var i, b, u;
        for (i = 0; i < run.boards.length; i++) {
            b = run.boards[i];
            u = boardUrl(b.slug);
            html += u
                ? '<a href="' + esc(u) + '">' + esc(b.name) + '</a>'
                : '<a>' + esc(b.name) + '</a>';
        }

        pop = document.createElement('div');
        pop.className = 'pm-pop';
        pop.innerHTML = html;
        document.body.appendChild(pop);
        popDot = dot;
        dot.classList.add('is-open');

        // Keep taps inside the panel from reaching focus-synthesis's
        // dismiss (row links still navigate normally).
        pop.addEventListener('click', function (e) { e.stopPropagation(); });
        pop.addEventListener('mouseenter', cancelHide);
        pop.addEventListener('mouseleave', scheduleHide);

        // Position: centred above the dot, clamped to the viewport; flip
        // below (chevron up) when there's no headroom. Same math as fn-pop,
        // with the width measured (the list sizes itself) instead of fixed.
        var r = dot.getBoundingClientRect();
        var maxW = document.documentElement.clientWidth - 16;
        if (pop.offsetWidth > maxW) { pop.style.width = maxW + 'px'; }
        var w = pop.offsetWidth;
        var h = pop.offsetHeight;

        var left = r.left + r.width / 2 - w / 2 + window.scrollX;
        left = Math.max(window.scrollX + 8,
               Math.min(left, window.scrollX + document.documentElement.clientWidth - w - 8));
        var below = r.top < h + 18;
        pop.classList.toggle('is-below', below);
        pop.style.left = left + 'px';
        pop.style.top  = (below
            ? r.bottom + window.scrollY + 10
            : r.top    + window.scrollY - h - 10) + 'px';
        pop.style.setProperty('--chev-x',
            (r.left + r.width / 2 + window.scrollX - left) + 'px');
    }

    function togglePop(dot) {
        if (popDot === dot) { hidePop(); } else { showPop(dot); }
    }

    // Hover path — fine pointers only, mirroring the footnote popover.
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        document.addEventListener('mouseover', function (e) {
            var dot = e.target.closest ? e.target.closest('.pm-dot') : null;
            if (dot) {
                cancelHide();
                clearTimeout(showTimer);
                showTimer = setTimeout(function () { showPop(dot); }, 120);
                return;
            }
            if (pop && !(e.target.closest && e.target.closest('.pm-pop'))) {
                scheduleHide();
            }
        });
    }

    // Outside click / Escape close the panel. The dot and the panel stop
    // their own propagation, so any click that reaches here is outside.
    document.addEventListener('click', function () {
        if (pop) { hidePop(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && pop) { hidePop(); }
    });

    /* ---- repaint triggers -------------------------------------------------
       All coalesced through a zero-delay timer so a burst of writes (an
       import, an undo cascade) paints once, not once per event.
       ----------------------------------------------------------------- */

    var queued = false;
    function schedulePaint() {
        if (queued) { return; }
        queued = true;
        setTimeout(function () { queued = false; paint(); }, 0);
    }

    // Same-tab writes: the store's guarded emit (store-emit r1).
    document.addEventListener('mb:pericope-change', schedulePaint);

    // Cross-tab writes: the native storage event. key === null is a
    // wholesale clear() — repaint for that too.
    window.addEventListener('storage', function (e) {
        if (!e.key || e.key.indexOf('mbPericope.') === 0) { schedulePaint(); }
    });

    // Back/forward into a bfcached page: the DOM is frozen from before,
    // but boards may have changed on the pages visited in between.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { schedulePaint(); }
    });

    paint();
})(typeof globalThis !== 'undefined' ? globalThis
   : (typeof self !== 'undefined' ? self
   : (typeof window !== 'undefined' ? window : this)));
