/* =========================================================================
   hub-xref r3 — XREF VERSE + ORIG-WORD POPOVERS  ·  public/js/xref-popover.js
   -------------------------------------------------------------------------
   Two popover systems for the book hub's prose, sharing one shell:

   1. VERSE PREVIEWS on any anchor carrying the data-xref-* contract —
      HubProse's prose links AND (r3) the outline's ref links; the class
      no longer matters, the data attributes are the contract. Desktop
      (hover-capable fine pointers): hovering floats the target passage —
      the first verses, capped — in a .fn-pop panel; clicking the panel,
      or the link itself, navigates. Touch (r3): the FIRST tap opens the
      panel instead of navigating; tapping the panel, or the link again,
      navigates; tapping anywhere else dismisses. If the preview fetch
      fails on that first tap, the tap falls through to plain navigation —
      a dead-feeling link is worse than a missing preview.

   2. DEFINITIONS on original-language words (.orig-word). The word, its
      transliteration, its language, and the hand-written definition — all
      read straight from the data-orig-* attributes HubProse stamped. No
      fetching; the page already carries the content.
      hub-xref r2.1: on desktop these now behave EXACTLY like the verse
      previews — hover opens, moving off (with the usual grace window)
      closes. But where the verse panel is a click-through surface, the
      definition panel is terminal: clicking it navigates nowhere and
      dismisses nothing — it just gives a small "poke" pulse, an
      acknowledgement that the click landed and there is nowhere further
      to go. Touch devices keep the r2 behaviour: tap the word to open,
      tap it again or tap away to close (a panel tap pokes there too).
      Enter/Space still toggles on every device — keyboards can't hover.

   Verse text comes from GET {ctx.url}?book&chapter&v — the same
   verse-translations endpoint the pericope cards use. The response lists
   every edition carrying the passage; we prefer the page's own edition
   and fall back to the first carrier (the cross-edition case: 1 Enoch
   from a KJV hub), badging the panel whenever what's shown isn't the
   page's edition. Long ranges are trimmed AT THE FETCH (19-34 asks for
   19-21) so the payload stays small; the panel then says where the
   reference continues to. Responses are memoized per reference, and the
   endpoint itself sends Cache-Control, so a hub full of refs costs at
   most one request each.

   The page provides context BEFORE this script runs (the focus-synthesis
   bridge pattern — inline script during parse, this file deferred after):

       window.MBXrefContext = { url: '/bible/verse-translations', tx: 'kjv' };

   Panel chrome (.fn-pop: border, shadow, chevron, lift) and the interior
   styles (.xp-*, .op-*) live in book.blade's style block. Positioning is
   the source-popover math, verbatim: centred above the target, viewport-
   clamped, flipped below when there's no headroom, chevron tracking the
   target even when the panel is clamped.

   COORDINATION: opening any panel here dispatches "mb:pop-open" on
   document, and this module closes its own panel whenever someone ELSE
   announces one. As of r3 the source-marker popover speaks the same
   event, so only one panel — of any kind — can ever be up at a time.

   All text is written via textContent — fetched verse text and data
   attributes never touch innerHTML.
   ========================================================================= */
