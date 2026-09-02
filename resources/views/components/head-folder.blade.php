{{--
    HEAD FOLDER  ·  resources/views/components/head-folder.blade.php
    ------------------------------------------------------------------
    The per-page "apps" folder in the sticky head's corner: one folder
    circle that, when open, becomes the right end of a horizontal pill of
    app buttons growing LEFT from it, inline with the circle. The pill may
    run over the title on narrow screens — that's intended. THE folder is
    the one place for anything that changes the page, so the Aa text
    settings live in it too (drop the include in the slot; its own panel
    still hangs below its trigger).

    The apps are whatever the page puts in the slot — the folder knows
    nothing about them beyond two conventions:

      TOGGLE app    a <button> carrying aria-pressed. While any app reads
                    aria-pressed="true" (or wears .is-active) the folder
                    circle shows an accent dot when shut.
      ONE-SHOT app  a <button>/<a> with no aria-pressed (share, export…).

    THE FOLDER ONLY CLOSES WHEN THE USER CLOSES IT. Someone who opened it is
    about to use the apps repeatedly, so nothing on the page — outside
    clicks, Escape, firing an app — shuts it behind their back. The circle
    toggles it; that is the whole close model. (Escape still closes a panel
    open INSIDE it, like Aa — that panel's own listener.)

    USAGE (inside .head-actions — it's the whole cluster now):

        <x-head-folder>
            <button type="button" class="fld-app" id="…" aria-pressed="false" aria-label="…" title="…">svg</button>
            …
            (the text-settings partial include, if the page has one)
        </x-head-folder>

    Anonymous Blade component: Laravel resolves <x-head-folder> to this file
    by name, no class needed. `$slot` is whatever sits between the tags.

    Built on <details>/<summary> like the Aa panel, so open/close is native
    and keyboard-accessible with no JS, and shortcuts.js can treat it like
    any other details dropdown. The look is the FAB's app drawer scaled up:
    the same parchment pill, ghost circles, accent fill for the active app.

    CSS NOTE: keep each rule's braces on their own line — two adjacent
    opening braces in a Blade file read as an echo tag (see sticky-head).
--}}

<details class="head-folder" id="{{ $id ?? 'head-folder' }}">
    <summary class="fld-toggle" aria-label="Apps" title="Apps">
        {{-- Closed / open folder marks — the same single-path glyphs as the
             FAB; CSS swaps them on [open]. --}}
        <svg class="fld-ico-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>
        <svg class="fld-ico-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/></svg>
    </summary>

    <div class="fld-drawer" role="group" aria-label="Apps">
        {{ $slot }}
    </div>
</details>

<style>
    /* ─── Toggle: the same 40px circle as .ts-trigger ───────────────── */
    /* .head-folder is the anchor for the pill; z-index keeps the circle
       ABOVE the pill so it reads as the pill's right-hand end when open. */
    .head-folder { position: relative; flex: 0 0 auto; }
    .head-folder > summary { list-style: none; }
    .head-folder > summary::-webkit-details-marker { display: none; }

    .fld-toggle {
        position: relative; z-index: 81;
        display: inline-flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; border-radius: 50%; cursor: pointer;
        color: var(--muted); background: var(--bg);
        border: 1px solid var(--rule);
        transition: color .12s, background .12s, border-color .12s;
        user-select: none;
    }
    @media (hover: hover) {
        .fld-toggle:hover { color: var(--bg); background: var(--accent); border-color: var(--accent); }
    }
    .fld-toggle:focus-visible { outline: none; color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .head-folder[open] .fld-toggle { color: var(--bg); background: var(--accent); border-color: var(--accent); }
    /* Glyph swap. Each rule names .fld-toggle so it outranks the generic
       `.fld-toggle svg` display:block below — a bare `.fld-ico-open` lost
       that fight and both folders painted at once. */
    .fld-toggle svg { display: block; width: 21px; height: 21px; pointer-events: none; }
    .fld-toggle .fld-ico-open { display: none; }
    .head-folder[open] .fld-toggle .fld-ico-closed { display: none; }
    .head-folder[open] .fld-toggle .fld-ico-open   { display: block; }

    /* In-use dot: a tool inside is active while the folder is SHUT. The
       ring in --bg keeps it legible over the accent hover fill. */
    .head-folder.has-active:not([open]) .fld-toggle::after {
        content: ""; position: absolute; top: 2px; right: 2px;
        width: 9px; height: 9px; border-radius: 50%;
        background: var(--accent); box-shadow: 0 0 0 2px var(--bg);
    }

    /* ─── Pill: grows LEFT from the folder, inline with it ──────────── */
    /* Vertically centred on the circle; its right edge sits 5px past the
       circle's, and the 50px right padding (5 + 40 + 5) leaves the circle
       its own seat inside the pill. Absolute, so the head's height never
       changes and the sticky measurement is untouched. z-index matches
       .ts-panel. */
    .fld-drawer {
        --fld-pad: 5px;
        /* --fld-edge: distance from the pill's right edge to the viewport's.
           1.5rem is the container padding; a page whose cluster sits
           elsewhere overrides it on .head-folder (the board uses its gutter).
           The pill can never be wider than the screen minus that edge and a
           .5rem left margin — past that, the apps SHRINK (see .fld-app). */
        --fld-edge: 1.5rem;
        position: absolute; z-index: 80;
        top: 50%; right: calc(var(--fld-pad) * -1);
        transform: translateY(-50%);
        max-width: calc(100vw - var(--fld-edge) - .5rem);
        display: flex; align-items: center; gap: .3rem;
        padding: var(--fld-pad) calc(40px + 2 * var(--fld-pad)) var(--fld-pad) var(--fld-pad);
        background: var(--bg); border: 1px solid var(--rule); border-radius: 999px;
        box-shadow: 0 8px 28px rgba(42,31,23,.18);
        animation: fld-slide .18s cubic-bezier(.2,.8,.2,1);
    }
    @keyframes fld-slide {
        from { opacity: 0; transform: translate(8px, -50%); }
        to   { opacity: 1; transform: translate(0, -50%); }
    }
    @media (prefers-reduced-motion: reduce) {
        .fld-drawer { animation: none; }
    }

    /* ─── Apps: ghost circles, accent when active ───────────────────── */
    /* Sized by flex, not a fixed width: 42px when there's room, shrinking
       evenly (down to 26px) when the pill hits its max-width. aspect-ratio
       keeps every app a circle at any size; the glyph is a % of the circle
       so it shrinks in step. */
    .fld-app {
        flex: 0 1 42px; min-width: 26px;
        display: inline-flex; align-items: center; justify-content: center;
        width: 42px; height: auto; aspect-ratio: 1; padding: 0;
        border: none; border-radius: 50%;
        background: none; color: var(--muted); cursor: pointer;
        text-decoration: none;
        transition: color .12s, background .12s;
    }
    @media (hover: hover) {
        .fld-app:hover { color: var(--accent); background: var(--panel); }
        .fld-app.is-active:hover,
        .fld-app[aria-pressed="true"]:hover { filter: brightness(1.12); }
    }
    .fld-app:focus-visible { outline: none; color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .fld-app.is-active,
    .fld-app[aria-pressed="true"] { color: var(--bg); background: var(--accent); }
    /* Not yet wired / nothing to do (undo with an empty stack, etc.). */
    .fld-app:disabled { opacity: .4; cursor: default; filter: none; }
    @media (hover: hover) {
        .fld-app:disabled:hover { color: var(--muted); background: none; }
    }
    .fld-app[hidden] { display: none; }
    /* Whole circle is one click surface — never a stroke path (FAB lesson). */
    .fld-app svg { display: block; width: 52%; height: auto; pointer-events: none; }

    @media (max-width: 520px) {
        .fld-drawer { --fld-pad: 4px; gap: .15rem; }
    }

    /* ─── Panels beneath the pill ────────────────────────────────────── */
    /* Any <details> app (Aa, Share…) is made position:static so its panel
       positions against the PILL, not its own trigger: right:0 lands under
       the folder circle and top:100% sits just below the pill — every
       panel opens in the same place. Panels never outgrow the screen. */
    .fld-drawer details { position: static; }
    .fld-drawer details > summary { list-style: none; }
    .fld-drawer details > summary::-webkit-details-marker { display: none; }
    .fld-drawer details > div { max-width: calc(100vw - var(--fld-edge) - .5rem); box-sizing: border-box; }

    /* ─── Aa inside the pill ─────────────────────────────────────────── */
    /* The text-settings trigger arrives with its own 40px bordered circle;
       inside the pill it dresses as a ghost app like everything else. Its
       panel keeps its own absolute positioning (below the trigger). */
    .fld-drawer .text-settings,
    .fld-drawer .pb-share { margin: 0; flex: 0 1 42px; min-width: 26px; position: static; }
    .fld-drawer .ts-trigger {
        width: 100%; height: auto; aspect-ratio: 1;
        background: none; border-color: transparent;
    }
    @media (max-width: 520px) {
        .fld-drawer .ts-aa { font-size: .9rem; }   /* the glyph is text, so it can't scale by % like the SVGs */
    }
    @media (hover: hover) {
        .fld-drawer .ts-trigger:hover { color: var(--accent); background: var(--panel); border-color: transparent; }
    }
    .fld-drawer .text-settings[open] .ts-trigger { color: var(--bg); background: var(--accent); border-color: transparent; }
</style>

<script>
    (function () {
        var root = document.getElementById('{{ $id ?? 'head-folder' }}');
        if (!root) return;
        var drawer = root.querySelector('.fld-drawer');

        // Is any app inside currently switched on? (Drives the badge.)
        function hasActive() {
            return !!root.querySelector('.fld-app[aria-pressed="true"], .fld-app.is-active');
        }
        function syncBadge() { root.classList.toggle('has-active', hasActive()); }

        // Apps flip their own aria-pressed / .is-active (zoom, edit…); watch
        // for it so the badge is right no matter who changed the state.
        if (window.MutationObserver) {
            new MutationObserver(syncBadge).observe(drawer, {
                subtree: true, attributes: true, attributeFilter: ['aria-pressed', 'class']
            });
        }
        syncBadge();

        // One panel at a time beneath the pill: when an inner <details>
        // opens, any other open one shuts. (`toggle` doesn't bubble, so
        // each gets its own listener.)
        Array.prototype.forEach.call(drawer.querySelectorAll('details'), function (d) {
            d.addEventListener('toggle', function () {
                if (!d.open) return;
                Array.prototype.forEach.call(drawer.querySelectorAll('details[open]'), function (o) {
                    if (o !== d) o.open = false;
                });
            });
        });

        // Shutting the folder must not strand an open Aa panel behind a
        // display:none drawer — it would pop back open next time.
        root.addEventListener('toggle', function () {
            if (root.open) return;
            Array.prototype.forEach.call(root.querySelectorAll('.fld-drawer details[open]'), function (d) {
                d.open = false;
            });
        });
    })();
</script>
