/* ======================================================================
   PERICOPE BOARD — SHARING                      public/js/pericope-share.js
   ----------------------------------------------------------------------
   share r2.1 — SHORT LINKS, brand link display. The panel hands out a
   vanity code minted on the server:

       megabible.net/SweetHoneyedEmber            (bare code = latest)
       megabible.net/SweetHoneyedEmber?v=3        (a re-share, pinned)

   The panel, top to bottom (the Aa panel's anatomy — bold title first):

       Share Pericope                       ← .pbs-head, mirrors .ts-head
       [ ▶ Presentation            ⚙ ]      ← the red pill, unchanged
       ──────────────────────────────       ← .pbs-divider
       …the share body…                     ← one of two states:

       CREATE   blurb + [Create short link]
       LINKED   the LINK CARD · status line · QR ·
                [Delete link]  ([Update link] when edited)  [Copy link]

   THE LINK CARD (r2.1): no more URL trailing off in a one-line input.
   A button-card shows the address the way it reads aloud — the brand
   wordmark small, the code large and fully visible:

       MEGABIBLE.net/            ← the x-brand component's exact markup
       FaithfulCrownedOstrich    ← the code, wrapping when long
                            ?v=3 ← the pin, small + muted, re-shares only

   The wordmark markup is replicated from components/brand.blade (JS
   builds this panel, so the Blade component can't be included here);
   the .mb-brand / .mb-tld classes carry the header-logo treatment and
   size to their surroundings, so this stays in lockstep with every
   other page's wordmark. Clicking the card copies the full URL. A
   GHOST input (#pbs-url, offscreen) still holds that URL verbatim —
   the Copy button, the execCommand fallback, and the native share
   tray all read it, one source of truth.

   State lives on the board document as board.share = {code, secret,
   version, h} via the store's short-link r2 functions. h is
   MBPericope.hashStr of the last-pushed blob — when encodeShare(board)
   hashes differently, the board has changed since it was shared, and
   the panel leads with "Update link" (which PUTs the new blob, bumps
   the version, and shows the ?v=N form; the bare code serves the
   latest version either way). Deleting the link DELETEs the code —
   the same endpoint the store's remove() hook fires when the whole
   board dies. Endpoints + CSRF arrive through window.MBShareLinkCtx
   = { url, csrf }, set by the layout next to MBCollectCtx.

   The GEAR still flips the panel to the presentation settings (font,
   alignment, tint, wall pattern — MBPericopePresent's own prefs); the
   header swaps to "Presentation Settings" while it's open.

   S2 — THE IMPORT DRIVER, same file, other page, UNCHANGED by r2.x.
   When this script finds #pbi-root (the /extras/pericope/shared shell)
   it runs the import instead: the blob arrives in location.hash (legacy
   long links — still honoured forever) or in MBPericopeSharedConfig.blob
   (short links: PericopeShareController::resolve serves this same shell
   with the blob in config). decodeShare → importShared → fetch each
   verse card's text by reference → redirect to the new board.

   S3 — THE QR CODE. A short link is always tiny, so the QR always
   renders (vendored qrcode-generator, MIT, loaded on demand). Always
   dark-on-white in its own backing box regardless of theme. Vanilla ES5.
   ====================================================================== */
