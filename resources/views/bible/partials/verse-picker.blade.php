{{--
    Verse picker — a QuickNav-style drill-down: canon-ordered book chips
    (testament-split, section-tinted, SHORT labels so more fit per line) →
    chapter grid → verse grid → pick → translation choice.

    TRANSLATION COMES LAST. Every book chip is live from the start; grids are
    built from the UNION of all editions' outlines. Only after a verse is
    locked does the edition matter: the verse grid disappears, the picked
    panel shows "✓ Hosea 4:6", and a translation switcher appears BELOW it —
    and only when more than one edition carries that exact verse.

    Crumb clicks DROP everything below them: tapping the book name clears the
    pick + switcher and returns to chapters; tapping "All books" clears the
    book from the crumbs too.

    Expects:
      $pickerTestaments  array  from TypingController::pickerTestaments() —
                                [{label, books: [{slug, name, label, color,
                                offset}]}]

    Contract (JS):

        const picker = MBVersePicker({
            root:         document.getElementById('vp-root'),
            outlineUrl:   '…/outline',              // ?translation= appended
            translations: [{slug, name}, …],        // priority order
            onPick:  function ({b, c, v, t, name, ref, txOptions}) { … },
                     // fires on verse pick AND again on switcher change
            onClear: function () { … },             // pick dropped (crumb nav)
        });
        picker.reset();
--}}

@once
<style>
    .vp { font-family: var(--sans); }

    /* ---- Crumbs --------------------------------------------------------- */
    .vp-crumbs {
        display: flex; align-items: center; gap: .35rem; flex-wrap: wrap;
        font-size: .8rem; color: var(--muted); margin-bottom: .8rem; min-height: 1.4em;
    }
    .vp-crumb {
        background: none; border: 0; padding: 0; cursor: pointer;
        font: inherit; color: var(--accent); font-weight: 600;
    }
    .vp-crumb:hover { text-decoration: underline; }
    .vp-crumb[disabled] { color: var(--muted); cursor: default; text-decoration: none; }
    .vp-crumb-sep { color: var(--rule); }

    /* ---- Screens: light slide-in on level change ------------------------ */
    .vp-screen { display: none; }
    .vp-screen.is-active { display: block; animation: vp-in .16s ease; }
    @keyframes vp-in {
        from { opacity: 0; transform: translateY(4px); }
        to   { opacity: 1; transform: none; }
    }
    @media (prefers-reduced-motion: reduce) { .vp-screen.is-active { animation: none; } }

    /* ---- Books: one flat canon-ordered grid per testament ---------------- */
    .vp-testament {
        font-family: var(--serif); font-size: 1.05rem; font-weight: 400;
        color: var(--ink); margin: 1rem 0 .5rem;
    }
    .vp-books .vp-testament:first-child { margin-top: 0; }
    .vp-book-grid {
        display: grid; gap: .4rem;
        grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
        margin-bottom: .6rem;
    }

    /* One chip style for every cell type; --bk carries the canon tint. */
    .vp-cell {
        font-family: var(--sans); font-size: .82rem; font-weight: 600;
        color: var(--ink); background: var(--bg);
        border: 1px solid var(--rule); border-left: 3px solid var(--bk, var(--rule));
        border-radius: 6px;
        padding: .45rem .5rem; cursor: pointer; text-align: left;
        transition: border-color .12s, background .12s, transform .14s ease, box-shadow .14s ease;
    }
    .vp-cell:hover {
        border-color: var(--bk, var(--accent));
        transform: scale(1.05);
        box-shadow: 0 3px 10px rgba(0,0,0,.08);
        z-index: 2; position: relative;
    }
    .vp-cell.soon {
        color: var(--soon); border-style: dashed; border-left-style: dashed;
        cursor: default; transform: none; box-shadow: none;
    }
    .vp-cell.soon:hover { border-color: var(--rule); transform: none; box-shadow: none; }
    @media (prefers-reduced-motion: reduce) { .vp-cell:hover { transform: none; } }

    /* ---- Chapter / verse grids: compact numeric cells ------------------- */
    .vp-num-grid {
        display: grid; gap: .4rem;
        grid-template-columns: repeat(auto-fill, minmax(52px, 1fr));
    }
    .vp-num-grid .vp-cell {
        text-align: center; padding: .55rem .3rem;
        border-left-width: 1px; border-left-color: var(--rule);
        font-variant-numeric: tabular-nums;
    }
    .vp-num-grid .vp-cell:hover { border-color: var(--accent); }

    .vp-level-label {
        font-size: .72rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .08em; color: var(--muted); margin: 0 0 .5rem;
    }

    /* ---- Picked panel: the ✓ line + the translation switcher ------------ */
    .vp-picked-ref {
        font-family: var(--serif);
        font-size: 1.35rem;            /* ← PICKED-REF SIZE — tweak me */
        color: var(--ink); margin: 0 0 .7rem;
    }
    .vp-picked-ref .vp-check { color: var(--accent); font-weight: 700; margin-right: .35rem; }

    /* Client-side twin of the site's translation switcher: abbreviation
       pills, active one filled. Only rendered when >1 edition carries the
       picked verse. */
    .vp-tx-switch { display: flex; gap: .4rem; flex-wrap: wrap; }
    .vp-tx {
        font-family: var(--sans); font-size: .78rem; font-weight: 700;
        letter-spacing: .04em;
        color: var(--muted); background: var(--bg);
        border: 1px solid var(--rule); border-radius: 999px;
        padding: .3rem .8rem; cursor: pointer;
        transition: color .12s, background .12s, border-color .12s;
    }
    .vp-tx:hover { color: var(--accent); border-color: var(--accent); }
    .vp-tx.is-active { color: #fff; background: var(--accent); border-color: var(--accent); }
</style>
@endonce

<div class="vp" id="vp-root">
    <div class="vp-crumbs" data-vp-crumbs></div>

    {{-- Screen 1: the whole canon, server-rendered once. One flat grid per
         testament — sections and subgroups share lines, QuickNav-style;
         each chip keeps its own section tint. --}}
    <div class="vp-screen vp-books is-active" data-vp-screen="books">
        @foreach ($pickerTestaments as $testament)
            <h4 class="vp-testament">{{ $testament['label'] }}</h4>
            <div class="vp-book-grid">
                @foreach ($testament['books'] as $bk)
                    <button type="button" class="vp-cell vp-book"
                            style="--bk:var(--tl-{{ $bk['color'] }})"
                            data-slug="{{ $bk['slug'] }}"
                            data-name="{{ $bk['name'] }}"
                            data-offset="{{ $bk['offset'] }}">{{ $bk['label'] }}</button>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Screens 2–4: filled by the module. --}}
    <div class="vp-screen" data-vp-screen="chapters">
        <p class="vp-level-label">Chapter</p>
        <div class="vp-num-grid" data-vp-chapter-grid></div>
    </div>
    <div class="vp-screen" data-vp-screen="verses">
        <p class="vp-level-label">Verse</p>
        <div class="vp-num-grid" data-vp-verse-grid></div>
    </div>
    <div class="vp-screen" data-vp-screen="picked">
        <p class="vp-picked-ref" data-vp-picked></p>
        <div class="vp-tx-switch" data-vp-tx hidden></div>
    </div>
