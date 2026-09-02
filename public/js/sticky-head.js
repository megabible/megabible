/* =============================================================================
   MEGABIBLE — sticky chapter head
   -----------------------------------------------------------------------------
   Partner to resources/views/bible/partials/sticky-head.blade.php. Loaded with
   `defer`, so it runs after the document is parsed and the head is guaranteed
   to exist. Safe to include on any page: if the markup isn't there it returns
   immediately.

   TWO JOBS.

   1. MEASURE how much shorter the head gets when it pins, and publish that
      number to CSS as --mb-head-shrink. The .is-stuck rule adds it straight
      back as margin-bottom, so the head's FOOTPRINT in the document never
      changes.

      This is the whole point of the file. Without it the page below jumps
      ~30px at the moment of pinning, and Chrome/Edge scroll anchoring fights
      the jump in a loop — stick, shift, correct, un-stick, shift back,
      correct, stick — that feels exactly like the scroll has seized up. It is
      only reachable if you park within the shift distance of the threshold,
      which is why it used to show up when creeping down one wheel notch at a
      time and never when scrolling fast.

   2. WATCH the sentinel that sits just above the head. When it leaves the
      viewport the head has pinned, so we flag it stuck and the CSS does the
      rest.

   Exposes window.MB.stickyHead.remeasure() for anything that changes the
   head's contents in a way the ResizeObserver somehow misses. In practice the
   observer catches everything; the hook is there so callers don't have to
   reach into this file.
   ============================================================================= */
(function () {
    'use strict';

    var head     = document.querySelector('.chapter-head');
    var sentinel = document.querySelector('.chapter-head-sentinel');
    if (!head || !sentinel) return;

    // Last published value, so we skip pointless style writes.
    var lastShrink = -1;

    function measure() {
        var wasStuck = head.classList.contains('is-stuck');

        // Zero the compensation first, so the two readings below are of the
        // head's real heights and not heights plus a stale margin.
        head.style.setProperty('--mb-head-shrink', '0px');

        head.classList.remove('is-stuck');
        var tall = head.getBoundingClientRect().height;

        head.classList.add('is-stuck');
        var short = head.getBoundingClientRect().height;

        head.classList.toggle('is-stuck', wasStuck);

        // All of the above runs inside a single task. The browser computes
        // layout twice but never paints an intermediate state, so the class
        // toggling is invisible.
        var shrink = Math.max(0, Math.round(tall - short));
        if (shrink === lastShrink) return;

        lastShrink = shrink;
        head.style.setProperty('--mb-head-shrink', shrink + 'px');
    }

    measure();

    /* Anything that changes the head's own height changes the shrink: a title
       rewrapping on rotate, a web font swapping in, the vigil's stats panel
       appearing when a chapter is finished. One observer covers them all, so
       callers never have to remember to re-measure.

       This cannot loop. measure() restores the head's class before the task
       ends, so the size ResizeObserver sees at delivery time is unchanged and
       no notification is queued — and margin-bottom sits outside the box it
       watches in any case. */
    if (window.ResizeObserver) {
        new ResizeObserver(measure).observe(head);
    }

    // Backstops for the cases a ResizeObserver alone can miss: a viewport
    // change that alters the STUCK height without altering the resting one,
    // a theme swap, and the web font arriving after first paint.
    window.addEventListener('resize', measure, { passive: true });
    document.addEventListener('mb:reader-change', measure);
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(measure);
    }

    /* Flag the head the moment the sentinel clears the top of the viewport.
       threshold:0 is fine now that the sentinel is a fat 24px box; at 1px it
       was sub-device-pixel on scaled Windows displays and on phones, and the
       ratio flip-flopped across a single scroll increment. */
    new IntersectionObserver(
        function (entries) {
            head.classList.toggle('is-stuck', !entries[0].isIntersecting);
        },
        { threshold: 0 }
    ).observe(sentinel);

    window.MB = window.MB || {};
    window.MB.stickyHead = { remeasure: measure };
})();
