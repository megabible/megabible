/* ======================================================================
   PERICOPE BOARD — PRESENTATION MODE          public/js/pericope-present.js
   ----------------------------------------------------------------------
   The board as slides, fullscreen. Opened from the share panel's
   Presentation button (pericope-share.js) or MBPericopePresent.open().

   THE DECK comes from MBPericope.slides(board) — pure, tested in Node:
   strict reading order (column ascending, then row), a group per slide,
   headings as title slides, notes left out, long passages continued over
   "cont." slides at SLIDE.chars. Parts flagged il (their card carries an
   interlinear child, card-edit Phase 4) draw the original-language trio
   BESIDE the verse text — stacked under 900px — from the BOARD's session
   token cache (B.interlinearData / B.fetchInterlinear): the deck itself
   stays token-free, and open() warms the cache for every il part up
   front. This file only paints and navigates.

   SETTINGS live in localStorage as mb.present (JSON; a bare "dark"/"light"
   string from the first pass still reads): look, font, alignment, tint
   colour, wall pattern and its density, and an optional custom Google
   Fonts URL. They are the presenter's — never the board's — so they are
   NOT in the share URL. The share panel's gear (pericope-share.js) edits
   them through MBPericopePresent.prefs()/setPrefs(); a change applies
   live if a deck is open. Styles: bible/partials/present-styles.

   FONTS: the four built-ins are self-hosted (public/fonts/present/*.woff2,
   @font-face in present-styles) — no request leaves the site. A custom
   font is the one exception: a fonts.googleapis.com css2 URL, loaded as a
   <link> the first time a deck opens with it, and only then.

   FULLSCREEN where the browser allows it (requestFullscreen on the
   overlay); iPhone Safari has no Fullscreen API for anything but video,
   so there the overlay simply covers the viewport with the body locked —
   same picture, browser chrome kept. Leaving fullscreen (Esc, the system
   gesture) closes the deck; closing the deck leaves fullscreen.

   TEXT FIT: the slide's type is a clamp() of the viewport; after each
   paint the body is measured and --pbp-scale stepped down until it fits,
   so a long slide never clips. The budget in slides() keeps that from
   ever going far.

   Vanilla ES5. Attaches through window.MBPericopeBoard on
   mb:pericope-board-ready, like drag / edit / share.
   ====================================================================== */