</div>

@once
<script>
    /* MBVersePicker — see the contract in the partial's header comment. */
    window.MBVersePicker = function (opts) {
        'use strict';

        const root         = opts.root;
        const outlineUrl   = opts.outlineUrl;
        const TXS          = opts.translations || [];   // priority order
        const onPick       = opts.onPick  || function () {};
        const onClear      = opts.onClear || function () {};

        const crumbsEl = root.querySelector('[data-vp-crumbs]');
        const chGrid   = root.querySelector('[data-vp-chapter-grid]');
        const vGrid    = root.querySelector('[data-vp-verse-grid]');
        const pickedEl = root.querySelector('[data-vp-picked]');
        const txEl     = root.querySelector('[data-vp-tx]');

        const state = {
            data: {},              // txSlug → { bookSlug: {chapters:{n: count}} }
            ready: false,
            level: 'books',
            book: null,            // { slug, name, offset }
            chapter: null,
            pick: null,            // { b, c, v, t, name, ref, txOptions }
        };

        /* ---------------- outlines: every edition, up front ---------------- */
        Promise.all(TXS.map(function (tx) {
            return fetch(outlineUrl + '?translation=' + encodeURIComponent(tx.slug))
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    const map = {};
                    (j.books || []).forEach(function (b) { map[b.slug] = b; });
                    state.data[tx.slug] = map;
                })
                .catch(function () { state.data[tx.slug] = {}; });
        })).then(function () {
            state.ready = true;
            // Books no edition carries at all go dashed.
            root.querySelectorAll('.vp-book').forEach(function (btn) {
                const live = TXS.some(function (tx) { return !!state.data[tx.slug][btn.dataset.slug]; });
                btn.classList.toggle('soon', !live);
                btn.disabled = !live;
            });
        });

        /* ---------------- union helpers ---------------- */
        function chaptersUnion(slug) {
            const set = {};
            TXS.forEach(function (tx) {
                const bk = state.data[tx.slug][slug];
                if (!bk) return;
                Object.keys(bk.chapters).forEach(function (n) {
                    const count = bk.chapters[n];
                    set[n] = Math.max(set[n] || 0, count);
                });
            });
            return set;                    // { chapter: maxVerseCount }
        }
        function txsForVerse(b, c, v) {
            return TXS.filter(function (tx) {
                const bk = state.data[tx.slug][b];
                return bk && bk.chapters[c] && v <= bk.chapters[c];
            });
        }

        /* ---------------- navigation ---------------- */
        function show(level) {
            state.level = level;
            root.querySelectorAll('[data-vp-screen]').forEach(function (sc) {
                sc.classList.toggle('is-active', sc.dataset.vpScreen === level);
            });
            renderCrumbs();
        }

        // A crumb click DROPS everything below it: the pick always dies, and
        // state above the target level is cleared so the crumbs shrink too.
        function go(level) {
            clearPick();
            if (level === 'books') {
                state.book = null;
                state.chapter = null;
                show('books');
            } else if (level === 'chapters') {
                state.chapter = null;
                buildChapterGrid();
                show('chapters');
            } else if (level === 'verses') {
                buildVerseGrid();
                show('verses');
            }
        }

        function clearPick() {
            if (!state.pick) return;
            state.pick = null;
            txEl.hidden = true;
            txEl.innerHTML = '';
            onClear();
        }

        function renderCrumbs() {
            let html = crumb('books', 'All books', state.level !== 'books');
            if (state.book) {
                html += sep() + crumb('chapters', state.book.name, state.level !== 'chapters');
            }
            if (state.book && state.chapter !== null) {
                html += sep() + crumb('verses',
                    'Chapter ' + (state.chapter + state.book.offset),
                    state.level !== 'verses');
            }
            if (state.pick) {
                html += sep() + crumb('picked', 'Verse ' + state.pick.v, false);
            }
            crumbsEl.innerHTML = html;
        }
        function sep() { return '<span class="vp-crumb-sep">\u203A</span>'; }
        function crumb(level, label, clickable) {
            return '<button type="button" class="vp-crumb" data-vp-go="' + level + '"' +
                   (clickable ? '' : ' disabled') + '>' + escapeHtml(label) + '</button>';
        }
        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        crumbsEl.addEventListener('click', function (e) {
            const b = e.target.closest('[data-vp-go]');
            if (!b || b.disabled) return;
            go(b.dataset.vpGo);
        });

        /* ---------------- grids ---------------- */
        function buildChapterGrid() {
            const union = chaptersUnion(state.book.slug);
            chGrid.innerHTML = '';
            Object.keys(union).map(Number).sort(function (a, b) { return a - b; })
                .forEach(function (n) {
                    const cell = document.createElement('button');
                    cell.type = 'button';
                    cell.className = 'vp-cell';
                    cell.dataset.chapter = n;
                    cell.textContent = n + state.book.offset;
                    chGrid.appendChild(cell);
                });
        }

        function buildVerseGrid() {
            const count = chaptersUnion(state.book.slug)[state.chapter] || 0;
            vGrid.innerHTML = '';
            for (let v = 1; v <= count; v++) {
                const cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'vp-cell';
                cell.dataset.verse = v;
                cell.textContent = v;
                vGrid.appendChild(cell);
            }
        }

        root.querySelector('[data-vp-screen="books"]').addEventListener('click', function (e) {
            const btn = e.target.closest('.vp-book');
            if (!btn || btn.disabled || !state.ready) return;
            clearPick();
            state.book = {
                slug: btn.dataset.slug,
                name: btn.dataset.name,
                offset: parseInt(btn.dataset.offset, 10) || 0,
            };
            state.chapter = null;
            buildChapterGrid();
            show('chapters');
        });

        chGrid.addEventListener('click', function (e) {
            const cell = e.target.closest('.vp-cell');
            if (!cell) return;
            clearPick();
            state.chapter = parseInt(cell.dataset.chapter, 10);
            buildVerseGrid();
            show('verses');
        });

        vGrid.addEventListener('click', function (e) {
            const cell = e.target.closest('.vp-cell');
            if (!cell) return;
            lockPick(parseInt(cell.dataset.verse, 10));
        });

        /* ---------------- the pick + translation-last flow ---------------- */
        function lockPick(v) {
            const options = txsForVerse(state.book.slug, state.chapter, v);
            if (!options.length) return;               // union says it exists; belt & braces

            state.pick = {
                b: state.book.slug,
                c: state.chapter,
                v: v,
                t: options[0].slug,                    // priority edition first
                name: state.book.name,
                ref: state.book.name + ' ' + (state.chapter + state.book.offset) + ':' + v,
                txOptions: options,
            };

            pickedEl.innerHTML = '<span class="vp-check">\u2713</span>' + escapeHtml(state.pick.ref);
            renderTxSwitch(options);
            show('picked');
            onPick(state.pick);
        }

        function renderTxSwitch(options) {
            // The switcher only exists when there's a choice to make.
            if (options.length < 2) {
                txEl.hidden = true;
                txEl.innerHTML = '';
                return;
            }
            txEl.hidden = false;
            txEl.innerHTML = '';
            options.forEach(function (tx) {
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'vp-tx' + (tx.slug === state.pick.t ? ' is-active' : '');
                pill.textContent = tx.slug.toUpperCase();
                pill.title = tx.name;
                pill.addEventListener('click', function () {
                    state.pick.t = tx.slug;
                    txEl.querySelectorAll('.vp-tx').forEach(function (p) {
                        p.classList.toggle('is-active', p === pill);
                    });
                    onPick(state.pick);
                });
                txEl.appendChild(pill);
            });
        }

        /* ---------------- public API ---------------- */
        function reset() {
            clearPick();
            state.book = null;
            state.chapter = null;
            show('books');
        }

        renderCrumbs();
        return { reset: reset };
    };
</script>
@endonce
