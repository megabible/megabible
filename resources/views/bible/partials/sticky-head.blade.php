{{--
  ===========================================================================
  STICKY CHAPTER HEAD — shared styles
  ---------------------------------------------------------------------------
  Included INSIDE each page's <style> block, alongside reading-styles. Raw CSS
  only, no <style> wrapper — the including page owns those tags. Pull it in
  with a Blade include of: bible.partials.sticky-head

  The behaviour lives in public/js/sticky-head.js. Both are required; the CSS
  alone will pin the head but the shrink will jitter, because the script is
  what keeps the head's footprint constant.

  TWO RULES GOVERN THIS FILE. Both exist to stop the head fighting the scroll.

  1. CONSTANT FOOTPRINT. A sticky element stays in normal flow, so if the head
     simply got shorter when it pinned, the document would get shorter too and
     everything below would jump up. Chrome and Edge then "helpfully" correct
     the scroll position to compensate (scroll anchoring), which drags the
     sentinel back into view, un-sticks the head, grows it again, and starts
     the cycle over — a scroll that feels seized up. So .is-stuck hands back
     exactly the height it takes away, as extra margin-bottom. The amount is
     --mb-head-shrink, measured at runtime by the script. Document height
     never changes, so there is nothing for the browser to correct.

  2. NO LAYOUT TRANSITIONS. Nothing that affects layout is animated. The
     shrink is one instantaneous reflow confined to the head, instead of a
     dozen full-page reflows spread over 180ms. Only the ::after shadow fades,
     and opacity is a compositor-only property. Animating padding, font-size
     or max-height here is what used to cause the ghosted-text artifacts on
     mobile Chrome, so please don't reintroduce it.

  MARKUP CONTRACT — the page must provide, in this order:

      <div class="chapter-head-sentinel"></div>
      <div class="chapter-head">
          <div class="head-actions">…corner buttons…</div>
          <div class="chapter-head-top"><h1>…</h1></div>
          …anything else that should stay pinned…
      </div>
      …everything else on the page…

  The sentinel must be the head's immediately preceding sibling, and the head
  must be a direct child of an element that spans the whole page (.container),
  or sticky will stop working partway down.

  BLADE NOTE: never let two opening braces end up adjacent in this file — a
  minified at-rule wrapping a selector will do exactly that, and Blade reads a
  doubled opening brace as an echo tag and tries to compile the CSS. Keep each
  rule's brace on its own line, as below.
  ===========================================================================
--}}
/* ---- The head itself ---------------------------------------------------- */
    .chapter-head {
        /* ---- KNOBS ---------------------------------------------------
           Override any of these on .chapter-head in the page's own <style>
           block, AFTER this include. Everything below reads from them, so a
           page never needs to restate a whole rule just to change a size. */
        --mb-head-gap:         0.2rem;   /* air below the head */
        --mb-head-title:       2.4rem;   /* h1 at rest */
        --mb-head-title-stuck: 1.7rem;   /* h1 when pinned */
        --mb-head-reserve:     9.5rem;   /* width kept clear for the corner cluster */
        --mb-head-pad:          .9rem;   /* padding below the head content at rest */
        --mb-head-pad-stuck:    .55rem;  /* ...and when pinned, top and bottom */
        --mb-head-actions-top:  .55rem;  /* corner cluster's offset from the head's top */

        /* How far the head's background bleeds PAST the text column on each
           side. 1.5rem is the .container side padding, so on a normal page
           the head reaches exactly to the container edge. A full-bleed page
           (the pericope board) sets this to its own gutter instead; a page whose
           head should not bleed at all sets it to 0. */
        --mb-head-bleed: 1.5rem;

        position: sticky;
        top: 0;
        z-index: 30;
        background: var(--bg);

        /* Give the head its own compositor layer. Without it, Chrome and Edge
           can raster the text scrolling underneath into the same tile as the
           head and leave ghosted glyphs behind. will-change:opacity promotes
           the layer WITHOUT making the head a containing block for positioned
           descendants — which `transform` and `contain` both would, breaking
           the QuickNav and Aa panels that hang off it. Do not swap it for
           translateZ(0) without checking those two panels. */
        will-change: opacity;

        /* Stretch the head wider than the text column on each side so it
           always covers serif/italic glyph overhang from the text sliding
           underneath. The matching left/right padding keeps the CONTENT
           exactly where it was — only the background grows. Size it with
           --mb-head-bleed above. */
        margin-left:  calc(var(--mb-head-bleed) * -1);
        margin-right: calc(var(--mb-head-bleed) * -1);
        padding-left:  var(--mb-head-bleed);
        padding-right: var(--mb-head-bleed);

        padding-bottom: var(--mb-head-pad);
        margin-bottom:  var(--mb-head-gap);
    }

    /* Pinned: tuck in the padding, shrink the type, and give back every pixel
       of lost height as margin so the document below never moves. See rule 1
       in the header comment. --mb-head-shrink is set by sticky-head.js. */
    .chapter-head.is-stuck {
        padding-top:    var(--mb-head-pad-stuck);
        padding-bottom: var(--mb-head-pad-stuck);
        margin-bottom:  calc(var(--mb-head-gap) + var(--mb-head-shrink, 0px));
    }

