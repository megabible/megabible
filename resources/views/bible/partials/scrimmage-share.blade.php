{{--
    Scrimmage Share — the share-arrow button + dropdown panel.

    A <details> popover in the text-settings mould (same trigger circle, same
    panel dress, ss- prefixes). Include once on the scrimmage page, inside
    .sc-settings-slot next to the text-settings include; the page shows/hides
    it per screen (scrim only, never the builder) through the API below.

    PHASE 1 (this file): the page URL in a readonly field + a Copy button.
    PHASE 2 (future):    #ss-results is the reserved mount — after each scrim
                         the page will call MBScrimShare.setResults(payload)
                         to render the marks card, the styled PNG preview,
                         and its own share button beneath the link row.

    The page owns the URL: it calls MBScrimShare.setUrl(url) on load, on
    translation switch, and again with &score= once a round completes. No
    state lives here.
--}}

<details class="scrim-share" id="scrimmage-share">
    <summary class="ss-trigger" aria-label="Share this scrimmage" title="Share this scrimmage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"></path>
            <polyline points="16 6 12 2 8 6"></polyline>
            <line x1="12" y1="2" x2="12" y2="15"></line>
        </svg>
    </summary>

    <div class="ss-panel" role="group" aria-label="Share this scrimmage">
        <div class="ss-head">
            <span class="ss-title">Share Scrimmage</span>
        </div>

        <span class="ss-label">Link to this scrim</span>
        <div class="ss-row">
            <input class="ss-url" id="ss-url" type="text" readonly value=""
                   aria-label="Scrimmage link">
            <button type="button" class="ss-copy" id="ss-copy">Copy</button>
        </div>

        {{-- Phase 2 mount: marks card + PNG preview + PNG share render here. --}}
        <div class="ss-results" id="ss-results" hidden></div>
    </div>
</details>

<style>
    /* ─── Trigger: mirrors the .ts-trigger circle ──────────────────── */
    .scrim-share { position: relative; flex: 0 0 auto; }
    .scrim-share > summary { list-style: none; }
    .scrim-share > summary::-webkit-details-marker { display: none; }

    .ss-trigger{
        display:inline-flex;align-items:center;justify-content:center;
        width:40px;height:40px;border-radius:50%;cursor:pointer;
        color:var(--muted);background:var(--bg);
        border:1px solid var(--rule);
        transition:color .12s,background .12s,border-color .12s;
        user-select:none;
    }
    .ss-trigger svg{width:20px;height:20px;display:block;}
    .ss-trigger:hover{color:var(--bg);background:var(--accent);border-color:var(--accent);}
    .ss-trigger:focus-visible{outline:none;color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}
    .scrim-share[open] .ss-trigger{color:var(--bg);background:var(--accent);border-color:var(--accent);}

    /* ─── Panel: the ts-panel dress ────────────────────────────────── */
    .ss-panel{
        position:absolute;right:0;top:calc(100% + 10px);z-index:80;
        width:320px;padding:1rem;
        background:var(--bg);border:1px solid var(--rule);border-radius:12px;
        box-shadow:0 12px 32px rgba(0,0,0,.18);
    }
    .ss-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;}
    .ss-title{font-family:var(--sans);font-weight:700;font-size:.95rem;color:var(--ink);}

    .ss-label{
        display:block;font-family:var(--sans);font-size:.72rem;font-weight:600;
        text-transform:uppercase;letter-spacing:.08em;color:var(--muted);
        margin:0 0 .35rem;
    }
    .ss-row{display:flex;gap:.4rem;}
    .ss-url{
        flex:1;min-width:0;
        font-family:var(--sans);font-size:.8rem;color:var(--muted);
        background:var(--panel);border:1px solid var(--rule);border-radius:6px;
        padding:.45rem .55rem;
    }
    .ss-url:focus{outline:none;border-color:var(--accent);color:var(--ink);}
    .ss-copy{
        flex:0 0 auto;
        font-family:var(--sans);font-size:.8rem;font-weight:700;
        color:#fff;background:var(--accent);
        border:1px solid var(--accent);border-radius:6px;
        padding:.45rem .8rem;cursor:pointer;
        transition:filter .12s;
    }
    .ss-copy:hover{filter:brightness(1.12);}
    .ss-copy.is-done{background:var(--bg);color:var(--accent);}
</style>

@once
<script>
    /* MBScrimShare — the page-facing API. See the partial's header comment. */
    window.MBScrimShare = (function () {
        'use strict';

        const root  = document.getElementById('scrimmage-share');
        const url   = document.getElementById('ss-url');
        const copy  = document.getElementById('ss-copy');
        if (!root) return null;

        /* Copy: clipboard API with the select+execCommand fallback. Either
           way the button confirms, then settles back. */
        let settleTimer = 0;
        copy.addEventListener('click', function () {
            const done = function () {
                copy.textContent = 'Copied \u2713';
                copy.classList.add('is-done');
                clearTimeout(settleTimer);
                settleTimer = setTimeout(function () {
                    copy.textContent = 'Copy';
                    copy.classList.remove('is-done');
                }, 1400);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url.value).then(done, function () {
                    url.select(); document.execCommand('copy'); done();
                });
            } else {
                url.select(); document.execCommand('copy'); done();
            }
        });

        // A click on the field selects the whole link — the manual-copy path.
        url.addEventListener('click', function () { url.select(); });

        /* Close on outside click / Escape — the QuickNav / tx pattern. */
        document.addEventListener('click', function (e) {
            if (root.hasAttribute('open') && !e.target.closest('.scrim-share')) {
                root.removeAttribute('open');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') root.removeAttribute('open');
        });

        return {
            setUrl: function (u) { url.value = u; },
            show:   function () { root.hidden = false; },
            hide:   function () { root.hidden = true; root.removeAttribute('open'); },
            /* Phase 2 will add:
               setResults(payload) → renders the marks card + PNG preview +
               PNG share button into #ss-results and unhides it;
               clearResults()     → empties + rehides it for the next round. */
        };
    })();
</script>
@endonce
