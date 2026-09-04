{{--
    PRESENTATION MODE — styles  ·  bible/partials/present-styles.blade.php
    ----------------------------------------------------------------------
    Raw CSS, no <style> wrapper: include it INSIDE the page's <style> block
    (the board does; the chapter page will when it gets a deck of its own).
    Partner to public/js/pericope-present.js, which builds the .pbp overlay.

    TWO LOOKS, dark and light, on purpose independent of the site themes: a
    slide projected in a room wants its own palette. Everything reads from
    the --pp-* tokens set per look, so a third look is one more block.

    TYPE: the verse is a clamp() of the viewport times --pbp-scale, which
    the script steps down until a long slide fits; --pp-font is the chosen
    slide font (four self-hosted built-ins, or a custom Google Font).
    Wall pattern, density, alignment and tint arrive as data-* attributes
    and --pp-tint on .pbp, set by the presenter from its settings. The reference beneath it
    is deliberately the other voice — spaced small caps in the sans, in the
    look's accent.

    BLADE NOTE: keep every rule's braces on their own line — two adjacent
    opening braces in a Blade file read as an echo tag.
--}}
    /* ---- fonts (self-hosted: public/fonts/present/) --------------------- */
    @font-face { font-family: "Jim Nightshade"; src: url("/fonts/present/jim-nightshade.woff2") format("woff2"); font-display: swap; }
    @font-face { font-family: "New Rocker";     src: url("/fonts/present/new-rocker.woff2")     format("woff2"); font-display: swap; }
    @font-face { font-family: "Tinos";          src: url("/fonts/present/tinos.woff2")          format("woff2"); font-display: swap; }
    @font-face { font-family: "Cal Sans";       src: url("/fonts/present/cal-sans.woff2")       format("woff2"); font-display: swap; }

    /* ---- overlay + looks ------------------------------------------------ */
    .pbp {
        position: fixed; inset: 0; z-index: 400;
        display: flex; flex-direction: column;
        background: var(--pp-bg);
        color: var(--pp-ink);
        overscroll-behavior: contain;
        -webkit-user-select: none; user-select: none;
        cursor: default;
    }
    .pbp[hidden] { display: none; }
    .pbp.is-dark {
        --pp-bg:     #131820;
        --pp-ink:    #f4efe5;
        --pp-muted:  rgba(244,239,229,.58);
        --pp-accent: #d2ad66;
        --pp-line:   rgba(244,239,229,.12);
        --pp-glow:   rgba(210,173,102,.10);
        --pp-grain:  .06;
    }
    .pbp.is-light {
        --pp-bg:     #f4efe6;
        --pp-ink:    #221b16;
        --pp-muted:  rgba(34,27,22,.58);
        --pp-accent: #8b2c2c;
        --pp-line:   rgba(34,27,22,.14);
        --pp-glow:   rgba(139,44,44,.06);
        --pp-grain:  .05;
    }
    /* Background, in layers: the WALL (the look's base, tinted with the
       chosen palette colour and drifted a few degrees per slide), the
       PATTERN (chosen design at the chosen density), and a grain. All in
       the look's own tones — a wall behind words, nothing more. */
    /* Stacking: wall (0) → pattern ::before (1) → grain ::after (1) → stage
       (1, later in DOM) → chrome (2). The wall MUST sit below the pseudo-
       elements or it hides the pattern — that was the first-pass bug. */
    .pbp-wall {
        position: absolute; inset: 0; z-index: 0; pointer-events: none;
        background: var(--pp-bg);
    }
    .pbp.has-tint.is-dark  .pbp-wall { background: color-mix(in oklab, var(--pp-tint) 26%, var(--pp-bg)); }
    .pbp.has-tint.is-light .pbp-wall { background: color-mix(in oklab, var(--pp-tint) 16%, var(--pp-bg)); }
    .pbp.has-tint .pbp-wall { filter: hue-rotate(var(--pbp-hue, 0deg)); }
    .pbp.has-tint .pbp-ref, .pbp.has-tint .pbp-vn,
    .pbp.has-tint .pbp-title::after, .pbp.has-tint .pbp-group::after { color: var(--pp-tint); background-color: transparent; }
    .pbp.has-tint .pbp-title::after, .pbp.has-tint .pbp-group::after { background-color: var(--pp-tint); }
    .pbp.has-tint.is-dark  .pbp-ref, .pbp.has-tint.is-dark  .pbp-vn { color: color-mix(in oklab, var(--pp-tint) 60%, #fff); }

    .pbp::before {
        content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
        --pp-pitch: 30px;
        background-image: radial-gradient(120% 70% at 50% -10%, var(--pp-glow), transparent 60%);
        opacity: .9;
    }
    .pbp[data-density="0"]::before { --pp-pitch: 52px; }
    .pbp[data-density="1"]::before { --pp-pitch: 30px; }
    .pbp[data-density="2"]::before { --pp-pitch: 14px; }
    .pbp[data-pattern="diagonal"]::before {
        background-image:
            radial-gradient(120% 70% at 50% -10%, var(--pp-glow), transparent 60%),
            repeating-linear-gradient(135deg, transparent 0 calc(var(--pp-pitch) - 1px), var(--pp-line) calc(var(--pp-pitch) - 1px) var(--pp-pitch));
    }
    .pbp[data-pattern="grid"]::before {
        background-image:
            radial-gradient(120% 70% at 50% -10%, var(--pp-glow), transparent 60%),
            repeating-linear-gradient(0deg,  transparent 0 calc(var(--pp-pitch) - 1px), var(--pp-line) calc(var(--pp-pitch) - 1px) var(--pp-pitch)),
            repeating-linear-gradient(90deg, transparent 0 calc(var(--pp-pitch) - 1px), var(--pp-line) calc(var(--pp-pitch) - 1px) var(--pp-pitch));
    }
    .pbp[data-pattern="dots"]::before {
        background-image:
            radial-gradient(120% 70% at 50% -10%, var(--pp-glow), transparent 60%),
            radial-gradient(circle, var(--pp-line) 1.3px, transparent 1.9px);
        background-size: 100% 100%, var(--pp-pitch) var(--pp-pitch);
    }
    .pbp[data-pattern="crosshatch"]::before {
        background-image:
            radial-gradient(120% 70% at 50% -10%, var(--pp-glow), transparent 60%),
            repeating-linear-gradient(45deg,  transparent 0 calc(var(--pp-pitch) - 1px), var(--pp-line) calc(var(--pp-pitch) - 1px) var(--pp-pitch)),
            repeating-linear-gradient(135deg, transparent 0 calc(var(--pp-pitch) - 1px), var(--pp-line) calc(var(--pp-pitch) - 1px) var(--pp-pitch));
    }
    .pbp::after {
        content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
        opacity: var(--pp-grain);
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5 0 0 0 0 .5 0 0 0 0 .5 0 0 0 1 0'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
        background-size: 160px 160px;
        mix-blend-mode: overlay;
    }

    /* ---- stage + slide -------------------------------------------------- */
    .pbp-stage {
        position: relative; z-index: 1;
        flex: 1 1 auto; min-height: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .pbp-slide {
        --pbp-scale: 1;
        box-sizing: border-box;
        width: 100%; max-width: min(96vw, 84rem);
        max-height: 100%;
        padding: clamp(1.2rem, 4vh, 3rem) clamp(1rem, 4vw, 4rem);
        display: flex; flex-direction: column; justify-content: center;
        text-align: center;
        opacity: 0; transform: translateY(6px);
        transition: opacity .22s ease, transform .22s ease;
    }
    .pbp-slide.is-in { opacity: 1; transform: none; }
    /* Verse alignment (settings): left / center / right — text only, the
       slide stays where it is. The rules under references and titles
       follow the text's edge. */
    .pbp[data-align="left"]  .pbp-slide { text-align: left; }
    .pbp[data-align="right"] .pbp-slide { text-align: right; }
    .pbp[data-align="left"]  .pbp-title::after, .pbp[data-align="left"]  .pbp-group::after { margin-left: 0; margin-right: auto; }
    .pbp[data-align="right"] .pbp-title::after, .pbp[data-align="right"] .pbp-group::after { margin-left: auto; margin-right: 0; }

    .pbp-kicker {
        margin: 0 0 clamp(.8rem, 2vh, 1.4rem);
        font-family: var(--sans);
        font-size: calc(clamp(.72rem, .5vw + .55rem, .95rem));
        letter-spacing: .22em; text-transform: uppercase;
        color: var(--pp-muted);
    }
    .pbp-cont { opacity: .7; }
    .pbp-cont::before { content: "\00b7\00a0"; }
    .pbp-kicker .pbp-cont:only-child::before { content: none; }

    /* The group's name — a real header when present. */
    .pbp-group {
        margin: 0 0 clamp(1rem, 3vh, 2rem);
        font-family: var(--pp-font, var(--serif)); font-weight: 400;
        font-size: calc(clamp(2.3rem, 4vw + 1rem, 5.2rem) * var(--pbp-scale));
        line-height: 1.08; letter-spacing: -.01em;
        text-wrap: balance;
    }
    .pbp-group .pbp-cont { font-size: .45em; letter-spacing: .18em; text-transform: uppercase; font-family: var(--sans); color: var(--pp-muted); vertical-align: middle; }
    .pbp-group::after {
        content: ""; display: block; width: 3.5rem; height: 2px;
        margin: clamp(.7rem, 2vh, 1.2rem) auto 0; background: var(--pp-accent);
    }

    .pbp-body { display: flex; flex-direction: column; gap: clamp(1rem, 2.6vh, 2rem); }
    .pbp-part { break-inside: avoid; }
    .pbp-part + .pbp-part {
        padding-top: clamp(1rem, 2.6vh, 2rem);
        border-top: 1px solid var(--pp-line);
    }
    /* Long slides use the margins: two columns on wide screens, so a big
       group or a long range stays on ONE slide instead of splitting. */
    @media (min-width: 900px) {
        .pbp-slide.is-many .pbp-body { display: block; columns: 2; column-gap: clamp(2rem, 5vw, 5rem); }
        .pbp-slide.is-many .pbp-part { margin-bottom: clamp(1rem, 2.6vh, 2rem); }
        .pbp-slide.is-many .pbp-part + .pbp-part { padding-top: 0; border-top: none; }
        .pbp-slide.is-many .pbp-text { font-size: calc(clamp(1.2rem, 1.5vw + .9rem, 2.3rem) * var(--pbp-scale)); }
    }

    .pbp-text {
        margin: 0;
        font-family: var(--pp-font, var(--serif));
        font-size: calc(clamp(1.35rem, 2.1vw + 1rem, 3rem) * var(--pbp-scale));
        line-height: 1.36;
        text-wrap: pretty;
        font-weight: 400;
    }
    .pbp-vn {
        font-family: var(--sans);
        font-size: .42em; font-weight: 600;
        color: var(--pp-accent);
        vertical-align: .9em; line-height: 0;
        margin-right: .35em;
    }
    .pbp-empty { font-style: italic; color: var(--pp-muted); font-size: .7em; }

    /* The reference — the other voice, beneath the verse. */
    .pbp-ref {
        margin: clamp(.9rem, 2.2vh, 1.6rem) 0 0;
        font-family: var(--sans); font-weight: 600;
        font-size: calc(clamp(1.05rem, .9vw + .75rem, 1.7rem) * var(--pbp-scale));
        letter-spacing: .16em; text-transform: uppercase;
        color: var(--pp-accent);
    }
    .pbp-tx { margin-left: .9em; font-size: .78em; font-weight: 500; color: var(--pp-muted); letter-spacing: .14em; }

    /* Phones: the browser's own bars (and, without the Fullscreen API on
       iPhone, the address bar) crowd the top and bottom — keep the slide
       well clear of both edges. Safe-area insets on top of that. */
    @media (max-width: 700px) {
        .pbp-slide {
            padding-top:    calc(4.5rem + env(safe-area-inset-top, 0px));
            padding-bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px));
        }
    }

    /* Cover (the board's name) and title slides (heading cards). */
    .pbp-sub {
        margin: clamp(.9rem, 2.2vh, 1.6rem) 0 0;
        font-family: var(--sans); font-weight: 600;
        font-size: calc(clamp(.95rem, .8vw + .7rem, 1.5rem) * var(--pbp-scale));
        letter-spacing: .16em; text-transform: uppercase;
        color: var(--pp-muted);
    }
    .pbp-title {
        margin: 0;
        font-family: var(--pp-font, var(--serif)); font-weight: 400;
        font-size: calc(clamp(2rem, 4vw + 1rem, 5rem) * var(--pbp-scale));
        line-height: 1.12; letter-spacing: -.01em;
        text-wrap: balance;
    }
    .pbp-title::after {
        content: ""; display: block; width: 3.5rem; height: 2px;
        margin: clamp(1rem, 3vh, 2rem) auto 0; background: var(--pp-accent);
    }

    /* ---- chrome ----------------------------------------------------------- */
    .pbp-chrome {
        position: absolute; z-index: 2;
        top: max(.75rem, env(safe-area-inset-top)); right: max(.75rem, env(safe-area-inset-right));
        display: flex; gap: .4rem;
        transition: opacity .3s ease;
    }
    .pbp-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 42px; height: 42px; padding: 0;
        border: 1px solid var(--pp-line); border-radius: 50%;
        background: transparent; color: var(--pp-muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .pbp-btn svg { width: 20px; height: 20px; display: block; pointer-events: none; }
    @media (hover: hover) {
        .pbp-btn:hover { color: var(--pp-ink); background: var(--pp-line); }
    }
    .pbp-btn:focus-visible { outline: none; color: var(--pp-ink); box-shadow: 0 0 0 3px var(--pp-line); }

    .pbp-counter {
        position: absolute; z-index: 2;
        right: max(1rem, env(safe-area-inset-right)); bottom: max(.9rem, env(safe-area-inset-bottom));
        font-family: var(--sans); font-size: .78rem; letter-spacing: .12em;
        color: var(--pp-muted);
        transition: opacity .3s ease;
    }
    /* Still hands: the chrome and counter fade; a move or tap brings them back. */
    .pbp.is-idle .pbp-chrome,
    .pbp.is-idle .pbp-counter { opacity: 0; }
    .pbp.is-idle { cursor: none; }

    /* Invisible tap/click zones (the script decides by x; these exist only
       to give a pointer cursor where paging will happen). */
    .pbp-nav { position: absolute; top: 0; bottom: 0; z-index: 1; }
    .pbp-nav-prev { left: 0; width: 33.333%; cursor: w-resize; }
    .pbp-nav-next { right: 0; width: 66.666%; cursor: e-resize; }
    .pbp.is-idle .pbp-nav { cursor: none; }

    /* ---- interlinear panes (card-edit Phase 4, reworked) ----------------- */
    /* A part whose card carries an interlinear child is a DUO: the verse
       text (with its reference) on one side, the original-language trio on
       the other — side by side above 900px, stacked below. Within the
       pane, each WORD is its own vertical stack (original / transliteration
       / gloss) and the stacks flow as a wrapping row, so it reads as an
       interlinear: the Hebrew word, what it sounds like, and what it means,
       one above the other, per word.

       ┌─ SIZE KNOBS ────────────────────────────────────────────────────────┐
       │ The trio is drawn LARGER than the verse text (item 3). Each row is a │
       │ multiple of the verse text's own clamp; bump a factor to grow that   │
       │ row across every screen size. All three ride --pbp-scale, so the fit │
       │ loop still shrinks a dense slide to fit.                             │
       │   --pbp-il-orig     original language (Hebrew/Greek)   default 1.30  │
       │   --pbp-il-translit transliteration                    default 1.00  │
       │   --pbp-il-gloss    literal gloss                      default 0.92  │
       │ (1.00 = the same size as the verse text. All three are ABOVE the     │
       │ verse text at their defaults except translit, which matches it, and  │
       │ gloss, just under — raise --pbp-il-translit / --pbp-il-gloss past    │
       │ 1.0 if you want the whole trio strictly larger.)                     │
       │ Column split desktop: --pbp-il-split (pane share of the row width).  │
       │ Gap between word stacks: --pbp-il-gap.                               │
       └──────────────────────────────────────────────────────────────────────┘ */
    .pbp-slide { --pbp-il-rev: 2;   /* tripwire: check on .pbp-slide in DevTools computed styles */
                 --pbp-il-orig: 1.30; --pbp-il-translit: 1.00; --pbp-il-gloss: .92;
                 --pbp-il-split: 42%; --pbp-il-gap: clamp(.7rem, 1.6vw, 1.5rem); }

    .pbp-duo { display: flex; flex-direction: column; gap: clamp(1rem, 2.4vh, 1.8rem); }
    .pbp-verse-col { min-width: 0; }
    @media (min-width: 900px) {
        .pbp-duo { flex-direction: row; align-items: flex-start; gap: clamp(1.5rem, 3.5vw, 3.5rem); }
        .pbp-verse-col { flex: 1 1 auto; }
        .pbp-duo > .pbp-il { flex: 0 0 var(--pbp-il-split); }
    }

    /* The base em for the trio: the verse text's own clamp, so every row
       tracks the verse size and the knobs above are plain multiples of it. */
    .pbp-il { --pbp-il-base: calc(clamp(1.35rem, 2.1vw + 1rem, 3rem) * var(--pbp-scale)); text-align: left; }
    .pbp-il-verse { display: flex; align-items: baseline; gap: .5em; }
    .pbp-il-verse + .pbp-il-verse { margin-top: clamp(.7rem, 1.8vh, 1.3rem); }
    .pbp-il-verse.is-rtl { flex-direction: row; }   /* the number stays at the start */

    /* The wrapping row of word-stacks. dir=rtl on the element (set inline
       for Hebrew) lays the stacks right-to-left; each stack is upright. */
    .pbp-il-words { display: flex; flex-wrap: wrap; align-items: flex-start;
                    gap: var(--pbp-il-gap) calc(var(--pbp-il-gap) * 1.2); flex: 1 1 auto; }
    .pbp-il-word { display: flex; flex-direction: column; align-items: center; text-align: center;
                   /* a hair of separation so adjacent stacks don't merge */
                   padding-bottom: .1em; }

    .pbp-il-original {
        font-family: var(--pp-font, var(--serif));
        font-size: calc(var(--pbp-il-base) * var(--pbp-il-orig));
        line-height: 1.2;
    }
    .pbp-il-original[dir="rtl"] { direction: rtl; }
    .pbp-il-translit {
        font-style: italic; opacity: .82;
        font-family: var(--pp-font, var(--serif));
        font-size: calc(var(--pbp-il-base) * var(--pbp-il-translit));
        line-height: 1.25; margin-top: .12em;
    }
    .pbp-il-gloss {
        opacity: .7;
        font-family: var(--sans);
        font-size: calc(var(--pbp-il-base) * var(--pbp-il-gloss));
        line-height: 1.2; margin-top: .1em;
    }
    .pbp-il-vn {
        font-size: calc(var(--pbp-il-base) * .55); opacity: .55;
        font-family: var(--sans); align-self: flex-start;
    }
    .pbp-il-pending {
        margin: 0; font-style: italic;
        font-size: calc(.95rem * var(--pbp-scale));
        color: var(--pp-muted);
    }

    /* ---- reduced motion ------------------------------------------------- */
    @media (prefers-reduced-motion: reduce) {
        .pbp-slide { transition: none; transform: none; }
        .pbp-chrome, .pbp-counter { transition: none; }
    }
