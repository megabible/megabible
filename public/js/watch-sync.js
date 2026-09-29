/*
 * WATCH SYNC  ·  public/js/watch-sync.js  ·  sync r1
 * ---------------------------------------------------------------------------
 * The hybrid-subtitle engine for the watch page: while the video plays, the
 * verse being narrated is highlighted in the text below and kept centred on
 * screen — and tapping any verse seeks the video to it.
 *
 * INPUTS (all rendered by watch.blade.php):
 *   window.mbWatchCues   [{v: verseNumber, t: seconds}, …] sorted by t.
 *                        Empty/absent = this video has no cues yet; the
 *                        script stands down and the page is video + text.
 *   <lite-youtube js-api>  the facade. Its js-api attribute makes activation
 *                        go through the YouTube IFrame API and exposes
 *                        getYTPlayer().
 *   .reading             the chapter flow; every verse fragment carries
 *                        data-verse="N" (reading-flow.blade.php).
 *
 * THE ONE RULE THAT KEEPS THE FACADE HONEST: getYTPlayer() ACTIVATES the
 * facade if it hasn't been clicked (that's in the library), so this script
 * must never call it early — it waits for the .lyt-activated class the
 * library sets on the user's own click/keypress, THEN takes the player
 * handle. Zero YouTube traffic until the user acts remains true.
 *
 * FOLLOW MODEL: auto-scroll follows the narration until the user scrolls on
 * their own (wheel / touch drag / scroll keys) — then following suspends and
 * a "Resume following" pill appears. Tapping the pill, or tapping any verse
 * (which also seeks), resumes. Scrolls this script causes are fenced off by
 * a timestamp guard so they can't suspend their own mode. (A desktop
 * scrollbar drag emits only bare scroll events and slips past the intent
 * listeners — accepted for r1 rather than risking false suspends from the
 * guard racing long smooth scrolls.)
 *
 * ES5 on purpose — MB-authored scripts stay ES5 (house rule); only the
 * vendored lite-yt-embed.js is exempt.
 */
(function () {
    'use strict';

    var yt      = document.querySelector('lite-youtube[js-api]');
    var reading = document.querySelector('.reading');
    var cues    = window.mbWatchCues || [];
    if (!yt || !reading || !cues.length) return;

    var player          = null;   // YT.Player once the user activates
    var timer           = null;
    var activeVerse     = null;
    var follow          = true;   // auto-scroll to the narrated verse
    var autoScrollUntil = 0;      // fence: scrolls we caused, ignore until then

    /* ---- Resume pill (built here: it only exists when sync can run) ---- */
    var pill = document.createElement('button');
    pill.type = 'button';
    pill.className = 'watch-follow-pill';
    pill.textContent = 'Resume following';
    pill.hidden = true;
    document.body.appendChild(pill);

    pill.addEventListener('click', function () {
        follow = true;
        pill.hidden = true;
        scrollToVerse(activeVerse);
    });

    /* ---- Highlight + scroll -------------------------------------------- */
    function fragmentsOf(v) {
        return reading.querySelectorAll('[data-verse="' + v + '"]');
    }

    function scrollToVerse(v) {
        if (v === null) return;
        var els = fragmentsOf(v);
        if (!els.length) return;
        autoScrollUntil = Date.now() + 1200;
        els[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setActive(v) {
        if (v === activeVerse) return;

        var old = reading.querySelectorAll('.is-playing');
        for (var i = 0; i < old.length; i++) old[i].classList.remove('is-playing');

        activeVerse = v;
        if (v === null) return;

        var els = fragmentsOf(v);
        for (var j = 0; j < els.length; j++) els[j].classList.add('is-playing');

        if (follow) scrollToVerse(v);
    }

    /* Last cue at or before t — binary search; cues are sorted by t
       (mb:video-cues guarantees it, the controller re-sorts anyway). */
    function verseAt(t) {
        var lo = 0, hi = cues.length - 1, ans = -1;
        while (lo <= hi) {
            var mid = (lo + hi) >> 1;
            if (cues[mid].t <= t) { ans = mid; lo = mid + 1; }
            else { hi = mid - 1; }
        }
        return ans === -1 ? null : cues[ans].v;
    }

    function tick() {
        if (!player || typeof player.getCurrentTime !== 'function') return;
        var t = player.getCurrentTime();
        if (typeof t !== 'number' || isNaN(t)) return;
        setActive(verseAt(t));
    }

    /* ---- Player pickup: only AFTER the user's own activation ----------- */
    function onActivated() {
        yt.getYTPlayer().then(function (p) {
            player = p;
            if (!timer) timer = setInterval(tick, 250);
            // Verses become seek targets — the class turns on the pointer
            // cursor so the affordance appears exactly when it works.
            document.body.classList.add('sync-live');
        });
    }

    if (yt.classList.contains('lyt-activated')) {
        onActivated();
    } else if (window.MutationObserver) {
        var mo = new MutationObserver(function () {
            if (yt.classList.contains('lyt-activated')) {
                mo.disconnect();
                onActivated();
            }
        });
        mo.observe(yt, { attributes: true, attributeFilter: ['class'] });
    }

    /* ---- The user's own scrolling suspends following -------------------- */
    function suspend() {
        if (!follow || !player) return;   // nothing to suspend before play
        follow = false;
        pill.hidden = false;
    }

    window.addEventListener('wheel', suspend, { passive: true });
    window.addEventListener('touchmove', suspend, { passive: true });
    window.addEventListener('keydown', function (e) {
        switch (e.key) {
            case 'ArrowUp': case 'ArrowDown':
            case 'PageUp': case 'PageDown':
            case 'Home': case 'End':
            case ' ':
                suspend();
        }
    });

    /* ---- Tap a verse to seek the video there ---------------------------- */
    /* Post-activation only: before the user has pressed play, a tap on the
       text is just reading, and must never launch the video. Tapping to
       seek is engagement with the narration, so it also resumes following. */
    reading.addEventListener('click', function (e) {
        if (!player || typeof player.seekTo !== 'function') return;
        var el = e.target && e.target.closest ? e.target.closest('[data-verse]') : null;
        if (!el || !reading.contains(el)) return;

        var v = parseInt(el.getAttribute('data-verse'), 10);
        for (var i = 0; i < cues.length; i++) {
            if (cues[i].v === v) {
                player.seekTo(cues[i].t, true);
                follow = true;
                pill.hidden = true;
                setActive(v);
                break;
            }
        }
        // A verse with no cue: nothing to seek to; the tap does nothing.
    });
})();
