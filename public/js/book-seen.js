/* =========================================================================
   bk-seen r1 — THE "SEEN" BEACON  ·  public/js/book-seen.js
   -------------------------------------------------------------------------
   Counts this device into a book's anonymous daily visit counter, at most
   once per book per day. Included (deferred) by every page that "is" part
   of a book: the reader hub + chapter, the vigil hub + chapter, and the
   scrim page (whose verse belongs to a book).

   The page provides context BEFORE this script runs (the focus-synthesis
   bridge pattern — inline script during parse, this file deferred after):

       window.MBSeenContext = { osis: 'Gen', url: '/bible/seen', csrf: '…' };

   Dedup: mbSeen.v1 in localStorage maps osis → 'YYYY-MM-DD' (device-local
   date). Same book, same day, another page → no beacon. Clearing storage
   re-counts the device — accepted: the pill is approximate by design, and
   the server stores counts only (see BibleController::seen).

   fetch + keepalive rather than navigator.sendBeacon: the POST needs the
   CSRF header, which sendBeacon cannot carry; keepalive preserves the one
   thing sendBeacon bought us (surviving an immediate navigation).
   ========================================================================= */
(function () {
    'use strict';

    var ctx = window.MBSeenContext;
    if (!ctx || !ctx.osis || !ctx.url) return;

    var KEY = 'mbSeen.v1';

    // Device-local date — the day the visitor experiences. The server
    // buckets by ITS date; the two disagree for a few hours around
    // midnight, and that noise is invisible in a weekly sum.
    function p(n) { n = String(n); return n.length < 2 ? '0' + n : n; }
    var d = new Date();
    var today = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());

    var store = {};
    try { store = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) {}

    if (store[ctx.osis] === today) return;       // already counted today

    // Stamp FIRST, then fire. If the beacon is lost (network, adblock) the
    // device under-counts today instead of machine-gunning retries — the
    // right failure mode for a counter.
    store[ctx.osis] = today;
    try {
        localStorage.setItem(KEY, JSON.stringify(store));
    } catch (e) {
        return;                                  // private mode: no flag, no beacon
    }

    try {
        fetch(ctx.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': ctx.csrf || ''
            },
            body: JSON.stringify({ osis: ctx.osis }),
            keepalive: true,
            credentials: 'same-origin'
        }).catch(function () { /* counter: losses are acceptable */ });
    } catch (e) { /* ancient browsers: nothing to do */ }
})();