(function () {
    'use strict';

    var B = null, panel = null, root = null;
    var qrLibState = 0;      // 0 not requested · 1 loading · 2 ready · 3 failed
    var qrPending = null;    // url waiting for the lib to arrive
    var busy = false;        // one mint/update/delete in flight at a time

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    // The layout's bridge to the share-link endpoints (app.blade, beside
    // MBCollectCtx): { url: POST base, csrf }. PUT/DELETE append /{code}.
    function ctx() { return window.MBShareLinkCtx || null; }

    // The public face of a share record. The server's route shape is
    // simply /{code} on this same host, so the origin is all we need;
    // v1 stays bare, re-shares carry their pin (bare serves latest
    // regardless — the pin is for "this exact revision").
    function shortUrl(rec) {
        return location.origin + '/' + rec.code +
               (rec.version > 1 ? '?v=' + rec.version : '');
    }

    // The x-brand wordmark, verbatim from components/brand.blade — see
    // the header note for why the markup lives here too.
    var BRAND = '<span class="mb-brand">MEGABIBLE<span class="mb-tld">.net</span></span>';

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

        // No title row here — the panel's own header swaps to
        // "Presentation Settings" while the gear is on (share r2).

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
                hint.textContent = 'That doesn’t look like a fonts.googleapis.com css URL with a family= in it.';
                hint.classList.add('is-error');
                return;
            }
            hint.classList.remove('is-error');
            hint.textContent = 'Using “' + fam + '”.';
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
        var gear  = document.getElementById('pbs-gear');
        var share = document.getElementById('pbs-share-body');
        var box   = document.getElementById('pbs-settings');
        var title = document.getElementById('pbs-head-title');
        if (!gear || !share || !box) { return; }
        gear.setAttribute('aria-pressed', on ? 'true' : 'false');
        gear.classList.toggle('is-on', on);
        share.hidden = on;
        box.hidden = !on;
        if (title) { title.textContent = on ? 'Presentation Settings' : 'Share Pericope'; }
        if (on) { syncSettings(); }
    }

    /* =================== the share body =================== */

    // Build the panel's skeleton once; renderShare() fills the live parts
    // on every open and after every server round-trip.
    function buildPanel() {
        if (panel.getAttribute('data-built')) { return; }
        panel.setAttribute('data-built', '1');
        panel.innerHTML =
            '<div class="pbs-head"><span class="pbs-title" id="pbs-head-title">Share Pericope</span></div>' +
            '<div class="pbs-present-row">' +
                '<button type="button" class="pbs-present" id="pbs-present">' + ICON_PRESENT + '<span>Presentation</span></button>' +
                '<button type="button" class="pbs-gear" id="pbs-gear" aria-pressed="false" aria-label="Presentation settings" title="Presentation settings">' + ICON_GEAR + '</button>' +
            '</div>' +
            '<div class="pbs-divider" aria-hidden="true"></div>' +
            '<div id="pbs-share-body">' +
                // CREATE — no link yet.
                '<div id="pbs-make" hidden>' +
                    '<p class="pbs-blurb">Create an easy to remember link. ' +
                        'This mints a snapshot of the board exactly as it currently exists so the link can rebuild it. ' +
                        'Deleting the link or the board will clear your URL.</p>' +
                    '<p class="pbs-error" id="pbs-make-err" hidden></p>' +
                    '<div class="pbs-btns">' +
                        '<button type="button" class="pbs-btn is-primary" id="pbs-mint">Create short link</button>' +
                    '</div>' +
                '</div>' +
                // LINKED — the code exists; maybe the board has moved on.
                // The link card is one <button>: brand wordmark small, the
                // code large, the ?v pin small — click copies. No spaces
                // between the spans: the card reads as the URL it is.
                '<div id="pbs-linked" hidden>' +
                    '<button type="button" class="pbs-link" id="pbs-link" title="Copy link">' +
                        '<span class="pbs-link-brand">' + BRAND + '<span class="pbs-link-slash">/</span></span>' +
                        '<span class="pbs-link-code" id="pbs-link-code"></span>' +
                        '<span class="pbs-link-pin" id="pbs-link-pin" hidden></span>' +
                    '</button>' +
                    '<input type="text" class="pbs-url is-ghost" id="pbs-url" readonly tabindex="-1" aria-hidden="true">' +
                    '<p class="pbs-note" id="pbs-note"></p>' +
                    '<div class="pbs-qr" id="pbs-qr" hidden></div>' +
                    '<p class="pbs-error" id="pbs-linked-err" hidden></p>' +
                    '<div class="pbs-btns">' +
                        '<button type="button" class="pbs-btn is-quiet pbs-del" id="pbs-unlink">Delete link</button>' +
                        '<button type="button" class="pbs-btn is-quiet" id="pbs-native" hidden>Share&hellip;</button>' +
                        '<button type="button" class="pbs-btn is-primary" id="pbs-update" hidden>Update link</button>' +
                        '<button type="button" class="pbs-btn is-primary" id="pbs-copy">Copy link</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pbs-settings" id="pbs-settings" hidden>' + settingsHtml() + '</div>';

        document.getElementById('pbs-present').addEventListener('click', function () {
            if (window.MBPericopePresent) { window.MBPericopePresent.open(); }   // panel stays open underneath
        });
        document.getElementById('pbs-gear').addEventListener('click', function () {
            setSettingsMode(this.getAttribute('aria-pressed') !== 'true');
        });
        wireSettings();

        document.getElementById('pbs-mint').addEventListener('click', mintLink);
        document.getElementById('pbs-update').addEventListener('click', updateLink);
        document.getElementById('pbs-unlink').addEventListener('click', deleteLink);
        document.getElementById('pbs-copy').addEventListener('click', copyUrl);
        document.getElementById('pbs-link').addEventListener('click', copyUrl);

        var native = document.getElementById('pbs-native');
        if (navigator.share) {
            native.addEventListener('click', function () {
                var board = B.board();
                navigator.share({
                    title: (board && board.name) ? board.name + ' — Pericope' : 'Pericope',
                    url: document.getElementById('pbs-url').value
                }).catch(function () { /* user dismissed the tray — not an error */ });
            });
        }
    }

    function showErr(id, msg) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.hidden = !msg;
        el.textContent = msg || '';
    }

    // Paint the share body for the board as it is RIGHT NOW.
    function renderShare(keepErrors) {
        var board = B.board();
        if (!board) { return; }
        buildPanel();

        var make   = document.getElementById('pbs-make');
        var linked = document.getElementById('pbs-linked');
        var present = document.getElementById('pbs-present');
        if (present) { present.disabled = !(board.cards && board.cards.length); }
        if (!keepErrors) { showErr('pbs-make-err', null); showErr('pbs-linked-err', null); }

        var rec = window.MBPericope.shareLink(board.id);
        if (!rec) {
            make.hidden = false;
            linked.hidden = true;
            renderQr(null);
            return;
        }

        make.hidden = true;
        linked.hidden = false;

        var blob    = window.MBPericope.encodeShare(board);
        var changed = !!blob && window.MBPericope.hashStr(blob) !== rec.h;
        var url     = shortUrl(rec);

        // The ghost input carries the FULL url — copy, fallback, and the
        // native tray all read it; the card displays its readable parts.
        document.getElementById('pbs-url').value = url;
        document.getElementById('pbs-link-code').textContent = rec.code;
        var pin = document.getElementById('pbs-link-pin');
        pin.hidden = !(rec.version > 1);
        pin.textContent = rec.version > 1 ? '?v=' + rec.version : '';
        document.getElementById('pbs-link')
            .setAttribute('aria-label', 'Copy link: ' + url);

        var note = document.getElementById('pbs-note');
        note.classList.toggle('is-stale', changed);
        note.textContent = changed
            ? 'This pericope has changed since v' + rec.version +
              ' — update the link to share the changes.'
            : 'Up to date · v' + rec.version;

        var updateBtn = document.getElementById('pbs-update');
        var copyBtn   = document.getElementById('pbs-copy');
        var nativeBtn = document.getElementById('pbs-native');
        updateBtn.hidden = !changed;
        updateBtn.disabled = false;
        updateBtn.textContent = 'Update link';
        copyBtn.className = changed ? 'pbs-btn is-quiet' : 'pbs-btn is-primary';
        copyBtn.textContent = 'Copy link';
        nativeBtn.hidden = changed || !navigator.share;

        renderQr(url);
    }

    /* ---- server round-trips ---------------------------------------------- */

    // One small fetch wrapper: JSON in, {status, data} out; network
    // failures resolve to status 0 so every caller handles one shape.
    function api(method, url, payload) {
        var c = ctx();
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (c && c.csrf) || ''
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(
                function (data) { return { status: r.status, data: data }; },
                function ()     { return { status: r.status, data: null }; }
            );
        }, function () {
            return { status: 0, data: null };
        });
    }

    function netMsg(status) {
        if (status === 0)   { return 'Couldn’t reach MEGABIBLE — check your connection and try again.'; }
        if (status === 419) { return 'This page sat open too long — reload it and try again.'; }
        if (status === 429) { return 'Too many link requests in a row — give it a minute.'; }
        if (status === 422) { return 'The server didn’t recognise this board’s share data.'; }
        return 'Something went wrong on MEGABIBLE’s side (' + status + ') — try again in a moment.';
    }

    function mintLink() {
        if (busy) { return; }
        var board = B.board();
        var c = ctx();
        if (!board) { return; }
        if (!c || !c.url) {
            showErr('pbs-make-err', 'Short links aren’t available right now.');
            try { console.warn('[pericope] share r2.1: window.MBShareLinkCtx missing — add it in app.blade beside MBCollectCtx'); } catch (e) {}
            return;
        }
        var blob = window.MBPericope.encodeShare(board);
        if (!blob) { showErr('pbs-make-err', 'This board couldn’t be encoded for sharing.'); return; }

        var btn = document.getElementById('pbs-mint');
        busy = true; btn.disabled = true; btn.textContent = 'Minting…';

        api('POST', c.url, { blob: blob }).then(function (res) {
            busy = false; btn.disabled = false; btn.textContent = 'Create short link';
            if (res.status === 200 && res.data && res.data.code) {
                window.MBPericope.setShareLink(board.id, {
                    code:    res.data.code,
                    secret:  res.data.secret,
                    version: res.data.version || 1,
                    h:       window.MBPericope.hashStr(blob)
                });
                renderShare();
                return;
            }
            showErr('pbs-make-err', netMsg(res.status));
        });
    }

    function updateLink() {
        if (busy) { return; }
        var board = B.board();
        var c = ctx();
        var rec = board && window.MBPericope.shareLink(board.id);
        if (!board || !rec || !c || !c.url) { return; }
        var blob = window.MBPericope.encodeShare(board);
        if (!blob) { showErr('pbs-linked-err', 'This board couldn’t be encoded for sharing.'); return; }

        var btn = document.getElementById('pbs-update');
        busy = true; btn.disabled = true; btn.textContent = 'Updating…';

        api('PUT', c.url + '/' + encodeURIComponent(rec.code), { blob: blob, secret: rec.secret })
        .then(function (res) {
            busy = false;
            if (res.status === 200 && res.data && res.data.version) {
                window.MBPericope.setShareLink(board.id, {
                    code:    rec.code,
                    secret:  rec.secret,
                    version: res.data.version,
                    h:       window.MBPericope.hashStr(blob)
                });
                renderShare();
                return;
            }
            if (res.status === 404) {
                // The code is gone server-side (unminted by hand, perhaps).
                // Forget it and offer a fresh mint — self-healing.
                window.MBPericope.clearShareLink(board.id);
                renderShare(true);
                showErr('pbs-make-err', 'That link no longer exists on MEGABIBLE — you can mint a fresh one.');
                return;
            }
            renderShare(true);
            showErr('pbs-linked-err', res.status === 403
                ? 'This browser doesn’t hold the key for that link.'
                : netMsg(res.status));
        });
    }

    function deleteLink() {
        if (busy) { return; }
        var board = B.board();
        var c = ctx();
        var rec = board && window.MBPericope.shareLink(board.id);
        if (!board || !rec || !c || !c.url) { return; }
        if (!window.confirm('Delete this short link? Anyone who has it will find nothing there. The board itself stays on this device.')) { return; }

        var btn = document.getElementById('pbs-unlink');
        busy = true; btn.disabled = true;

        api('DELETE', c.url + '/' + encodeURIComponent(rec.code), { secret: rec.secret })
        .then(function (res) {
            busy = false; btn.disabled = false;
            // 204 deleted · 404 already gone — either way, forget it here.
            if (res.status === 204 || res.status === 404) {
                window.MBPericope.clearShareLink(board.id);
                renderShare();
                return;
            }
            showErr('pbs-linked-err', res.status === 403
                ? 'This browser doesn’t hold the key for that link.'
                : netMsg(res.status));
        });
    }

    function open() {
        renderShare();
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
            box.hidden = true;
            box.innerHTML = '';
        }
    }

    function copyUrl() {
        var urlEl = document.getElementById('pbs-url');
        var btnEl = document.getElementById('pbs-copy');
        var card  = document.getElementById('pbs-link');
        function done() {
            btnEl.textContent = 'Copied';
            if (card) { card.classList.add('is-copied'); }
            setTimeout(function () {
                btnEl.textContent = 'Copy link';
                if (card) { card.classList.remove('is-copied'); }
            }, 1400);
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
        // short-link r1: vanity URLs carry the blob in config, not the fragment.
        if (!hash && CFG.blob) { hash = String(CFG.blob); }
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

        // Scroll r4: the import lands in the visitor's preferred view (the
        // hub tiles' rule), skipping the grid dispatcher's second hop.
        var wantScroll = false;
        try {
            var vv = localStorage.getItem('mb.pericope.view');
            wantScroll = vv === 'scroll' ||
                (!vv && !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches));
        } catch (e) {}
        var doneUrl = ((CFG.hubUrl || '/extras/pericope').replace(/\/$/, '')) + '/' +
                      encodeURIComponent(res.board.slug) + (wantScroll ? '/scroll' : '');
        function finish() { location.replace(doneUrl); }

        if (!jobs.length) { return finish(); }
        say('Rebuilding verse 1 of ' + jobs.length + '…');

        var at = 0;
        function next() {
            if (at >= jobs.length) { return finish(); }
            var job = jobs[at++];
            say('Rebuilding verse ' + at + ' of ' + jobs.length + '…');
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
        try { console.log('[pericope] share r2.1 — short links'); } catch (e) {}
        var importRoot = document.getElementById('pbi-root');
        if (importRoot) { runImport(importRoot); return; }
        if (window.MBPericopeBoard) { init(); }
        else { document.addEventListener('mb:pericope-board-ready', init); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
})();