(function () {
    'use strict';

    var ctx = window.MBXrefContext;
    if (!ctx || !ctx.url || !ctx.tx) return;

    /* ---- Knobs --------------------------------------------------------- */

    var VERSE_CAP  = 3;     // verses shown before the "continues" line
    var SHOW_DELAY = 120;   // ms of hover before a verse panel opens
    var HIDE_GRACE = 250;   // ms allowed for pointer travel link → panel
    var W_VERSE    = 340;   // panel widths, viewport-clamped
    var W_ORIG     = 300;

    /* ---- Pure helpers (exposed as window.MBXrefPop for the harness) ----- */

    // "25" → {v1:25, v2:null} · "19-34" → {v1:19, v2:34} · junk → null.
    // HubProse guarantees data-xref-v is a single verse or an ordered run,
    // but this is the defensive edge of the client anyway.
    function parseRun(v) {
        var m = /^(\d+)(?:-(\d+))?$/.exec(String(v || ''));
        if (!m) return null;
        return { v1: parseInt(m[1], 10), v2: m[2] ? parseInt(m[2], 10) : null };
    }

    // What to actually fetch, and whether a "continues" line is owed.
    // Requesting 19-34 with a cap of 3 fetches "19-21" and owes "… to 34".
    function fetchSpan(run, cap) {
        if (run.v2 === null || run.v2 <= run.v1) {
            return { v: String(run.v1), moreTo: null };
        }
        var end = Math.min(run.v2, run.v1 + cap - 1);
        return { v: run.v1 + '-' + end, moreTo: run.v2 > end ? run.v2 : null };
    }

    // Prefer the page's edition; fall back to the first carrier.
    function pickTranslation(list, tx) {
        if (!list || !list.length) return null;
        for (var i = 0; i < list.length; i++) {
            if (list[i].abbr === tx) return list[i];
        }
        return list[0];
    }

    window.MBXrefPop = {
        parseRun: parseRun,
        fetchSpan: fetchSpan,
        pickTranslation: pickTranslation,
    };

    /* ---- The shared panel shell ----------------------------------------- */

    var pop = null;         // the open panel, or null
    var owner = null;       // the link / word that opened it
    var hideTimer = 0;

    function closePop() {
        clearTimeout(hideTimer);
        if (pop) { pop.remove(); pop = null; owner = null; }
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(closePop, HIDE_GRACE);
    }
    function cancelHide() { clearTimeout(hideTimer); }

    // Someone else's panel opened → ours yields. Ours opening is announced
    // below in openPop; the source guard keeps us from closing ourselves.
    document.addEventListener('mb:pop-open', function (e) {
        if (!e.detail || e.detail.source !== 'xref') closePop();
    });

    function openPop(el, target, width) {
        closePop();
        document.body.appendChild(el);
        pop = el;
        owner = target;

        // Position: centred above the target, clamped to the viewport;
        // flip below (chevron up) when there's no headroom. Verbatim from
        // source-popover, so every panel on the page moves identically.
        var w = Math.min(width, document.documentElement.clientWidth - 16);
        el.style.width = w + 'px';
        var h = el.offsetHeight;
        var r = target.getBoundingClientRect();

        var left = r.left + r.width / 2 - w / 2 + window.scrollX;
        left = Math.max(window.scrollX + 8,
               Math.min(left, window.scrollX + document.documentElement.clientWidth - w - 8));
        var below = r.top < h + 18;
        el.classList.toggle('is-below', below);
        el.style.left = left + 'px';
        el.style.top  = (below
            ? r.bottom + window.scrollY + 10
            : r.top    + window.scrollY - h - 10) + 'px';
        el.style.setProperty('--chev-x',
            (r.left + r.width / 2 + window.scrollX - left) + 'px');

        document.dispatchEvent(new CustomEvent('mb:pop-open', {
            detail: { source: 'xref' },
        }));
    }

    /* ---- Verse previews: fetch ------------------------------------------ */

    var cache = {};   // "book|chapter|v" → Promise resolving to the picked edition

    function fetchPick(book, chapter, v) {
        var key = book + '|' + chapter + '|' + v;
        if (!cache[key]) {
            var p = fetch(ctx.url
                    + '?book=' + encodeURIComponent(book)
                    + '&chapter=' + encodeURIComponent(chapter)
                    + '&v=' + encodeURIComponent(v))
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (json) {
                    var pick = pickTranslation(json && json.translations, ctx.tx);
                    if (!pick || !pick.verses || !pick.verses.length) {
                        throw new Error('empty');
                    }
                    return pick;
                });
            p.catch(function () { delete cache[key]; });   // failures aren't sticky
            cache[key] = p;
        }
        return cache[key];
    }

    /* ---- Verse previews: panel ------------------------------------------ */

    function buildVersePanel(link, pick, moreTo) {
        var el = document.createElement('div');
        el.className = 'fn-pop xref-pop';

        var head = document.createElement('div');
        head.className = 'xp-head';

        var ref = document.createElement('span');
        ref.className = 'xp-ref';
        ref.textContent = (link.textContent || '').replace(/\s+/g, ' ').trim();
        head.appendChild(ref);

        // Badge only when what's shown ISN'T the page's edition — the quiet
        // cousin of the link's own cross-edition title attribute.
        if (pick.abbr !== ctx.tx && pick.short) {
            var tx = document.createElement('span');
            tx.className = 'xp-tx';
            tx.textContent = pick.short;
            head.appendChild(tx);
        }
        el.appendChild(head);

        var verses = pick.verses;
        for (var i = 0; i < verses.length && i < VERSE_CAP; i++) {
            var line = document.createElement('p');
            line.className = 'xp-verse';
            var num = document.createElement('sup');
            num.textContent = verses[i][0];
            line.appendChild(num);
            line.appendChild(document.createTextNode(' ' + verses[i][1]));
            el.appendChild(line);
        }

        if (moreTo !== null) {
            var more = document.createElement('div');
            more.className = 'xp-more';
            more.textContent = '\u2026 continues to verse ' + moreTo;
            el.appendChild(more);
        }

        // The whole panel is one click surface, identical to the link.
        el.addEventListener('mouseenter', cancelHide);
        el.addEventListener('mouseleave', scheduleHide);
        el.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var to = link.href;
            closePop();
            window.location.assign(to);
        });

        return el;
    }

    /* ---- Verse previews: desktop hover wiring ---------------------------
       Gated on hover-capable fine pointers, exactly like source-popover;
       touch devices skip straight to the tap layer in the click handler. */

    var hoverFine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var showTimer = 0;
    var showSeq = 0;   // invalidates in-flight shows once the pointer moves on

    function showVersePop(link) {
        if (owner === link) { cancelHide(); return; }

        var run = parseRun(link.getAttribute('data-xref-v'));
        if (!run) return;
        var span = fetchSpan(run, VERSE_CAP);
        var seq = ++showSeq;

        fetchPick(link.getAttribute('data-xref-book'),
                  link.getAttribute('data-xref-chapter'), span.v)
            .then(function (pick) {
                if (seq !== showSeq) return;                  // pointer moved on
                if (!document.body.contains(link)) return;
                openPop(buildVersePanel(link, pick, span.moreTo), link, W_VERSE);
            })
            .catch(function () {});    // no panel; the link itself still works

        // A network miss leaves no panel — deliberate. The link is real and
        // navigates regardless; the preview is pure enhancement.
    }

    if (hoverFine) {
        document.addEventListener('mouseover', function (e) {
            // r2.1: verse links and original-language words share one hover
            // pipeline — same delay, same grace, same feel.
            var target = e.target.closest('a[data-xref-book], .orig-word');
            if (target) {
                cancelHide();
                clearTimeout(showTimer);
                showTimer = setTimeout(function () {
                    if (target.classList.contains('orig-word')) showOrigPop(target);
                    else showVersePop(target);
                }, SHOW_DELAY);
                return;
            }

            // Anywhere that isn't the panel: kill a pending show (pre-delay
            // AND in-flight — source-popover lets a slow fetch's panel land
            // after the pointer has left; the seq bump closes that hole)
            // and start the grace clock on an open one.
            if (!e.target.closest('.fn-pop')) {
                clearTimeout(showTimer);
                showSeq++;
                if (pop) scheduleHide();
            }
        });
    }

    /* ---- Definition popovers: click everywhere -------------------------- */

    function buildOrigPanel(word) {
        var el = document.createElement('div');
        el.className = 'fn-pop orig-pop';

        var w = document.createElement('span');
        w.className = 'op-word';
        w.textContent = word.textContent;
        if (word.getAttribute('dir') === 'rtl') w.setAttribute('dir', 'rtl');
        el.appendChild(w);

        var translit = word.getAttribute('data-orig-translit');
        if (translit) {
            var t = document.createElement('span');
            t.className = 'op-translit';
            t.textContent = translit;
            el.appendChild(t);
        }

        var lang = document.createElement('span');
        lang.className = 'op-lang';
        lang.textContent = word.getAttribute('data-orig-langname') || '';
        el.appendChild(lang);

        var def = document.createElement('p');
        def.className = 'op-def';
        def.textContent = word.getAttribute('data-orig-def') || '';
        el.appendChild(def);

        // Same grace-travel contract as the verse panel: the pointer may
        // cross the gap between word and panel without losing it.
        el.addEventListener('mouseenter', cancelHide);
        el.addEventListener('mouseleave', scheduleHide);

        return el;
    }

    // Desktop's opener — hover path, no toggling.
    function showOrigPop(word) {
        if (owner === word) { cancelHide(); return; }
        openPop(buildOrigPanel(word), word, W_ORIG);
    }

    // The click acknowledgement: restart the .is-poked pulse (remove,
    // reflow, re-add — the reflow read is what lets the same animation
    // play again on every click).
    function poke(el) {
        el.classList.remove('is-poked');
        void el.offsetWidth;
        el.classList.add('is-poked');
    }

    function toggleOrigPop(word) {
        if (owner === word) { closePop(); return; }   // second tap on the word closes
        openPop(buildOrigPanel(word), word, W_ORIG);
    }

    /* ---- Verse previews: touch tap layer (r3) ---------------------------
       First tap intercepts navigation and opens the preview; the link is
       remembered while its fetch is in flight, so an impatient SECOND tap
       during the wait — or once the panel is up — sails through as plain
       navigation. A failed fetch also navigates: the tap must always do
       SOMETHING. */

    var pendingTap = null;

    function tapVersePop(link) {
        var run = parseRun(link.getAttribute('data-xref-v'));
        if (!run) { window.location.assign(link.href); return; }

        var span = fetchSpan(run, VERSE_CAP);
        var seq = ++showSeq;
        pendingTap = link;

        fetchPick(link.getAttribute('data-xref-book'),
                  link.getAttribute('data-xref-chapter'), span.v)
            .then(function (pick) {
                if (seq !== showSeq || pendingTap !== link) return;
                pendingTap = null;
                if (!document.body.contains(link)) return;
                openPop(buildVersePanel(link, pick, span.moreTo), link, W_VERSE);
            })
            .catch(function () {
                if (seq !== showSeq || pendingTap !== link) return;
                pendingTap = null;
                window.location.assign(link.href);
            });
    }

    // One delegated click handler owns ALL click behaviour: the panel
    // poke, the word (poke on desktop, toggle on touch), tap-away
    // dismissal (mobile's only close gesture), and dropping a verse panel
    // when its link is clicked (it would otherwise linger over the new
    // scroll position). A verse-panel click never reaches here — it
    // navigates via its own stopPropagation handler above.
    document.addEventListener('click', function (e) {
        // r2.1: the definition panel is terminal — a click dismisses
        // nothing and goes nowhere; it just pulses. Only pointer motion
        // (desktop) or tap-away / word-tap (touch) closes it.
        var panel = e.target.closest('.fn-pop.orig-pop');
        if (panel) {
            poke(panel);
            return;
        }

        // r3: verse links on touch — first tap previews, second navigates.
        // On desktop this branch never fires (hoverFine), so a click there
        // stays native navigation; the tail below drops the open panel.
        var link = e.target.closest('a[data-xref-book]');
        if (link && !hoverFine) {
            if (owner === link || pendingTap === link) return;  // 2nd tap → navigate
            e.preventDefault();
            tapVersePop(link);
            return;
        }

        var word = e.target.closest('.orig-word');
        if (word) {
            e.preventDefault();
            if (hoverFine) {
                // Hover already owns open/close on desktop; a click on the
                // word is the same dead end as a click on the panel.
                if (owner === word && pop) { poke(pop); } else { showOrigPop(word); }
            } else {
                toggleOrigPop(word);
            }
            return;
        }

        // Any other click: abandon a pending tap-preview (its late fetch
        // must not surface a panel over whatever the user did next) and
        // dismiss an open one.
        pendingTap = null;
        showSeq++;
        if (pop && !e.target.closest('.fn-pop')) closePop();
    });

    // The .orig-word spans carry tabindex/role from HubProse; honour them.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closePop(); return; }
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var word = e.target && e.target.closest && e.target.closest('.orig-word');
        if (word) {
            e.preventDefault();
            toggleOrigPop(word);
        }
    });
})();
