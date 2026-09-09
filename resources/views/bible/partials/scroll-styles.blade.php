{{--
    PERICOPE — SCROLL PAGE STYLES        bible/partials/scroll-styles
    ---------------------------------------------------------------
    RAW CSS, NO <style> WRAPPER: the scroll page includes this INSIDE its
    own open <style> block, the present-styles convention. A wrapper here
    would close the page's style element early and dump everything after
    it into the page as raw text.

    Scroll r3: this partial belongs to /extras/pericope/{slug}/scroll
    ONLY — the grid page no longer includes it. It carries the feed
    (painted by public/js/pericope-scroll.js) AND the page shell: back
    link, view pill, the share panel with scroll settings, and the
    empty / missing states. The .pbs-* / .pps-* panel rules and the
    shell rules are copied from board.blade's style block (the source
    of truth for that chrome until the shared partials are extracted
    alongside the font manager) — a change there wants a mirror here.

    Everything reads the theme tokens (--bg / --ink / --muted / --rule /
    --panel / --accent / --tl-*), so midnight, pure and terminal repaint the
    feed without a line here changing.

    KNOBS (custom properties on #pb-feed):
      --pbf-w      feed column width on desktop (Instagram's 470px)
      --pbf-type   verse type as a fraction of the box width (cqw)
      --pbf-pad    inner padding of a page, also in cqw
--}}
    /* Revision tripwire (pairs with console '[pericope] scroll r2'). */
    #pb-feed { --pbf-rev: 4; }

    /* ---- page shell (scroll r3) ---------------------------------------
       The grid page's chrome, mirrored: back link, the view pill in the
       sticky head, and the not-found / empty panels. Copied from
       board.blade — see the header note. */
    .pb-head { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .pb-name { color: var(--ink); }
    .pb-back {
        display: inline-flex; align-items: center; gap: .35rem;
        font-family: var(--sans); font-size: .85rem; color: var(--muted);
        text-decoration: none; margin: .3rem 0 1.4rem;
    }
    .pb-back:hover { color: var(--accent); }
    .chapter-head .pb-view { margin-top: .45rem; }
    .pb-empty, .pb-missing {
        max-width: 30rem; margin: 3rem auto; text-align: center;
        font-family: var(--sans); color: var(--muted);
    }
    .pb-empty h2, .pb-missing h2 { margin: 0 0 .3rem; color: var(--ink); font-size: 1.25rem; font-weight: 400; }
    .pb-empty p, .pb-missing p { margin: 0 0 1rem; font-size: .9rem; line-height: 1.55; }
    .pb-missing a, .pb-empty a { color: var(--accent); }

    /* ---- the share panel + scroll settings ----------------------------
       The grid share panel's clothes (.pbs-* / .pps-*), copied from
       board.blade; the header pill here is a LABEL ("Scroll"), not a
       button, so it gets the .is-label calm. */
    .pbs-panel {
        position: absolute; right: 0; top: calc(100% + 10px); z-index: 80;
        width: 300px; padding: 1rem;
        background: var(--bg); border: 1px solid var(--rule); border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
        text-align: left; cursor: default;
    }
    .pbs-present-row {
        display: flex; align-items: center; gap: .3rem;
        margin: 0 0 1rem; padding: .28rem .28rem .28rem 0;
        background: var(--accent); border: 1px solid var(--accent); border-radius: 999px;
    }
    .pbs-present {
        flex: 1 1 auto; display: flex; align-items: center; justify-content: center; gap: .55rem;
        min-width: 0; padding: .45rem .6rem .45rem 1rem; border: none; border-radius: 999px;
        font-family: var(--sans); font-size: .92rem; font-weight: 600;
        background: none; color: #fff;
    }
    .pbs-present.is-label { cursor: default; }
    .pbs-present svg { width: 18px; height: 18px; display: block; pointer-events: none; }
    .pbs-gear {
        flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; padding: 0; border-radius: 50%; cursor: pointer;
        border: 1px solid rgba(255,255,255,.55); background: none; color: #fff;
        transition: color .12s, background .12s, transform .25s;
    }
    .pbs-gear svg { width: 18px; height: 18px; display: block; pointer-events: none; }
    .pbs-gear:hover { background: rgba(255,255,255,.14); }
    .pbs-gear.is-on { background: #fff; color: var(--accent); border-color: #fff; transform: rotate(60deg); }
    .pbs-title { font-family: var(--serif); font-size: 1.05rem; color: var(--ink); margin: 0 0 .5rem; }
    .pbs-blurb { font-family: var(--sans); font-size: .82rem; color: var(--muted); margin: 0 0 .8rem; line-height: 1.5; }
    .pbs-url {
        width: 100%; box-sizing: border-box; resize: none;
        font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .74rem; line-height: 1.45;
        color: var(--ink); background: var(--panel);
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .5rem .6rem; word-break: break-all;
    }
    .pbs-url:focus { outline: none; border-color: var(--accent); }
    .pbs-size { font-family: var(--sans); font-size: .78rem; color: var(--muted); margin: .5rem 0 .9rem; }
    .pbs-size.is-over { color: var(--accent); }
    .pbs-qr { display: flex; justify-content: center; margin: 0 0 1rem; }
    .pbs-qr svg {
        width: min(58vw, 13rem); height: auto; display: block;
        background: #fff; border-radius: 10px;
        box-shadow: 0 1px 6px rgba(0,0,0,.12);
    }
    .pbs-btns { display: flex; justify-content: flex-end; gap: .5rem; }
    .pbs-btn {
        font-family: var(--sans); font-size: .88rem; font-weight: 600; cursor: pointer;
        border-radius: 999px; padding: .45rem 1rem; border: 1px solid var(--rule);
        background: none; color: var(--muted);
        transition: color .12s, background .12s, filter .12s;
    }
    .pbs-btn.is-quiet:hover { color: var(--ink); background: var(--panel); }
    .pbs-btn.is-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .pbs-btn.is-primary:hover { filter: brightness(1.12); }
    .pps-title { font-family: var(--serif); font-weight: 700; font-size: 1.05rem; color: var(--ink); margin: 0 0 .7rem; }
    .pps-row { display: grid; gap: .5rem; margin-bottom: .55rem; }
    .pps-preview {
        min-height: 3.2rem; display: flex; align-items: center; justify-content: center;
        padding: .5rem .8rem; margin-bottom: .55rem;
        font-size: 1.5rem; line-height: 1.15; color: var(--ink); text-align: center;
        background: var(--panel); border: 1px solid var(--rule); border-radius: 8px;
        overflow: hidden; text-overflow: ellipsis;
    }
    .pps-select {
        width: 100%; box-sizing: border-box; min-height: 40px;
        font-family: var(--sans); font-size: .85rem; color: var(--ink);
        background: var(--panel); border: 1px solid var(--rule); border-radius: 8px; padding: .4rem .6rem;
    }
    .pps-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .pps-hint { margin: 0; font-family: var(--sans); font-size: .72rem; color: var(--muted); line-height: 1.4; }

    /* ---- the feed column --------------------------------------------- */
    #pb-feed {
        --pbf-w: 470px;
        --pbf-type: 6.6cqw;
        --pbf-pad: 9cqw;
    }
    .pbf-feed {
        max-width: var(--pbf-w); margin: .2rem auto 0; padding-bottom: 4rem;
    }
    /* Phones: posts run edge to edge, the Instagram way. The container's
       padding is the board's gutter (--pb-gutter, set by the script). */
    @media (max-width: 640px) {
        .pbf-feed { max-width: none; margin-left: calc(-1 * var(--pb-gutter, 1.5rem)); margin-right: calc(-1 * var(--pb-gutter, 1.5rem)); }
    }

    /* ---- a post ------------------------------------------------------- */
    .pbf-post { margin: 0 0 1.4rem; }
    .pbf-post + .pbf-post { border-top: 1px solid var(--rule); padding-top: .4rem; }

    .pbf-head {
        display: flex; align-items: center; gap: .7rem;
        padding: .55rem .9rem .6rem; font-family: var(--sans);
    }
    /* The "avatar": a section-coloured circle with the story-ring gap. */
    .pbf-dot {
        flex: 0 0 auto; width: 34px; height: 34px; border-radius: 50%;
        background: var(--bk);
        box-shadow: 0 0 0 2px var(--bg), 0 0 0 3.5px var(--bk);
    }
    .pbf-who { min-width: 0; line-height: 1.2; }
    .pbf-book {
        font-weight: 600; font-size: .92rem; color: var(--ink);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .pbf-tx { font-weight: 500; color: var(--muted); }
    .pbf-ref { font-size: .8rem; color: var(--muted); margin-top: .1rem;
               white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* ---- the content box ---------------------------------------------- */
    .pbf-box {
        position: relative; overflow: hidden;
        aspect-ratio: 1 / 1;
        container-type: inline-size;
        color: #fff;
        touch-action: manipulation;          /* no double-tap zoom: the double tap is ours */
        -webkit-user-select: none; user-select: none;
        cursor: pointer;
    }
    .pbf-box.is-16x9 { aspect-ratio: 16 / 9; }
    .pbf-box.is-4x5  { aspect-ratio: 4 / 5; }
    @media (min-width: 641px) { .pbf-box { border-radius: 6px; } }

    /* Backdrop v1: the gradient. --pbf-a is the section colour, --pbf-b a
       seeded second palette colour; both darkened a touch so white type
       clears them under every theme (midnight's palette runs pastel). */
    .pbf-box.bd-gradient::before {
        content: ""; position: absolute; inset: 0;
        background:
            radial-gradient(circle at var(--pbf-hx, 30%) var(--pbf-hy, 25%), rgba(255,255,255,.22), transparent 55%),
            linear-gradient(var(--pbf-angle, 160deg),
                color-mix(in oklab, var(--pbf-a) 80%, #000) 0%,
                color-mix(in oklab, var(--pbf-a) 60%, var(--pbf-b)) 100%);
    }
    /* A faint paper grain so flat colour doesn't read as a mockup. */
    .pbf-box.bd-gradient::after {
        content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .07;
        background-image: repeating-linear-gradient(0deg, transparent 0 2px, rgba(255,255,255,.6) 2px 3px);
        mix-blend-mode: overlay;
    }
    :root[data-theme="terminal"] .pbf-box { color: var(--ink); }

    .pbf-pages {
        position: absolute; inset: 0; z-index: 1;
        display: flex; overflow-x: auto; overflow-y: hidden;
        scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch;
        scrollbar-width: none; outline: none;
    }
    .pbf-pages::-webkit-scrollbar { display: none; }
    .pbf-pages:focus-visible { box-shadow: inset 0 0 0 3px rgba(255,255,255,.55); }
    .pbf-page {
        flex: 0 0 100%; scroll-snap-align: start; box-sizing: border-box;
        display: flex; align-items: center; justify-content: center;
        padding: var(--pbf-pad);
    }
    .pbf-page-in {
        --pbf-scale: 1;
        max-height: 100%; width: 100%; text-align: center;
        font-family: var(--pbf-font, var(--serif));
        font-size: calc(var(--pbf-type) * var(--pbf-scale));
        line-height: 1.28;
        text-shadow: 0 1px 2px rgba(0,0,0,.35), 0 0 18px rgba(0,0,0,.18);
        text-wrap: pretty;
    }
    .pbf-text { margin: 0; }
    .pbf-part + .pbf-part { margin-top: .7em; }
    .pbf-vn {
        font-family: var(--sans); font-size: .5em; font-weight: 600;
        opacity: .8; margin-right: .2em; vertical-align: super;
    }
    .pbf-part-ref {
        margin: .35em 0 0; font-family: var(--sans); font-size: .48em;
        letter-spacing: .08em; text-transform: uppercase; opacity: .85;
    }
    .pbf-cont {
        display: inline-block; margin-bottom: .6em;
        font-family: var(--sans); font-size: .42em; letter-spacing: .12em;
        text-transform: uppercase; opacity: .7;
    }
    .pbf-empty { font-family: var(--sans); font-size: .6em; opacity: .8; }

    /* Carousel dots + desktop arrows (touch swipes natively). */
    .pbf-dots {
        position: absolute; left: 0; right: 0; bottom: .55rem; z-index: 2;
        display: flex; justify-content: center; gap: .3rem; pointer-events: none;
    }
    .pbf-dot-i { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,.45); }
    .pbf-dot-i.is-on { background: #fff; }
    .pbf-arrow {
        position: absolute; top: 50%; transform: translateY(-50%); z-index: 2;
        width: 30px; height: 30px; border-radius: 50%; border: 0;
        background: rgba(255,255,255,.85); color: #222; cursor: pointer;
        display: none; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .15s;
    }
    .pbf-arrow svg { width: 18px; height: 18px; }
    .pbf-arrow.is-prev { left: .5rem; }
    .pbf-arrow.is-next { right: .5rem; }
    @media (hover: hover) and (pointer: fine) {
        .pbf-arrow { display: inline-flex; }
        .pbf-box:hover .pbf-arrow:not([hidden]) { opacity: 1; }
    }

    /* The double-tap heart. */
    .pbf-pop {
        position: absolute; inset: 0; z-index: 3; pointer-events: none;
        display: flex; align-items: center; justify-content: center; opacity: 0;
    }
    .pbf-pop svg { width: 28cqw; height: 28cqw; fill: #fff; stroke: #fff; filter: drop-shadow(0 4px 12px rgba(0,0,0,.35)); }
    .pbf-pop.is-pop { animation: pbf-pop .9s cubic-bezier(.2, .9, .3, 1.2) both; }
    @keyframes pbf-pop {
        0%   { opacity: 0; transform: scale(.4); }
        18%  { opacity: 1; transform: scale(1.12); }
        35%  { transform: scale(1); }
        75%  { opacity: 1; transform: scale(1); }
        100% { opacity: 0; transform: scale(1.15); }
    }
    @media (prefers-reduced-motion: reduce) {
        .pbf-pop.is-pop { animation: pbf-pop-still .9s linear both; }
        @keyframes pbf-pop-still { 0%, 80% { opacity: 1; } 100% { opacity: 0; } }
    }

    /* ---- actions, likes, summary, comments ---------------------------- */
    .pbf-actions { display: flex; gap: .15rem; padding: .35rem .45rem 0; }
    .pbf-act {
        width: 40px; height: 40px; border: 0; border-radius: 50%;
        background: none; color: var(--ink); cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: transform .12s, color .12s;
    }
    .pbf-act svg { width: 24px; height: 24px; }
    .pbf-act:hover { color: var(--accent); }
    .pbf-act:active { transform: scale(.88); }
    .pbf-act:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
    .pbf-act.is-liked { color: var(--accent); }
    .pbf-act.is-liked svg { fill: var(--accent); }

    .pbf-likes {
        margin: .1rem 0 0; padding: 0 .9rem; min-height: 1.2em;
        font-family: var(--sans); font-size: .85rem; font-weight: 600; color: var(--ink);
    }
    .pbf-summary {
        margin: .15rem 0 0; padding: 0 .9rem 0;
        font-family: var(--sans); font-size: .85rem; color: var(--ink);
    }
    /* Head links into the reader — the single post's reference and a
       group's member refs — the grid cards' habit. Quiet by default,
       accent on hover. */
    .pbf-book a, .pbf-ref a { color: inherit; text-decoration: none; }
    .pbf-book a:hover, .pbf-ref a:hover { color: var(--accent); }
    /* Heading cards become dividers between posts. */
    .pbf-heading { padding: 1.1rem .9rem .5rem; }
    .pbf-heading h2 {
        margin: 0; font-family: var(--serif); font-weight: 400; font-size: 1.15rem;
        color: var(--ink); letter-spacing: -.01em;
    }

    /* ---- the recent rail (scroll r4, desktop only) ---------------------
       The visitor's other boards beside the feed, Instagram's desktop
       shape: the 470px column plus a sticky rail of "accounts". Hidden
       below the breakpoint — phones get the feed alone. Rows are painted
       by pericope-scroll.js from the store's index. */
    .pbf-rail { display: none; }
    @media (min-width: 900px) {
        #pb-feed { display: flex; justify-content: center; align-items: flex-start; gap: 3rem; }
        #pb-feed[hidden] { display: none; }
        .pbf-feed { flex: 0 0 auto; width: var(--pbf-w); max-width: var(--pbf-w); margin: .2rem 0 0; }
        .pbf-rail {
            display: block; flex: 0 0 auto; width: 290px;
            position: sticky; top: 5.5rem;
            padding-top: .4rem;
        }
        .pbf-rail[hidden] { display: none; }
    }
    .pbf-rail-title {
        margin: 0 0 .7rem; font-family: var(--sans); font-size: .85rem;
        font-weight: 600; color: var(--muted);
    }
    .pbf-acct {
        display: flex; align-items: center; gap: .7rem;
        padding: .45rem .5rem; margin: 0 -.5rem; border-radius: 10px;
        text-decoration: none;
    }
    .pbf-acct:hover { background: var(--panel); }
    .pbf-acct .pbf-dot { width: 40px; height: 40px; }
    .pbf-acct-who { min-width: 0; line-height: 1.25; }
    .pbf-acct-name {
        font-family: var(--sans); font-size: .9rem; font-weight: 600; color: var(--ink);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .pbf-acct-meta { font-family: var(--sans); font-size: .78rem; color: var(--muted); }
    .pbf-rail-all {
        display: inline-block; margin-top: .8rem;
        font-family: var(--sans); font-size: .85rem; color: var(--accent);
        text-decoration: none;
    }
    .pbf-rail-all:hover { text-decoration: underline; }

    /* ---- toast ---------------------------------------------------------- */
    .pbf-toast {
        position: fixed; left: 50%; bottom: 1.6rem; z-index: 600;
        transform: translate(-50%, 8px); opacity: 0; pointer-events: none;
        padding: .55rem 1rem; border-radius: 999px;
        background: var(--ink); color: var(--bg);
        font-family: var(--sans); font-size: .85rem; font-weight: 500;
        box-shadow: 0 8px 24px rgba(0,0,0,.18);
        transition: opacity .18s, transform .18s;
        max-width: calc(100vw - 2rem); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .pbf-toast.is-on { opacity: 1; transform: translate(-50%, 0); }
