@extends('layouts.app')

@section('title', 'Pericope — MEGABIBLE.net')

@section('styles')
<style>
    /* ================================================================
       PERICOPE HUB  ·  the visitor's board collection.
       Tiles are painted by the script from localStorage (window.MBPericope);
       the server sends none of this data. No page wrapper of its own — like
       acts-of-the-user, content runs in app.blade's .container, and the hero
       below MIRRORS .acts-hero so the extras pages read as one family. (If a
       third page joins, promote the hero to a shared class in app.blade.)
       ================================================================ */
    .peri-hero { margin: 0 0 1.6rem; }
    .peri-hero h1 { font-size: 2.4rem; font-weight: 400; margin: 0 0 .3rem; letter-spacing: -.01em; }
    .peri-hero p  { color: var(--muted); font-family: var(--sans); font-size: .9rem; margin: 0; max-width: 58ch; }

    .peri-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
        gap: 1rem;
    }
    /* Mobile: always at least two columns of boards (never a lonely single). */
    @media (max-width: 520px) {
        .peri-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .7rem; }
    }

    .peri-tile {
        position: relative;
        display: flex; flex-direction: column;
        border: 1px solid var(--rule); border-radius: 14px;
        background: var(--bg); overflow: hidden;
        text-decoration: none; color: var(--ink);
        transition: border-color .14s, box-shadow .14s, transform .14s;
    }
    .peri-tile:hover {
        border-color: var(--accent);
        box-shadow: 0 8px 26px rgba(42,31,23,.14);
        transform: translateY(-2px);
    }

    /* Thumbnail — a MINIATURE of the board itself: one tinted block per card
       at its real grid cell, group boxes behind them, all in an SVG whose
       viewBox is a fixed window on the home block (so it reads like the
       board's opening view, just tiny). Empty boards show a faint scissors. */
    .peri-tile-thumb {
        position: relative;
        aspect-ratio: 4 / 3;
        display: flex;
        background: radial-gradient(120% 90% at 50% 0%, var(--panel), var(--bg));
        border-bottom: 1px solid var(--rule);
        overflow: hidden;
    }
    .peri-tile-thumb svg { display: block; width: 100%; height: 100%; }
    .peri-tile-thumb.is-empty { align-items: center; justify-content: center; color: var(--rule); }
    .peri-tile-thumb.is-empty svg { width: 32%; height: auto; opacity: .5; }

    /* Tile body. The name inherits the page serif (like every heading on the
       acts page); the meta and date lines take the sans/muted treatment. */
    .peri-tile-body { padding: .8rem .9rem .95rem; }
    .peri-tile-name {
        display: block;
        font-size: 1.1rem; color: var(--ink);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .peri-tile-meta {
        display: block; margin-top: .25rem;
        font-family: var(--sans); font-size: .8rem; color: var(--muted);
    }
    .peri-tile-dates {
        display: block; margin-top: .45rem;
        font-family: var(--sans); font-size: .72rem; line-height: 1.5;
        color: var(--muted); opacity: .8;
    }
    .peri-tile-dates span { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .peri-tile-del {
        position: absolute; top: .5rem; right: .5rem;
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; padding: 0;
        border: none; border-radius: 50%;
        background: color-mix(in srgb, var(--bg) 82%, transparent);
        color: var(--muted); cursor: pointer;
        opacity: 1; transition: opacity .12s, color .12s, background .12s;
    }
    /* Hover-to-reveal ONLY on devices that actually hover. On touch the
       button stays visible, because a hidden-until-hover control makes the
       FIRST tap resolve the tile's hover (revealing the button) instead of
       following the link — the "two taps to open" trap. There the button is
       always shown and one tap opens the pericope, like on desktop. */
    @media (hover: hover) {
        .peri-tile-del { opacity: 0; }
        .peri-tile:hover .peri-tile-del,
        .peri-tile-del:focus { opacity: 1; }
    }
    .peri-tile-del:hover { color: var(--accent); background: var(--panel); }
    .peri-tile-del svg { width: 15px; height: 15px; display: block; }

    .peri-storage {
        margin: 1.6rem 0 0; text-align: center;
        font-family: var(--sans); font-size: .78rem; color: var(--muted);
    }

    .peri-empty {
        max-width: 30rem; margin: 2rem auto; text-align: center;
        font-family: var(--sans); color: var(--muted);
    }
    .peri-empty svg { width: 40px; height: 40px; color: var(--rule); }
    .peri-empty h2 { margin: .8rem 0 .3rem; color: var(--ink); font-size: 1.25rem; font-weight: 400; }
    .peri-empty p  { margin: 0; font-size: .9rem; line-height: 1.55; }
</style>
@endsection

@section('content')
    <div class="peri-hero">
        <h1>Pericope</h1>
        <p>Collect and arrange verses on a simple board interface.</p>
        <p>Your pericopae are saved only in this browser.</p>
    </div>

    {{-- Painted by the script from window.MBPericope. Starts hidden; the
         script shows either the grid or the empty state. --}}
    <div class="peri-grid" id="peri-grid" hidden></div>
    <p class="peri-storage" id="peri-storage" hidden></p>

    <div class="peri-empty" id="peri-empty" hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
        <h2>No pericopae yet</h2>
        <p>Select one or more verses while reading, open the <strong>folder</strong> on the toolbar, and choose the <strong>scissors</strong> to cut your first pericope.</p>
    </div>
@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    var HUB_URL   = @json($hubUrl);    // base for board links: HUB_URL + '/' + slug
    var BOOK_META = @json($bookMeta);  // osis => {name, slug, off, single, short, color}

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function dateLabel(ts) {
        if (!ts) return '';
        try { return new Date(ts).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
        catch (e) { return ''; }
    }
    function fmtBytes(b) {
        b = b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(1) + ' MB';
    }
    // Sanitise a palette colour name to the --tl-{name} vars (lowercase only).
    function paletteColor(c) {
        c = String(c == null ? '' : c).replace(/[^a-z]/g, '');
        return c || 'clay';
    }
    function num(n) { return String(Math.round(n * 100) / 100); }

    var SCISSORS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>';
    var TRASH    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';

    /* ---- MINI-BOARD THUMBNAIL -------------------------------------------
       Geometry comes from MBPericope.footprint() (logical grid cells, groups
       as bounding boxes); this only turns cells into SVG rectangles.

       Units are LOGICAL, not pixels: one cell is 27 × 10 with a gap of 2,
       the same 2.7:1 proportion as the real board's cell (~240 × 89 px,
       16 px gutters), so a miniature reads like the board rather than a
       grid of squares.

       The viewBox is a FIXED WINDOW centred on the home block (cols 1..3)
       and anchored at row 1 — the board's zoomed-out view, the same for
       every board so the hub reads as one uniform sheet of miniatures.
       Anything outside the window is clipped, edge to edge, no fade.

       Cards look like the real thing: a plain card with the book's colour
       as a CHIP in its top-left, not a wash of colour — so the miniature
       and the board it opens into don't feel like different objects. */
    var U = { col: 27, row: 10, gap: 2 };
    var PITCH_X = U.col + U.gap, PITCH_Y = U.row + U.gap;
    var HALO = 1.5;                  // group box overhang past member cells
    var WINDOW = {
        cols: 7,                     // window width, in columns (the zoomed-out view)
        aspect: 3 / 4                // must match .peri-tile-thumb's aspect-ratio
    };
    var CHIP = { w: 11, h: 3.6, inset: 1.6 };   // the colour chip inside a verse card

    function colX(col) { return (col - 1) * PITCH_X; }   // col 1 → x 0; col 0 → x −29
    function rowY(row) { return (row - 1) * PITCH_Y; }   // row 1 → y 0

    function rect(x, y, w, h, rx, style, extra) {
        return '<rect x="' + num(x) + '" y="' + num(y) + '" width="' + num(w) + '" height="' + num(h) +
               '" rx="' + rx + '" style="' + style + '"' + (extra || '') + '/>';
    }

    function cardRect(c) {
        var x = colX(c.col), y = rowY(c.row);
        var w = c.cw * PITCH_X - U.gap, h = c.rh * PITCH_Y - U.gap;
        if (c.type === 'verse') {
            var color = paletteColor(BOOK_META[c.osis] && BOOK_META[c.osis].color);
            // The card itself: the board's .peri-card (bg fill, rule stroke)…
            var out = rect(x, y, w, h, 1.5, 'fill:var(--bg);stroke:var(--rule);stroke-width:.5');
            // …and its .peri-cell chip, top-left, in the book's colour.
            out += rect(x + CHIP.inset, y + CHIP.inset, Math.min(CHIP.w, w - 2 * CHIP.inset), CHIP.h, .9,
                        'fill:var(--tl-' + color + ')');
            // An expanded card shows faint "text lines" under the chip.
            if (c.exp && h > 8) {
                var ly = y + CHIP.inset + CHIP.h + 1.6, lx = x + CHIP.inset, lw = w - 2 * CHIP.inset;
                while (ly < y + h - 1.6) {
                    out += rect(lx, ly, lw * (ly + 1.2 >= y + h - 1.6 ? .6 : 1), .7, .35, 'fill:var(--ink);fill-opacity:.16');
                    ly += 1.6;
                }
            }
            return out;
        }
        if (c.type === 'heading') {
            return rect(x, y, w, h, 1.5, 'fill:var(--ink);fill-opacity:.16');
        }
        // note — quiet panel with a dashed edge, like the italic card on the board
        return rect(x, y, w, h, 1.5, 'fill:var(--panel);stroke:var(--rule);stroke-width:.6;stroke-dasharray:1.6 1');
    }

    function groupBox(g) {
        var color = paletteColor(g.color);
        var x = colX(g.c0) - HALO, y = rowY(g.r0) - HALO;
        var w = (g.c1 - g.c0 + 1) * PITCH_X - U.gap + 2 * HALO;
        var h = (g.r1 - g.r0 + 1) * PITCH_Y - U.gap + 2 * HALO;
        return rect(x, y, w, h, 2,
            'fill:var(--tl-' + color + ');fill-opacity:.09;stroke:var(--tl-' + color + ');stroke-opacity:.7;stroke-width:.8');
    }
    // The label chip: a solid tab floating just above the box's top-left,
    // painted LAST so it sits over cards, exactly like the board's sibling chip.
    function groupChip(g) {
        var color = paletteColor(g.color);
        var x = colX(g.c0) - HALO + 1.5, y = rowY(g.r0) - HALO - 2.2;
        var w = Math.min(12, (g.c1 - g.c0 + 1) * PITCH_X - U.gap);
        return rect(x, y, w, 3, .9, 'fill:var(--tl-' + color + ')');
    }

    function thumbHtml(board) {
        var fp = window.MBPericope.footprint(board);
        if (!fp.cards.length) { return '<span class="peri-tile-thumb is-empty">' + SCISSORS + '</span>'; }

        var W = WINDOW.cols * PITCH_X, H = W * WINDOW.aspect;
        var homeW = window.MBPericope.CAPS.gridCols * PITCH_X - U.gap;
        var x0 = homeW / 2 - W / 2;        // centre the window on the home block
        var y0 = -(HALO + 2.5);            // headroom so a row-1 group's chip is visible

        var body = '', i;
        for (i = 0; i < fp.groups.length; i++) { body += groupBox(fp.groups[i]); }
        for (i = 0; i < fp.cards.length;  i++) { body += cardRect(fp.cards[i]); }
        for (i = 0; i < fp.groups.length; i++) { body += groupChip(fp.groups[i]); }

        return '<span class="peri-tile-thumb">' +
                   '<svg viewBox="' + num(x0) + ' ' + num(y0) + ' ' + num(W) + ' ' + num(H) +
                        '" preserveAspectRatio="xMidYMin slice" aria-hidden="true" focusable="false">' +
                       body +
                   '</svg>' +
               '</span>';
    }

    function tileHtml(entry) {
        var href  = HUB_URL + '/' + encodeURIComponent(entry.slug);
        var board = window.MBPericope.get(entry.id);   // one small localStorage read per tile
        var cards = (board && board.cards) || [];
        var sub   = window.MBPericope.summarize(cards).label;
        return '' +
            '<a class="peri-tile" href="' + esc(href) + '">' +
                thumbHtml(board) +
                '<span class="peri-tile-body">' +
                    '<span class="peri-tile-name">' + esc(entry.name) + '</span>' +
                    '<span class="peri-tile-meta">' + esc(sub) + '</span>' +
                    '<span class="peri-tile-dates">' +
                        // A board that arrived through a share link says so.
                        (entry.imported
                            ? '<span>Imported ' + esc(dateLabel(entry.imported)) + '</span>'
                            : '<span>Created ' + esc(dateLabel(entry.created)) + '</span>') +
                        '<span>Updated ' + esc(dateLabel(entry.updated)) + '</span>' +
                    '</span>' +
                '</span>' +
                '<button type="button" class="peri-tile-del" data-id="' + esc(entry.id) +
                    '" data-name="' + esc(entry.name) + '" aria-label="Delete ' + esc(entry.name) + '">' +
                    TRASH +
                '</button>' +
            '</a>';
    }

    // Runs on init() — NOT at parse time: window.MBPericope loads with `defer`,
    // so it exists by DOMContentLoaded but not while this inline script parses.
    function init() {
        var grid    = document.getElementById('peri-grid');
        var empty   = document.getElementById('peri-empty');
        var storage = document.getElementById('peri-storage');
        if (!grid || !empty) return;

        if (!window.MBPericope) {
            console.warn('[pericope] store (window.MBPericope) not loaded on the hub.');
            grid.hidden = true; empty.hidden = false;
            return;
        }

        function render() {
            var boards = window.MBPericope.list() || [];
            boards.sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });

            if (!boards.length) {
                grid.hidden = true; empty.hidden = false; grid.innerHTML = '';
                if (storage) storage.hidden = true;
                return;
            }
            empty.hidden = true; grid.hidden = false;
            var html = '';
            for (var i = 0; i < boards.length; i++) { html += tileHtml(boards[i]); }
            grid.innerHTML = html;

            if (storage) {
                var used = 0;
                try { used = window.MBPericope.usage().pericopeBytes; } catch (e) {}
                storage.textContent = boards.length + (boards.length === 1 ? ' pericope' : ' pericopae') +
                    ' \u00B7 using ' + fmtBytes(used) + ' in this browser';
                storage.hidden = false;
            }
        }

        grid.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.peri-tile-del') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var id = btn.getAttribute('data-id');
            var name = btn.getAttribute('data-name') || 'this pericope';

            var ask = window.mbConfirm
                ? window.mbConfirm(['Delete \u201c' + name + '\u201d?',
                                    'This removes the pericope from this browser. It can\u2019t be undone.'],
                                   { confirmLabel: 'Delete', cancelLabel: 'Keep' })
                : Promise.resolve(window.confirm('Delete "' + name + '"?'));

            ask.then(function (ok) {
                if (!ok) return;
                if (window.MBActs) { window.MBActs.log('pericope.delete', { id: id, name: name }); }
                window.MBPericope.remove(id);
                render();
            });
        });

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endsection
