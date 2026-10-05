@extends('layouts.app')

@section('title', 'Top Books · MEGABIBLE.net')

@section('styles')
<style>
    /* ---- Page head ---------------------------------------------------- */
    .tb-head{margin:.4rem 0 1.4rem;}
    .tb-title{font-size:2.4rem;font-weight:400;line-height:1.15;letter-spacing:-.01em;margin:0 0 .6rem;}
    .tb-lead{font-size:1.05rem;line-height:1.6;margin:0 0 1rem;}

    /* The weekly / all-time switch — server-rendered, one query param. */
    .tb-windows{display:inline-flex;gap:.3rem;border:1px solid var(--rule);border-radius:999px;padding:.2rem;font-family:var(--sans);font-size:.8rem;}
    .tb-windows a{padding:.25rem .85rem;border-radius:999px;text-decoration:none;color:var(--muted);transition:color .12s;}
    .tb-windows a:hover{color:var(--accent);}
    .tb-windows a.on{background:var(--accent);color:#fff;}

    /* ---- The table ----------------------------------------------------
       Wide content scrolls inside its own wrapper on narrow screens; the
       page body never scrolls sideways. */
    .tb-scroll{overflow-x:auto;margin:1.2rem 0 .6rem;}
    #tb-table{width:100%;border-collapse:collapse;font-family:var(--sans);font-size:.85rem;}
    #tb-table th,#tb-table td{padding:.45rem .6rem;border-bottom:1px solid var(--rule);text-align:right;white-space:nowrap;}
    #tb-table thead th{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);border-bottom:2px solid var(--rule);}

    th.tb-book-h,td.tb-book{text-align:left;}

    /* tb-abbr r2: on mobile the table slims to Rank + Book + Total — the
       four per-feature columns hide, so nothing scrolls. Full names
       return; only books with a homepage short name swap ("1 Thess",
       "Wisdom of Sol"), the same chain as the index tiles.
       BREAKPOINT KNOB: 560px, shared with the homepage. */
    .bk-short{display:none;}
    .tb-note-mobile{display:none;}
    @media (max-width:560px){
        .tb-wide{display:none;}
        td.tb-book .bk-full{display:none;}
        td.tb-book .bk-short{display:inline;}
        .tb-note-full{display:none;}
        .tb-note-mobile{display:block;}
    }

    /* Rank — a CSS counter, so the numbers renumber themselves whenever
       the sorter re-appends rows. No JS bookkeeping. */
    #tb-table tbody{counter-reset:rk;}
    #tb-table tbody tr{counter-increment:rk;}
    td.tb-rank{width:2.2rem;color:var(--muted);font-variant-numeric:tabular-nums;}
    td.tb-rank::before{content:counter(rk);}

    /* Sortable headers: the whole label is a button. The active column
       carries aria-sort (set by the sorter), which drives both the accent
       and the direction glyph — state lives in one attribute. */
    th[data-key] button{background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;}
    th[data-key] button:hover{color:var(--accent);}
    th[aria-sort] button{color:var(--accent);font-weight:700;}
    th[aria-sort="descending"] button::after{content:" \25BC";font-size:.6rem;}
    th[aria-sort="ascending"]  button::after{content:" \25B2";font-size:.6rem;}

    /* Book cell: canon-colour swatch (the homepage line's little cousin)
       + the book-name font. Books with no verses anywhere render muted
       and unlinked, same as the homepage's "soon" tiles. */
    td.tb-book::before{content:"";display:inline-block;width:.55rem;height:.55rem;border-radius:2px;background:var(--cg,var(--tl-clay));margin-right:.55rem;}
    td.tb-book a{color:var(--ink);text-decoration:none;font-family:var(--book-font);font-size:.98rem;}
    td.tb-book a:hover{color:var(--accent);}
    td.tb-book .tb-soon{color:var(--soon);font-family:var(--book-font);font-size:.98rem;}

    td.num{font-variant-numeric:tabular-nums;}
    td.num.zero{color:var(--soon);}
    td.tb-total{font-weight:700;}

    .tb-note{font-family:var(--sans);font-size:.75rem;color:var(--muted);line-height:1.5;margin:.4rem 0 0;}
</style>
@endsection

@section('content')
    <div class="tb-head">
        <h1 class="tb-title">Top Bible Books</h1>
        <p class="tb-lead">
            The entire 91 book canon, ranked by {{ $window === 'all' ? 'all-time' : "this week's" }}
            anonymous activity. This table counts Bible reading, Vigil typing, Verse Scrims, and
            verses collected into Pericopae.
        </p>

        <nav class="tb-windows" aria-label="Time window">
            <a href="{{ route('extras.topbooks') }}"
               class="{{ $window === 'weekly' ? 'on' : '' }}"
               @if ($window === 'weekly') aria-current="page" @endif>This week</a>
            <a href="{{ route('extras.topbooks', ['window' => 'all']) }}"
               class="{{ $window === 'all' ? 'on' : '' }}"
               @if ($window === 'all') aria-current="page" @endif>All time</a>
        </nav>
    </div>

    <div class="tb-scroll">
        <table id="tb-table">
            <thead>
                <tr>
                    <th scope="col" aria-label="Rank"></th>
                    <th scope="col" class="tb-book-h" data-key="canon">
                        <button type="button" title="Sort in canon order">Book</button>
                    </th>
                    <th scope="col" class="tb-wide" data-key="readers">
                        <button type="button">Reader</button>
                    </th>
                    <th scope="col" class="tb-wide" data-key="typed">
                        <button type="button">Vigil</button>
                    </th>
                    <th scope="col" class="tb-wide" data-key="scrimmed">
                        <button type="button">Scrim</button>
                    </th>
                    <th scope="col" class="tb-wide" data-key="collected">
                        <button type="button">Pericope</button>
                    </th>
                    <th scope="col" data-key="total">
                        <button type="button">Total</button>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr data-canon="{{ $row['canon'] }}"
                        data-readers="{{ $row['readers'] }}"
                        data-typed="{{ $row['typed'] }}"
                        data-scrimmed="{{ $row['scrimmed'] }}"
                        data-collected="{{ $row['collected'] }}"
                        data-total="{{ $row['total'] }}">
                        <td class="tb-rank"></td>
                        <td class="tb-book" style="--cg:var(--tl-{{ $row['color'] }})">
                            @if ($row['href'])
                                <a href="{{ $row['href'] }}">
                                    @if ($row['abbr'])
                                        <span class="bk-full">{{ $row['name'] }}</span><span class="bk-short">{{ $row['abbr'] }}</span>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </a>
                            @else
                                <span class="tb-soon">
                                    @if ($row['abbr'])
                                        <span class="bk-full">{{ $row['name'] }}</span><span class="bk-short">{{ $row['abbr'] }}</span>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td class="num tb-wide{{ $row['readers']   ? '' : ' zero' }}">{{ number_format($row['readers']) }}</td>
                        <td class="num tb-wide{{ $row['typed']     ? '' : ' zero' }}">{{ number_format($row['typed']) }}</td>
                        <td class="num tb-wide{{ $row['scrimmed']  ? '' : ' zero' }}">{{ number_format($row['scrimmed']) }}</td>
                        <td class="num tb-wide{{ $row['collected'] ? '' : ' zero' }}">{{ number_format($row['collected']) }}</td>
                        <td class="num tb-total{{ $row['total'] ? '' : ' zero' }}">{{ number_format($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="tb-note tb-note-full">
        Reader counts devices (one per book per day) / Vigil counts verses
        completed in Vigil mode / Scrim counts finished scrimmage rounds in
        every mode and language / Pericope counts verses added to pericope
        boards. All counters are anonymous daily tallies.
    </p>
    <p class="tb-note tb-note-mobile">
        Total aggregates each book's Reader, Vigil, Scrim, and Pericope
        numbers. All counters are anonymous
        daily tallies.
    </p>
@endsection

@section('scripts')
<script>
    /* =====================================================================
       tb-rank r1 — THE COLUMN SORTER
       ---------------------------------------------------------------------
       Ninety-one rows, so sorting lives entirely in the client: read the
       data-* numbers, sort, re-append. The server ships rows already in
       the default order (readers descending, canon tie-break), so the
       first paint IS the default state and no-JS visitors still get a
       ranked page — this script only marks the active header on load.

       Rules: a numeric column starts DESCENDING and toggles on re-click;
       the Book column starts in CANON order and toggles reversed. Every
       sort tie-breaks on canon order so equal counts keep a stable,
       meaningful sequence. Rank numbers are a CSS counter and renumber
       themselves on re-append.
       ===================================================================== */
    (function () {
        'use strict';
        var table = document.getElementById('tb-table');
        if (!table || !table.tBodies.length) return;

        var tbody = table.tBodies[0];
        var ths   = table.querySelectorAll('th[data-key]');
        var cur   = { key: 'readers', dir: -1 };   // matches the server's first paint

        function val(tr, key) {
            return parseFloat(tr.getAttribute('data-' + key)) || 0;
        }

        function paint() {
            var i, th;
            for (i = 0; i < ths.length; i++) {
                th = ths[i];
                if (th.getAttribute('data-key') === cur.key) {
                    th.setAttribute('aria-sort', cur.dir === 1 ? 'ascending' : 'descending');
                } else {
                    th.removeAttribute('aria-sort');
                }
            }
        }

        function apply() {
            var rows = Array.prototype.slice.call(tbody.rows);
            rows.sort(function (a, b) {
                var d = (val(a, cur.key) - val(b, cur.key)) * cur.dir;
                return d || (val(a, 'canon') - val(b, 'canon'));
            });
            for (var i = 0; i < rows.length; i++) { tbody.appendChild(rows[i]); }
            paint();
        }

        Array.prototype.forEach.call(ths, function (th) {
            var btn = th.querySelector('button');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var key = th.getAttribute('data-key');
                if (key === cur.key) {
                    cur.dir = -cur.dir;
                } else {
                    cur.key = key;
                    cur.dir = key === 'canon' ? 1 : -1;   // Book: canon first; numbers: biggest first
                }
                apply();
            });
        });

        // tb-abbr r2: the mobile view shows only Book + Total, so ranking
        // by the now-hidden Reader column would look arbitrary — rank 2
        // could carry a bigger Total than rank 1. Small screens therefore
        // open sorted by Total, the one number actually visible. Checked
        // once at load; phones don't resize mid-visit.
        if (window.matchMedia && matchMedia('(max-width:560px)').matches) {
            cur.key = 'total';
            apply();
        } else {
            paint();   // rows arrive pre-sorted; just mark the default column
        }
    })();
</script>
@endsection
