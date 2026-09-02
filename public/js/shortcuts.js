/*
 * shortcuts.js — global desktop keyboard shortcuts for MEGABIBLE.net.
 *
 * Loaded on every page from app.blade.php (defer). It is deliberately
 * defensive: it does nothing while you're typing, nothing when a modifier
 * (Ctrl / Cmd / Alt) is held, and nothing for keys it doesn't own — so it
 * never steals a browser shortcut, a form keystroke, or vertical scrolling.
 *
 * Tier 1 shortcuts:
 *   j  /  ArrowRight   → next chapter      (whatever the RIGHT arrow points to,
 *                                            including the last-chapter "rewind
 *                                            to hub" — the keyboard mirrors the
 *                                            visible arrows exactly)
 *   k  /  ArrowLeft    → previous chapter   (whatever the LEFT arrow points to)
 *   t                  → open the translation switcher dropdown
 *   /                  → focus the site search box
 *   Escape             → close the topmost open chrome dropdown, one level:
 *                        an open .tx first, then an open .qn. The synthesis
 *                        study board owns its OWN Escape in focus-synthesis.js
 *                        (it's an aria-modal dialog), so we don't touch it here.
 *
 * Chapter nav behaves like a real button press: the matching arrow LIGHTS UP
 * (.is-active) on keydown and the navigation commits on keyup. Holding the key
 * keeps the arrow highlighted, which reinforces which key drives which arrow;
 * a quick tap still feels instant. Targets are READ from the .chapter-nav
 * arrows themselves, so there's no second source of truth and no controller
 * change: if an arrow isn't in the DOM, its key simply does nothing.
 *
 * Vertical-scroll keys (ArrowUp, ArrowDown, Space, PageUp, PageDown, Home,
 * End) are intentionally left ALONE so long chapters still scroll natively.
 */
(function () {
    'use strict';

    // The arrow currently held down by a chapter key, and which side it is, so
    // the matching keyup can commit (and a mismatched keyup is ignored).
    let armedArrow = null;
    let armedSide  = null;

    // ---- Guards ------------------------------------------------------------

    // True when the keystroke belongs to a text field and must pass through.
    function isTyping(el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT'
            || tag === 'TEXTAREA'
            || tag === 'SELECT'
            || el.isContentEditable === true;
    }

    // Which chapter side, if any, a key refers to. Letters are lower-cased so
    // Caps Lock doesn't defeat them; arrows are matched by their full key name.
    function chapterSide(e) {
        if (e.key === 'ArrowRight') return 'next';
        if (e.key === 'ArrowLeft')  return 'prev';
        const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if (k === 'j') return 'next';
        if (k === 'k') return 'prev';
        return null;
    }

    // ---- Chapter nav: press to arm, release to go --------------------------

    function armChapter(side, e) {
        const arrow = document.querySelector('.chapter-nav.' + side);
        if (!arrow || !arrow.getAttribute('href')) return;   // no arrow → no-op
        e.preventDefault();

        // Re-pressing the other direction before releasing: last one wins.
        if (armedArrow && armedArrow !== arrow) armedArrow.classList.remove('is-active');

        armedArrow = arrow;
        armedSide  = side;
        arrow.classList.add('is-active');
    }

    function commitChapter() {
        if (!armedArrow) return;
        const href = armedArrow.getAttribute('href');
        armedArrow.classList.remove('is-active');
        armedArrow = null;
        armedSide  = null;
        if (href) window.location.href = href;
    }

    function clearArmed() {
        if (!armedArrow) return;
        armedArrow.classList.remove('is-active');
        armedArrow = null;
        armedSide  = null;
    }

    // ---- Other actions -----------------------------------------------------

    // Open the visible translation switcher. There can be more than one .tx on
    // a page (the chapter head plus a copy inside the closed synthesis panel),
    // so skip any that live inside a closed study board.
    function openTranslation(e) {
        const tx = Array.prototype.find.call(
            document.querySelectorAll('details.tx'),
            function (d) {
                const inSynth = d.closest('.synthesis');
                return !inSynth || inSynth.classList.contains('is-open');
            }
        );
        if (!tx) return;
        e.preventDefault();

        // One dropdown at a time: close any open QuickNav first.
        document.querySelectorAll('details.qn[open]').forEach(function (d) {
            d.removeAttribute('open');
        });

        tx.open = true;
        // Move focus into the menu so Tab / Enter work straight away, falling
        // back to the pill itself if there are no linkable options.
        const target = tx.querySelector('.tx-option[href]') || tx.querySelector('summary');
        if (target) target.focus();
    }

    function focusSearch(e) {
        const box = document.querySelector('.site-search-input');
        if (!box) return;
        e.preventDefault();             // stop the "/" from being typed into it
        box.focus();
        if (typeof box.select === 'function') box.select();
    }

    // Close ONE open chrome dropdown, highest priority first. Returns true if
    // it closed something (so we can preventDefault only then).
    function closeTopmost() {
        const tx = Array.prototype.find.call(
            document.querySelectorAll('details.tx[open]'),
            function (d) {
                const inSynth = d.closest('.synthesis');
                return !inSynth || inSynth.classList.contains('is-open');
            }
        );
        if (tx) { tx.removeAttribute('open'); return true; }

        const qn = document.querySelector('details.qn[open]');
        if (qn) { qn.removeAttribute('open'); return true; }

        return false;   // nothing of ours open — let the modal / browser have Esc
    }

    // ---- Listeners ---------------------------------------------------------

    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented) return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;    // leave real combos alone
        if (isTyping(e.target) || isTyping(document.activeElement)) return;
        if (e.repeat) return;                              // ignore auto-repeat while held

        if (e.key === 'Escape') {
            if (closeTopmost()) e.preventDefault();
            return;
        }

        const side = chapterSide(e);
        if (side) { armChapter(side, e); return; }         // highlight now, go on release

        const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if (k === 't') openTranslation(e);
        else if (k === '/') focusSearch(e);
    });

    document.addEventListener('keyup', function (e) {
        const side = chapterSide(e);
        if (side && side === armedSide) commitChapter();   // release the held arrow → navigate
    });

    // Safety: if focus leaves the window mid-hold (alt-tab), don't strand a
    // highlighted arrow with no keyup coming.
    window.addEventListener('blur', clearArmed);
})();