{{-- ======================================================================
     INTERLINEAR TRIO STYLES     resources/views/bible/partials/interlinear-styles.blade.php
     ----------------------------------------------------------------------
     The original / transliteration / literal row set, EXTRACTED VERBATIM
     from chapter.blade.php (card-edit Phase 3) so the reader's synthesis
     card-backs and the pericope board's interlinear child cards share one
     definition. The only change from the chapter original: the reading
     vars gained fallbacks (19px / 1.55 — the reader's defaults), because
     the board can render before any Aa setting has ever been touched.

     RAW CSS, NO STYLE-TAG WRAPPER — same convention as present-styles,
     fab-styles and sticky-head: include it INSIDE the page's style block.
     (A wrapped partial included there closes the host stylesheet early and
     spills the rest of the page's CSS onto the screen as text.)

     Included by:
       resources/views/bible/chapter.blade.php        (synthesis card-backs)
       resources/views/extras/pericope/board.blade.php (child cards)
     ====================================================================== --}}
    /* ---- the interlinear trio ---- */
    .iface-verse + .iface-verse { margin-top: 1rem; }
    .iface-row { margin-bottom: .55rem; }
    .iface-row:last-child { margin-bottom: 0; }
    .iface-label {
        display: block;
        font-family: var(--sans); font-size: .62rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted);
        margin-bottom: .15rem;
    }
    /* The interlinear face follows the reader's SIZE and SPACING, but NOT the
       serif/sans toggle: each row pins its own family, so --reading-family
       never reaches it. Row sizes are em off --reading-size — the multipliers
       reproduce the old fixed sizes at the 19px default and scale from there.
       Leading is calc()'d off --reading-leading the same way the poetry lines
       are, preserving the original's extra air over the translit and gloss. */
    .iface { font-size: var(--reading-size, 19px); }
    .row-original { font-family: var(--serif); font-size: 1.14em; line-height: calc(var(--reading-leading, 1.55) + .25); }
    .row-original[dir="rtl"] { text-align: right; }   /* Hebrew/Aramaic read right-to-left */
    .row-translit { font-family: var(--serif); font-style: italic; font-size: .82em; line-height: calc(var(--reading-leading, 1.55) + .05); }
    .row-gloss    { font-family: var(--sans); font-size: .74em; line-height: calc(var(--reading-leading, 1.55) + .05); color: var(--muted); }

    /* STEPBible marks syllables with periods ('be.re.Shit'); we show them
       as faint, raised interpuncts. Each is its own span (fillTranslit in
       focus-synthesis.js, ilTranslit in pericope-board.js) because CSS
       can't target a character mid-text. */
    .syl-sep {
        opacity: .45;             /* fainter than the syllables around it */
        /* · (U+00B7) already sits near mid-height in most serifs; if yours
           sets it low, nudge: position: relative; top: -.04em; */
    }
    .iface .w.pin .syl-sep { opacity: .6; }   /* legible on the accent fill */

    /* Word chips: hover previews the word across all three rows; click pins
       it (multiple pins allowed) — the verse-focus interaction, one level
       down. Same hug-the-text treatment as verse highlights. (The board's
       child cards don't emit .w spans yet, so these rules are dormant
       there.) */
    .iface .w {
        cursor: pointer;
        border-radius: 4px;
        padding: 0 .12em;
        margin: 0 -.02em;
        transition: background-color .12s ease, color .12s ease;
        -webkit-box-decoration-break: clone;
                box-decoration-break: clone;
    }
    .iface .w.hl  { background: var(--rule); }
    .iface .w.pin { background: var(--accent); color: #fff; }

    /* CC BY attribution, required by the STEPBible license. */
    .iface-credit {
        margin-top: .9rem; padding-top: .6rem;
        border-top: 1px solid var(--rule);
        font-family: var(--sans); font-size: .68rem; color: var(--muted);
    }
    .iface-credit a { color: inherit; }
