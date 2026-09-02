/* =====================================================================
   VERSE HOVER  ·  public/js/verse-hover.js
   ---------------------------------------------------------------------
   One hover engine for the reader, the vigil and the parallel view.
   Replaces CSS :hover (and parallel's mouseover/mouseout delegation)
   with a JS-driven .is-hover class.

   WHY THIS EXISTS
   A prose verse is an INLINE <span>. When it wraps, the browser draws one
   hit box per line — and those boxes are only as tall as the glyphs, not
   as tall as the line. The leading between two wrapped lines is therefore
   dead space belonging to no inline box: :hover switches OFF for the split
   second the pointer crosses it, so a slow drag down a long verse strobes.
   mouseover/mouseout delegation has exactly the same hole.

   This engine hit-tests the pointer against the verse's own line
   rectangles instead, treating the whole block — lines AND the leading
   between them — as one target. Both lines of a wrapped verse resolve to
   the same verse number, so crossing the gap inside a verse is a no-op.
   Crossing between two verses hands over at the midpoint, with no blank
   frame in between.

   It also fixes a quieter bug: a verse can be spread across several DOM
   fragments (a prose run, then poetry lines, then more prose). CSS :hover
   only ever lit the fragment under the cursor. This lights every fragment
   sharing the same data-verse, exactly like a click does.

   WIRING
     data-verse-hover            Marks a HIT REGION — where the pointer is
                                 tested. Its value is the target selector;
                                 empty means ".verse".
     data-verse-hover-group      Optional, on an ANCESTOR. Widens the PAINT
                                 SCOPE to that ancestor, so one pointer can
                                 light matching targets in sibling regions
                                 (this is what keeps parallel's two columns
                                 highlighting in tandem). Absent → the class
                                 is painted inside the hit region only.

     Single column:
       <div class="reading" data-verse-hover>

     Parallel:
       <div class="parallel-cols" data-verse-hover-group>
         <div class="reading" data-verse-hover=".verse, .footnote-row"> … </div>
         <div class="reading" data-verse-hover=".verse, .footnote-row"> … </div>
       </div>

   Then style .is-hover instead of :hover.

   CLICK HANDLERS
   The same dead band swallows CLICKS, not just hovers — a click in the
   leading reports the <p> as its target, so closest('.verse') comes back
   null. window.MBVerseHover.fromEvent(e) resolves it properly; see the
   call sites in focus-synthesis.js, vigil and parallel.

   Mouse only. Touch and pen pointers are ignored outright, which also
   kills the classic mobile bug where a hover state sticks to the last
   tapped element.
   ===================================================================== */