(function () {
    'use strict';

    var PREFS_KEY  = 'mb.present';
    var IDLE_MS    = 2600;      // chrome fades after this much stillness
    var SWIPE_PX   = 40;
    var SCALE_MIN  = .42, SCALE_STEP = .05;
    var MANY_CHARS = 650;       // past this a wide slide goes two-column

    var B = null, CFG = window.MBPericopeBoardConfig || {};
    var BOOK_META = CFG.bookMeta || {};

    var el = null, wall = null, stage = null, counter = null, lookBtn = null;
    var deck = [], at = 0, isOpen = false;

    /* ---- settings -------------------------------------------------------- */
    var FONTS = {
        jim:     { label: 'Jim Nightshade', family: '"Jim Nightshade"' },
        rocker:  { label: 'New Rocker',     family: '"New Rocker"' },
        tinos:   { label: 'Tinos',          family: 'Tinos' },
        calsans: { label: 'Cal Sans',       family: '"Cal Sans"' }
    };
    var PATTERNS = ['none', 'diagonal', 'grid', 'dots', 'crosshatch'];
    // look '' = follow the site theme (parchment/pure → light, midnight/
    // terminal → dark) until the user flips it in the deck.
    var DEFAULTS = { look: '', font: 'tinos', customUrl: '', align: 'center', color: '', pattern: 'diagonal', density: 1 };
    var CUSTOM_FONTS = false;   // custom Google Font: built, off for launch (see pericope-share.js)
    var prefs = null;
    var GOOGLE_CSS = /^https:\/\/fonts\.googleapis\.com\/css2?\?/;
    var loadedFontUrls = {};

    function loadPrefs() {
        var p = {}, k;
        for (k in DEFAULTS) { if (DEFAULTS.hasOwnProperty(k)) { p[k] = DEFAULTS[k]; } }
        try {
            var raw = localStorage.getItem(PREFS_KEY);
            if (raw === 'dark' || raw === 'light') { p.look = raw; }          // first-pass format
            else if (raw) { var o = JSON.parse(raw); if (o && typeof o === 'object') { for (k in o) { if (o.hasOwnProperty(k)) { p[k] = o[k]; } } } }
        } catch (_) {}
        prefs = sanitizePrefs(p);
        return prefs;
    }
    function sanitizePrefs(p) {
        var colors = (window.MBPericope && window.MBPericope.GROUP_COLORS) || [];
        var q = {};
        q.look      = (p.look === 'light' || p.look === 'dark') ? p.look : '';
        q.font      = FONTS[p.font] ? p.font : (p.font === 'custom' && CUSTOM_FONTS ? 'custom' : DEFAULTS.font);
        q.customUrl = (typeof p.customUrl === 'string' && GOOGLE_CSS.test(p.customUrl)) ? p.customUrl.slice(0, 600) : '';
        if (q.font === 'custom' && !q.customUrl) { q.font = DEFAULTS.font; }
        q.align     = (p.align === 'left' || p.align === 'right') ? p.align : 'center';
        q.color     = (colors.indexOf(p.color) >= 0) ? p.color : '';
        q.pattern   = PATTERNS.indexOf(p.pattern) >= 0 ? p.pattern : DEFAULTS.pattern;
        q.density   = (p.density === 0 || p.density === 2) ? p.density : 1;
        return q;
    }
    function getPrefs() { if (!prefs) { loadPrefs(); } var c = {}, k; for (k in prefs) { if (prefs.hasOwnProperty(k)) { c[k] = prefs[k]; } } return c; }
    function setPrefs(patch) {
        var p = getPrefs(), k;
        for (k in patch) { if (patch.hasOwnProperty(k)) { p[k] = patch[k]; } }
        prefs = sanitizePrefs(p);
        try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch (_) {}
        if (el) { applyPrefs(); }
        if (isOpen) { paint(); }
        try { document.dispatchEvent(new CustomEvent('mb:present-prefs', { detail: getPrefs() })); } catch (_) {}
        return getPrefs();
    }

    // "Lora" from https://fonts.googleapis.com/css2?family=Lora:wght@400;700&display=swap
    function customFamily(url) {
        var m = /[?&]family=([^&:]+)/.exec(url || '');
        if (!m) { return ''; }
        try { return decodeURIComponent(m[1]).replace(/\+/g, ' ').trim(); } catch (_) { return ''; }
    }
    function ensureCustomFont(url) {
        if (!url || loadedFontUrls[url] || !GOOGLE_CSS.test(url)) { return; }
        var link = document.createElement('link');
        link.rel = 'stylesheet'; link.href = url; link.setAttribute('data-pbp-font', '1');
        document.head.appendChild(link);
        loadedFontUrls[url] = true;
    }
    function fontFamily() {
        if (prefs.font === 'custom') {
            var fam = customFamily(prefs.customUrl);
            return fam ? '"' + fam.replace(/"/g, '') + '", ' + FONTS.tinos.family : FONTS.tinos.family;
        }
        return FONTS[prefs.font].family;
    }

    // Push every setting onto the overlay as attributes / custom properties;
    // the CSS does the rest. Called on open and on every setPrefs.
    function applyPrefs() {
        if (!el) { return; }
        var look = resolvedLook();
        el.classList.toggle('is-light', look === 'light');
        el.classList.toggle('is-dark',  look === 'dark');
        el.setAttribute('data-align',   prefs.align);
        el.setAttribute('data-pattern', prefs.pattern);
        el.setAttribute('data-density', String(prefs.density));
        el.classList.toggle('has-tint', !!prefs.color);
        el.style.setProperty('--pp-tint', prefs.color ? 'var(--tl-' + prefs.color + ')' : 'var(--pp-accent)');
        el.style.setProperty('--pp-font', fontFamily());
        if (prefs.font === 'custom' && CUSTOM_FONTS) { ensureCustomFont(prefs.customUrl); }
        if (lookBtn) {
            lookBtn.setAttribute('aria-label', look === 'dark' ? 'Switch to light look' : 'Switch to dark look');
            lookBtn.title = lookBtn.getAttribute('aria-label');
        }
    }
    var idleTimer = null, savedScrollY = 0, wentFullscreen = false;
    var touchX = null, touchY = null;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    /* ---- references (mirrors pericope-board.js displayRef) --------------- */
    function displayRef(card) {
        if (B && B.displayRef) { return B.displayRef(card); }
        var d = '\u2013';
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + d + card.v2);
        var meta = BOOK_META[card.osis];
        if (!meta) { return (card.osis || '?') + ' ' + card.ch + ':' + vparam; }
        if (meta.single) { return meta.name + ' ' + vparam; }
        return meta.name + ' ' + (card.ch + (meta.off || 0)) + ':' + vparam;
    }
    // "Romans 8:28–30" for a part that carries only some of a card's verses
    // (a continuation): the range shown is the range on THIS slide.
    function partRef(part) {
        var c = part.card;
        if (part.verses && part.verses.length) {
            var f = part.verses[0][0], l = part.verses[part.verses.length - 1][0];
            return displayRef({ osis: c.osis, ch: c.ch, v1: f, v2: l });
        }
        return displayRef(c);
    }
    function txLabel(part) {
        var t = part.card.tx;
        return t ? String(t).toUpperCase() : '';
    }

    function siteTheme() {
        try { if (window.MB && window.MB.reader) { return window.MB.reader.get().theme || ''; } } catch (_) {}
        return document.documentElement.getAttribute('data-theme') || '';
    }
    function resolvedLook() {
        if (prefs.look) { return prefs.look; }
        var t = siteTheme();
        return (t === 'midnight' || t === 'terminal') ? 'dark' : 'light';
    }
    function toggleLook() { setPrefs({ look: resolvedLook() === 'dark' ? 'light' : 'dark' }); }

    /* ---- build ----------------------------------------------------------- */
    var ICON_SUN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    var ICON_CLOSE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    function build() {
        if (el) { return; }
        el = document.createElement('div');
        el.className = 'pbp';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', 'Presentation');
        el.hidden = true;
        el.innerHTML =
            '<div class="pbp-wall" aria-hidden="true"></div>' +
            '<div class="pbp-stage" id="pbp-stage"></div>' +
            '<div class="pbp-chrome">' +
                '<button type="button" class="pbp-btn" id="pbp-look">' + ICON_SUN + '</button>' +
                '<button type="button" class="pbp-btn" id="pbp-close" aria-label="Exit presentation" title="Exit (Esc)">' + ICON_CLOSE + '</button>' +
            '</div>' +
            '<div class="pbp-counter" id="pbp-counter" aria-live="polite"></div>' +
            '<div class="pbp-nav pbp-nav-prev" aria-hidden="true"></div>' +
            '<div class="pbp-nav pbp-nav-next" aria-hidden="true"></div>';
        document.body.appendChild(el);
        wall    = el.querySelector('.pbp-wall');
        stage   = el.querySelector('#pbp-stage');
        counter = el.querySelector('#pbp-counter');
        lookBtn = el.querySelector('#pbp-look');

        lookBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleLook(); });
        el.querySelector('#pbp-close').addEventListener('click', function (e) { e.stopPropagation(); close(); });

        // Tap zones: left third back, the rest forward. Chrome buttons stop
        // propagation above, so they never page.
        el.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.pbp-btn')) { return; }
            var x = e.clientX, w = el.clientWidth;
            if (x < w / 3) { go(at - 1); } else { go(at + 1); }
            wake();
        });
        el.addEventListener('touchstart', function (e) {
            var t = e.touches && e.touches[0];
            touchX = t ? t.clientX : null; touchY = t ? t.clientY : null;
        }, { passive: true });
        el.addEventListener('touchend', function (e) {
            if (touchX == null) { return; }
            var t = e.changedTouches && e.changedTouches[0];
            if (!t) { return; }
            var dx = t.clientX - touchX, dy = t.clientY - touchY;
            touchX = touchY = null;
            if (Math.abs(dx) < SWIPE_PX || Math.abs(dx) < Math.abs(dy)) { return; }
            e.preventDefault();
            go(dx < 0 ? at + 1 : at - 1);
            wake();
        });
        el.addEventListener('mousemove', wake, { passive: true });

        document.addEventListener('keydown', onKey);
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        window.addEventListener('resize', function () { if (isOpen) { fit(); } });
    }

    /* ---- interlinear panes (card-edit Phase 4) ---------------------------- */
    // Tokens come from the BOARD's session cache through B.interlinearData —
    // never stored, never in the deck. paint() notes when a pane is still
    // waiting (ilPendingPainted) so warmInterlinear's resolutions repaint
    // the CURRENT slide, and only when it actually showed a placeholder.
    var ilPendingPainted = false;

    function ilTranslitHtml(val) {
        return esc(val).split('.').join('<span class="syl-sep">\u00B7</span>');
    }

    function ilPaneHtml(part) {
        if (!B || !B.interlinearData) { return ''; }
        var data = B.interlinearData(part.card);
        if (data.state === 'pending') {
            ilPendingPainted = true;
            return '<div class="pbp-il"><p class="pbp-il-pending">Loading original text\u2026</p></div>';
        }
        if (data.state !== 'ready') { return ''; }   // no coverage: the verse stands alone
        // A continuation part shows only ITS verses' rows.
        var want = null, k;
        if (part.verses) {
            want = {};
            for (k = 0; k < part.verses.length; k++) { want[part.verses[k][0]] = true; }
        }
        // Each TOKEN is a vertical stack — original / transliteration /
        // gloss for that one word — and the stacks flow as a wrapping row,
        // so a reader can see at a glance which word means what (item 2).
        // The row's writing direction follows the language (RTL Hebrew
        // reads its words right-to-left, but each stack stays upright).
        // No credit on the slide — it lives on the board card (item 1).
        var h = '<div class="pbp-il">', i, v, t, tok, rtl;
        var multi = data.verses.length > 1;
        for (i = 0; i < data.verses.length; i++) {
            v = data.verses[i];
            if (want && !want[v.n]) { continue; }
            rtl = v.lang.rtl;
            h += '<div class="pbp-il-verse' + (rtl ? ' is-rtl' : '') + '">';
            if (multi) { h += '<span class="pbp-il-vn">' + esc(v.n) + '</span>'; }
            h += '<div class="pbp-il-words"' + (rtl ? ' dir="rtl"' : '') + '>';
            for (t = 0; t < v.tokens.length; t++) {
                tok = v.tokens[t];
                h += '<div class="pbp-il-word">' +
                         '<span class="pbp-il-original"' + (rtl ? ' dir="rtl"' : '') + '>' + esc(tok[0] || '\u00B7') + '</span>' +
                         '<span class="pbp-il-translit">' +
                             (String(tok[1] || '').indexOf('.') !== -1 ? ilTranslitHtml(tok[1]) : esc(tok[1] || '\u00B7')) +
                         '</span>' +
                         '<span class="pbp-il-gloss">' + esc(tok[2] || '\u00B7') + '</span>' +
                     '</div>';
            }
            h += '</div></div>';
        }
        h += '</div>';
        return h;
    }

    // Warm the cache for every il part in the deck (open() calls this); a
    // settled fetch repaints the current slide iff it painted a placeholder.
    function warmInterlinear() {
        if (!B || !B.fetchInterlinear) { return; }
        var seen = {}, i, j, s, p;
        for (i = 0; i < deck.length; i++) {
            s = deck[i];
            if (!s.parts) { continue; }
            for (j = 0; j < s.parts.length; j++) {
                p = s.parts[j];
                if (p.il && p.card && !seen[p.card.id]) {
                    seen[p.card.id] = true;
                    B.fetchInterlinear(p.card).then(onIlSettled);
                }
            }
        }
    }
    function onIlSettled() {
        if (isOpen && ilPendingPainted) { paint(); }
    }

    /* ---- paint ----------------------------------------------------------- */
    function slideHtml(s, i) {
        if (s.kind === 'title' || s.kind === 'heading') {
            return '<div class="pbp-slide is-title' + (s.kind === 'title' ? ' is-cover' : '') + '">' +
                   '<h2 class="pbp-title">' + esc(s.text) + '</h2>' +
                   (s.sub ? '<p class="pbp-sub">' + esc(s.sub) + '</p>' : '') +
                   '</div>';
        }
        var chars = 0, j, k, part, txt;
        for (j = 0; j < s.parts.length; j++) { chars += (s.parts[j].text || '').length; if (s.parts[j].verses) { for (k = 0; k < s.parts[j].verses.length; k++) { chars += s.parts[j].verses[k][1].length; } } }
        // Wide slides spread long content over two columns (the margins are
        // there to be used); the CSS only does it above 900px.
        // A slide with an interlinear pane never goes two-column — the duo
        // already spends the width (Phase 4).
        var hasIl = false;
        for (j = 0; j < s.parts.length; j++) { if (s.parts[j].il) { hasIl = true; break; } }
        var many = !hasIl && (s.parts.length >= 3 || chars > MANY_CHARS);
        var h = '<div class="pbp-slide is-' + s.kind + (many ? ' is-many' : '') + '">';
        if (s.kind === 'group' && s.label) {
            h += '<h2 class="pbp-group">' + esc(s.label) + (s.cont ? ' <span class="pbp-cont">continued</span>' : '') + '</h2>';
        } else if (s.cont) {
            h += '<p class="pbp-kicker"><span class="pbp-cont">continued</span></p>';
        }
        h += '<div class="pbp-body">';
        for (j = 0; j < s.parts.length; j++) {
            part = s.parts[j];
            var body = '<p class="pbp-text">';
            if (part.verses) {
                var numbered = part.verses.length > 1 || part.card.v1 !== part.card.v2;
                for (k = 0; k < part.verses.length; k++) {
                    if (numbered) { body += '<sup class="pbp-vn">' + esc(part.verses[k][0]) + '</sup>'; }
                    body += esc(part.verses[k][1]) + (k < part.verses.length - 1 ? ' ' : '');
                }
            } else {
                txt = part.text || '';
                body += txt ? esc(txt) : '<span class="pbp-empty">(text not yet loaded — open the card on the board once)</span>';
            }
            body += '</p>';
            var ref = '<p class="pbp-ref">' + esc(partRef(part)) +
                      (txLabel(part) ? '<span class="pbp-tx">' + esc(txLabel(part)) + '</span>' : '') + '</p>';
            // A part with an interlinear child becomes a DUO: verse text and
            // the trio side by side, stacked under 900px (Phase 4). The ref
            // (book·chapter·verse·translation) rides with the VERSE text
            // column only — it names that translation, not the gloss (item
            // 0) — so it goes INSIDE the verse column here, and stays after
            // the part for a plain slide.
            if (part.il) {
                h += '<div class="pbp-part has-il">' +
                         '<div class="pbp-duo">' +
                             '<div class="pbp-verse-col">' + body + ref + '</div>' +
                             ilPaneHtml(part) +
                         '</div>' +
                     '</div>';
            } else {
                h += '<div class="pbp-part">' + body + ref + '</div>';
            }
        }
        h += '</div></div>';
        return h;
    }

    function paint() {
        var s = deck[at];
        ilPendingPainted = false;                 // slideHtml re-arms it if a pane waits
        stage.innerHTML = slideHtml(s, at);
        counter.textContent = (at + 1) + ' / ' + deck.length;
        // Each slide's tint drifts a little around the chosen hue so a deck
        // isn't one flat colour: ±20° in a stride that never repeats early.
        el.style.setProperty('--pbp-hue', (((at * 47) % 41) - 20) + 'deg');
        fit();
        // A fresh slide fades in.
        var node = stage.firstChild;
        if (node && window.requestAnimationFrame) {
            requestAnimationFrame(function () { node.classList.add('is-in'); });
        } else if (node) { node.classList.add('is-in'); }
    }

    // Step --pbp-scale down until the body fits the stage.
    function fit() {
        var node = stage.firstChild;
        if (!node) { return; }
        var scale = 1;
        node.style.setProperty('--pbp-scale', '1');
        var guard = 0;
        while (node.scrollHeight > stage.clientHeight + 1 && scale > SCALE_MIN && guard++ < 20) {
            scale = Math.max(SCALE_MIN, scale - SCALE_STEP);
            node.style.setProperty('--pbp-scale', String(scale));
        }
    }

    function go(i) {
        if (!isOpen) { return; }
        if (i < 0 || i >= deck.length || i === at) { return; }
        at = i;
        paint();
    }

    /* ---- chrome idle ----------------------------------------------------- */
    function wake() {
        if (!el) { return; }
        el.classList.remove('is-idle');
        if (idleTimer) { clearTimeout(idleTimer); }
        idleTimer = setTimeout(function () { if (isOpen) { el.classList.add('is-idle'); } }, IDLE_MS);
    }

    /* ---- keys ------------------------------------------------------------ */
    function onKey(e) {
        if (!isOpen) { return; }
        var k = e.key;
        if (k === 'Escape' || k === 'Esc') { e.preventDefault(); close(); return; }
        if (k === 'ArrowRight' || k === 'ArrowDown' || k === 'PageDown' || k === ' ' || k === 'Enter') { e.preventDefault(); go(at + 1); }
        else if (k === 'ArrowLeft' || k === 'ArrowUp' || k === 'PageUp' || k === 'Backspace') { e.preventDefault(); go(at - 1); }
        else if (k === 'Home') { e.preventDefault(); go(0); }
        else if (k === 'End')  { e.preventDefault(); go(deck.length - 1); }
        else if (k === 'l' || k === 'L') { toggleLook(); }
        else { return; }
        wake();
    }

    /* ---- fullscreen + body lock ------------------------------------------ */
    function fsElement() { return document.fullscreenElement || document.webkitFullscreenElement || null; }
    function enterFullscreen() {
        var fn = el.requestFullscreen || el.webkitRequestFullscreen;
        if (!fn) { return; }
        try {
            var r = fn.call(el, { navigationUI: 'hide' });
            wentFullscreen = true;
            if (r && r.catch) { r.catch(function () { wentFullscreen = false; }); }
        } catch (_) { wentFullscreen = false; }
    }
    function exitFullscreen() {
        if (!fsElement()) { return; }
        var fn = document.exitFullscreen || document.webkitExitFullscreen;
        if (fn) { try { var r = fn.call(document); if (r && r.catch) { r.catch(function () {}); } } catch (_) {} }
    }
    function onFullscreenChange() {
        // The system took us out (Esc, swipe, home button): the deck ends.
        if (isOpen && wentFullscreen && !fsElement()) { close(); }
    }
    function lockBody() {
        savedScrollY = window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = (-savedScrollY) + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    }
    function unlockBody() {
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        window.scrollTo(window.pageXOffset || 0, savedScrollY);
    }

    /* ---- open / close ---------------------------------------------------- */
    function open() {
        if (isOpen) { return; }
        var board = B && B.board();
        if (!board || !window.MBPericope || !window.MBPericope.slides) { return; }
        deck = window.MBPericope.slides(board);
        if (!deck.length) { return; }
        warmInterlinear();
        build();
        loadPrefs(); applyPrefs();
        at = 0; isOpen = true;
        lockBody();
        el.hidden = false;
        el.classList.remove('is-idle');
        enterFullscreen();
        paint();
        wake();
        try { el.querySelector('#pbp-close').focus(); } catch (_) {}
        if (window.MBActs) { window.MBActs.log('pericope.present', { id: board.id, name: board.name, slides: deck.length }); }
    }

    function close() {
        if (!isOpen) { return; }
        isOpen = false;
        wentFullscreen = false;
        exitFullscreen();
        el.hidden = true;
        stage.innerHTML = '';
        if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
        unlockBody();
        try { document.dispatchEvent(new CustomEvent('mb:present-closed')); } catch (_) {}
    }

    /* ---- wiring ---------------------------------------------------------- */
    function init() {
        B = window.MBPericopeBoard;
        if (!B) { return; }
        // Deployment tripwire (same convention as the board's geometry rN):
        // if DevTools doesn't print this line, the served file is stale.
        if (window.console && console.info) { console.info('[pericope] presenter il-panes r2'); }
        BOOK_META = (window.MBPericopeBoardConfig && window.MBPericopeBoardConfig.bookMeta) || BOOK_META;
    }

    window.MBPericopePresent = {
        open:  open,
        close: close,
        prefs:    getPrefs,
        setPrefs: setPrefs,
        FONTS:    FONTS,
        PATTERNS: PATTERNS,
        customFamily: customFamily,
        fontFamily:   function () { if (!prefs) { loadPrefs(); } return fontFamily(); },
        next:  function () { go(at + 1); },
        prev:  function () { go(at - 1); },
        active: function () { return isOpen; }
    };

    if (window.MBPericopeBoard) { init(); }
    else { document.addEventListener('mb:pericope-board-ready', init); }
})();
