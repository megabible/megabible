/* HEADed — Phase 3 (polish): read report, viewer, search, jump, cross-ref
   check, and the guarded write layer (edit / create / delete), now with
   clickable cross-ref links, an xref-only editor, a modal stale-file gate,
   and a last-modified readout. */
(function () {
    'use strict';

    const LS_KEY = 'mbHeaded.v1';
    const TITLE_KINDS  = ['s', 'ms', 'sr', 'sp', 'd'];
    const PRIMARY_RANK = { ms: 0, mr: 1, s: 2, sr: 3, r: 4, sp: 5, d: 6 };
    const ALL_KINDS    = ['s', 'ms', 'mr', 'r', 'sr', 'sp', 'd'];

    const $ = (id) => document.getElementById(id);
    const body       = document.body;
    const loadUrl    = body.dataset.loadUrl;
    const resolveUrl = body.dataset.resolveUrl;
    const writeUrl   = body.dataset.writeUrl;
    const csrf       = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    let state = {
        data: null,
        mtime: 0,
        lineByN: {},
        rowEls: {},
        groups: {},
        xrefBad: {},
        activeGroup: null,
        activeLine: null,
        staleOpen: false,       // is the stale-file modal already showing?
    };

    function el(tag, cls, txt) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt != null) n.textContent = txt;
        return n;
    }
    function esc(s) {
        return String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    }
    function renderRaw(raw) {
        return esc(raw).replace(/[\t ]/g, (ch) =>
            ch === '\t' ? '<span class="wst">→</span>' : '<span class="wss">·</span>'
        );
    }
    let toastTimer = null;
    function toast(msg) {
        let t = $('toast');
        if (!t) { t = el('div'); t.id = 'toast'; body.appendChild(t); }
        t.textContent = msg;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { if (t.parentNode) t.parentNode.removeChild(t); }, 2600);
    }

    // ======================================================================
    //  LOAD SCREEN
    // ======================================================================
    const $path = $('path'), $set = $('set'), $load = $('load'), $report = $('report');

    try {
        const saved = JSON.parse(localStorage.getItem(LS_KEY) || '{}');
        if (saved.path) $path.value = saved.path;
        if (saved.set)  $set.value  = saved.set;
    } catch (e) { /* ignore */ }

    function card(title, flag) {
        const c = el('div', 'card' + (flag ? ' flag' : ''));
        if (title) c.appendChild(el('h2', null, title));
        return c;
    }
    function stat(n, k, tone) {
        const s = el('div', 'stat' + (tone ? ' ' + tone : ''));
        s.appendChild(el('div', 'n', String(n)));
        s.appendChild(el('div', 'k', k));
        return s;
    }
    function renderError(msg) {
        $report.innerHTML = '';
        const c = card('Could not load');
        c.appendChild(el('div', 'err', msg));
        $report.appendChild(c);
    }

    function renderReport(data) {
        $report.innerHTML = '';
        const c = data.counts;

        const sum = card(null);
        const w = el('div', 'summary');
        w.appendChild(stat(c.rows, 'rows to insert'));
        w.appendChild(stat(data.books.length, 'books in scope'));
        w.appendChild(stat(data.set, 'set key'));
        if (data.dupes.length)      w.appendChild(stat(data.dupes.length, 'duplicates', 'warn'));
        if (data.xref_order.length)    w.appendChild(stat(data.xref_order.length, 'xref order issues', 'warn'));
        if ((data.mirror_issues || []).length) w.appendChild(stat(data.mirror_issues.length, 'xrefs missing mirror', 'warn'));
        if (c.skipped_set)  w.appendChild(stat(c.skipped_set,  'other-set rows'));
        if (c.skipped_book) w.appendChild(stat(c.skipped_book, 'unknown books', 'bad'));
        if (c.skipped_kind) w.appendChild(stat(c.skipped_kind, 'unknown kinds', 'bad'));
        if (c.skipped_bad)  w.appendChild(stat(c.skipped_bad,  'bad rows', 'bad'));
        sum.appendChild(w);
        $report.appendChild(sum);

        const bc = card('Books in scope (canon order)');
        const bl = el('div', 'books');
        data.books.forEach((b, i) => {
            if (i) bl.appendChild(document.createTextNode('   '));
            bl.appendChild(el('b', null, b.name));
            bl.appendChild(document.createTextNode(' (' + b.count + ')'));
        });
        bc.appendChild(bl);
        $report.appendChild(bc);

        const dc = card('Duplicate rows', data.dupes.length > 0);
        if (!data.dupes.length) {
            dc.appendChild(el('div', 'pass', 'None — no two rows share an anchor.'));
        } else {
            const list = el('div', 'rows');
            data.dupes.forEach((d) => {
                const r = el('div', 'r');
                r.appendChild(el('span', 'lref', 'line ' + d.line));
                r.appendChild(document.createTextNode(' duplicates '));
                r.appendChild(el('span', 'lref', 'line ' + d.first_line));
                r.appendChild(document.createTextNode('  '));
                r.appendChild(el('span', 'key', '[' + d.key + ']'));
                list.appendChild(r);
            });
            dc.appendChild(list);
            dc.appendChild(el('div', 'hint', 'The importer keeps the FIRST and drops the rest.'));
        }
        $report.appendChild(dc);

        const xc = card('Cross-Ref Canon Order Check', data.xref_order.length > 0);
        if (!data.xref_order.length) {
            xc.appendChild(el('div', 'pass', 'Clean — every cross-reference lists its books in canon order.'));
        } else {
            const list = el('div', 'rows');
            data.xref_order.forEach((x) => {
                const r = el('div', 'r');
                const head = el('div');
                head.appendChild(el('span', 'lref', 'line ' + x.line + '  '));
                head.appendChild(el('span', 'key', x.book + ' ' + x.chapter + ':' + x.before + '  '));
                head.appendChild(el('span', 'aux', x.text));
                r.appendChild(head);
                r.appendChild(el('div', 'issue-detail', x.detail));
                list.appendChild(r);
            });
            xc.appendChild(list);
            xc.appendChild(el('div', 'hint', 'Books listed out of the site’s canon order (Tanakh order for the OT), or a book name we couldn’t recognize.'));
        }
        $report.appendChild(xc);

        const mc = card('Cross-Ref Mirror Check', (data.mirror_issues || []).length > 0);
        if (!(data.mirror_issues || []).length) {
            mc.appendChild(el('div', 'pass', 'Clean — every cross-reference has a matching mirror.'));
        } else {
            const list = el('div', 'rows');
            data.mirror_issues.forEach((x) => {
                const r = el('div', 'r');
                const head = el('div');
                head.appendChild(el('span', 'lref', 'line ' + x.line + '  '));
                head.appendChild(el('span', 'key', x.book + ' ' + x.chapter + ':' + x.before + '  '));
                head.appendChild(el('span', 'aux', x.text));
                r.appendChild(head);
                r.appendChild(el('div', 'issue-detail bad', 'Mirror not found: ' + x.missing));
                list.appendChild(r);
            });
            mc.appendChild(list);
            mc.appendChild(el('div', 'hint', 'No reciprocal cross-reference (matching book + chapter) exists at the far end. Verses are ignored.'));
        }
        $report.appendChild(mc);

        if (data.warnings.length) {
            const wc = card('Skipped rows', true);
            const list = el('div', 'rows');
            data.warnings.forEach((wr) => list.appendChild(el('div', 'r', wr)));
            wc.appendChild(list);
            $report.appendChild(wc);
        }

        const foot = card(null);
        const open = el('button', 'btn primary', 'Open editor  →');
        open.addEventListener('click', openEditor);
        foot.appendChild(open);
        $report.appendChild(foot);
    }

    async function fetchLoad(path, set) {
        const url = loadUrl + '?path=' + encodeURIComponent(path) + '&set=' + encodeURIComponent(set);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        return res.json();
    }

    async function doLoad() {
        const path = $path.value.trim();
        const set  = $set.value.trim();
        if (!path) { renderError('Enter a file path first.'); return; }

        $load.disabled = true;
        $load.textContent = 'Loading…';
        $report.innerHTML = '';

        try {
            const data = await fetchLoad(path, set);
            if (!data.ok) { renderError(data.error || 'Unknown error.'); return; }
            localStorage.setItem(LS_KEY, JSON.stringify({ path, set }));
            state.data = data;
            state.mtime = data.mtime;
            renderReport(data);
        } catch (e) {
            renderError('Request failed: ' + e.message);
        } finally {
            $load.disabled = false;
            $load.textContent = 'Load';
        }
    }

    $load.addEventListener('click', doLoad);
    [$path, $set].forEach((inp) =>
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLoad(); })
    );

    // ======================================================================
    //  EDITOR
    // ======================================================================
    const $editor  = $('editor');
    const $loadScr = $('loadScreen');
    const $viewer  = $('viewer');
    const $detail  = $('detail');
    const $q       = $('q');
    const $results = $('results');
    const $jump    = $('jump');
    const $jumpBtn = $('jumpBtn');
    const $jumpMsg = $('jumpmsg');

    $('backToLoad').addEventListener('click', () => {
        $editor.hidden = true;
        $loadScr.hidden = false;
    });

    function fmtTime(unix) {
        if (!unix) return '';
        const d = new Date(unix * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' '
             + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function mountEditor(data) {
        $('filecrumb').textContent  = data.path;
        $('countcrumb').textContent =
            data.counts.rows + ' rows · ' + data.books.length + ' books · ' + data.set +
            ' · modified ' + fmtTime(data.mtime);
                state.xrefBad = {};
        state.reorderable = {};
        (data.xref_order || []).forEach((x) => {
            state.xrefBad[x.line] = x.detail;
            if (x.reorderable) state.reorderable[x.line] = x.expect ? x.expect : {
                osis: x.osis, chapter: x.chapter, before: x.before, kind: null, level: null, text: x.text,
            };
        });
        state.mirrorMissing = data.mirror_missing || {};
        buildIndexes(data);
        buildViewer(data);
    }

    function openEditor() {
        $loadScr.hidden = true;
        $editor.hidden = false;
        mountEditor(state.data);
        state.activeGroup = null;
        state.activeLine = null;
        renderDetail(null);
        $q.value = ''; $results.innerHTML = '';
        $jump.value = ''; $jumpMsg.textContent = '';
    }

    function buildIndexes(data) {
        state.lineByN = {};
        state.groups = {};
        data.lines.forEach((ln) => {
            state.lineByN[ln.n] = ln;
            if (ln.type === 'data' && ln.gkey) {
                let g = state.groups[ln.gkey];
                if (!g) g = state.groups[ln.gkey] = { lines: [], osis: ln.osis, chapter: ln.chapter, before: ln.before };
                g.lines.push(ln.n);
            }
        });
    }

    function buildViewer(data) {
        let html = '';
        data.lines.forEach((ln) => {
            const cls = ['ln'];
            if (ln.type !== 'data') {
                cls.push(ln.type);
            } else {
                if (ln.gkey)                    cls.push('group');
                if (ln.other)                   cls.push('other');
                if (ln.invalid)                 cls.push('invalid');
                if (ln.dupe)                    cls.push('dupe');
                if (state.xrefBad[ln.n])        cls.push('xref-bad');
                if (state.mirrorMissing[ln.n])  cls.push('mirror-bad');
            }
            const gattr = (ln.type === 'data' && ln.gkey) ? ' data-g="' + esc(ln.gkey) + '"' : '';
            html += '<div class="' + cls.join(' ') + '" data-n="' + ln.n + '"' + gattr + '>'
                  +   '<span class="gutter">' + ln.n + '</span>'
                  +   '<span class="content">' + renderRaw(ln.raw) + '</span>'
                  + '</div>';
        });
        $viewer.innerHTML = html;
        state.rowEls = {};
        $viewer.querySelectorAll('.ln').forEach((n) => { state.rowEls[n.dataset.n] = n; });
    }

    $viewer.addEventListener('mouseover', (e) => {
        const row = e.target.closest('.ln.group');
        clearHover();
        if (row) setGroupClass(row.dataset.g, 'ghover', true);
    });
    $viewer.addEventListener('mouseleave', clearHover);
    $viewer.addEventListener('click', (e) => {
        const row = e.target.closest('.ln.group');
        if (!row) return;
        activate(row.dataset.g, parseInt(row.dataset.n, 10), false);
    });

    function clearHover() {
        $viewer.querySelectorAll('.ln.ghover').forEach((n) => n.classList.remove('ghover'));
    }
    function setGroupClass(gkey, cls, on) {
        const g = state.groups[gkey];
        if (!g) return;
        g.lines.forEach((n) => {
            const eln = state.rowEls[n];
            if (eln) eln.classList.toggle(cls, on);
        });
    }

    function activate(gkey, lineN, center) {
        if (state.activeGroup && state.activeGroup !== gkey) setGroupClass(state.activeGroup, 'gactive', false);
        if (state.activeLine != null && state.rowEls[state.activeLine]) state.rowEls[state.activeLine].classList.remove('line-active');

        state.activeGroup = gkey;
        setGroupClass(gkey, 'gactive', true);

        const g = state.groups[gkey];
        if (!g) { renderDetail(null); return; }
        if (lineN == null) lineN = primaryLine(g);
        state.activeLine = lineN;
        const activeEl = state.rowEls[lineN];
        if (activeEl) {
            activeEl.classList.add('line-active');
            if (center) activeEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        renderDetail(gkey);
    }

    function primaryLine(g) {
        let best = g.lines[0], bestRank = 99;
        g.lines.forEach((n) => {
            const ln = state.lineByN[n];
            const rank = PRIMARY_RANK[ln.kind] != null ? PRIMARY_RANK[ln.kind] : 99;
            if (rank < bestRank) { bestRank = rank; best = n; }
        });
        return best;
    }

    // ---- Detail: read view ----------------------------------------------
    function renderDetail(gkey) {
        $detail.innerHTML = '';
        if (!gkey || !state.groups[gkey]) {
            $detail.appendChild(el('div', 'detail-empty', 'Select a heading to see its details.'));
            return;
        }
        const g = state.groups[gkey];
        const rowsAll = g.lines.map((n) => state.lineByN[n]);
        const heads = rowsAll.filter((r) => r.kind !== 'r' && r.kind !== 'mr');
        const xrefs = rowsAll.filter((r) => r.kind === 'r' || r.kind === 'mr');
        const primary = state.lineByN[primaryLine(g)];

        $detail.appendChild(el('div', 'ref-big', primary.book + ' ' + g.chapter + ':' + g.before));
        $detail.appendChild(el('div', 'ref-sub', primary.osis + ' · ' + state.data.set));

        if (heads.length) {
            heads.forEach((h) => $detail.appendChild(headBlock(h, xrefs.length > 0)));
        } else {
            const note = el('div', 'head-block');
            note.appendChild(el('div', 'field', 'No section heading here (cross-reference only).'));
            $detail.appendChild(note);
        }

        $detail.appendChild(el('div', 'sub-head', 'Cross-reference'));
        if (xrefs.length) {
            xrefs.forEach((x) => $detail.appendChild(xrefBlock(x)));
        } else {
            $detail.appendChild(el('div', 'xref none', 'No cross-reference for this verse.'));
            const create = el('button', 'btn small', '+ Create cross-reference');
            create.style.marginTop = '10px';
            create.addEventListener('click', () => startCreateXref(g));
            $detail.appendChild(create);
        }

        // New-heading — its own section, below the cross-ref block.
        const nh = el('div', 'newheading');
        const newBtn = el('button', 'btn small', '+ New heading');
        newBtn.addEventListener('click', () => startCreate({ osis: g.osis, chapter: g.chapter, before: g.before, kind: 's', title: 'New heading' }));
        nh.appendChild(newBtn);
        $detail.appendChild(nh);
    }

    function headBlock(h, groupHasXref) {
        const b = el('div', 'head-block');
        b.appendChild(field('Kind', kindLabel(h.kind) + '  ', badge('level ' + h.level)));
        b.appendChild(field('Text', h.text, null, true));
        b.appendChild(field('Source', h.source || '— (set default)'));
        const tools = el('div', 'head-actions');
        const edit = el('button', 'btn small', 'Edit');
        edit.addEventListener('click', () => startEdit(h));
        const del = el('button', 'btn small danger', 'Delete');
        del.addEventListener('click', () => confirmDelete(h, groupHasXref));
        tools.appendChild(edit); tools.appendChild(del);
        b.appendChild(tools);
        return b;
    }

    function xrefBlock(x) {
        const b = el('div', 'head-block');

        // The ref text, with a green check appended when fully mirrored.
        const links = xrefLinks(x.text);
        if (!state.mirrorMissing[x.n]) {
            links.appendChild(el('span', 'mirror-ok', '✓'));
        }
        b.appendChild(links);

        // Canon-order note + Canonize Order button.
        if (state.xrefBad[x.n]) {
            b.appendChild(el('div', 'xref-note', state.xrefBad[x.n]));
            if (state.reorderable[x.n]) {
                const cz = el('button', 'btn small canonize', 'Canonize Order');
                cz.addEventListener('click', () => canonizeOrder(x));
                b.appendChild(cz);
            }
        }

        // Mirror-missing note.
        if (state.mirrorMissing[x.n]) {
            b.appendChild(el('div', 'mirror-note', 'XREF mirror not found: ' + state.mirrorMissing[x.n]));
        }

        b.appendChild(field('Kind', kindLabel(x.kind) + '  ', badge('level ' + x.level)));
        b.appendChild(field('Source', x.source || '— (set default)'));

        const tools = el('div', 'row-tools');
        const edit = el('button', 'btn small', 'Edit');
        edit.addEventListener('click', () => startEditXref(x));
        const del = el('button', 'btn small danger', 'Delete');
        del.addEventListener('click', () => confirmDelete(x, false));
        tools.appendChild(edit); tools.appendChild(del);
        b.appendChild(tools);
        return b;
    }

    // Turn "(John 1:1–5; Hebrews 11:1–3)" into clickable jump links.
    function xrefLinks(text) {
        const wrap = el('div', 'xreflinks');
        const inner = text.replace(/^\(/, '').replace(/\)$/, '');
        wrap.appendChild(document.createTextNode('('));
        inner.split(';').forEach((seg, i) => {
            if (i) wrap.appendChild(el('span', 'xref-plain', '; '));
            const s = seg.trim();
            const a = el('span', 'xreflink', s);
            a.addEventListener('click', () => jumpToRef(s));
            wrap.appendChild(a);
        });
        wrap.appendChild(document.createTextNode(')'));
        return wrap;
    }

    function field(k, text, extra, isText) {
        const f = el('div', 'field');
        f.appendChild(el('div', 'k', k));
        const val = el('div', 'v' + (isText ? ' text' : ''));
        val.appendChild(document.createTextNode(text));
        if (extra) val.appendChild(extra);
        f.appendChild(val);
        return f;
    }
    function badge(t) { return el('span', 'badge', t); }
    function kindLabel(k) {
        return ({ s: 'Section (s)', ms: 'Major section (ms)', mr: 'Major ref (mr)',
                  r: 'Reference (r)', sr: 'Section ref (sr)', sp: 'Speaker (sp)', d: 'Descriptive (d)' }[k]) || k;
    }

    // ---- Forms: full (section) vs. xref-only ----------------------------
    function startEdit(row) {
        replaceDetailWithForm(fullForm({
            title: 'Edit heading',
            action: 'edit',
            line: row.n,
            expect: { osis: row.osis, chapter: row.chapter, before: row.before, kind: row.kind, level: row.level, text: row.text },
            values: { osis: row.osis, chapter: row.chapter, before: row.before, kind: row.kind, level: row.level, text: row.text, source: row.source },
        }));
    }
    function startCreate(opts) {
        replaceDetailWithForm(fullForm({
            title: opts.title || 'New heading',
            action: 'create',
            values: { osis: opts.osis, chapter: opts.chapter, before: opts.before, kind: opts.kind || 's', level: 1, text: '', source: '' },
        }));
    }
    function startEditXref(row) {
        replaceDetailWithForm(xrefForm({
            title: 'Edit cross-reference',
            action: 'edit',
            line: row.n,
            expect: { osis: row.osis, chapter: row.chapter, before: row.before, kind: row.kind, level: row.level, text: row.text },
            fixed: { osis: row.osis, chapter: row.chapter, before: row.before, kind: row.kind, level: row.level },
            values: { text: row.text, source: row.source },
        }));
    }
    function startCreateXref(g) {
        replaceDetailWithForm(xrefForm({
            title: 'New cross-reference',
            action: 'create',
            fixed: { osis: g.osis, chapter: g.chapter, before: g.before, kind: 'r', level: 1 },
            values: { text: '', source: inheritSource(g) },
        }));
    }

    // Inherit source_key from an existing row at this anchor, if any.
    function inheritSource(g) {
        for (const n of g.lines) {
            const ln = state.lineByN[n];
            if (ln.source) return ln.source;
        }
        return '';
    }

    function replaceDetailWithForm(form) {
        $detail.innerHTML = '';
        const g = state.groups[state.activeGroup];
        if (g) {
            const primary = state.lineByN[primaryLine(g)];
            $detail.appendChild(el('div', 'ref-big', primary.book + ' ' + g.chapter + ':' + g.before));
            $detail.appendChild(el('div', 'ref-sub', primary.osis + ' · ' + state.data.set));
        }
        $detail.appendChild(form);
    }

    function bookOptions(selectedOsis) {
        const names = state.data.book_names || {};
        const pos = state.data.canon_pos || {};
        const list = Object.keys(names).map((osis) => ({ osis, name: names[osis], pos: pos[osis] != null ? pos[osis] : 1e9 }));
        list.sort((a, b) => a.pos - b.pos);
        const sel = el('select');
        list.forEach((b) => {
            const o = el('option', null, b.name + '  (' + b.osis + ')');
            o.value = b.osis;
            if (b.osis === selectedOsis) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function sourceField(value) {
        const wrap = el('div', 'full');
        wrap.appendChild(el('label', null, 'Source key (blank = set default)'));
        const inp = el('input'); inp.type = 'text'; inp.value = value || '';
        inp.setAttribute('list', 'source-keys');
        wrap.appendChild(inp);
        let dl = $('source-keys');
        if (!dl) {
            dl = el('datalist'); dl.id = 'source-keys';
            (state.data.source_keys || []).forEach((k) => { const o = el('option'); o.value = k; dl.appendChild(o); });
            body.appendChild(dl);
        }
        return { wrap, inp };
    }

    function textField(value) {
        const wrap = el('div', 'full');
        wrap.appendChild(el('label', null, 'Text'));
        const inp = el('textarea'); inp.value = value;
        wrap.appendChild(inp);
        return { wrap, inp };
    }

    // Full form — section headings, all fields editable.
    function fullForm(opts) {
        const v = opts.values;
        const wrap = el('div', 'hform');
        wrap.appendChild(el('h3', null, opts.title));
        const grid = el('div', 'grid');

        const bookWrap = el('div', 'full');
        bookWrap.appendChild(el('label', null, 'Book'));
        const bookSel = bookOptions(v.osis);
        bookWrap.appendChild(bookSel);
        grid.appendChild(bookWrap);

        const chWrap = el('div');
        chWrap.appendChild(el('label', null, 'Chapter'));
        const chIn = el('input', 'num'); chIn.type = 'number'; chIn.min = '1'; chIn.value = v.chapter;
        chWrap.appendChild(chIn); grid.appendChild(chWrap);

        const bvWrap = el('div');
        bvWrap.appendChild(el('label', null, 'Before verse'));
        const bvIn = el('input', 'num'); bvIn.type = 'number'; bvIn.min = '1'; bvIn.value = v.before;
        bvWrap.appendChild(bvIn); grid.appendChild(bvWrap);

        const kWrap = el('div');
        kWrap.appendChild(el('label', null, 'Kind'));
        const kSel = el('select');
        ALL_KINDS.forEach((k) => { const o = el('option', null, k); o.value = k; if (k === v.kind) o.selected = true; kSel.appendChild(o); });
        kWrap.appendChild(kSel); grid.appendChild(kWrap);

        const lWrap = el('div');
        lWrap.appendChild(el('label', null, 'Level'));
        const lIn = el('input', 'num'); lIn.type = 'number'; lIn.min = '1'; lIn.value = v.level;
        lWrap.appendChild(lIn); grid.appendChild(lWrap);

        const t = textField(v.text); grid.appendChild(t.wrap);
        const s = sourceField(v.source); grid.appendChild(s.wrap);
        wrap.appendChild(grid);

        const errBox = el('div', 'ferr'); wrap.appendChild(errBox);

        function refreshWarn() {
            const existing = wrap.querySelector('.fwarn');
            if (existing) existing.remove();
            const k = kSel.value;
            if ((k === 'r' || k === 'mr') && opts.action === 'create') {
                const g = state.groups[state.activeGroup];
                const hasSection = g && g.lines.some((n) => { const ln = state.lineByN[n]; return ln.kind !== 'r' && ln.kind !== 'mr'; });
                if (!hasSection) wrap.appendChild(el('div', 'fwarn', 'Note: this verse has no section heading. Cross-references normally sit under one.'));
            }
        }
        kSel.addEventListener('change', refreshWarn);
        refreshWarn();

        const actions = el('div', 'actions');
        const confirm = el('button', 'btn primary', opts.action === 'edit' ? 'Confirm changes' : 'Create');
        const cancel  = el('button', 'btn', 'Cancel');
        confirm.addEventListener('click', () => {
            errBox.textContent = '';
            const row = {
                osis: bookSel.value,
                chapter: parseInt(chIn.value, 10) || 0,
                before: parseInt(bvIn.value, 10) || 0,
                kind: kSel.value,
                level: parseInt(lIn.value, 10) || 1,
                text: t.inp.value.trim(),
                source: s.inp.value.trim(),
            };
            const bad = validateFields(row);
            if (bad) { errBox.textContent = bad; return; }
            confirm.disabled = true; confirm.textContent = 'Saving…';
            const op = opts.action === 'edit'
                ? { action: 'edit', line: opts.line, expect: opts.expect, row }
                : { action: 'create', row };
            submitOp(op, errBox, () => { confirm.disabled = false; confirm.textContent = opts.action === 'edit' ? 'Confirm changes' : 'Create'; });
        });
        cancel.addEventListener('click', () => renderDetail(state.activeGroup));
        actions.appendChild(confirm); actions.appendChild(cancel);
        wrap.appendChild(actions);
        return wrap;
    }

    // Xref-only form — anchor/kind/level are fixed, only text + source show.
    function xrefForm(opts) {
        const v = opts.values;
        const f = opts.fixed;
        const wrap = el('div', 'hform');
        wrap.appendChild(el('h3', null, opts.title));
        const grid = el('div', 'grid');

        const t = textField(v.text); grid.appendChild(t.wrap);
        const s = sourceField(v.source); grid.appendChild(s.wrap);
        wrap.appendChild(grid);

        const errBox = el('div', 'ferr'); wrap.appendChild(errBox);

        const actions = el('div', 'actions');
        const confirm = el('button', 'btn primary', opts.action === 'edit' ? 'Confirm changes' : 'Create');
        const cancel  = el('button', 'btn', 'Cancel');
        confirm.addEventListener('click', () => {
            errBox.textContent = '';
            const row = {
                osis: f.osis, chapter: f.chapter, before: f.before, kind: f.kind, level: f.level,
                text: t.inp.value.trim(), source: s.inp.value.trim(),
            };
            const bad = validateFields(row);
            if (bad) { errBox.textContent = bad; return; }
            confirm.disabled = true; confirm.textContent = 'Saving…';
            const op = opts.action === 'edit'
                ? { action: 'edit', line: opts.line, expect: opts.expect, row }
                : { action: 'create', row };
            submitOp(op, errBox, () => { confirm.disabled = false; confirm.textContent = opts.action === 'edit' ? 'Confirm changes' : 'Create'; });
        });
        cancel.addEventListener('click', () => renderDetail(state.activeGroup));
        actions.appendChild(confirm); actions.appendChild(cancel);
        wrap.appendChild(actions);
        return wrap;
    }

    function validateFields(row) {
        if (!row.text) return 'Text cannot be empty.';
        if (/[\t\r\n]/.test(row.text)) return 'Text cannot contain tabs or line breaks.';
        if (row.chapter < 1 || row.before < 1) return 'Chapter and verse must be 1 or greater.';
        return null;
    }

    function confirmDelete(row, groupHasXref) {
        let msg = 'Delete this ' + row.kind + '/' + row.level + ' heading?\n\n"' + row.text + '"';
        if (row.kind !== 'r' && row.kind !== 'mr' && groupHasXref) {
            msg += '\n\nThis verse still has a cross-reference. Deleting the section heading will leave it orphaned.';
        }
        if (!window.confirm(msg)) return;
        submitOp({
            action: 'delete',
            line: row.n,
            expect: { osis: row.osis, chapter: row.chapter, before: row.before, kind: row.kind, level: row.level, text: row.text },
        });
    }

    function canonizeOrder(x) {
        submitOp({
            action: 'reorder',
            line: x.n,
            expect: { osis: x.osis, chapter: x.chapter, before: x.before, kind: x.kind, level: x.level, text: x.text },
        });
    }    

    // ---- The one write path ---------------------------------------------
    async function submitOp(op, errBox, done) {
        try {
            const res = await fetch(writeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({ path: state.data.path, set: state.data.set, mtime: state.mtime, op }),
            });
            const data = await res.json();

            if (!data.ok) {
                if (data.stale) { openStaleModal(); return; }
                if (errBox) errBox.textContent = data.error || 'Write failed.';
                else toast(data.error || 'Write failed.');
                return;
            }

            state.data = data;
            state.mtime = data.mtime;
            mountEditor(data);
            if (data.activate) {
                const gk = data.activate.osis + '|' + data.activate.chapter + '|' + data.activate.before;
                if (state.groups[gk]) { activate(gk, primaryLine(state.groups[gk]), true); toast('Saved.'); return; }
            }
            state.activeGroup = null; state.activeLine = null;
            renderDetail(null);
            toast('Saved.');
        } catch (e) {
            if (errBox) errBox.textContent = 'Request failed: ' + e.message;
            else toast('Request failed.');
        } finally {
            if (done) done();
        }
    }

    // ---- Stale-file gate (modal) ----------------------------------------
    function openStaleModal() {
        if (state.staleOpen) return;
        state.staleOpen = true;

        const back = el('div', 'modal-back');
        const box  = el('div', 'modal');
        box.appendChild(el('h3', null, 'File changed on disk'));
        box.appendChild(el('p', null,
            'This TSV was modified by another program since HEADed loaded it. ' +
            'HEADed needs to refresh before you can continue. Any unsaved edit in the panel will be discarded.'));
        const actions = el('div', 'actions');
        const reload = el('button', 'btn primary', 'Refresh now');
        reload.addEventListener('click', async () => {
            back.remove();
            state.staleOpen = false;
            await reloadFromDisk();
            toast('Reloaded from disk.');
        });
        actions.appendChild(reload);
        box.appendChild(actions);
        back.appendChild(box);
        body.appendChild(back);
    }

    async function reloadFromDisk() {
        try {
            const data = await fetchLoad(state.data.path, state.data.set);
            if (data && data.ok) {
                const keep = state.activeGroup;
                state.data = data; state.mtime = data.mtime;
                mountEditor(data);
                if (keep && state.groups[keep]) activate(keep, primaryLine(state.groups[keep]), true);
                else { state.activeGroup = null; state.activeLine = null; renderDetail(null); }
            }
        } catch (e) { toast('Reload failed.'); }
    }

    // Quietly check for external changes when returning to the tab, so you
    // learn about them on focus, not only when you try to save.
    async function checkStaleOnFocus() {
        if (!state.data || $editor.hidden || state.staleOpen) return;
        try {
            const data = await fetchLoad(state.data.path, state.data.set);
            if (data && data.ok && data.mtime !== state.mtime) openStaleModal();
        } catch (e) { /* ignore */ }
    }
    window.addEventListener('focus', checkStaleOnFocus);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkStaleOnFocus(); });

    // ---- Heading search -------------------------------------------------
    let searchTimer = null;
    $q.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 80);
    });
    function normalize(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function runSearch() {
        const term = normalize($q.value.trim());
        $results.innerHTML = '';
        if (term === '') return;
        const hits = [];
        state.data.lines.forEach((ln) => {
            if (ln.type !== 'data' || ln.other || !ln.gkey) return;
            if (TITLE_KINDS.indexOf(ln.kind) === -1) return;
            if (normalize(ln.text).indexOf(term) === -1) return;
            if (hits.length <= 200) hits.push(ln);
        });
        if (!hits.length) { $results.appendChild(el('div', 'none', 'No headings match.')); return; }
        hits.forEach((ln) => {
            const hit = el('div', 'hit');
            hit.appendChild(el('span', 'ref', ln.book + ' ' + ln.chapter + ':' + ln.before + '  '));
            hit.appendChild(el('span', 'txt', ln.text));
            hit.addEventListener('click', () => activate(ln.gkey, ln.n, true));
            $results.appendChild(hit);
        });
    }

    // ---- Reference jump (box + xref links share jumpToRef) --------------
    $jumpBtn.addEventListener('click', () => jumpFromBox());
    $jump.addEventListener('keydown', (e) => { if (e.key === 'Enter') jumpFromBox(); });

    function jumpFromBox() {
        const q = $jump.value.trim();
        $jumpMsg.className = 'jumpmsg';
        if (!q) return;
        jumpToRef(q, (msg, bad) => { $jumpMsg.textContent = msg || ''; $jumpMsg.className = 'jumpmsg' + (bad ? ' bad' : ''); });
    }

    async function jumpToRef(q, report) {
        let target;
        try {
            const res = await fetch(resolveUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
            target = await res.json();
        } catch (e) { if (report) report('Lookup failed.', true); return; }
        if (!target.ok) { if (report) report('Not a recognizable reference.', true); else toast('Couldn’t resolve: ' + q); return; }
        landOn(target, report);
    }

    function landOn(t, report) {
        const say = report || (() => {});
        const inBook = [];
        for (const gkey in state.groups) if (state.groups[gkey].osis === t.osis) inBook.push(state.groups[gkey]);

        if (!inBook.length) {
            scrollToCanonSlot(t.osis);
            say(t.name + ' has no headings in this file yet.', false);
            return;
        }
        const verse = t.verse || 1;
        let pick = null;
        if (t.chapter == null) {
            pick = firstInBook(inBook);
        } else {
            const inCh = inBook.filter((g) => g.chapter === t.chapter);
            if (inCh.length) pick = inCh.find((g) => g.before === verse) || nearestBefore(inCh, verse) || sortByAnchor(inCh)[0];
            else pick = nearestChapter(inBook, t.chapter) || firstInBook(inBook);
        }
        if (pick) {
            const gkey = pick.osis + '|' + pick.chapter + '|' + pick.before;
            activate(gkey, primaryLine(pick), true);
            const exact = (t.chapter == null) || (pick.chapter === t.chapter && pick.before === verse);
            say(exact ? '' : 'Nearest heading: ' + pick.chapter + ':' + pick.before + '.', false);
        }
    }
    function sortByAnchor(gs) { return gs.slice().sort((a, b) => a.chapter - b.chapter || a.before - b.before); }
    function firstInBook(gs) { return sortByAnchor(gs)[0]; }
    function nearestBefore(gs, verse) { return gs.filter((g) => g.before <= verse).sort((a, b) => b.before - a.before)[0] || null; }
    function nearestChapter(gs, chapter) { return gs.filter((g) => g.chapter <= chapter).sort((a, b) => b.chapter - a.chapter || b.before - a.before)[0] || null; }
    function scrollToCanonSlot(osis) {
        const targetPos = state.data.canon_pos[osis];
        if (targetPos == null) return;
        const lines = state.data.lines;
        for (let i = 0; i < lines.length; i++) {
            const ln = lines[i];
            if (ln.type === 'data' && ln.pos != null && ln.pos >= targetPos) {
                const eln = state.rowEls[ln.n];
                if (eln) eln.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
        }
        $viewer.scrollTop = $viewer.scrollHeight;
    }
})();