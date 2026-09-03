/* ---- Shared verse number: small superscript accent figure ---- */
    .verse-number {
        font-family: var(--sans);
        font-size: .7rem; color: var(--accent); font-weight: 600;
        vertical-align: super; margin-right: .15rem; line-height: 0;
    }

    /* .reading's font/size/leading now come from the shared .reader-text rule
       in app.blade.php — see "Reading-text opt-in" there. */

    /* ---- Prose paragraphs ---- */
    .reading p { margin: 0 0 1rem; }
    .reading p.m  { text-indent: 0; }            /* margin paragraph, flush left */
    .reading p.pi { padding-left: 1.6rem; }      /* indented paragraph */
    .reading p.pc { text-align: center; }
    .reading p.pr { text-align: right; }

    /* ---- Poetry: each line is its own block; indents grow by level, and long
            lines hang-indent under their start. ---- */
    .reading p.poetry {
        margin: 0;
        padding-left: 1.4rem;
        text-indent: -1.4rem;          /* hanging indent for wrapped poetic lines */
        line-height: calc(var(--reading-leading) - .15);
    }
    .reading p.poetry.q1 { padding-left: 1.4rem; }
    .reading p.poetry.q2 { padding-left: 3.0rem; }
    .reading p.poetry.q3 { padding-left: 4.6rem; }
    .reading p.poetry.q4 { padding-left: 6.2rem; }
    .reading p.poetry.qc { text-align: center; padding-left: 0; text-indent: 0; }
    .reading p.poetry.qr { text-align: right;  padding-left: 0; text-indent: 0; }
    .reading p.poetry.qd { text-align: center; padding-left: 0; text-indent: 0; font-style: italic; color: var(--muted); }
    /* a little air before the first poetry line of a run and after the last */
    .reading p.poetry + p:not(.poetry) { margin-top: 1rem; }

    /* Stanza break (\b): vertical space between poetic groups. */
    .stanza-break { height: .85rem; }

    /* ---- Headings between verses ---- */
    .heading { font-family: var(--sans); }
    .heading.s {
        color: var(--accent); font-weight: 600; font-size: 1.15rem;
        margin: 1.8rem 0 .7rem; letter-spacing: .01em;
    }
    .heading.s.lvl-2 { font-size: 1rem; color: var(--ink); }
    .heading.ms {
        color: var(--accent); font-weight: 700; font-size: 1.4rem;
        margin: 2.2rem 0 .8rem;
    }
    .heading.r, .heading.mr, .heading.sr {
        color: var(--muted); font-style: italic; font-size: .85rem;
        margin: -.3rem 0 .9rem;
    }
    /* Linked references inside r/mr/sr headings. Accent-coloured against the
       muted italic reference line, with a dotted underline that solidifies on
       hover so it reads clearly as a link. */
    .heading.r a.xref-link,
    .heading.mr a.xref-link,
    .heading.sr a.xref-link {
        color: var(--accent);
        text-decoration-line: underline;
        text-decoration-style: dotted;
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
        transition: text-decoration-style .12s ease;
    }
    .heading.r a.xref-link:hover,
    .heading.mr a.xref-link:hover,
    .heading.sr a.xref-link:hover {
        text-decoration-style: solid;
    }
    .heading.sp {                           /* speaker label (Song of Songs) */
        color: var(--muted); font-weight: 600; font-size: .8rem;
        text-transform: uppercase; letter-spacing: .08em; margin: 1.2rem 0 .4rem;
    }
    /* Psalm / descriptive title (\d): italic superscription under the chapter head.
    The negative top margin pulls the OPENING superscription up snug under the
    chapter title. */
    .heading.d {
        font-family: var(--serif); font-style: italic; color: var(--muted);
        font-size: 1rem; margin: -1.4rem 0 .5rem;
    }

    /* But \d is also used for mid-psalm descriptive headings — the Psalm 119
    acrostic letters (ALEPH … HETH … TAW). Those follow verse text, so the
    negative pull above would drag them onto the preceding line. When a \d
    heading comes right after a verse paragraph, a poetry line, or a stanza
    break, give it clear space above instead of pulling it up. */
    p + .heading.d,
    .stanza-break + .heading.d {
        margin-top: 1.8rem;
    }
    
    /* ---- Footnote markers: superscript letters at verse end ----
       SIZE KNOB: font-size below is the one value to tweak to taste.
       Muted so the accent verse numbers stay the dominant wayfinding mark;
       warms to accent on hover. line-height: 0 stops line-box inflation. */
    .fn-markers { line-height: 0; }
    .fn-marker {
        font-family: var(--sans);
        font-size: .78rem;             /* ← marker size — tweak me */
        font-weight: 600; font-style: normal;
        color: var(--muted);
        text-decoration: none;
        padding-left: .12rem;          /* breathing room from the last word,
                                          and between stacked letters */
        transition: color .12s;
    }
    .fn-marker:hover { color: var(--accent); }

    /* ---- End-of-chapter footnotes block ----
       Lives inside .reading, after the last verse. Draws NO rules of its
       own — the footer's single separator line stays the only one. */
    .footnotes {
        margin-top: 2.4rem;
        font-family: var(--sans);
        font-size: .88rem;
        color: var(--muted);
    }
    .footnotes-title {
        font-size: .8rem; font-weight: 600; color: var(--muted);
        text-transform: uppercase; letter-spacing: .08em;
        margin: 0 0 .7rem;
    }
    .footnote-row {
        margin: 0 0 .35rem;
        padding-left: 1.55rem;
        text-indent: -1.15rem;         /* hang wrapped lines under the text */
        cursor: pointer;               /* the row body is a focus control now */
    }
    /* Highlighted in tandem with its selected verse — same token the verse
       highlight uses. Painted on the INLINE .fn-line, not the block row, so
       the background hugs the text per line box and ends where the words end
       (the verse-highlight fix, applied here). box-decoration-break keeps
       the padding/radius on every wrapped line, not just the first.
       One box treatment for every highlighted state — focus selection in the
       chapter view. */
    /* GEOMETRY IS UNCONDITIONAL, and must stay that way. box-decoration-break
       :clone applies horizontal padding to EVERY line fragment of a wrapped
       inline box, while the margins only cancel it at the two outer ends — so
       a note wrapping to N lines is (N-1) x .5rem wider than its own bare text.
       Put that in a state rule and the note re-wraps the instant it lights up,
       shoving the rest of the block around. Baked in from the start it is just
       part of the layout, and the states swap colour only. */
    .footnote-row .fn-line {
        background: transparent;
        border-radius: 3px;
        padding: .1rem .25rem;
        margin: 0 -.25rem;             /* cancels the horizontal padding at the
                                          ends. Vertical margins do nothing on an
                                          inline box, which is why there is none. */
        transition: background-color .15s ease;
        -webkit-box-decoration-break: clone;
        box-decoration-break: clone;
    }
    /* Selected / locked: the strong token; hover preview: the soft one. */
    .footnote-row.is-selected .fn-line,
    .footnote-row.vp-lock     .fn-line { background: var(--rule); }
    .footnote-row.is-hover:not(.vp-lock):not(.is-selected) .fn-line { background: var(--panel); }

    /* The letter: plain text, the in-text marker's landing point. */
    .footnote-row .fn-letter {
        color: var(--accent); font-weight: 700;
        margin-right: .45rem;
    }
    /* The verse number: the jump-back-to-verse focus link. */
    .footnote-row .fn-verse {
        color: var(--muted); font-weight: 600;
        text-decoration: none;
        margin-right: .45rem;
        cursor: pointer;
    }
    .footnote-row .fn-verse:hover { color: var(--accent); text-decoration: underline; }
    /* The captured anchor — the word(s) the note glosses. */
    .footnote-row .fn-anchor { color: var(--ink); font-weight: 600; }

    /* ---- Footnote hover popover (desktop) ----
       Floats above/below its marker; the chevron (a rotated square riding
       --chev-x) points at the letter even when the panel is viewport-clamped. */
    .fn-pop {
        position: absolute;
        z-index: 90;
        padding: .55rem .7rem;
        background: var(--bg);
        border: 1px solid var(--rule);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,.16);
        font-family: var(--sans);
        font-size: .85rem;
        line-height: 1.45;
        color: var(--muted);
        cursor: pointer;
        transition: transform .14s ease, background .14s ease,
                    border-color .14s ease, box-shadow .14s ease;
        transform-origin: var(--chev-x, 50%) bottom;
    }
    /* Flipped panels grow downward from the chevron instead. */
    .fn-pop.is-below { transform-origin: var(--chev-x, 50%) top; }

    /* Hover affordance: swell + shade — "I'm clickable". */
    .fn-pop:hover {
        transform: scale(1.04);
        background: var(--panel);
        border-color: var(--muted);
        box-shadow: 0 10px 28px rgba(0,0,0,.22);
    }
    .fn-pop:hover::after { background: var(--panel); }

    .fn-pop .fn-anchor { color: var(--ink); font-weight: 600; }
    .fn-pop::after {
        content: "";
        position: absolute;
        left: var(--chev-x, 50%);
        bottom: -5.5px;
        width: 10px; height: 10px;
        transform: translateX(-50%) rotate(45deg);
        background: var(--bg);
        border-right: 1px solid var(--rule);
        border-bottom: 1px solid var(--rule);
    }
    .fn-pop.is-below::after {
        bottom: auto;
        top: -5.5px;
        border: none;
        border-left: 1px solid var(--rule);
        border-top: 1px solid var(--rule);
    }