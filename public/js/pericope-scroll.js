/* ======================================================================
   PERICOPE — SCROLL PAGE                        public/js/pericope-scroll.js
   ----------------------------------------------------------------------
   The board as a feed, on ITS OWN PAGE (scroll r3): /{slug}/scroll — a
   read-only sibling of the grid, which stays the source of truth. This
   page loads no editing machinery; the script resolves the slug against
   window.MBPericope (the store, loaded by app.blade) with MBPericope.get,
   and paints posts from MBPericope.feed() — the same reading-order walk
   the presenter's deck uses, tested in Node (tests/js/pericope-feed.test.js).
   Grid order and grouping decide the whole flow; a card or a group is one
   POST, headings are dividers, notes are left out, and a long passage
   continues over CAROUSEL pages at FEED.chars.

   STATES: slug doesn't resolve → #pb-missing (boards are device-local;
   the address alone can't carry one). Board with no verse cards →
   #pb-empty. Otherwise the feed builds once — no rebuild machinery, no
   grid to collapse: edits happen on the grid page and this page is fresh
   on every visit.

   THE VIEW PILL (#pb-view) is plain navigation now: Scroll is current,
   Grid is a link to the grid URL. Choosing writes mb.pericope.view (the
   grid shell's inline dispatcher reads it); merely VISITING either page
   never rewrites the preference.

   ONE POST, top to bottom:
     head      section-coloured circle · "John 3:16" (a live reader link) ·
               the translation beneath. A group: its colour, its label,
               the members' refs beneath (each a reader link) — no
               translation line.
     box       the content — a gradient backdrop with the verse painted on
               it, 16:9 / 1:1 / 4:5 by text weight (post.ratio), pages
               swiping sideways with dots when there are more than one
     actions   heart / comment / share
     likes     "You like this" / "1 like" / "23 likes" / "1.2k likes" —
               the server aggregate, moved optimistically on a toggle
     summary   "3 verses from the Torah and Nevi’im" — canon sections from
               bookMeta.section + config.sectionLabels

   THE FOLDER (scroll r3) carries four apps: HOME (Δ) scrolls the feed
   back to the top; ZOOM is the future 3-across profile view (a quiet
   toast until it lands); SHARE opens the same link + QR panel the grid
   has, except the header pill reads "Scroll" and its gear opens SCROLL
   SETTINGS instead of presentation settings; Aa is Aa.

   SCROLL SETTINGS (mb.scroll — its own key; mb.present belongs to the
   presenter): font 'random' (the per-post seeded pick) or one of the
   four faces pinned; backdrop 'auto' (the seeded gradients — the control
   exists so the media pool from the scroll plan's media phase slots in
   as options). A change saves and rebuilds the feed.

   LAZY: every post gets its shell up front (the box's aspect-ratio fixes
   its height, so the page never jumps); the verse text is painted when
   the box comes within LAZY_MARGIN of the viewport, then FIT — the
   type is a fraction of the box width (cqw), stepped down through
   --pbf-scale until the page fits, the presenter's trick in a container.

   BACKDROPS: v1 is gradients only — the post's section colour from the
   theme palette (--tl-*) blended toward a second palette colour picked
   by the post's seed, at a seeded angle. Everything reads CSS variables,
   so the whole feed repaints under midnight / terminal for free. The
   backdrop() function is the ONE place a future image / video pool
   plugs in: it returns a class and inline vars for the box and nothing
   else here cares which kind it was.

   LIKES (scroll r2): the heart and the double-tap both LIKE; only the
   heart unlikes. Device state in localStorage (mbLikes.v1) keyed by
   MBPericope.likeKey; the SERVER keeps one anonymous aggregate per key
   (verse_likes — the book_visits pattern: no IP, no cookie, dedup is
   this device's own record). A toggle fires one beacon (POST likeUrl,
   fetch + keepalive with the CSRF header, book-seen's shape) and moves
   the painted count optimistically; the build fetches the batch of
   counts in ONE POST (likeCountsUrl) and repaints the like lines when
   it lands. Losses in either direction are acceptable — it's a counter,
   not a ledger. Counts print coarse past 999 ("1.2k").

   THE RECENT RAIL (scroll r4, desktop): the visitor's other boards
   beside the feed as "accounts" — a dot in each board's DOMINANT section
   colour (its most common book colour), the name, "N cards · 3d ago" —
   from MBPericope.list(), newest updated first, the current board left
   out, capped at RAIL_MAX. Rows link straight to each board's scroll
   page (you're in Scroll; you stay in Scroll); the footer links to the
   hub. CSS hides the rail below the desktop breakpoint.

   Vanilla ES5. Deferred after app.blade's deferred pericope-store.js, so
   the store exists when this runs — no readiness event needed.
   ====================================================================== */