(function () {
    'use strict';

    /* ---- Knobs -------------------------------------------------------- */

    // How far above/below a line rectangle still counts as "on" that line,
    // as a fraction of the paragraph's line-height. 0.6 comfortably spans
    // the leading between two wrapped lines from either side, so the two
    // search zones meet in the middle and leave no dead band.
    var GAP_SLACK = 0.6;

    // Horizontal forgiveness, in px, at the two ends of a line.
    var EDGE_SLACK = 2;

    var REGION_ATTR = 'data-verse-hover';
    var GROUP_ATTR  = 'data-verse-hover-group';
    var HOVER_CLASS = 'is-hover';
    var DEFAULT_SEL = '.verse';

    if (!document.querySelector('[' + REGION_ATTR + ']')) return;

    /* ---- State -------------------------------------------------------- */

    var curScope = null;   // element the class is currently painted inside
    var curVn    = null;   // verse number currently lit (string)
    var lastX    = null;   // last known pointer position, viewport coords
    var lastY    = null;
    var queued   = false;  // rAF throttle latch

    /* ---- Painting ------------------------------------------------------ */

    function clear() {
        if (!curScope) return;
        curScope.querySelectorAll('.' + HOVER_CLASS).forEach(function (el) {
            el.classList.remove(HOVER_CLASS);
        });
        curScope = null;
        curVn    = null;
    }

    // ".verse, .footnote-row" + "7" → '.verse[data-verse="7"], .footnote-row[data-verse="7"]'
    function withVerse(sel, vn) {
        return sel.split(',').map(function (part) {
            return part.trim() + '[data-verse="' + vn + '"]';
        }).join(', ');
    }

    function apply(scope, sel, vn) {
        if (scope === curScope && vn === curVn) return;   // nothing changed
        clear();
        scope.querySelectorAll(withVerse(sel, vn)).forEach(function (el) {
            el.classList.add(HOVER_CLASS);
        });
        curScope = scope;
        curVn    = vn;
    }

    /* ---- Hit testing --------------------------------------------------- */

    // data-verse goes straight into an attribute selector, so hold it to
    // the digits it is supposed to be.
    function safeVn(el) {
        var raw = el.getAttribute('data-verse') || '';
        return /^[0-9]+$/.test(raw) ? raw : null;
    }

    function selectorFor(region) {
        return region.getAttribute(REGION_ATTR) || DEFAULT_SEL;
    }

    function scopeFor(region) {
        return region.closest('[' + GROUP_ATTR + ']') || region;
    }

    // The paragraph's line-height in px, with a sane answer when the
    // computed value is the keyword "normal".
    function lineHeightOf(el) {
        var cs = window.getComputedStyle(el);
        var lh = parseFloat(cs.lineHeight);
        if (lh > 0) return lh;
        return (parseFloat(cs.fontSize) || 16) * 1.6;
    }

    /**
     * Slow path: the pointer sits inside a paragraph but not on any glyph —
     * i.e. in the leading between two wrapped lines. Measure every line
     * rectangle of every target in this paragraph and take the vertically
     * nearest one the pointer sits within horizontally.
     */
    function nearestIn(para, sel, x, y) {
        var limit    = lineHeightOf(para) * GAP_SLACK;
        var best     = null;
        var bestDist = Infinity;

        para.querySelectorAll(sel).forEach(function (frag) {
            if (safeVn(frag) === null) return;

            var rects = frag.getClientRects();
            for (var i = 0; i < rects.length; i++) {
                var r = rects[i];
                if (x < r.left - EDGE_SLACK || x > r.right + EDGE_SLACK) continue;

                var d = y < r.top ? r.top - y
                      : y > r.bottom ? y - r.bottom
                      : 0;

                if (d < bestDist) { bestDist = d; best = frag; }
            }
        });

        return bestDist <= limit ? best : null;
    }

    /** Resolve a viewport point to {el, vn, scope, sel}, or null. */
    function resolve(x, y) {
        var under = document.elementFromPoint(x, y);
        if (!under) return null;

        var region = under.closest('[' + REGION_ATTR + ']');
        if (!region) return null;

        var sel = selectorFor(region);

        // Fast path: straight onto verse text, a verse number, a footnote
        // marker, a typed character span, a whole poetry line block, or a
        // footnote row.
        var el = under.closest(sel);

        // Slow path: inside a prose paragraph, between two wrapped lines.
        if (!el) {
            var para = under.closest('p');
            if (!para || !region.contains(para)) return null;
            el = nearestIn(para, sel, x, y);
            if (!el) return null;
        }

        var vn = safeVn(el);
        return vn === null ? null : { el: el, vn: vn, scope: scopeFor(region), sel: sel };
    }

    /* ---- Scheduling ---------------------------------------------------- */

    // One resolve per animation frame at most. Pointer moves fire far
    // faster than the screen repaints, and the slow path measures rects.
    function schedule() {
        if (queued || lastX === null) return;
        queued = true;
        window.requestAnimationFrame(function () {
            queued = false;
            if (lastX === null) return;
            var hit = resolve(lastX, lastY);
            if (hit) apply(hit.scope, hit.sel, hit.vn); else clear();
        });
    }

    /* ---- Events -------------------------------------------------------- */

    document.addEventListener('pointermove', function (ev) {
        // Touch and pen never hover. Bailing here is also what stops a tap
        // from leaving a stuck highlight behind on mobile.
        if (ev.pointerType && ev.pointerType !== 'mouse') { lastX = null; clear(); return; }
        lastX = ev.clientX;
        lastY = ev.clientY;
        schedule();
    }, { passive: true });

    // A wheel scroll moves the TEXT under a stationary pointer, so the
    // hovered verse changes with no pointer event to announce it. Native
    // :hover handled that for free; we have to re-test by hand.
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });

    // Pointer left the window, or the window lost focus: drop the hover.
    document.documentElement.addEventListener('pointerleave', function () {
        lastX = null;
        clear();
    });
    window.addEventListener('blur', function () {
        lastX = null;
        clear();
    });

    /* ---- Public API ----------------------------------------------------- */

    window.MBVerseHover = {
        /**
         * The verse fragment (or footnote row) under a viewport point, or
         * null. The same hit test the highlight uses, so a click always
         * lands on whatever the reader can see is lit.
         */
        elementAt: function (x, y) {
            var hit = resolve(x, y);
            return hit ? hit.el : null;
        },

        /**
         * Click-handler convenience. Returns null for keyboard-activated
         * clicks, which carry no real coordinates (detail === 0).
         */
        fromEvent: function (ev) {
            if (!ev || !ev.detail) return null;
            return this.elementAt(ev.clientX, ev.clientY);
        },
    };
})();
