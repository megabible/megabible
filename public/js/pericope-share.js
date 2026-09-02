/* ======================================================================
   PERICOPE BOARD — SHARING                      public/js/pericope-share.js
   ----------------------------------------------------------------------
   S1 of the share plan. The head folder's share app is a <details> whose
   panel (.pbs-panel, filled here on open) hangs beneath the toolbar like
   the Aa panel, and carries the board's whole state as one self-contained
   URL:

       <site>/extras/pericope/shared#<p1 blob>

   The blob is MBPericope.encodeShare(board) — the frozen p1 format from
   pericope-store.js. It rides in the FRAGMENT, so it is never sent to the
   server, never logged, never cached: the board travels person-to-person.
   The panel offers copy, the platform share tray where the browser has one
   (navigator.share), and a size readout against the QR comfort line. The
   QR itself is S3 (the .pbs-qr container is already here for it); the
   import page that these URLs point at is S2 — until that route ships, a
   generated link 404s by design.

   The panel also leads with the PRESENTATION button — the door into
   pericope-present.js (the board as fullscreen slides) — and its GEAR,
   which swaps the share content for the presentation settings (font,
   alignment, tint, wall pattern). Those are read from and written to
   MBPericopePresent.prefs()/setPrefs(); they live in the presenter's own
   localStorage key, never in the share URL. Attaches through
   window.MBPericopeBoard on mb:pericope-board-ready, like the drag and
   edit modules.

   S2 — THE IMPORT DRIVER, same file, other page. When this script finds
   #pbi-root (the /extras/pericope/shared shell) it runs the import instead:
   decode the fragment, importShared() into THIS browser's store (fresh ids,
   name deduped with a trailing number), then fetch each verse card's text
   by reference through the same verseTranslations endpoint the switcher
   uses — sequentially, with a progress line — and redirect to the new
   board. A shared translation this site doesn't have for that reference
   falls back to the first available one (and the card's tx is corrected to
   match). A failed fetch just leaves the card text-less: the board's own
   self-heal finishes it on first expand.

   S3 — THE QR CODE. Under QR_COMFORT the panel also renders the whole URL
   as a QR (vendored qrcode-generator, MIT, public/js/vendor/qrcode.js),
   loaded ON DEMAND the first time the panel needs one — an ordinary board
   visit never pays for it. Always dark-on-white in its own backing box
   regardless of theme: scanners want contrast, not aesthetics. Vanilla
   ES5.
   ====================================================================== */