/* ---- Type inside the head ----------------------------------------------- */
    .chapter-head h1 {
        font-size: var(--mb-head-title);
        font-weight: 400;
        margin: 0;                  /* every page wraps the h1 in its own row */
        letter-spacing: -.01em;
    }
    .chapter-head.is-stuck h1 { font-size: var(--mb-head-title-stuck); }

    /* Book name as a link to the hub — keeps the ink colour, not link blue. */
    .chapter-head h1 .book-link {
        color: inherit; text-decoration: none; transition: color .12s;
    }
    .chapter-head h1 .book-link:hover { color: var(--accent); }

    .chapter-head .subtitle {
        font-family: var(--sans);
        font-size: .9rem; color: var(--muted); margin: 0;
    }
    .chapter-head.is-stuck .subtitle { font-size: .8rem; }

/* ---- Title row and corner cluster --------------------------------------- */
    /* padding-right reserves the corner so a long title wraps BESIDE the
       buttons, never underneath them. Size it with --mb-head-reserve. */
    .chapter-head-top {
        margin-bottom: .25rem;
        padding-right: var(--mb-head-reserve);
    }

    /* Absolutely anchored to the nearest positioned ancestor — normally the
       head itself, since position:sticky makes it a positioning context. So
       title wrap never moves the buttons AND they ride along while the head
       is pinned. right:1.5rem lands on the container content edge, cancelling
       the head's own bleed.

       A full-bleed page can give .chapter-head-top position:relative to
       re-anchor the cluster to the centred title row instead; the cluster
       then wants --mb-head-actions-top: 0, because the title row's top edge
       already IS the title's top edge.

       POSITION KNOBS: --mb-head-actions-top / right. */
    .chapter-head .head-actions {
        position: absolute;
        top: var(--mb-head-actions-top); right: 1.5rem;
        z-index: 60;
        display: flex; align-items: center; gap: .5rem;
    }

/* ---- Hairline + shadow strip -------------------------------------------- */
    /* Drawn as one strip directly BELOW the head. Using a pseudo-element
       rather than a border or box-shadow lets us fade every edge: left:0 and
       right:0 keep the sides flush so nothing leaks sideways, and the mask
       further down feathers the two ends on wide screens. This opacity fade
       is the ONLY transition on the head — it carries all the softness the
       old size animation used to. */
    .chapter-head::after {
        content: "";
        position: absolute;
        left: 0; right: 0;
        top: 100%;                  /* flush against the bottom of the head */
        height: 16px;
        pointer-events: none;
        opacity: 0;
        transition: opacity .18s ease;
        border-top: 1px solid var(--rule);
        /* rgb of --ink (#2a1f17): a warm shadow reads better on parchment
           than a cold pure-black one. */
        background: linear-gradient(to bottom,
            rgba(42,31,23,.20),
            rgba(42,31,23,.05) 45%,
            rgba(42,31,23,0));
    }
    .chapter-head.is-stuck::after { opacity: 1; }

    /* On wide screens the head no longer spans the viewport, so the line and
       shadow would stop abruptly against the parchment at each end. This mask
       fades the outer ~8% of the strip to nothing. Skipped on narrow screens,
       where the strip runs edge to edge and the ends fall off-screen. */
    @media (min-width: 821px) {
        .chapter-head::after {
            -webkit-mask-image: linear-gradient(to right,
                transparent, #000 8%, #000 92%, transparent);
                    mask-image: linear-gradient(to right,
                transparent, #000 8%, #000 92%, transparent);
        }
    }

/* ---- The trigger -------------------------------------------------------- */
    /* The marker that tells the script when the head has reached the top. The
       negative margin cancels its height exactly, so it adds no visible space.

       THE HEIGHT IS A KNOB, AND 24 IS DELIBERATE. At 1px this box is smaller
       than one device pixel on a 125%/150% Windows display or any phone, and
       IntersectionObserver's ratio flips back and forth across a single wheel
       notch — visible flicker on a slow scroll. At 24px the trigger is a fat,
       stable target, and the shrink fires 24px after the head pins rather
       than 1px after, which buys a natural buffer. Keep the two numbers equal
       and opposite or the sentinel will start adding real space. */
    .chapter-head-sentinel { height: 24px; margin-bottom: -24px; }

/* ---- Scroll anchoring --------------------------------------------------- */
    /* Belt and braces for rule 1. Even if the head's height is out by a pixel
       for one frame, Chrome must not "correct" the scroll position for it.
       Excluding every sibling after the head takes all the plausible anchor
       candidates off the table on any page that uses this partial, with no
       per-page class to remember. */
    .chapter-head ~ * { overflow-anchor: none; }

/* ---- Reduced motion ----------------------------------------------------- */
    /* The shadow fade is the only animation left in the whole sticky-head
       system, so this one rule covers every page that includes the partial.
       Nothing else here transitions — see rule 2 in the header comment. */
    @media (prefers-reduced-motion: reduce) {
        .chapter-head::after { transition: none; }
    }
