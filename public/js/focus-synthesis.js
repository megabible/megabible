/* ======================================================================
   FOCUS & SYNTHESIS MODE                       public/js/focus-synthesis.js
   ----------------------------------------------------------------------
   Vanilla JS, module pattern (matches the rest of the codebase — no Alpine
   or Tailwind in this build). One IIFE owns all the state and behaviour.

   LOADED BY resources/views/bible/chapter.blade.php via a deferred
   <script src>. The Blade computes the page context server-side and hands
   it over as window.MBFocusContext in a tiny inline bridge that runs
   before this file executes (inline scripts run during parse; deferred
   scripts run after). This file never reads Blade variables directly.

   State 0  Reading        — nothing selected.
   State 1  Focus          — 1+ verses selected via tap; selected verses are
                              highlighted, the rest stay fully readable.
   State 3  Synthesis       — the study board overlay of selected verses.

   A single shared link (?v=16) highlights and scrolls to that verse. Two or
   more verses (?v=3,8) open straight into the Synthesis board.
   ====================================================================== */
(function () {
    const reading = document.querySelector('.reading');
    if (!reading) return;

    // Context handed over from Blade (see the inline bridge in
    // chapter.blade.php). refBook/refChapter are the DISPLAY reference
    // parts from the controller — usually just the book name and chapter,
    // but books in config('canon.reader_labels') override them (Five
    // Psalms of David copies as "Psalm 151:3", never "Five Psalms of
    // David 1:3"). multiChapter really means "include the chapter in
    // references" — true whenever refChapter is set, even if this
    // translation only carries one chapter of the book (e.g. WEB's
    // Psalm 151). interlinear/interlinearUrl gate + feed the card backs;
    // scrimUrl is the scrimmage route with a __V__ placeholder for the
    // verse number (server-built, so a route rename can't strand it).
    const MB = window.MBFocusContext;
    if (!MB) return;   // no bridge, no engine — fail quiet, reader still works

    const selected = new Set();   // verse numbers currently selected

    // The server-rendered title ("Genesis 2 - WEB - MEGABIBLE.net"),
    // restored whenever the selection empties.
    const baseTitle = document.title;

    // Reflect the live selection in the tab title:
    //   "Genesis 2:4 - WEB - This is the history of the generations… | MEGABIBLE.net"
    // Label uses the same compact serialisation as the ?v= param
    // (so 2:4, 2:4-6, 2:4-6,9 all read naturally); the snippet is the
    // first selected verse's opening words, cut at a word boundary.
    const updateTitle = () => {
        if (!selected.size) { document.title = baseTitle; return; }
        const label = MB.multiChapter
            ? `${MB.book} ${MB.chapter}:${serialize()}`
            : `${MB.book} ${serialize()}`;
        let snippet = verseText(Math.min(...selected)).replace(/\s+/g, ' ').trim();
        if (snippet.length > 60) snippet = snippet.slice(0, 60).replace(/\s+\S*$/, '') + '…';
        document.title = `${label} - ${MB.translation} - ${snippet} | MEGABIBLE.net`;
    };

    let   fab, synthEl, countEl, savedScrollY = 0, scrollLocked = false;
    // Head-folder apps the engine DRIVES but never builds: server-rendered in
    // chapter.blade's <x-head-folder>, found by id in buildChrome().
    let   scrimBtn;

    const el = (tag, cls) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        return node;
    };

    const ICON_ARROW = '<svg class="fab-pill-arrow" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg>';
    const ICON_CLOSE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
    const ICON_COPY  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    const ICON_SHARE = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/></svg>';
    const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    const ICON_FLIP  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2.1l4 4-4 4"/><path d="M3 12.2v-2a4 4 0 0 1 4-4h14"/><path d="M7 21.9l-4-4 4-4"/><path d="M21 11.8v2a4 4 0 0 1-4 4H3"/></svg>';

    // Display name for a language code — used on the flip button and the
    // credit line before (and after) tokens load. Mirrors config/
    // interlinear.php's language names; kept here so the button can label
    // itself with no network round-trip.
    const LANG_NAME = { hbo: 'Hebrew', arc: 'Aramaic', grc: 'Greek' };
    const langName = (code) => LANG_NAME[code] || 'Original';

    /* ---- small helpers -------------------------------------------------- */

    // A verse reference label.
    const ref = (n) => MB.multiChapter ? `${MB.book} ${MB.chapter}:${n}` : `${MB.book} ${n}`;

    // A range reference label, e.g. "Numbers 34:7-8" (or "Jude 7-8").
    const rangeRef = (a, b) => a === b ? ref(a)
        : (MB.multiChapter ? `${MB.book} ${MB.chapter}:${a}-${b}` : `${MB.book} ${a}-${b}`);

    // Group the current selection into ascending runs of contiguous verses,
    // e.g. {4,7,8} → [[4], [7, 8]]. Each run becomes one synthesis card.
    const runs = () => {
        const sorted = [...selected].sort((a, b) => a - b);
        const out = [];
        let run = null;
        for (const n of sorted) {
            if (run && n === run[run.length - 1] + 1) run.push(n);
            else { run = [n]; out.push(run); }
        }
        return out;
    };

    // Every verse number actually present in the chapter, ascending.
    const presentVerses = () => {
        const set = new Set();
        document.querySelectorAll('.verse[data-verse]').forEach(node => {
            const n = parseInt(node.dataset.verse, 10);
            if (!isNaN(n)) set.add(n);
        });
        return set;
    };

    // All DOM nodes that make up verse n.
    const nodesFor = (n) => document.querySelectorAll(`.verse[data-verse="${n}"]`);

    // The full text of verse n, stripped of its superscript number. Poetry
    // lines are joined with newlines (CSS white-space:pre-line renders them).
    const verseText = (n) => {
        return [...nodesFor(n)].map(node => {
            const clone = node.cloneNode(true);
            clone.querySelectorAll('.verse-number, .fn-markers').forEach(s => s.remove());
            return clone.textContent.replace(/\s+/g, ' ').trim();
        }).filter(Boolean).join('\n');
    };

    // Collapse an ascending list of numbers into a compact "1-3,8" string.
    const serialize = () => {
        const s = [...selected].sort((a, b) => a - b);
        const out = [];
        let start = null, prev = null;
        for (const n of s) {
            if (start === null) { start = prev = n; continue; }
            if (n === prev + 1) { prev = n; continue; }
            out.push(start === prev ? `${start}` : `${start}-${prev}`);
            start = prev = n;
        }
        if (start !== null) out.push(start === prev ? `${start}` : `${start}-${prev}`);
        return out.join(',');
    };

    // Parse "1-3,8" (or "1,4") into a Set of numbers.
    const parseParam = (str) => {
        const nums = new Set();
        str.split(',').forEach(part => {
            part = part.trim();
            if (!part) return;
            const m = part.match(/^(\d+)\s*-\s*(\d+)$/);
            if (m) {
                let a = +m[1], b = +m[2];
                if (a > b) [a, b] = [b, a];
                for (let i = a; i <= b; i++) nums.add(i);
            } else if (/^\d+$/.test(part)) {
                nums.add(+part);
            }
        });
        return nums;
    };

    // Keep every translation-switcher link carrying the live selection, so
    // picking another translation lands on exactly the same verses. We
    // rewrite only each link's ?v= and leave its path (its own translation)
    // untouched. The active row is a <span>, not an <a>, so it's skipped.
    const syncSwitcherLinks = () => {
        const param   = selected.size ? serialize() : null;
        const onBoard = synthEl && synthEl.classList.contains('is-open');
        document.querySelectorAll('.tx a.tx-option[href]').forEach(a => {
            const url = new URL(a.getAttribute('href'), window.location.origin);
            if (param) url.searchParams.set('v', param);
            else       url.searchParams.delete('v');
            // While the board is open, each option also carries the synthesis
            // flag, so picking another translation reloads straight back into
            // the board on the new text. Closing the board re-runs this and
            // strips the flag again.
            if (param && onBoard) url.searchParams.set('view', 'synthesis');
            else                  url.searchParams.delete('view');
            a.setAttribute('href', url.toString());
        });
    };

    // Persist the current selection to the address bar (?v=) AND to the
    // switcher links, so the URL and every "switch translation" target stay
    // in lock-step with what's actually selected.
    const writeUrl = () => {
        const url = new URL(window.location.href);
        if (selected.size) url.searchParams.set('v', serialize());
        else               url.searchParams.delete('v');
        // The board's open/closed state rides in the URL too, so the address
        // bar always matches what's on screen: add &view=synthesis while the
        // study board is open, drop it the moment it closes.
        if (synthEl && synthEl.classList.contains('is-open')) url.searchParams.set('view', 'synthesis');
        else                                                  url.searchParams.delete('view');
        history.replaceState(null, '', url);   // replace, so we don't spam history
        syncSwitcherLinks();
        updateTitle();
    };

    // Plain-text block of the selection, for the clipboard. Each verse gets a
    // reference line + its text, with a "[ … ]" marker between non-contiguous
    // runs and a translation tag at the end.
    const selectionText = () => {
        const nums = [...selected].sort((a, b) => a - b);
        const parts = [];
        let prev = null;
        nums.forEach(n => {
            if (prev !== null && n !== prev + 1) parts.push('[ … ]');
            parts.push(`${ref(n)}\n${verseText(n)}`);
            prev = n;
        });
        return parts.join('\n\n') + `\n\n— ${MB.translation}, MEGABIBLE.net`;
    };

    // Plain-text block for one card (a contiguous run). A single verse copies
    // as ref + text; a range copies each verse on its own line, number-prefixed.
    const cardText = (run) => {
        const label = rangeRef(run[0], run[run.length - 1]);
        const body = run.length === 1
            ? verseText(run[0])
            : run.map(n => `${n} ${verseText(n)}`).join('\n');
        return `${label}\n${body}\n\n— ${MB.translation}, MEGABIBLE.net`;
    };

    // Plain-text block for a card's INTERLINEAR side: per verse, three
    // lines — original / transliteration / literal. Tokens come from the
    // cache, which is guaranteed warm (you can only see this side after a
    // flip, and flipping fetches). Falls back to the translation if, for
    // any reason, tokens aren't cached.
    const cardInterlinearText = (run) => {
        const rows = run.map(n => {
            const v = IL.cache.get(n);
            if (!v) return null;
            const line = (col) => v.tokens.map(t => t[col] || '·').join(' ');
            const head = run.length === 1 ? '' : `${n} `;
            return `${head}${line(0)}\n${line(1)}\n${line(2)}`;
        }).filter(Boolean);

        if (!rows.length) return cardText(run);   // safety net

        const label = rangeRef(run[0], run[run.length - 1]);
        const lang  = langName(IL.covered.get(run[0]));
        return `${label} (${lang})\n${rows.join('\n\n')}\n\n— STEP Bible via MEGABIBLE.net`;
    };

    // Copy text to the clipboard, with a fallback for older/non-secure
    // contexts. Resolves true on success.
    const copyToClipboard = async (text) => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_) { /* fall through to the legacy path */ }
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (_) {
            return false;
        }
    };

    // Briefly swap a FAB icon button to a check mark to confirm an action.
    const flashDone = (btn, original) => {
        btn.classList.add('is-done');
        btn.innerHTML = ICON_CHECK;
        clearTimeout(btn._doneTimer);
        btn._doneTimer = setTimeout(() => {
            btn.classList.remove('is-done');
            btn.innerHTML = original;
        }, 1400);
    };

    const onCopy = async (e) => {
        if (!selected.size) return;
        const ok = await copyToClipboard(selectionText());
        if (ok) flashDone(e.currentTarget, ICON_COPY);
    };

    // Share the current URL (which already carries ?v=). Uses the native
    // share sheet on devices that support it, otherwise copies the link.
    const onShare = async (e) => {
        if (!selected.size) return;
        const url = window.location.href;
        if (navigator.share) {
            try { await navigator.share({ url }); return; }
            catch (_) { /* user cancelled or it failed — fall back to copy */ }
        }
        const ok = await copyToClipboard(url);
        if (ok) flashDone(e.currentTarget, ICON_SHARE);
    };

    /* ---- one-time chrome (FAB + Synthesis shell) ------------------------ */

    const buildChrome = () => {
        fab = el('div', 'fab');
        fab.innerHTML =
            `<button type="button" class="fab-pill">` +
                `<span class="fab-pill-label"><span class="fab-pill-count"></span><span class="fab-pill-suffix"> selected</span></span>` +
                ICON_ARROW +
            `</button>` +
            `<button type="button" class="fab-icon fab-copy" aria-label="Copy selected verses" title="Copy verses">${ICON_COPY}</button>` +
            `<button type="button" class="fab-icon fab-share" aria-label="Copy link to this selection" title="Share link">${ICON_SHARE}</button>` +
            `<button type="button" class="fab-icon fab-close" aria-label="Exit focus mode" title="Exit">${ICON_CLOSE}</button>`;
        document.body.appendChild(fab);
        fab.querySelector('.fab-pill').addEventListener('click', openSynthesis);
        fab.querySelector('.fab-copy').addEventListener('click', onCopy);
        fab.querySelector('.fab-share').addEventListener('click', onShare);
        fab.querySelector('.fab-close').addEventListener('click', exitFocus);

        // The apps in the sticky head's folder. Server-rendered (chapter.blade)
        // so they exist with or without a selection; syncApps() keeps their
        // armed/disabled state in step with the selection. Either may be
        // absent on a page that omits it — every use is null-guarded.
        scrimBtn = document.getElementById('app-scrim');
        if (scrimBtn) {
            // A disarmed anchor has no href, but a click must still go
            // nowhere and must not fall through to the page's dismiss logic.
            scrimBtn.addEventListener('click', (e) => {
                if (scrimBtn.getAttribute('aria-disabled') === 'true') e.preventDefault();
            });
        }

        // The synthesis board is rendered in Blade (see section('content'))
        // so it can include the shared translation switcher. We just grab it
        // and wire up the bits the script owns.
        synthEl = document.querySelector('.synthesis');
        countEl = synthEl.querySelector('.synthesis-count');
        synthEl.querySelector('.synthesis-close').addEventListener('click', closeSynthesis);

        // Copy-all in the board header — the whole selection (contiguous runs
        // joined, gaps marked), exactly like the FAB's copy button. flashDone
        // restores ICON_COPY, which matches the server-rendered icon.
        const copyAllBtn = synthEl.querySelector('.synthesis-copyall');
        if (copyAllBtn) {
            copyAllBtn.addEventListener('click', async () => {
                if (!selected.size) return;
                const ok = await copyToClipboard(selectionText());
                if (ok) flashDone(copyAllBtn, ICON_COPY);
            });
        }
    };

    /* ---- head-folder apps + selection cards ----------------------------- */

    // The selection as Pericope verse cards. Each contiguous run becomes one
    // card (Rom 8:28-30 is a single v1:28,v2:30 card — same collapsing as
    // Synthesis). A card stores the RAW osis + RAW chapter + tx (display
    // strings like "Psalm 151:3" are derived later from bookMeta), plus a
    // text snapshot so the board paints instantly offline.
    const selectionCards = () => runs().map(run => {
        const v1 = run[0], v2 = run[run.length - 1];
        return {
            type: 'verse',
            osis: MB.osis,
            ch:   (MB.rawChapter != null ? MB.rawChapter : MB.chapter),
            v1:   v1,
            v2:   v2,
            tx:   MB.txSlug,
            text: run.map(verseText).join(' '),
            vv:   run.map(n => [n, verseText(n)])   // per-verse text (numbers + paging)
        };
    });

    // Human summary of the selection: "Genesis 8:14", "Jude 3-5", "4 verses".
    const selectionLabel = () => {
        const groups = runs();
        if (!groups.length) return '';
        return groups.length === 1
            ? rangeRef(groups[0][0], groups[0][groups[0].length - 1])
            : `${selected.size} verses`;
    };

    // Keep the folder's apps in step with the selection, and tell anyone
    // listening (the pericope panel, later pages) what's in hand. Runs on
    // EVERY selection change, including to empty — so the apps disarm as
    // soon as the last verse is dropped.
    //   scrim    armed only at exactly one verse (a scrim is one verse by
    //            definition, see App\Support\Challenge); MB.scrimUrl is the
    //            scrimmage route with a __V__ slot.
    //   pericope reads the published hand itself (pericope-sheet.js).
    const syncApps = () => {
        const count = selected.size;

        if (scrimBtn) {
            if (count === 1 && MB.scrimUrl) {
                scrimBtn.href = MB.scrimUrl.replace('__V__', [...selected][0]);
                scrimBtn.setAttribute('aria-disabled', 'false');
                scrimBtn.removeAttribute('tabindex');
            } else {
                scrimBtn.removeAttribute('href');
                scrimBtn.setAttribute('aria-disabled', 'true');
                scrimBtn.setAttribute('tabindex', '-1');
            }
        }
        // Publish the hand two ways: the global for a panel that opens LATER
        // (it reads the latest on open), the event for one that's open NOW.
        // Deferred scripts load in an order this file must not depend on.
        const detail = { count, cards: selectionCards(), label: selectionLabel() };
        window.MBFocusHand = detail;
        document.dispatchEvent(new CustomEvent('mb:focus-change', { detail }));
    };

    /* ---- selection ------------------------------------------------------ */

    const paintSelection = () => {
        document.querySelectorAll('.verse.is-selected, .footnote-row.is-selected')
                .forEach(node => node.classList.remove('is-selected'));
        selected.forEach(n => {
            nodesFor(n).forEach(node => node.classList.add('is-selected'));
            // Footnotes highlight in tandem with their verse — they're an
            // extension of the selection, not a separate state.
            document.querySelectorAll(`.footnote-row[data-verse="${n}"]`)
                    .forEach(row => row.classList.add('is-selected'));
        });
        updateFab();
    };

    const updateFab = () => {
        syncApps();             // the head-folder apps track every change, empty included
        if (!selected.size) {
            fab.classList.remove('is-visible');
            return;
        }
        const count = selected.size;
        fab.querySelector('.fab-pill-count').textContent =
            `${count} ${count === 1 ? 'verse' : 'verses'}`;
        fab.classList.add('is-visible');
    };

    // Tap to toggle a verse in or out of the selection.
    const toggleVerse = (n) => {
        if (selected.has(n)) selected.delete(n);
        else                 selected.add(n);

        if (selected.size === 0) { exitFocus(); return; }

        paintSelection();
        writeUrl();
    };

    const exitFocus = () => {
        selected.clear();
        document.querySelectorAll('.verse.is-selected, .footnote-row.is-selected')
                .forEach(node => node.classList.remove('is-selected'));
        closeSynthesis();
        updateFab();          // hides the FAB now that the set is empty
        writeUrl();           // clears ?v=
    };

    /* ---- interlinear (original-language card backs) ---------------------
       Coverage arrives with the page (MB.interlinear). Tokens arrive
       lazily: the first flip on a card fetches just that card's verses
       and caches them per verse, so re-flips and overlapping cards never
       refetch. Language metadata (names, RTL) and CC BY credits ride
       along on the first response. --------------------------------------- */

    const IL = {
        // verse number (int) -> language code, for every covered verse.
        covered: new Map(Object.entries(MB.interlinear).map(([n, l]) => [+n, l])),
        cache:   new Map(),                 // verse -> {lang, source, tokens}
        langs:   {},                        // lang code -> {name, rtl}
        credits: {},                        // source key -> {label, credit, url, license}
    };

    // The endpoint URL comes from Blade (MB.interlinearUrl) so it stays
    // correct even on verse-permalink paths like /john/3/16.
    const interlinearUrl = (verses) =>
        `${MB.interlinearUrl}?v=${verses.join(',')}`;

    // Fetch any not-yet-cached verses, then return [verse, data] pairs in
    // the order asked for. Throws on network/HTTP failure (caller handles).
    const fetchTokens = async (verses) => {
        const missing = verses.filter(n => !IL.cache.has(n));
        if (missing.length) {
            const res = await fetch(interlinearUrl(missing), {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(`interlinear ${res.status}`);
            const data = await res.json();
            Object.assign(IL.langs,   data.langs   || {});
            Object.assign(IL.credits, data.credits || {});
            Object.entries(data.verses || {}).forEach(([n, v]) => IL.cache.set(+n, v));
        }
        return verses.map(n => [n, IL.cache.get(n)]);
    };

    // Render a transliteration with STEPBible's syllable dots as their own
    // elements, so CSS can dim/raise them. The data stays 'be.re.Shit' in the
    // DB — only the display swaps periods for styled interpuncts. (You can't
    // target a character inside a text node, hence the per-separator span.)
    const fillTranslit = (w, val) => {
        val.split('.').forEach((syl, si) => {
            if (si > 0) {
                const sep = el('span', 'syl-sep');
                sep.textContent = '\u00B7';   // · MIDDLE DOT (interpunct)
                w.appendChild(sep);
            }
            if (syl) w.appendChild(document.createTextNode(syl));
        });
    };

    // Build one card's back face: per verse, three rows (original /
    // transliteration / literal), word-linked; then one credit line.
    const buildBack = (card, host, entries, multi) => {
        host.innerHTML = '';
        const sources = new Set();

        entries.forEach(([n, v], vi) => {
            if (!v) return;   // gated by coverage, so this is just a belt
            sources.add(v.source);

            const block = el('div', 'iface-verse');
            if (multi) {
                const num = el('span', 'synthesis-vn');
                num.textContent = n;
                block.appendChild(num);
            }

            const lang = IL.langs[v.lang] || { name: 'Original', rtl: false };
            // [row label, css class, rtl?, token column index]
            const rows = [
                [lang.name,         'row-original', !!lang.rtl, 0],
                ['Transliteration', 'row-translit', false,      1],
                ['Literal',         'row-gloss',    false,      2],
            ];

            rows.forEach(([label, cls, rtl, col]) => {
                const wrap = el('div', 'iface-row');
                // Labels once per card (on the first verse block), not per verse.
                if (vi === 0) {
                    const lab = el('span', 'iface-label');
                    lab.textContent = label;
                    wrap.appendChild(lab);
                }
                const line = el('div', cls);
                if (rtl) line.setAttribute('dir', 'rtl');
                v.tokens.forEach((tok, i) => {
                    const w = el('span', 'w');
                    w.dataset.k = `${n}:${i}`;            // links the trio
                    const val = tok[col] || '·';          // rare empty cell

                    if (cls === 'row-translit' && val.includes('.')) {
                        fillTranslit(w, val);
                    } else {
                        w.textContent = val;
                    }

                    line.appendChild(w);
                    if (i < v.tokens.length - 1) {
                        line.appendChild(document.createTextNode(' '));
                    }
                });
                wrap.appendChild(line);
                block.appendChild(wrap);
            });

            host.appendChild(block);
        });

        // Attribution, CC BY (required). New format, e.g.:
        //   "8 Greek words from STEP Bible TAHOT · CC BY 4.0 · Source: github.com"
        // Word count = tokens shown on this card; language from the first
        // verse; provider + label + license + source domain from config.
        const wordCount = entries.reduce(
            (sum, [, v]) => sum + (v ? v.tokens.length : 0), 0);
        const firstLang = entries.length ? langName(entries[0][1].lang) : 'Original';

        const credit = el('div', 'iface-credit');
        [...sources].forEach((s, i) => {
            const c = IL.credits[s] || {};
            if (i > 0) credit.appendChild(document.createTextNode('  '));

            // "N Language words from STEP Bible LABEL"
            credit.appendChild(document.createTextNode(
                `${wordCount} ${firstLang} ${wordCount === 1 ? 'word' : 'words'} from ${c.provider ? c.provider + ' ' : ''}${c.short || s}`));

            // " · CC BY 4.0"
            if (c.license) {
                credit.appendChild(document.createTextNode(` · ${c.license}`));
            }

            // " · Source: github.com" (linked to the full URL)
            if (c.url) {
                credit.appendChild(document.createTextNode(' · Source: '));
                const a = el('a');
                a.href = c.url; a.target = '_blank'; a.rel = 'noopener';
                a.textContent = sourceDomain(c.url);
                credit.appendChild(a);
            }
        });
        host.appendChild(credit);

        // Word-group highlight: hover previews, click pins (toggle,
        // multiple pins allowed). Pin state lives on the card's _il record
        // so the global click-to-clear handler can reach it; hover state is
        // transient and stays local. Delegated — three listeners per back.
        const rec = card._il;
        const group = (k) => host.querySelectorAll(`.w[data-k="${CSS.escape(k)}"]`);
        const repaintPins = () => {
            host.querySelectorAll('.w.pin').forEach(x => x.classList.remove('pin'));
            rec.pinned.forEach(kk => group(kk).forEach(x => x.classList.add('pin')));
        };
        // Reachable from the global handler: clear this card's pins.
        rec.clearPins = () => { rec.pinned.clear(); repaintPins(); };

        host.addEventListener('mouseover', (e) => {
            const w = e.target.closest('.w');
            if (w) group(w.dataset.k).forEach(x => x.classList.add('hl'));
        });
        host.addEventListener('mouseout', (e) => {
            const w = e.target.closest('.w');
            if (w) group(w.dataset.k).forEach(x => x.classList.remove('hl'));
        });
        host.addEventListener('click', (e) => {
            const w = e.target.closest('.w');
            if (!w) return;
            const k = w.dataset.k;
            rec.pinned.has(k) ? rec.pinned.delete(k) : rec.pinned.add(k);
            repaintPins();
        });
    };

    // Short, human source label for a URL: hostname without "www.".
    // e.g. "https://github.com/STEPBible/STEPBible-Data" -> "github.com".
    const sourceDomain = (url) => {
        try { return new URL(url).hostname.replace(/^www\./, ''); }
        catch (_) { return url; }
    };

    // Pin the stage to the ACTIVE face's natural height (px), so the CSS
    // height transition has concrete endpoints to animate between.
    const syncFaces = (card) => {
        const stage = card.querySelector('.card-faces');
        if (!stage) return;
        const active = card.classList.contains('is-flipped')
            ? stage.querySelector('.face-back')
            : stage.querySelector('.face-front');
        stage.style.height = active.scrollHeight + 'px';
    };

    // Flip one card. First flip to the back fetches + builds it; failures
    // show a message on the back and are NOT cached, so the next flip
    // retries cleanly.
    const onFlip = async (card, run, btn) => {
        const stage = card.querySelector('.card-faces');
        const front = stage.querySelector('.face-front');
        const back  = stage.querySelector('.face-back');
        const goingToBack = !card.classList.contains('is-flipped');

        if (goingToBack && !back.dataset.ready) {
            btn.disabled = true;
            try {
                const entries = await fetchTokens(run);
                buildBack(card, back, entries, run.length > 1);
                back.dataset.ready = '1';
            } catch (_) {
                back.textContent = 'Could not load the original text. Try again.';
            } finally {
                btn.disabled = false;
            }
        }

        // Freeze the current height so auto → px doesn't skip the
        // animation, then swap faces and glide to the new height.
        stage.style.height = stage.offsetHeight + 'px';
        void stage.offsetHeight;   // force the freeze to take

        card.classList.toggle('is-flipped');
        const flipped = card.classList.contains('is-flipped');
        if (card._il) card._il.flipped = flipped;
        btn.setAttribute('aria-pressed', flipped ? 'true' : 'false');
        btn.setAttribute('title', flipped
            ? 'Show translation'
            : `Show ${langName(IL.covered.get(card._il.run[0]))}`);

        front.classList.toggle('is-active', !flipped);
        back.classList.toggle('is-active', flipped);
        syncFaces(card);
    };

    // Card heights depend on wrapping — re-pin any card that has an
    // explicit height whenever the window resizes.
    let ilResizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(ilResizeTimer);
        ilResizeTimer = setTimeout(() => {
            document.querySelectorAll('.synthesis-card .card-faces[style]')
                .forEach(stage => syncFaces(stage.closest('.synthesis-card')));
        }, 120);
    });

    // Reader Text Settings (size / spacing / font) change each face's natural
    // height. The reader engine fires mb:reader-change AFTER it writes the new
    // data-* to <html>, so the faces have already reflowed by the time we hear
    // it — re-pin every card carrying an explicit height, exactly as the resize
    // handler does. Auto-height cards (front-facing or no interlinear) size
    // themselves and have no inline style, so they're correctly skipped.
    document.addEventListener('mb:reader-change', () => {
        document.querySelectorAll('.synthesis-card .card-faces[style]')
            .forEach(stage => syncFaces(stage.closest('.synthesis-card')));
    });

    /* ---- synthesis view ------------------------------------------------- */

    const buildCards = () => {
        const cards = synthEl.querySelector('.synthesis-cards');
        cards.innerHTML = '';
        const groups = runs();

        groups.forEach((run, i) => {
            // Skipped text between non-contiguous runs.
            if (i > 0) {
                const gap = el('div', 'synthesis-gap');
                gap.textContent = '[ … ]';
                cards.appendChild(gap);
            }

            const a = run[0], b = run[run.length - 1];
            const card = el('article', 'synthesis-card');

            const head = el('div', 'synthesis-ref');
            const label = el('span');
            label.textContent = rangeRef(a, b);
            head.appendChild(label);

            // Copy just this card's verse block.
            const copy = el('button', 'synthesis-copy');
            copy.type = 'button';
            copy.setAttribute('aria-label', `Copy ${rangeRef(a, b)}`);
            copy.setAttribute('title', 'Copy');
            copy.innerHTML = ICON_COPY;
            copy.addEventListener('click', async () => {
                // Copy the side that's showing: interlinear when flipped,
                // translation otherwise.
                const text = (card._il && card._il.flipped)
                    ? cardInterlinearText(run)
                    : cardText(run);
                const ok = await copyToClipboard(text);
                if (ok) flashDone(copy, ICON_COPY);
            });
            head.appendChild(copy);

            // The card body is now a two-face stage: the translation on
            // the front (exactly the old .synthesis-text), the interlinear
            // on the back. Cards without full coverage get no back face
            // and no flip button — they behave exactly as before.
            const faces = el('div', 'card-faces');
            const front = el('div', 'face face-front is-active');

            const text = el('div', 'synthesis-text');
            if (run.length === 1) {
                text.textContent = verseText(a);
            } else {
                // A combined card: prefix each verse with its superscript
                // number so the boundaries between verses stay visible.
                run.forEach((n, j) => {
                    const num = el('span', 'synthesis-vn');
                    num.textContent = n;
                    text.appendChild(num);
                    text.appendChild(document.createTextNode(
                        verseText(n) + (j < run.length - 1 ? ' ' : '')));
                });
            }
            front.appendChild(text);
            faces.appendChild(front);

            // Flip button + back face, only when EVERY verse in the run
            // has tokens (partial runs would show a hole mid-card).
            if (run.every(n => IL.covered.has(n))) {
                const back = el('div', 'face face-back iface');
                faces.appendChild(back);

                // Language is known up front from the coverage map, so the
                // button can name it ("Hebrew"/"Greek") before any flip.
                // A run's verses share a language in every real case; the
                // first verse decides the label.
                const lang = langName(IL.covered.get(a));

                const flip = el('button', 'synthesis-flip');
                flip.type = 'button';
                flip.setAttribute('aria-pressed', 'false');
                flip.setAttribute('title', `Show ${lang}`);
                flip.setAttribute('aria-label',
                    `Show ${lang} for ${rangeRef(a, b)}`);
                flip.innerHTML = `${ICON_FLIP}<span class="flip-label">${lang}</span>`;
                flip.addEventListener('click', () => onFlip(card, run, flip));
                head.insertBefore(flip, copy);   // flip sits left of copy

                // Per-card record: lets the global click-to-clear handler
                // and the side-aware copy button reach this card's state.
                card._il = { run, flipped: false, pinned: new Set(), clearPins: null };
            }

            card.appendChild(head);
            card.appendChild(faces);
            cards.appendChild(card);
        });

        const count = selected.size;
        countEl.textContent = `· ${count} ${count === 1 ? 'verse' : 'verses'}`;
    };

    /* Scroll lock for the study board.
       body { overflow:hidden } is enough on desktop, but iOS Safari ignores
       it for touch scrolling: the reader keeps moving behind the board, and
       during that momentum/overscroll the fixed overlay jitters just enough
       for the text underneath to peek out at the edges. Pinning the body with
       position:fixed physically takes it out of the scroll flow — there's then
       genuinely nothing to scroll on any device, which is what makes mobile
       match desktop. We shift the body up by the current scroll offset so
       nothing visibly jumps, and put the scroll back exactly on unlock. */
    const lockScroll = () => {
        if (scrollLocked) return;
        savedScrollY = window.scrollY || window.pageYOffset || 0;
        const s = document.body.style;
        s.position = 'fixed';
        s.top      = `-${savedScrollY}px`;
        s.left     = '0';
        s.right    = '0';
        s.width    = '100%';
        scrollLocked = true;
    };
    const unlockScroll = () => {
        if (!scrollLocked) return;
        const s = document.body.style;
        s.position = s.top = s.left = s.right = s.width = '';
        scrollLocked = false;
        window.scrollTo(0, savedScrollY);
    };

    function openSynthesis() {
        if (!selected.size) return;
        buildCards();
        lockScroll();                              // pin the reader (see lockScroll)
        synthEl.classList.add('is-open');
        writeUrl();   // reflect the open board in the URL: ?v=…&view=synthesis
    }

    function closeSynthesis() {
        if (!synthEl) return;
        synthEl.classList.remove('is-open');
        unlockScroll();                            // restore the reader + scroll position
        writeUrl();   // board closed → drop &view=synthesis, keep the ?v= selection
    }

    /* ---- global listeners ----------------------------------------------- */

    // One delegated click handler covers: tap a verse (toggle), and tap the
    // margins / empty page (dismiss). Clicks inside the FAB or Synthesis are
    // handled by their own buttons, so we ignore them here.
    document.addEventListener('click', (e) => {
        // Inside the open study board
        // isnt in the translation switcher clears all pinned words across
        // every card — the same tap empty space to reset gesture focus
        // mode uses, one level down. Word taps (.w) handle their own toggle;
        // the switcher stays untouched.
        if (synthEl && synthEl.classList.contains('is-open')) {
            if (!e.target.closest('.w') && !e.target.closest('.tx') && !e.target.closest('.text-settings')) {
                document.querySelectorAll('.synthesis-card').forEach(card => {
                    if (card._il && card._il.clearPins) card._il.clearPins();
                });
            }
            return;   // board owns its own clicks; never fall through to focus dismiss
        }

        // Ignore clicks inside our own chrome (FAB, Synthesis) and inside a
        // handful of "safe" controls. Interacting with any of these must NOT
        // drop the current selection:
        //   .tx            translation switcher — verses ride to the new translation
        //   .qn            QuickNav popup
        //   .text-settings the "Aa" panel (trigger + every button inside it)
        //   .head-folder   the apps folder — circle, pill, every app and any
        //                  panel hanging beneath it. Opening the folder to
        //                  reach the scissors must never drop the selection.
        //   .site-search   the search box (clicking in to type keeps focus mode)
        // A target that a handler earlier in the chain already removed from
        // the page (a panel re-rendering itself on click) is never "outside"
        // — closest() can't see its old ancestors, so without this guard the
        // click would read as a dismiss and drop the selection.
        if (!document.contains(e.target)) return;

        if (e.target.closest('.fab') ||
            e.target.closest('.synthesis') ||
            e.target.closest('.tx') ||
            e.target.closest('.qn') ||
            e.target.closest('.text-settings') ||
            e.target.closest('.head-folder') ||
            e.target.closest('.site-search')) return;

        // Footnote marker inside a verse: focus THIS collection — the
        // clicked verse stays/becomes selected (never deselects) and
        // everything else is dropped. The browser's default anchor jump
        // then carries the reader down; writeUrl() runs before the hash
        // lands, so the address bar ends up ?v=N#fn-x.
        const marker = e.target.closest('.fn-marker');
        if (marker) {
            const markerVerse = marker.closest('.verse');
            const mn = markerVerse ? parseInt(markerVerse.dataset.verse, 10) : NaN;
            if (!isNaN(mn) && !(selected.size === 1 && selected.has(mn))) {
                selected.clear();
                selected.add(mn);
                paintSelection();
                writeUrl();
            }
            return;   // never falls through to toggleVerse
        }

        // Verse-number link in the footnotes block: focus THIS collection
        // (drop everything else, keep/select this verse — never a
        // deselect) and jump back UP to the verse. Intercepted so it
        // never reloads; the ?v=N href stays the no-JS fallback.
        const fnVerse = e.target.closest('.fn-verse');
        if (fnVerse) {
            e.preventDefault();
            const fn = parseInt(fnVerse.dataset.verse, 10);
            if (!isNaN(fn)) {
                if (!(selected.size === 1 && selected.has(fn))) {
                    selected.clear();
                    selected.add(fn);
                    paintSelection();
                    writeUrl();
                }
                const up = document.getElementById('v' + fn);
                if (up) up.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // Footnote row body (anywhere on the row EXCEPT the verse-number
        // link, which was handled above): focus THIS note's collection.
        // Clicking a note drops any existing selection and selects just
        // its verse — lighting the verse and every note tied to it.
        // Clicking again while that verse is the sole selection clears
        // everything. No scrolling in either direction.
        const fnRow = e.target.closest('.footnote-row');
        if (fnRow) {
            const rn = parseInt(fnRow.dataset.verse, 10);
            if (!isNaN(rn)) {
                if (selected.size === 1 && selected.has(rn)) {
                    exitFocus();                    // second tap: clear
                } else {
                    selected.clear();               // drop everything else
                    selected.add(rn);               // …keep only this collection
                    paintSelection();
                    writeUrl();
                }
            }
            return;   // never falls through to the outside-click dismiss
        }

        // A click in the leading between two wrapped lines reports the <p>,
        // not the verse span — so without this fallback it fell through to the
        // outside-click dismiss below and wiped the entire selection.
        let verseEl = e.target.closest('.verse');
        if (!verseEl && window.MBVerseHover) verseEl = window.MBVerseHover.fromEvent(e);
        if (verseEl) {
            const n = parseInt(verseEl.dataset.verse, 10);
            if (!isNaN(n)) toggleVerse(n);
            return;
        }

        // Clicked outside any verse: dismiss if anything is selected.
        if (selected.size) exitFocus();
    });

    /* ---- initialise from the URL ---------------------------------------- */

    const init = () => {
        buildChrome();

        const url0       = new URL(window.location.href);
        const raw        = url0.searchParams.get('v');
        const wantsBoard = url0.searchParams.get('view') === 'synthesis';

        // The page may have been server-rendered with the board already
        // covering the reader (see $bootSynthesis in the Blade), so a reload
        // into ?view=synthesis never flashes the reader underneath. If we
        // can't produce a valid selection to fill it, drop that pre-opened
        // cover so nobody's stranded on an empty overlay.
        const bailBoard = () => {
            if (synthEl && synthEl.classList.contains('is-open')) {
                synthEl.classList.remove('is-open');
                unlockScroll();
            }
        };

        if (!raw) { bailBoard(); return; }

        const present = presentVerses();
        const parsed  = [...parseParam(raw)].filter(n => present.has(n)).sort((a, b) => a - b);

        if (!parsed.length) {            // junk / out-of-range param: clean it off
            bailBoard();
            writeUrl();
            return;
        }

        parsed.forEach(n => selected.add(n));
        paintSelection();

        // Focus landing for every selection: highlight the verses and scroll
        // to the first (harmless when the board covers it — it just sets the
        // scroll position for when the board is later closed).
        const target = document.getElementById('v' + parsed[0]);
        if (target) requestAnimationFrame(() =>
            target.scrollIntoView({ behavior: 'smooth', block: 'center' }));

        // Opt-in deep link: ?v=…&view=synthesis lands on the study board.
        // The cover is already up from the server render, so openSynthesis
        // here just fills the cards and locks scroll — re-adding .is-open is
        // a no-op and triggers no fade. Plain links (no &view=) land in the
        // reader; ensure any stray server-rendered board is torn down.
        if (wantsBoard) openSynthesis();
        else            bailBoard();

        writeUrl();   // normalise the param to its compact form
    };

    init();
})();