(function () {
    'use strict';

    /* ---- knobs ----------------------------------------------------------- */
    var VIEW_KEY    = 'mb.pericope.view';       // 'scroll' | 'grid' — written ONLY by the view pill
    var SCROLL_KEY  = 'mb.scroll';              // scroll settings: { font, backdrop }
    var LIKES_KEY   = 'mbLikes.v1';             // { "<likeKey>": 1, … }
    var QR_COMFORT  = 1000;                     // pericope-share's ceiling for a scannable QR
    var RAIL_MAX    = 6;                        // recent boards shown beside the feed
    var LAZY_MARGIN = '700px';                  // paint a box this far before it scrolls in
    var SCALE_MIN   = .48, SCALE_STEP = .05;    // fit(): floor and step for --pbf-scale
    var DTAP_MS     = 320;                      // two taps this close = a like
    var POP_MS      = 900;                      // heart overlay life
    var TOAST_MS    = 1800;

    // The seed picks a face; the decorative faces only get short posts.
    // A face PINNED in scroll settings overrides the seed (and the weight
    // guard — the pin is the person's own call). Keys are the settings
    // panel's values; families must exist in present-styles' font-faces.
    // Families are SINGLE-quoted: they land inside a double-quoted HTML
    // style attribute (postHtml), where a double-quoted family truncates
    // the attribute at its first inner quote — the post silently loses its
    // font (and looked like "not much variety" rather than an error).
    var FONTS = [
        { key: 'tinos',  label: 'Tinos',          family: 'Tinos',             maxWeight: Infinity },
        { key: 'cal',    label: 'Cal Sans',       family: "'Cal Sans'",        maxWeight: Infinity },
        { key: 'night',  label: 'Jim Nightshade', family: "'Jim Nightshade'",  maxWeight: 200 },
        { key: 'rocker', label: 'New Rocker',     family: "'New Rocker'",      maxWeight: 160 }
    ];
    // The theme palette (app.blade --tl-*), for the gradient's second stop.
    var PALETTE = ['clay', 'slate', 'gold', 'plum', 'terracotta', 'teal',
                   'royal', 'olive', 'crimson', 'indigo', 'moss', 'navy'];

    var CFG = window.MBPericopeBoardConfig || {};
    var BOOK_META = CFG.bookMeta || {}, LABELS = CFG.sectionLabels || {};
    var READER = CFG.readerUrlPattern || '';

    var board = null;                 // the resolved board document (read-only here)
    var root = null, feedEl = null;
    var posts = [], io = null, toastEl = null, toastTimer = null;
    var qrLibState = 0, qrPending = null;   // 0 none · 1 loading · 2 ready · 3 failed
    // Server like counts, keyed by like key. Filled by fetchCounts() after
    // each build; moved optimistically by setLiked() between fetches.
    var counts = {};

    /* ---- small helpers --------------------------------------------------- */
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function readJson(key, fallback) {
        try { var raw = localStorage.getItem(key); return raw ? JSON.parse(raw) : fallback; }
        catch (_) { return fallback; }
    }
    function writeJson(key, val) {
        try { localStorage.setItem(key, JSON.stringify(val)); return true; } catch (_) { return false; }
    }
    function writeView(v) { try { localStorage.setItem(VIEW_KEY, v); } catch (_) {} }
    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    // "A, B, and C" / "A and B" / "A"
    function listWords(items) {
        if (items.length <= 1) { return items.join(''); }
        if (items.length === 2) { return items[0] + ' and ' + items[1]; }
        return items.slice(0, -1).join(', ') + ', and ' + items[items.length - 1];
    }

    /* ---- references (the presenter's, verbatim in spirit) ---------------- */
    function displayRef(card) {
        var d = '\u2013';
        var vparam = card.v1 === card.v2 ? String(card.v1) : (card.v1 + d + card.v2);
        var meta = BOOK_META[card.osis];
        if (!meta) { return (card.osis || '?') + ' ' + card.ch + ':' + vparam; }
        if (meta.single) { return meta.name + ' ' + vparam; }
        return meta.name + ' ' + (card.ch + (meta.off || 0)) + ':' + vparam;
    }
    function partRef(part) {
        var c = part.card;
        if (part.verses && part.verses.length) {
            var f = part.verses[0][0], l = part.verses[part.verses.length - 1][0];
            return displayRef({ osis: c.osis, ch: c.ch, v1: f, v2: l });
        }
        return displayRef(c);
    }
    function bookName(osis) { var m = BOOK_META[osis]; return m ? m.name : (osis || '?'); }
    function bookColor(osis) { var m = BOOK_META[osis]; return (m && m.color) || 'clay'; }
    function txOf(card) { return card.tx ? String(card.tx).toUpperCase() : ''; }

    /* ---- summary line ---------------------------------------------------- */
    // "3 verses from the Torah and Nevi’im". Sections come from each book's
    // canon section key; two keys with one label (both Apocrypha sections)
    // collapse to one word.
    function summaryLine(post) {
        var labels = [], seen = {}, i, meta, key, label;
        for (i = 0; i < post.osis.length; i++) {
            meta  = BOOK_META[post.osis[i]];
            key   = meta && meta.section;
            label = (key && LABELS[key]) || null;
            if (label && !seen[label]) { seen[label] = true; labels.push(label); }
        }
        var n = post.verses + ' verse' + (post.verses === 1 ? '' : 's');
        if (!labels.length) { return n; }
        return n + ' from the ' + listWords(labels);
    }

    /* ---- backdrop -------------------------------------------------------- */
    // The ONE seam for Phase 6 (images / video). Returns what the box
    // wears: a class and a style string. v1: gradient from the post's
    // section colour toward a seeded second palette colour.
    function backdrop(post) {
        var seed = post.seed || 0;
        var base = post.kind === 'group' && post.color ? post.color : bookColor(post.osis[0]);
        var other = PALETTE[seed % PALETTE.length];
        if (other === base) { other = PALETTE[(seed + 5) % PALETTE.length]; }
        var angle = 110 + (seed % 140);                 // 110°–250°: always some diagonal
        var hx = 20 + ((seed >> 3) % 60), hy = 15 + ((seed >> 7) % 50);   // highlight spot
        return {
            cls: 'bd-gradient',
            style: '--pbf-a:var(--tl-' + base + ');--pbf-b:var(--tl-' + other + ');' +
                   '--pbf-angle:' + angle + 'deg;--pbf-hx:' + hx + '%;--pbf-hy:' + hy + '%;'
        };
    }
    function fontFor(post) {
        var pin = prefs().font, i, guard = 0;
        for (i = 0; i < FONTS.length; i++) {
            if (FONTS[i].key === pin) { return FONTS[i].family; }
        }
        i = post.seed % FONTS.length;
        while (FONTS[i].maxWeight < post.weight && guard++ < FONTS.length) { i = (i + 1) % FONTS.length; }
        return FONTS[i].family;
    }

    /* ---- scroll settings (mb.scroll) ------------------------------------- */
    function prefs() {
        var p = readJson(SCROLL_KEY, null) || {};
        return {
            font:     typeof p.font === 'string' ? p.font : 'random',
            backdrop: typeof p.backdrop === 'string' ? p.backdrop : 'auto'
        };
    }
    function setPrefs(patch) {
        var p = prefs(), k;
        for (k in patch) { if (patch.hasOwnProperty(k)) { p[k] = patch[k]; } }
        writeJson(SCROLL_KEY, p);
    }

    /* ---- markup ---------------------------------------------------------- */
    var ICON_HEART   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
    var ICON_COMMENT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l2-4.3A8.4 8.4 0 0 1 3 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 9 8.4z"/></svg>';
    var ICON_SHARE   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    var ICON_LEFT    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>';
    var ICON_RIGHT   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

    function headHtml(post) {
        var color, line1, line2, refs = [], i, c, r, url;
        if (post.kind === 'group') {
            color = post.color || bookColor(post.osis[0]);
            line1 = esc(post.label || 'Group');
            // Each member ref is a live reader link (refs carry tx for
            // exactly this); the separators stay plain text.
            for (i = 0; i < post.refs.length; i++) {
                r   = post.refs[i];
                url = verseUrl(r);
                refs.push(url ? '<a href="' + esc(url) + '">' + esc(displayRef(r)) + '</a>' : esc(displayRef(r)));
            }
            line2 = refs.join(' \u00b7 ');
        } else {
            c     = post.pages[0].parts[0].card;
            color = bookColor(c.osis);
            url   = verseUrl(c);
            line1 = url ? '<a href="' + esc(url) + '">' + esc(displayRef(c)) + '</a>' : esc(displayRef(c));
            line2 = esc(txOf(c));
        }
        return '<header class="pbf-head">' +
                   '<span class="pbf-dot" style="--bk:var(--tl-' + esc(color) + ')" aria-hidden="true"></span>' +
                   '<div class="pbf-who">' +
                       '<div class="pbf-book">' + line1 + '</div>' +
                       (line2 ? '<div class="pbf-ref">' + line2 + '</div>' : '') +
                   '</div>' +
               '</header>';
    }

    function textHtml(part, showRef) {
        var h = '<div class="pbf-part"><p class="pbf-text">', k, numbered;
        if (part.verses) {
            numbered = part.verses.length > 1 || part.card.v1 !== part.card.v2;
            for (k = 0; k < part.verses.length; k++) {
                if (numbered) { h += '<sup class="pbf-vn">' + esc(part.verses[k][0]) + '</sup>'; }
                h += esc(part.verses[k][1]) + (k < part.verses.length - 1 ? ' ' : '');
            }
        } else {
            h += part.text ? esc(part.text) : '<span class="pbf-empty">(text not yet loaded \u2014 open the card on the board once)</span>';
        }
        h += '</p>';
        if (showRef) {
            h += '<p class="pbf-part-ref">' + esc(partRef(part)) + (txOf(part.card) ? ' \u00b7 ' + esc(txOf(part.card)) : '') + '</p>';
        }
        return h + '</div>';
    }

    // The box's pages, painted on demand (see fill).
    function pagesHtml(post) {
        var h = '<div class="pbf-pages" tabindex="0" aria-label="Verse text">', i, j, pg, many;
        for (i = 0; i < post.pages.length; i++) {
            pg = post.pages[i];
            // A group shows each member's own reference; a lone card's
            // reference is already in the head, so its pages stay clean —
            // unless the card continues, when the range on THIS page matters.
            many = post.kind === 'group' || post.pages.length > 1;
            h += '<div class="pbf-page" data-page="' + i + '"><div class="pbf-page-in">';
            if (pg.cont) { h += '<span class="pbf-cont">continued</span>'; }
            for (j = 0; j < pg.parts.length; j++) { h += textHtml(pg.parts[j], many); }
            h += '</div></div>';
        }
        h += '</div>';
        if (post.pages.length > 1) {
            h += '<button type="button" class="pbf-arrow is-prev" aria-label="Previous page" hidden>' + ICON_LEFT + '</button>' +
                 '<button type="button" class="pbf-arrow is-next" aria-label="Next page">' + ICON_RIGHT + '</button>' +
                 '<div class="pbf-dots" aria-hidden="true">';
            for (i = 0; i < post.pages.length; i++) { h += '<i class="pbf-dot-i' + (i ? '' : ' is-on') + '"></i>'; }
            h += '</div>';
        }
        h += '<div class="pbf-pop" aria-hidden="true">' + ICON_HEART + '</div>';
        return h;
    }

    function postHtml(post, idx) {
        if (post.kind === 'heading') {
            return '<div class="pbf-heading" data-id="' + esc(post.id) + '"><h2>' + esc(post.text) + '</h2></div>';
        }
        var bd = backdrop(post);
        var key = window.MBPericope.likeKey(post);
        var liked = !!likes()[key];
        var ratio = post.ratio === '16:9' ? 'is-16x9' : (post.ratio === '4:5' ? 'is-4x5' : 'is-1x1');
        return '<article class="pbf-post is-' + post.kind + '" data-idx="' + idx + '" data-id="' + esc(post.id) + '" data-key="' + esc(key) + '">' +
                   headHtml(post) +
                   '<div class="pbf-box ' + ratio + ' ' + bd.cls + '" style="' + bd.style + '--pbf-font:' + fontFor(post) + ';" data-lazy="1"></div>' +
                   '<div class="pbf-actions">' +
                       '<button type="button" class="pbf-act is-like' + (liked ? ' is-liked' : '') + '" data-act="like" aria-pressed="' + (liked ? 'true' : 'false') + '" aria-label="Like">' + ICON_HEART + '</button>' +
                       '<button type="button" class="pbf-act" data-act="comment" aria-label="Note">' + ICON_COMMENT + '</button>' +
                       '<button type="button" class="pbf-act" data-act="share" aria-label="Share">' + ICON_SHARE + '</button>' +
                   '</div>' +
                   '<p class="pbf-likes">' + esc(likesLine(counts[key] || 0, liked)) + '</p>' +
                   '<p class="pbf-summary">' + esc(summaryLine(post)) + '</p>' +
               '</article>';
    }

    /* ---- lazy fill + fit ------------------------------------------------- */
    function fill(box) {
        if (!box.hasAttribute('data-lazy')) { return; }
        var art = box.parentNode, post = posts[parseInt(art.getAttribute('data-idx'), 10)];
        if (!post) { return; }
        box.removeAttribute('data-lazy');
        box.innerHTML = pagesHtml(post);
        wireCarousel(box);
        fit(box);
    }

    // Step --pbf-scale down on each page until its text fits the box.
    function fit(box) {
        var pages = box.querySelectorAll('.pbf-page'), i, page, inner, scale, guard;
        var h = box.clientHeight;
        if (!h) { return; }
        for (i = 0; i < pages.length; i++) {
            page = pages[i]; inner = page.firstChild;
            if (!inner) { continue; }
            scale = 1; guard = 0;
            inner.style.setProperty('--pbf-scale', '1');
            while (inner.scrollHeight > page.clientHeight - paddingY(page) + 1 && scale > SCALE_MIN && guard++ < 20) {
                scale = Math.max(SCALE_MIN, scale - SCALE_STEP);
                inner.style.setProperty('--pbf-scale', String(scale));
            }
        }
    }
    function paddingY(el) {
        var cs = window.getComputedStyle(el);
        return (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
    }
    function refitAll() {
        if (mode !== 'scroll' || !feedEl) { return; }
        var boxes = feedEl.querySelectorAll('.pbf-box:not([data-lazy])'), i;
        for (i = 0; i < boxes.length; i++) { fit(boxes[i]); }
    }

    function observe() {
        if (io) { io.disconnect(); io = null; }
        var boxes = feedEl.querySelectorAll('.pbf-box[data-lazy]'), i;
        if (!window.IntersectionObserver) {
            for (i = 0; i < boxes.length; i++) { fill(boxes[i]); }
            return;
        }
        io = new IntersectionObserver(function (entries) {
            var j;
            for (j = 0; j < entries.length; j++) {
                if (entries[j].isIntersecting) { fill(entries[j].target); io.unobserve(entries[j].target); }
            }
        }, { rootMargin: LAZY_MARGIN + ' 0px' });
        for (i = 0; i < boxes.length; i++) { io.observe(boxes[i]); }
    }

    /* ---- carousel -------------------------------------------------------- */
    function wireCarousel(box) {
        var pages = box.querySelector('.pbf-pages'), dots = box.querySelectorAll('.pbf-dot-i');
        var prev = box.querySelector('.pbf-arrow.is-prev'), next = box.querySelector('.pbf-arrow.is-next');
        var n = box.querySelectorAll('.pbf-page').length, timer = null;
        if (!pages || n < 2) { return; }
        function current() { return Math.round(pages.scrollLeft / Math.max(1, pages.clientWidth)); }
        function sync() {
            var at = current(), i;
            for (i = 0; i < dots.length; i++) { dots[i].className = 'pbf-dot-i' + (i === at ? ' is-on' : ''); }
            if (prev) { prev.hidden = at <= 0; }
            if (next) { next.hidden = at >= n - 1; }
        }
        function goTo(i) {
            i = Math.max(0, Math.min(n - 1, i));
            try { pages.scrollTo({ left: i * pages.clientWidth, behavior: reducedMotion() ? 'auto' : 'smooth' }); }
            catch (_) { pages.scrollLeft = i * pages.clientWidth; }
        }
        pages.addEventListener('scroll', function () {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(sync, 60);
        });
        if (prev) { prev.addEventListener('click', function (e) { e.stopPropagation(); goTo(current() - 1); }); }
        if (next) { next.addEventListener('click', function (e) { e.stopPropagation(); goTo(current() + 1); }); }
        pages.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { goTo(current() + 1); e.preventDefault(); }
            else if (e.key === 'ArrowLeft') { goTo(current() - 1); e.preventDefault(); }
        });
    }

    /* ---- likes (scroll r2: device state + the anonymous counter) --------- */
    function likes() { var l = readJson(LIKES_KEY, {}); return (l && typeof l === 'object') ? l : {}; }

    // "You like this" / "1 like" / "23 likes" / "1.2k likes". The liker's
    // own like reads as themselves while the crowd is small; past one other
    // device it's just the number.
    function fmtCount(n) {
        if (n < 1000) { return String(n); }
        if (n < 1000000) { return (Math.floor(n / 100) / 10) + 'k'; }
        return (Math.floor(n / 100000) / 10) + 'M';
    }
    function likesLine(n, liked) {
        if (n <= 0) { return liked ? 'You like this' : ''; }
        if (n === 1 && liked) { return 'You like this'; }
        return fmtCount(n) + ' like' + (n === 1 ? '' : 's');
    }
    function paintLikeLine(art) {
        var key = art.getAttribute('data-key'), line = art.querySelector('.pbf-likes');
        if (!key || !line) { return; }
        line.textContent = likesLine(counts[key] || 0, !!likes()[key]);
    }
    function paintAllLikeLines() {
        var arts = feedEl.querySelectorAll('.pbf-post'), i;
        for (i = 0; i < arts.length; i++) { paintLikeLine(arts[i]); }
    }

    function beacon(url, payload) {
        if (!url || !window.fetch) { return; }
        try {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CFG.csrf || ''
                },
                body: JSON.stringify(payload),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function () { /* counter: losses are acceptable */ });
        } catch (_) { /* ancient browsers: nothing to do */ }
    }

    function setLiked(art, on) {
        var key = art.getAttribute('data-key'), l = likes();
        if (!key) { return; }
        if (!!l[key] === !!on) { paintLikeLine(art); return; }   // double-tap on a liked post: no re-count
        if (on) { l[key] = 1; } else { delete l[key]; }
        writeJson(LIKES_KEY, l);
        counts[key] = Math.max(0, (counts[key] || 0) + (on ? 1 : -1));
        var btn = art.querySelector('.pbf-act.is-like');
        if (btn) { btn.classList.toggle('is-liked', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
        paintLikeLine(art);
        beacon(CFG.likeUrl, { key: key, op: on ? 'like' : 'unlike' });
    }

    // One POST for the whole feed's counts; repaint when it lands. Stale
    // responses (a rebuild raced the fetch) can only repaint keys that
    // still exist, so no guard is needed beyond the try/catch.
    function fetchCounts() {
        if (!CFG.likeCountsUrl || !window.fetch || !posts.length) { return; }
        var keys = [], seen = {}, i, k;
        for (i = 0; i < posts.length; i++) {
            if (posts[i].kind === 'heading') { continue; }
            k = window.MBPericope.likeKey(posts[i]);
            if (k && !seen[k]) { seen[k] = true; keys.push(k); }
        }
        if (!keys.length) { return; }
        try {
            fetch(CFG.likeCountsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CFG.csrf || ''
                },
                body: JSON.stringify({ keys: keys }),
                credentials: 'same-origin'
            }).then(function (r) { return r.ok ? r.json() : null; })
             .then(function (data) {
                var k2;
                if (!data || !data.counts) { return; }
                for (k2 in data.counts) {
                    if (data.counts.hasOwnProperty(k2)) { counts[k2] = data.counts[k2] | 0; }
                }
                paintAllLikeLines();
             })
             .catch(function () { /* counts arrive next open */ });
        } catch (_) {}
    }

    function pop(box) {
        var el = box.querySelector('.pbf-pop');
        if (!el) { return; }
        el.classList.remove('is-pop');
        void el.offsetWidth;                          // restart the animation
        el.classList.add('is-pop');
        setTimeout(function () { el.classList.remove('is-pop'); }, POP_MS);
    }

    /* ---- share ------------------------------------------------------------- */
    function shareBase() {
        var hub = CFG.hubUrl || '/extras/pericope';
        return hub.replace(/\/$/, '') + '/shared';
    }
    function verseUrl(card) {
        var meta = BOOK_META[card.osis];
        if (!READER || !meta) { return ''; }
        var url = READER.replace('__TX__', encodeURIComponent(card.tx || ''))
                        .replace('__BOOK__', encodeURIComponent(meta.slug))
                        .replace('__CH__', String(card.ch));
        return url + '?v=' + (card.v1 === card.v2 ? card.v1 : card.v1 + '-' + card.v2);
    }
    function shareUrlFor(post) {
        if (post.kind === 'group') {
            var blob = board && window.MBPericope.encodeShare ? window.MBPericope.encodeShare(board) : '';
            return blob ? shareBase() + '#' + blob : '';
        }
        return verseUrl(post.pages[0].parts[0].card);
    }
    function absolute(url) {
        try { return new URL(url, window.location.href).href; } catch (_) { return url; }
    }
    function share(post) {
        var url = absolute(shareUrlFor(post));
        if (!url) { toast('Nothing to share yet'); return; }
        var title = post.kind === 'group' ? (post.label || 'Pericope') : displayRef(post.pages[0].parts[0].card);
        if (navigator.share) {
            navigator.share({ title: title + ' \u2014 MEGABIBLE', url: url })
                .then(null, function () { /* dismissed: nothing to say */ });
            return;
        }
        copyText(url, function () { toast('Link copied'); }, function () { toast('Copy failed \u2014 ' + url); });
    }
    function copyText(text, ok, fail) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok, function () { legacyCopy(text, ok, fail); });
        } else { legacyCopy(text, ok, fail); }
    }
    function legacyCopy(text, ok, fail) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', '');
        ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        var done = false;
        try { done = document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(ta);
        if (done) { ok(); } else { fail(); }
    }
    function toast(msg) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'pbf-toast';
            toastEl.setAttribute('role', 'status');
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.classList.add('is-on');
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, TOAST_MS);
    }

    /* ---- events ------------------------------------------------------------ */
    var lastTap = 0, lastTapBox = null;

    function onFeedClick(e) {
        var t = e.target, act = null, art, box, post;
        while (t && t !== feedEl) {
            if (!act && t.getAttribute && t.getAttribute('data-act')) { act = t.getAttribute('data-act'); }
            if (t.classList && t.classList.contains('pbf-box')) { box = t; }
            if (t.classList && t.classList.contains('pbf-post')) { art = t; break; }
            t = t.parentNode;
        }
        if (!art) { return; }
        post = posts[parseInt(art.getAttribute('data-idx'), 10)];
        if (act === 'like') {
            setLiked(art, !art.querySelector('.pbf-act.is-like').classList.contains('is-liked'));
            return;
        }
        if (act === 'comment') { toast('Notes are coming soon'); return; }
        if (act === 'share') { share(post); return; }

        // Two quick taps on the box = like (never unlike), heart overlay.
        // dblclick doesn't fire reliably on touch, so time the taps here.
        if (box) {
            var now = Date.now();
            if (lastTapBox === box && now - lastTap < DTAP_MS) {
                lastTap = 0; lastTapBox = null;
                setLiked(art, true);
                pop(box);
            } else { lastTap = now; lastTapBox = box; }
        }
    }

    /* ---- build ------------------------------------------------------------- */
    function build() {
        if (!board || !window.MBPericope || !window.MBPericope.feed) { return; }
        var f = window.MBPericope.feed(board);
        posts = f.posts;

        var nameEl = document.getElementById('pb-name');
        if (nameEl) { nameEl.textContent = f.name; }
        try { document.title = f.name + ' \u2014 MEGABIBLE'; } catch (_) {}

        var html = '', i, count = 0;
        for (i = 0; i < posts.length; i++) {
            html += postHtml(posts[i], i);
            if (posts[i].kind !== 'heading') { count++; }
        }
        feedEl.innerHTML = html;

        var empty = document.getElementById('pb-empty');
        root.hidden = !count;
        if (empty) { empty.hidden = !!count; }
        if (!count) { return; }

        observe();
        fetchCounts();
        if (window.MBActs) {
            window.MBActs.log('pericope.scroll', { id: board.id, name: board.name, posts: count });
        }
    }

    /* ---- the share panel + scroll settings (scroll r3) -------------------
       The grid's share panel is painted by pericope-share.js, which leans
       on the board script and the presenter — neither exists here. This is
       the same panel in the same clothes (.pbs-* / .pps-* classes; the CSS
       rides in scroll-styles), with the header pill reading "Scroll" and
       its gear flipping to SCROLL settings. The share body is the same
       whole-board link + QR the grid ships: the /shared fragment link is
       the only thing that carries a board across devices. */

    var ICON_SCROLL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="12" y2="17"/></svg>';
    var ICON_GEAR   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';

    var panelBuilt = false;

    function settingsHtml() {
        var h = '', i, f;
        h += '<p class="pps-title">Scroll settings</p>';
        h += '<div class="pps-preview" id="pfs-preview" aria-hidden="true"></div>';
        h += '<div class="pps-row"><select class="pps-select" id="pfs-font" aria-label="Post font">';
        h += '<option value="random">Random \u2014 every post its own face</option>';
        for (i = 0; i < FONTS.length; i++) {
            f = FONTS[i];
            h += '<option value="' + f.key + '">' + esc(f.label) + '</option>';
        }
        h += '</select></div>';
        h += '<div class="pps-row"><select class="pps-select" id="pfs-backdrop" aria-label="Post backdrop">' +
             '<option value="auto">Gradients \u2014 painted from each book\u2019s colour</option>' +
             '</select></div>';
        h += '<p class="pps-hint">Image and film backdrops arrive with the media pool.</p>';
        return h;
    }

    function syncSettings() {
        var pr = prefs();
        var fontSel = document.getElementById('pfs-font');
        var bdSel   = document.getElementById('pfs-backdrop');
        var prev    = document.getElementById('pfs-preview');
        if (fontSel) { fontSel.value = pr.font; }
        if (bdSel)   { bdSel.value = pr.backdrop; }
        if (prev) {
            prev.textContent = (board && board.name) || 'Pericope';
            var fam = '', i;
            for (i = 0; i < FONTS.length; i++) { if (FONTS[i].key === pr.font) { fam = FONTS[i].family; } }
            prev.style.fontFamily = fam || 'var(--serif)';
        }
    }

    function setSettingsMode(on) {
        var gear = document.getElementById('pfs-gear');
        var shareBody = document.getElementById('pfs-share-body');
        var box = document.getElementById('pfs-settings');
        if (!gear || !shareBody || !box) { return; }
        gear.classList.toggle('is-on', on);
        gear.setAttribute('aria-pressed', on ? 'true' : 'false');
        shareBody.hidden = on;
        box.hidden = !on;
        if (on) { syncSettings(); }
    }

    function buildPanel() {
        var panel = document.querySelector('#pb-share .pbs-panel');
        if (!panel || panelBuilt) { return; }
        panelBuilt = true;
        panel.innerHTML =
            '<div class="pbs-present-row">' +
                '<span class="pbs-present is-label">' + ICON_SCROLL + '<span>Scroll</span></span>' +
                '<button type="button" class="pbs-gear" id="pfs-gear" aria-pressed="false" aria-label="Scroll settings" title="Scroll settings">' + ICON_GEAR + '</button>' +
            '</div>' +
            '<div id="pfs-share-body">' +
                '<p class="pbs-title">Share this pericope</p>' +
                '<p class="pbs-blurb">The whole board \u2014 cards, placement, groups \u2014 lives inside this link. ' +
                    'Whoever opens it gets their own copy.</p>' +
                '<textarea class="pbs-url" id="pfs-url" readonly rows="3" spellcheck="false"></textarea>' +
                '<p class="pbs-size" id="pfs-size"></p>' +
                '<div class="pbs-qr" id="pfs-qr" hidden></div>' +
                '<div class="pbs-btns">' +
                    '<button type="button" class="pbs-btn is-quiet" id="pfs-native" hidden>Share&hellip;</button>' +
                    '<button type="button" class="pbs-btn is-primary" id="pfs-copy">Copy link</button>' +
                '</div>' +
            '</div>' +
            '<div class="pbs-settings" id="pfs-settings" hidden>' + settingsHtml() + '</div>';

        document.getElementById('pfs-gear').addEventListener('click', function () {
            setSettingsMode(this.getAttribute('aria-pressed') !== 'true');
        });
        var fontSel = document.getElementById('pfs-font');
        fontSel.addEventListener('change', function () {
            setPrefs({ font: fontSel.value });
            syncSettings();
            build();                                  // repaint every post's face
        });
        var copyBtn = document.getElementById('pfs-copy');
        copyBtn.addEventListener('click', function () {
            copyText(document.getElementById('pfs-url').value,
                function () { copyBtn.textContent = 'Copied'; setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 1400); },
                function () { toast('Copy failed'); });
        });
        var urlEl = document.getElementById('pfs-url');
        urlEl.addEventListener('focus', function () { urlEl.select(); });
        var native = document.getElementById('pfs-native');
        if (navigator.share) {
            native.hidden = false;
            native.addEventListener('click', function () {
                navigator.share({
                    title: (board && board.name) ? board.name + ' \u2014 Pericope' : 'Pericope',
                    url: document.getElementById('pfs-url').value
                }).catch(function () { /* dismissed the tray — not an error */ });
            });
        }
    }

    // Fill the panel for the board as it is right now (runs on every open).
    function openPanel() {
        if (!board || !window.MBPericope.encodeShare) { return; }
        var blob = window.MBPericope.encodeShare(board);
        if (!blob) { return; }
        buildPanel();
        setSettingsMode(false);

        var url = shareBase() + '#' + blob;
        document.getElementById('pfs-url').value = url;
        var sizeEl = document.getElementById('pfs-size');
        if (url.length <= QR_COMFORT) {
            sizeEl.textContent = url.length + ' characters \u2014 small enough for a scannable QR code.';
            sizeEl.classList.remove('is-over');
        } else {
            sizeEl.textContent = url.length + ' characters \u2014 too large for a comfortable QR code; share it as a link.';
            sizeEl.classList.add('is-over');
        }
        renderQr(url.length <= QR_COMFORT ? url : null);
    }

    /* ---- QR (pericope-share's vendored-lib pattern, verbatim) ------------ */
    function loadQrLib() {
        if (qrLibState === 1 || qrLibState === 2) { return; }
        qrLibState = 1;
        var tag = document.createElement('script');
        tag.src = '/js/vendor/qrcode.js';
        tag.onload = function () { qrLibState = 2; if (qrPending) { renderQr(qrPending); } };
        tag.onerror = function () { qrLibState = 3; };
        document.body.appendChild(tag);
    }
    function renderQr(url) {
        var box = document.getElementById('pfs-qr');
        if (!box) { return; }
        if (!url) { box.hidden = true; box.innerHTML = ''; qrPending = null; return; }
        if (!window.qrcode) { qrPending = url; box.hidden = true; loadQrLib(); return; }
        qrPending = null;
        try {
            var qr = window.qrcode(0, 'M');
            qr.addData(url, 'Byte');
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
            box.hidden = false;
        } catch (e) {
            box.hidden = true;         // overflow: the size line already said link-only
        }
    }

    /* ---- the recent rail (scroll r4) ------------------------------------- */
    // "just now" / "4m" / "3h" / "2d" / "3w" / "5mo" / "1y" — coarse on
    // purpose; the rail is a glance, not a ledger.
    function ago(ts) {
        var d = Date.now() - (ts || 0);
        if (d < 90 * 1000) { return 'just now'; }
        if (d < 60 * 60 * 1000) { return Math.round(d / 60000) + 'm ago'; }
        if (d < 24 * 60 * 60 * 1000) { return Math.round(d / 3600000) + 'h ago'; }
        if (d < 7 * 24 * 60 * 60 * 1000) { return Math.round(d / 86400000) + 'd ago'; }
        if (d < 35 * 24 * 60 * 60 * 1000) { return Math.round(d / (7 * 86400000)) + 'w ago'; }
        if (d < 365 * 24 * 60 * 60 * 1000) { return Math.round(d / (30 * 86400000)) + 'mo ago'; }
        return Math.round(d / (365 * 86400000)) + 'y ago';
    }

    // A board's dominant section colour: its most common book colour, ties
    // to whichever was seen first in card order. Costs one small
    // localStorage read per rail row (the hub's own per-tile habit).
    function dominantColor(entry) {
        var doc = window.MBPericope.get(entry.id), tally = {}, best = 'clay', bestN = 0, i, c, col;
        if (!doc || !isArrayLike(doc.cards)) { return best; }
        for (i = 0; i < doc.cards.length; i++) {
            c = doc.cards[i];
            if (!c || c.type !== 'verse') { continue; }
            col = bookColor(c.osis);
            tally[col] = (tally[col] || 0) + 1;
            if (tally[col] > bestN) { bestN = tally[col]; best = col; }
        }
        return best;
    }
    function isArrayLike(a) { return a && typeof a.length === 'number'; }

    function railRow(entry) {
        var hub = (CFG.hubUrl || '/extras/pericope').replace(/\/$/, '');
        var href = hub + '/' + encodeURIComponent(entry.slug) + '/scroll';
        var n = entry.cards | 0;
        return '<a class="pbf-acct" href="' + esc(href) + '">' +
                   '<span class="pbf-dot" style="--bk:var(--tl-' + esc(dominantColor(entry)) + ')" aria-hidden="true"></span>' +
                   '<span class="pbf-acct-who">' +
                       '<span class="pbf-acct-name">' + esc(entry.name || 'Pericope') + '</span><br>' +
                       '<span class="pbf-acct-meta">' + n + ' card' + (n === 1 ? '' : 's') + ' \u00b7 ' + esc(ago(entry.updated)) + '</span>' +
                   '</span>' +
               '</a>';
    }

    function buildRail() {
        var rail = document.getElementById('pb-rail');
        if (!rail || !window.MBPericope.list) { return; }
        var entries = window.MBPericope.list(), i, rows = [];
        entries.sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });
        for (i = 0; i < entries.length && rows.length < RAIL_MAX; i++) {
            if (board && entries[i].id === board.id) { continue; }
            rows.push(railRow(entries[i]));
        }
        if (!rows.length) { rail.hidden = true; return; }
        rail.innerHTML = '<p class="pbf-rail-title">Recent pericopae</p>' + rows.join('') +
                         '<a class="pbf-rail-all" href="' + esc(CFG.hubUrl || '/extras/pericope') + '">All pericopae \u2192</a>';
        rail.hidden = false;
    }

    /* ---- wiring ---------------------------------------------------------- */
    var resizeTimer = null;
    function onResize() {
        if (resizeTimer) { clearTimeout(resizeTimer); }
        resizeTimer = setTimeout(refitAll, 120);
    }

    function init() {
        if (!window.MBPericope || !window.MBPericope.get) { return; }
        if (window.console && console.info) { console.info('[pericope] scroll r4'); }

        root   = document.getElementById('pb-feed');
        feedEl = root && root.querySelector('.pbf-feed');
        if (!root || !feedEl) { return; }

        board = window.MBPericope.get(CFG.slug) || null;

        var missing = document.getElementById('pb-missing');
        var shell   = document.getElementById('pb-shell');
        if (!board) {
            if (missing) { missing.hidden = false; }
            if (shell) { shell.hidden = true; }
            return;
        }
        if (shell) { shell.hidden = false; }
        buildRail();

        feedEl.addEventListener('click', onFeedClick);
        window.addEventListener('resize', onResize);
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(refitAll, function () {}); }

        // The view pill: Scroll is current; Grid is a plain link. Choosing
        // writes the preference the grid shell's dispatcher reads.
        var pillEl = document.getElementById('pb-view');
        if (pillEl) {
            pillEl.addEventListener('click', function (e) {
                var t = e.target;
                while (t && t !== pillEl) {
                    if (t.getAttribute && t.getAttribute('data-view')) { writeView(t.getAttribute('data-view')); return; }
                    t = t.parentNode;
                }
            });
        }

        // Folder apps. HOME (Δ): the top of the feed. ZOOM: the 3-across
        // profile view, still on the horizon. SHARE: panel on open.
        var home = document.getElementById('pb-home');
        if (home) {
            home.disabled = false;
            home.addEventListener('click', function () {
                try { window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' }); }
                catch (_) { window.scrollTo(0, 0); }
            });
        }
        var zoom = document.getElementById('pb-zoom');
        if (zoom) {
            zoom.addEventListener('click', function () { toast('Zoomed-out view is coming soon'); });
        }
        var share = document.getElementById('pb-share');
        if (share) {
            share.addEventListener('toggle', function () { if (share.open) { openPanel(); } });
        }

        build();
    }

    window.MBPericopeScroll = {
        rebuild: build,
        refit:   refitAll,
        prefs:   prefs
    };

    init();
})();
