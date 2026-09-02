{{--
    SHARED FAB CHROME  ·  bible/partials/fab-styles.blade.php
    ----------------------------------------------------------
    The floating action bar's base styles — the pill, the ghost icon
    buttons, the rise/park transition — extracted VERBATIM from
    chapter.blade.php (Phase 5 of the Pericope board redesign) so the
    reader's Focus FAB and the board's edit FAB share one look without the
    CSS drifting apart. Pull it into a page INSIDE the <style> block with
    the usual Blade include directive, pointing at bible.partials.fab-styles.
    (No directive spelled out here on purpose — directive names inside Blade
    comments are a documented parser trap in this codebase.)

    Pages add their own page-specific FAB rules after the include (the
    reader keeps its .fab-app rules in chapter.blade; the board adds
    .pbe-fab rules in board.blade).
--}}
    /* ---- Floating Action Bar (FAB) ---- */
    .fab {
        position: fixed;
        left: 50%;
        bottom: 1.5rem;
        transform: translateX(-50%) translateY(160%);  /* parked off-screen */
        z-index: 60;
        display: flex;
        align-items: center;
        gap: .4rem;
        padding: .4rem .4rem .4rem .35rem;
        background: var(--bg);
        border: 1px solid var(--rule);
        border-radius: 999px;
        box-shadow: 0 8px 28px rgba(42,31,23,.18);
        opacity: 0;
        pointer-events: none;
        transition: transform .28s cubic-bezier(.2,.8,.2,1), opacity .2s ease;
    }
    .fab.is-visible {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
        pointer-events: auto;
    }
    /* The count pill IS the button that opens the Synthesis view. The diagonal
       arrow signals that the red text is clickable. */
    .fab-pill {
        display: inline-flex; align-items: center; gap: .35rem;
        border: none; cursor: pointer;
        font-family: var(--sans); font-size: .9rem; font-weight: 600;
        background: var(--accent); color: #fff;
        padding: .5rem 1.05rem; border-radius: 999px;
        white-space: nowrap;
        transition: filter .12s;
    }
    .fab-pill:hover { filter: brightness(1.12); }
    .fab-pill-arrow { display: block; flex: 0 0 auto; }
    /* The word "selected" is dropped on narrow screens so the pill can't wrap;
       the count + arrow are enough there. Full text returns on wider screens. */
    @media (max-width: 600px) {
        .fab-pill-suffix { display: none; }
    }
    /* Ghost icon buttons in the pill: copy, share, and the close/escape hatch. */
    .fab-icon {
        flex: 0 0 auto;
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; height: 38px;
        border: none; border-radius: 50%;
        background: none; color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .fab-icon:hover { color: var(--accent); background: var(--panel); }
    /* Brief confirmation flash after a copy/share action. */
    .fab-icon.is-done { color: var(--accent); }
    /* Icons are decorative: make them transparent to pointer events so a click
       ALWAYS lands on the <button>/<a>, never on an SVG <path>. Without this,
       an icon drawn with fill:none only "catches" clicks on its stroke lines,
       so e.target becomes the path and the drawer's outside-click guard
       misfires — the whole button must be one click surface. */
    .fab svg { display: block; pointer-events: none; }
    /* The old scrim jump stle is an <a> dressed as a .fab-icon. It had two extra rules:
       the hidden attribute must win over .fab-icon's inline-flex (it's
       hidden whenever the selection isn't exactly one verse), and the
       anchor must not pick up the base link underline. */
    .fab-icon[hidden] { display: none; }
    a.fab-icon { text-decoration: none; }