(function () {
    'use strict';

    // Above this many characters the panel stops promising a scannable QR
    // (≈ QR version 20 at medium error correction) and leads with the link.
    var QR_COMFORT = 1000;

    var B = null, panel = null, root = null;
    var qrLibState = 0;      // 0 not requested · 1 loading · 2 ready · 3 failed
    var qrPending = null;    // url waiting for the lib to arrive

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    // The import page's URL, derived from the hub route the shell already
    // ships (never cached, so LAN/mobile devices get the right host).
    function shareBase() {
        var hub = (window.MBPericopeBoardConfig && window.MBPericopeBoardConfig.hubUrl) || '/extras/pericope';
        return hub.replace(/\/$/, '') + '/shared';
    }

    var ICON_PRESENT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 18v3"/></svg>';
    var ICON_GEAR    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';
    var ICON_AL = {
        left:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg>',
        center: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg>',
        right:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg>'
    };

    /* ---- presentation settings ------------------------------------------ */
    // Custom Google Font: built and working, switched OFF for launch (untested
    // against real decks). Flip to true to show the option again.
    var CUSTOM_FONTS = false;
    function P() { return window.MBPericopePresent || null; }

    function settingsHtml() {
        var pr = P(), prefs = pr ? pr.prefs() : {}, fonts = pr ? pr.FONTS : {}, k, h = '';
        var colors = (window.MBPericope && window.MBPericope.GROUP_COLORS) || [];

        h += '<p class="pps-title">Presentation settings</p>';

        // Preview: the board's name in the chosen face.
        h += '<div class="pps-preview" id="pps-preview" aria-hidden="true"></div>';

        // Row 1: font
        h += '<div class="pps-row"><select class="pps-select" id="pps-font" aria-label="Slide font">';
        for (k in fonts) { if (fonts.hasOwnProperty(k)) { h += '<option value="' + k + '">' + esc(fonts[k].label) + '</option>'; } }
        if (CUSTOM_FONTS) { h += '<option value="custom">Custom Google Font…</option>'; }
        h += '</select></div>';
        if (CUSTOM_FONTS) {
            h += '<div class="pps-custom" id="pps-custom" hidden>' +
                 '<input type="url" class="pps-input" id="pps-custom-url" placeholder="https://fonts.googleapis.com/css2?family=…" spellcheck="false">' +
                 '<button type="button" class="pbs-btn is-quiet" id="pps-custom-apply">Use</button>' +
                 '<p class="pps-hint" id="pps-custom-hint">Paste the &lt;link&gt; href from fonts.google.com. Loaded from Google only while presenting.</p>' +
                 '</div>';
        }

        // Row 2: alignment
        h += '<div class="pps-row"><div class="pps-seg" role="group" aria-label="Verse alignment">';
        ['left', 'center', 'right'].forEach(function (a) {
            h += '<button type="button" class="pps-btn" data-align="' + a + '" aria-label="Align ' + a + '" title="Align ' + a + '">' + ICON_AL[a] + '</button>';
        });
        h += '</div></div>';

        // Row 3: colour
        h += '<div class="pps-row"><div class="pps-swatches" role="group" aria-label="Slide tint">';
        h += '<button type="button" class="pps-swatch is-none" data-color="" aria-label="No tint" title="None"></button>';
        colors.forEach(function (c) {
            h += '<button type="button" class="pps-swatch" data-color="' + c + '" style="--sw:var(--tl-' + c + ')" aria-label="' + esc(c) + '" title="' + esc(c) + '"></button>';
        });
        h += '</div></div>';

        // Row 4: design + density
        h += '<div class="pps-row"><select class="pps-select" id="pps-pattern" aria-label="Wall pattern">' +
             '<option value="diagonal">Diagonal</option><option value="grid">Grid</option>' +
             '<option value="dots">Dots</option><option value="crosshatch">Crosshatch</option>' +
             '<option value="none">None</option></select></div>';
        h += '<div class="pps-row" id="pps-density-row"><div class="pps-seg" role="group" aria-label="Pattern density">' +
             '<button type="button" class="pps-btn" data-density="0">Sparse</button>' +
             '<button type="button" class="pps-btn" data-density="1">Medium</button>' +
             '<button type="button" class="pps-btn" data-density="2">Dense</button>' +
             '</div></div>';
        return h;
    }

    // Paint the controls from the presenter's prefs.
    function syncSettings() {
        var pr = P(), box = document.getElementById('pbs-settings');
        if (!pr || !box) { return; }
        var prefs = pr.prefs();
        var fontSel = document.getElementById('pps-font');
        if (fontSel) { fontSel.value = prefs.font; }
        var preview = document.getElementById('pps-preview');
        if (preview) {
            var board = B && B.board();
            preview.textContent = (board && board.name) || 'Pericope';
            preview.style.fontFamily = pr.fontFamily();
        }
        var custom = document.getElementById('pps-custom');
        if (custom) {
            custom.hidden = prefs.font !== 'custom' && fontSel.value !== 'custom';
            var urlIn = document.getElementById('pps-custom-url');
            if (urlIn && prefs.customUrl && !urlIn.value) { urlIn.value = prefs.customUrl; }
        }
        Array.prototype.forEach.call(box.querySelectorAll('[data-align]'), function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-align') === prefs.align);
            b.setAttribute('aria-pressed', b.getAttribute('data-align') === prefs.align ? 'true' : 'false');
        });
        Array.prototype.forEach.call(box.querySelectorAll('[data-color]'), function (b) {
            b.classList.toggle('is-picked', b.getAttribute('data-color') === prefs.color);
        });
        var patSel = document.getElementById('pps-pattern');
        if (patSel) { patSel.value = prefs.pattern; }
        var dens = document.getElementById('pps-density-row');
        if (dens) { dens.hidden = prefs.pattern === 'none'; }
        Array.prototype.forEach.call(box.querySelectorAll('[data-density]'), function (b) {
            b.classList.toggle('is-on', Number(b.getAttribute('data-density')) === prefs.density);
        });
    }

    function wireSettings() {
        var pr = P(), box = document.getElementById('pbs-settings');
        if (!pr || !box) { return; }
        var fontSel = document.getElementById('pps-font');
        fontSel.addEventListener('change', function () {
            if (fontSel.value === 'custom' && CUSTOM_FONTS) {
                document.getElementById('pps-custom').hidden = false;
                document.getElementById('pps-custom-url').focus();
                return;             // applied by "Use", once there's a URL
            }
            pr.setPrefs({ font: fontSel.value });
        });
        var applyBtn = document.getElementById('pps-custom-apply');
        if (applyBtn) applyBtn.addEventListener('click', function () {
            var url = document.getElementById('pps-custom-url').value.replace(/^\s+|\s+$/g, '');
            var hint = document.getElementById('pps-custom-hint');
            var fam = pr.customFamily(url);
            if (!/^https:\/\/fonts\.googleapis\.com\/css2?\?/.test(url) || !fam) {
                hint.textContent = 'That doesn\u2019t look like a fonts.googleapis.com css URL with a family= in it.';
                hint.classList.add('is-error');
                return;
            }
            hint.classList.remove('is-error');
            hint.textContent = 'Using \u201c' + fam + '\u201d.';
            pr.setPrefs({ font: 'custom', customUrl: url });
        });
        box.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-align],[data-color],[data-density]') : null;
            if (!t) { return; }
            if (t.hasAttribute('data-align'))   { pr.setPrefs({ align: t.getAttribute('data-align') }); }
            if (t.hasAttribute('data-color'))   { pr.setPrefs({ color: t.getAttribute('data-color') }); }
            if (t.hasAttribute('data-density')) { pr.setPrefs({ density: Number(t.getAttribute('data-density')) }); }
        });
        document.getElementById('pps-pattern').addEventListener('change', function () {
            pr.setPrefs({ pattern: this.value });
        });
        document.addEventListener('mb:present-prefs', syncSettings);
        syncSettings();
    }

    function setSettingsMode(on) {
        var gear = document.getElementById('pbs-gear');
        var share = document.getElementById('pbs-share-body');
        var box = document.getElementById('pbs-settings');
        if (!gear || !share || !box) { return; }
        gear.setAttribute('aria-pressed', on ? 'true' : 'false');
        gear.classList.toggle('is-on', on);
        share.hidden = on;
        box.hidden = !on;
        if (on) { syncSettings(); }
    }

    // Build the panel's contents once; open() refreshes the values.
    function buildPanel() {
        if (panel.getAttribute('data-built')) { return; }
        panel.setAttribute('data-built', '1');
        panel.innerHTML =
            '<div class="pbs-present-row">' +
                '<button type="button" class="pbs-present" id="pbs-present">' + ICON_PRESENT + '<span>Presentation</span></button>' +
                '<button type="button" class="pbs-gear" id="pbs-gear" aria-pressed="false" aria-label="Presentation settings" title="Presentation settings">' + ICON_GEAR + '</button>' +
            '</div>' +
            '<div id="pbs-share-body">' +
                '<p class="pbs-title">Share this pericope</p>' +
                '<p class="pbs-blurb">The whole board — cards, placement, groups — lives inside this link. ' +
                    'Nothing is stored on MEGABIBLE; whoever opens it rebuilds their own copy.</p>' +
                '<textarea class="pbs-url" id="pbs-url" readonly rows="3" spellcheck="false"></textarea>' +
                '<p class="pbs-size" id="pbs-size"></p>' +
                '<div class="pbs-qr" id="pbs-qr" hidden></div>' +
                '<div class="pbs-btns">' +
                    '<button type="button" class="pbs-btn is-quiet" id="pbs-native" hidden>Share&hellip;</button>' +
                    '<button type="button" class="pbs-btn is-primary" id="pbs-copy">Copy link</button>' +
                '</div>' +
            '</div>' +
            '<div class="pbs-settings" id="pbs-settings" hidden>' + settingsHtml() + '</div>';

        document.getElementById('pbs-copy').addEventListener('click', copyUrl);
        document.getElementById('pbs-present').addEventListener('click', function () {
            if (window.MBPericopePresent) { window.MBPericopePresent.open(); }   // panel stays open underneath
        });
        document.getElementById('pbs-gear').addEventListener('click', function () {
            setSettingsMode(this.getAttribute('aria-pressed') !== 'true');
        });
        wireSettings();

        var urlEl = document.getElementById('pbs-url');
        urlEl.addEventListener('focus', function () { urlEl.select(); });

        var native = document.getElementById('pbs-native');
        if (navigator.share) {
            native.hidden = false;
            native.addEventListener('click', function () {
                var board = B.board();
                navigator.share({
                    title: (board && board.name) ? board.name + ' — Pericope' : 'Pericope',
                    url: document.getElementById('pbs-url').value
                }).catch(function () { /* user dismissed the tray — not an error */ });
            });
        }
    }

    // Fill the panel for the board as it is RIGHT NOW (runs on every open,
    // so a board edited since the last share gets a fresh link).
    function open() {
        var board = B.board();
        if (!board) { return; }
        var blob = window.MBPericope.encodeShare(board);
        if (!blob) { return; }
        buildPanel();

        var url = shareBase() + '#' + blob;
        document.getElementById('pbs-url').value = url;

        var sizeEl = document.getElementById('pbs-size');
        if (url.length <= QR_COMFORT) {
            sizeEl.textContent = url.length + ' characters — small enough for a scannable QR code.';
            sizeEl.classList.remove('is-over');
        } else {
            sizeEl.textContent = url.length + ' characters — too large for a comfortable QR code; share it as a link.';
            sizeEl.classList.add('is-over');
        }

        var copyBtn = document.getElementById('pbs-copy');
        copyBtn.textContent = 'Copy link';

        var present = document.getElementById('pbs-present');
        if (present) { present.disabled = !(board.cards && board.cards.length); }

        renderQr(url.length <= QR_COMFORT ? url : null);
    }

    function close() { if (root) { root.open = false; } }

    /* =================== S3: the QR code =================== */

    // The vendored generator is fetched the first time the panel wants a QR
    // (script tag onto <body>; the lib defines window.qrcode). Failures are
    // quiet — the link and copy still work, the QR box just stays empty.
    function loadQrLib() {
        if (qrLibState === 1 || qrLibState === 2) { return; }
        qrLibState = 1;
        var tag = document.createElement('script');
        tag.src = '/js/vendor/qrcode.js';
        tag.onload = function () {
            qrLibState = 2;
            if (qrPending) { renderQr(qrPending); }
        };
        tag.onerror = function () { qrLibState = 3; };
        document.body.appendChild(tag);
    }

    // Draw (or clear, when url is null) the QR into the panel's box.
    function renderQr(url) {
        var box = document.getElementById('pbs-qr');
        if (!box) { return; }
        if (!url) { box.hidden = true; box.innerHTML = ''; qrPending = null; return; }
        if (!window.qrcode) {
            qrPending = url;
            box.hidden = true;
            loadQrLib();
            return;
        }
        qrPending = null;
        try {
            var qr = window.qrcode(0, 'M');     // type 0 = smallest that fits, medium correction
            qr.addData(url, 'Byte');
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
            box.hidden = false;
        } catch (e) {
            // The lib throws plain strings on overflow — the size line has
            // already told the person this board is link-only.
            box.hidden = true;
            box.innerHTML = '';
        }
    }

    function copyUrl() {
        var urlEl = document.getElementById('pbs-url');
        var btnEl = document.getElementById('pbs-copy');
        function done() {
            btnEl.textContent = 'Copied';
            setTimeout(function () { btnEl.textContent = 'Copy link'; }, 1400);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(urlEl.value).then(done, function () { legacyCopy(urlEl, done); });
        } else {
            legacyCopy(urlEl, done);
        }
    }
    function legacyCopy(urlEl, done) {
        urlEl.focus();
        urlEl.select();
        try { if (document.execCommand('copy')) { done(); } } catch (_) {}
    }

    /* =================== S2: import driver =================== */

    function runImport(root) {
        var CFG = window.MBPericopeSharedConfig || {};
        var progress = document.getElementById('pbi-progress');
        var detail   = document.getElementById('pbi-detail');
        var errBox   = document.getElementById('pbi-error');
        var errMsg   = document.getElementById('pbi-error-msg');

        function fail(msg) {
            root.hidden = true;
            errBox.hidden = false;
            errMsg.textContent = msg;
        }
        function say(line) { if (detail) { detail.textContent = line; } }

        if (!window.MBPericope || !window.MBPericope.decodeShare) { return fail('This browser could not load the pericope store.'); }

        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) { return fail('This link carries no board data — it may have been cut short when copied.'); }

        var dec = window.MBPericope.decodeShare(hash);
        if (!dec.ok) {
            if (/^unknown version/.test(dec.error || '')) {
                return fail('This link was made by a newer version of MEGABIBLE than this one understands.');
            }
            return fail('This link is damaged and could not be read (' + dec.error + ').');
        }

        var res = window.MBPericope.importShared(dec);
        if (!res) { return fail('The board could not be saved here — this browser\'s storage may be full or blocked.'); }

        var jobs = [], i, dc;
        for (i = 0; i < dec.cards.length; i++) {
            dc = dec.cards[i];
            if (dc.type === 'verse' && res.cardIds[i]) { jobs.push({ dc: dc, cid: res.cardIds[i] }); }
        }

        var doneUrl = ((CFG.hubUrl || '/extras/pericope').replace(/\/$/, '')) + '/' + encodeURIComponent(res.board.slug);
        function finish() { location.replace(doneUrl); }

        if (!jobs.length) { return finish(); }
        say('Rebuilding verse 1 of ' + jobs.length + '\u2026');

        var at = 0;
        function next() {
            if (at >= jobs.length) { return finish(); }
            var job = jobs[at++];
            say('Rebuilding verse ' + at + ' of ' + jobs.length + '\u2026');
            fetchCard(job.dc, job.cid).then(next, next);   // a miss never stops the train
        }

        function fetchCard(dc, cid) {
            var meta = (CFG.bookMeta || {})[dc.osis];
            if (!meta || !CFG.cardTxUrl) { return Promise.resolve(); }   // board opens; self-heal can't help either, but nothing breaks
            var vmin = dc.verses[0], vmax = dc.verses[0], v;
            for (v = 1; v < dc.verses.length; v++) {
                vmin = Math.min(vmin, dc.verses[v]);
                vmax = Math.max(vmax, dc.verses[v]);
            }
            var url = CFG.cardTxUrl +
                '?book=' + encodeURIComponent(meta.slug) +
                '&chapter=' + encodeURIComponent(dc.ch) +
                '&v=' + encodeURIComponent(vmin === vmax ? String(vmin) : vmin + '-' + vmax);
            return fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (data) {
                    var list = (data && data.translations) || [];
                    if (!list.length) { return; }
                    var entry = null, e;
                    for (e = 0; e < list.length; e++) { if (list[e].abbr === dc.tx) { entry = list[e]; break; } }
                    var fallback = !entry;
                    if (fallback) { entry = list[0]; }
                    if (!entry || !entry.verses || !entry.verses.length) { return; }
                    // Only the verses the sender actually captured (non-
                    // contiguous focus selections stay non-contiguous).
                    var wanted = {}, pairs = [], texts = [], row;
                    for (v = 0; v < dc.verses.length; v++) { wanted[dc.verses[v]] = true; }
                    for (v = 0; v < entry.verses.length; v++) {
                        row = entry.verses[v];
                        if (row && wanted[row[0]]) { pairs.push(row); texts.push(row[1]); }
                    }
                    if (!pairs.length) { return; }
                    if (fallback) { window.MBPericope.setCardTx(res.board.id, cid, entry.abbr); }
                    window.MBPericope.setCardVerses(res.board.id, cid, pairs, texts.join(' '));
                });
        }

        next();
    }

    /* =================== wiring =================== */

    function init() {
        B = window.MBPericopeBoard;
        if (!B || !window.MBPericope || !window.MBPericope.encodeShare) { return; }
        root  = document.getElementById('pb-share');
        panel = root ? root.querySelector('.pbs-panel') : null;
        if (!root || !panel) { return; }
        // A <details>: the summary opens it natively; we fill it on open.
        root.addEventListener('toggle', function () {
            if (!root.open) { return; }
            if (window.MBPericopeDrag && window.MBPericopeDrag.active()) { root.open = false; return; }
            open();
        });
        // Outside clicks close it, like the Aa panel (the folder itself stays)
        // — except clicks inside a running presentation, and its Escape:
        // the user was in this panel when the deck opened and should land
        // back in it when the deck closes.
        function presenting() { return !!(window.MBPericopePresent && window.MBPericopePresent.active()); }
        document.addEventListener('click', function (e) {
            if (presenting() || (e.target.closest && e.target.closest('.pbp'))) { return; }
            if (root.open && !root.contains(e.target)) { root.open = false; }
        });
        document.addEventListener('keydown', function (e) {
            if (presenting()) { return; }
            if ((e.key === 'Escape' || e.key === 'Esc') && root.open) { root.open = false; }
        });
        document.addEventListener('mb:present-closed', function () { root.open = true; });
    }

    window.MBPericopeShare = { open: open, close: close };

    function boot() {
        var importRoot = document.getElementById('pbi-root');
        if (importRoot) { runImport(importRoot); return; }
        if (window.MBPericopeBoard) { init(); }
        else { document.addEventListener('mb:pericope-board-ready', init); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
})();
