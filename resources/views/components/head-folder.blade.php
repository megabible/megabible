{{--
    HEAD FOLDER  ·  resources/views/components/head-folder.blade.php
    ------------------------------------------------------------------
    fold-unify r5

    The per-page "apps" folder in the sticky head's corner: one folder
    circle that, when open, becomes the right end of a horizontal pill of
    app buttons growing LEFT from it, inline with the circle. The pill may
    run over the title on narrow screens — that's intended. THE folder is
    the one place for anything that changes the page, so the Aa text
    settings live in it too (drop the include in the slot; its own panel
    still hangs below its trigger).

    ONE SIZE. Every circle in the cluster — the folder toggle, every app,
    and the Aa — is exactly --fld-size. There is no per-app fudge and no
    partial shrinking: on small screens the token itself steps down, so
    the whole cluster scales together. The pill's thickness derives from
    the circles plus --fld-pad. These two are THE knobs:

      --fld-size   diameter of every circle (toggle, apps, Aa)
      --fld-pad    breathing room between the circles and the pill edge

    The apps are whatever the page puts in the slot — the folder knows
    nothing about them beyond three conventions:

      TOGGLE app    a <button> carrying aria-pressed. While pressed it
                    fills accent, and the shut folder circle shows a dot.
      ONE-SHOT app  a <button>/<a> with no aria-pressed (share, export…).
      DOTTED app    any .fld-app also carrying .has-dot: a mode is active
                    somewhere behind this app (the vigil, once its candle
                    opens a sheet instead of toggling). The app keeps its
                    ghost look but wears a small accent dot, and the shut
                    folder circle shows its dot too. The page or its
                    script owns the class; the folder only reads it.

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

@props(['id' => 'head-folder', 'open' => false, 'persist' => null])

{{-- `persist="reader"` remembers open/shut in localStorage under
     mb.fold.reader, across pages. Every reading-side surface — chapter,
     book, vigil, vigil-book — passes it, so they share ONE memory: the
     folder is open where you left it open and shut where you shut it,
     and nothing but the user's own toggle ever changes that.

     `open` renders the folder already out AND wins over the stored state:
     a page passing :open="true" always ARRIVES open; combined with
     persist it still records the user's toggles. No page currently uses
     it (the vigil pages did until fold-unify r5 folded them onto plain
     persist) — it stays for any future surface that must greet with the
     pill out. --}}
<details class="head-folder" id="{{ $id }}" @if ($open) open @endif>
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
    /* ═══ THE KNOBS ═══════════════════════════════════════════════════
       --fld-size   diameter of EVERY circle: the toggle, every .fld-app,
                    and the Aa. Explicit width AND height — nothing in the
                    cluster is ever allowed to derive its own size (the
                    aspect-ratio/auto-height chain let anchors collapse to
                    ~35px on some pages; fixed both ways, nothing can).
       --fld-pad    the ring of parchment between the circles and the
                    pill's edge; the pill's height and the folder circle's
                    seat both derive from it.
       --fld-glyph  every SVG glyph as a fraction of its circle.
       Change these here for every page; a page can override them on
       .head-folder in its own styles (none currently do — the board only
       overrides --fld-edge). The small-screen steps shrink the whole
       cluster together; the 420px step exists so the board's seven apps
       still clear a small phone now that apps never shrink individually. */
    .head-folder { --fld-size: 46px; --fld-pad: 6px; --fld-gap: .3rem; --fld-glyph: .56; position: relative; flex: 0 0 auto; }
    @media (max-width: 520px) {
        .head-folder { --fld-size: 40px; --fld-pad: 5px; --fld-gap: .15rem; }
    }
    .head-folder > summary { list-style: none; }
    .head-folder > summary::-webkit-details-marker { display: none; }

    /* border-box: the 1px border draws INSIDE --fld-size, so the toggle's
       disc measures exactly what every borderless app measures. */
    .fld-toggle {
        position: relative; z-index: 81;
        box-sizing: border-box;
        display: inline-flex; align-items: center; justify-content: center;
        width: var(--fld-size); height: var(--fld-size); border-radius: 50%; cursor: pointer;
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
    .fld-toggle svg { display: block; width: calc(var(--fld-size) * var(--fld-glyph)); height: calc(var(--fld-size) * var(--fld-glyph)); pointer-events: none; }
    /* Optical centering: both folder marks draw their ink high in the
       24-box (y ≈ 3–20), and the open mark's lid leans left — a perfectly
       centred SVG box still LOOKS up-left. Percent transforms scale with
       the token, so the nudge holds at every size. */
    .fld-toggle .fld-ico-closed { transform: translateY(3%); }
    .fld-toggle .fld-ico-open   { display: none; transform: translate(3%, 4%); }
    .head-folder[open] .fld-toggle .fld-ico-closed { display: none; }
    .head-folder[open] .fld-toggle .fld-ico-open   { display: block; }

    /* In-use dot: a tool inside is switched on while the folder is SHUT
       (aria-pressed, .is-active, or .has-dot — see syncBadge). The ring
       in --bg keeps it legible over the accent hover fill. */
    .head-folder.has-active:not([open]) .fld-toggle::after {
        content: ""; position: absolute; top: 2px; right: 2px;
        width: 9px; height: 9px; border-radius: 50%;
        background: var(--accent); box-shadow: 0 0 0 2px var(--bg);
    }

    /* ─── Pill: grows LEFT from the folder, inline with it ──────────── */
    /* Vertically centred on the circle; its right edge sits --fld-pad past
       the circle's, and the right padding (pad + circle + pad) leaves the
       circle its own seat inside the pill. Absolute, so the head's height
       never changes and the sticky measurement is untouched. z-index
       matches .ts-panel. */
    .fld-drawer {
        /* --fld-edge: distance from the pill's right edge to the viewport's.
           1.5rem is the container padding; a page whose cluster sits
           elsewhere overrides it on .head-folder (the board uses its gutter).
           The pill can never be wider than the screen minus that edge and a
           .5rem left margin. Apps never compress individually anymore — the
           small-screen token steps up top are what keep a full pill (the
           board's seven apps) inside that budget. */
        --fld-edge: 1.5rem;
        position: absolute; z-index: 80;
        top: 50%; right: calc(var(--fld-pad) * -1);
        transform: translateY(-50%);
        max-width: calc(100vw - var(--fld-edge) - .5rem);
        display: flex; align-items: center; gap: var(--fld-gap);
        padding: var(--fld-pad) calc(var(--fld-size) + 2 * var(--fld-pad)) var(--fld-pad) var(--fld-pad);
        background: var(--bg); border: 1px solid var(--rule); border-radius: 999px;
        box-shadow: 0 8px 28px rgba(42,31,23,.18);
    }
    /* Slide-in only for a folder the USER opened. A folder that arrives
       open (the vigil, or the reader restoring last state) paints in place
       — otherwise the first frames show the pill fading in from nothing.
       The script adds .fld-live on the first toggle. */
    .head-folder.fld-live .fld-drawer { animation: fld-slide .18s cubic-bezier(.2,.8,.2,1); }
    @keyframes fld-slide {
        from { opacity: 0; transform: translate(8px, -50%); }
        to   { opacity: 1; transform: translate(0, -50%); }
    }
    @media (prefers-reduced-motion: reduce) {
        .fld-drawer { animation: none; }
    }

    /* ─── Apps: ghost circles, accent when active ───────────────────── */
    /* Every app is exactly --fld-size both ways — the same circle as the
       toggle, whether it's a <button>, an <a>, or a <summary>. NOTHING
       here is auto, percentage, or shrinkable: the aspect-ratio/auto
       regime let anchors resolve smaller than their siblings, and flex
       shrink let apps compress while the toggle held firm. If the pill
       ever genuinely can't fit, the small-screen token steps handle it —
       uniformly. position:relative anchors the .has-dot badge. */
    .fld-app {
        position: relative;
        flex: 0 0 auto;
        display: inline-flex; align-items: center; justify-content: center;
        width: var(--fld-size); height: var(--fld-size); padding: 0;
        border: none; border-radius: 50%;
        background: none; color: var(--muted); cursor: pointer;
        text-decoration: none;
        transition: color .12s, background .12s;
    }
    @media (hover: hover) {
        .fld-app:hover { color: var(--accent); background: var(--panel); }
        .fld-app.is-active:hover,
        .fld-app[aria-pressed="true"]:hover,
        .fld-drawer details[open] > .fld-app:hover { filter: brightness(1.12); }
    }
    .fld-app:focus-visible { outline: none; color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .fld-app.is-active,
    .fld-app[aria-pressed="true"],
    .fld-drawer details[open] > .fld-app { color: var(--bg); background: var(--accent); }
    /* A <details> app (share, pericope…) reads active while its panel is out —
       the same accent fill a pressed toggle gets. The rule is here, not on each
       page, so every panel app looks the same. */

    /* Dotted app: the mode behind this app is switched on, but the app
       itself stays a ghost — only the dot says so (the vigil candle once
       it opens a sheet). Same dot as the shut folder's; the --bg ring
       keeps it legible over both the hover fill and the accent fill a
       details[open] summary wears. */
    .fld-app.has-dot::after {
        content: ""; position: absolute; top: 1px; right: 1px;
        width: 9px; height: 9px; border-radius: 50%;
        background: var(--accent); box-shadow: 0 0 0 2px var(--bg);
    }

    /* Not yet wired / nothing to do (undo with an empty stack, etc.).
       Buttons take :disabled; anchors can't, so they carry aria-disabled
       instead. The script owning the anchor also strips its href and sets
       tabindex=-1. */
    .fld-app:disabled,
    .fld-app[aria-disabled="true"] { opacity: .4; cursor: default; filter: none; }
    @media (hover: hover) {
        .fld-app:disabled:hover,
        .fld-app[aria-disabled="true"]:hover { color: var(--muted); background: none; }
    }
    .fld-app[hidden] { display: none; }
    /* Whole circle is one click surface — never a stroke path (FAB lesson).
       Width AND height explicit: an SVG with height:auto falls back on its
       own intrinsic sizing rules inside some parents, which is exactly the
       negotiation this file no longer permits. */
    .fld-app svg { display: block; width: calc(var(--fld-size) * var(--fld-glyph)); height: calc(var(--fld-size) * var(--fld-glyph)); pointer-events: none; }

    /* ─── Panels beneath the pill ────────────────────────────────────── */
    /* Any <details> app (Aa, Share…) is made position:static so its panel
       positions against the PILL, not its own trigger: right:0 lands under
       the folder circle and top:100% sits just below the pill — every
       panel opens in the same place. Panels never outgrow the screen.
       NOTE: static kills the summary's .has-dot anchor, so the dot rule
       above re-anchors fine — the summary itself stays position:relative
       via .fld-app. */
    .fld-drawer details { position: static; }
    .fld-drawer details > summary { list-style: none; }
    .fld-drawer details > summary::-webkit-details-marker { display: none; }
    .fld-drawer details > div {
        max-width: calc(100vw - var(--fld-edge) - .5rem);
        box-sizing: border-box;
        /* Tall panels (a long pericope list) scroll inside themselves rather
           than running under the fold. 8rem ≈ the pinned head plus the panel's
           own offset; dvh so a phone's shrinking toolbar doesn't clip it.
           overscroll-behavior stops a flick at the list's end from scrolling
           the reader underneath. */
        max-height: calc(100vh - 8rem);
        max-height: calc(100dvh - 8rem);
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    /* ─── Aa inside the pill ─────────────────────────────────────────── */
    /* The text-settings trigger arrives with its own 40px bordered circle;
       inside the pill it is EXACTLY --fld-size like every other app — the
       flex item carries the size, the trigger fills it, and the Aa glyph
       (text, so it can't scale by % like the SVGs) derives from the same
       token. Its panel keeps its own absolute positioning. */
    .fld-drawer .text-settings,
    .fld-drawer .pb-share { margin: 0; flex: 0 0 auto; position: static; }
    /* border-box: the trigger ships a 1px border (transparent in here, but
       still painted under by the background) — drawing it inside keeps the
       Aa's disc the same --fld-size as every other circle. */
    .fld-drawer .ts-trigger {
        box-sizing: border-box;
        width: var(--fld-size); height: var(--fld-size);
        background: none; border-color: transparent;
    }
    .fld-drawer .ts-aa { font-size: calc(var(--fld-size) * .44); }
    @media (hover: hover) {
        .fld-drawer .ts-trigger:hover { color: var(--accent); background: var(--panel); border-color: transparent; }
    }
    .fld-drawer .text-settings[open] .ts-trigger { color: var(--bg); background: var(--accent); border-color: transparent; }
</style>

<script>
    (function () {
        /* fold-unify r5 */
        var root = document.getElementById('{{ $id }}');
        if (!root) return;
        var drawer = root.querySelector('.fld-drawer');

        // Remembered state. This script sits directly after the markup, so
        // the restore lands before the browser has anything else to paint.
        var persistKey = @json($persist ? 'mb.fold.' . $persist : null);

        // A folder that declares `open` in its markup always ARRIVES open —
        // the stored state must not shut it. Its toggles still record below,
        // so vigil pages share the reader's folder memory without ever
        // greeting the user with the pill tucked away.
        var forceOpen = @json((bool) $open);

        // True until the first task after parse. It swallows every toggle
        // that isn't a user's: the one our restore queues by setting
        // root.open, AND the one the browser itself queues at parse time
        // for any <details open> in the markup. That parser event is why
        // vigil pages (which arrive open) used to slide their pill in on
        // every navigation while the reader (restored open, already
        // guarded) sat still. Cleared by the timeout below.
        var restoring = true;

        if (persistKey && !forceOpen) {
            try {
                var saved = localStorage.getItem(persistKey);
                if (saved === '1' && !root.open) { root.open = true; }
                else if (saved === '0' && root.open) { root.open = false; }
            } catch (e) { /* storage blocked: fall back to the markup */ }
        }

        // Every toggle after the restore: arm the slide-in (see .fld-live)
        // and, when persisting, record the new state.
        root.addEventListener('toggle', function () {
            if (restoring) return;                 // the restore's own event — ignore it
            root.classList.add('fld-live');
            if (persistKey) {
                try { localStorage.setItem(persistKey, root.open ? '1' : '0'); } catch (e) {}
            }
        });

        // Both non-user toggles (the parser's and the restore's) are queued
        // before this timeout is, so they fire first; by the time this runs
        // the coast is clear and real user toggles get through.
        setTimeout(function () { restoring = false; }, 0);

        // Is any app inside currently switched on? aria-pressed and
        // .is-active are the classic toggles; .has-dot is the sheet-style
        // mode marker (the vigil candle). All three light the shut
        // folder's badge.
        function hasActive() {
            return !!root.querySelector('.fld-app[aria-pressed="true"], .fld-app.is-active, .fld-app.has-dot');
        }
        function syncBadge() { root.classList.toggle('has-active', hasActive()); }

        // Apps flip their own aria-pressed / classes (zoom, edit…); watch
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
