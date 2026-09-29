{{--
  ===========================================================================
  tl-part r3 — TIMELINE styles (the book hub's Gantt chart)
  ---------------------------------------------------------------------------
  Included INSIDE the page's <style> block, alongside sticky-head. Raw CSS
  only, no <style> wrapper — the including page owns those tags. Pull it in
  with: @include('bible.partials.timeline-styles')

  Pairs with bible/partials/timeline (the markup + scripts). Geometry
  (left/width/positions) is pre-computed in BibleController::buildTimeline;
  this just paints it. Bar colours come from the --tl-* palette in
  app.blade.php via inline `background: var(--tl-<n>)`.

  BLADE NOTE (the sticky-head rule): never let two opening braces end up
  adjacent in this file — Blade reads a doubled opening brace as an echo tag.
  Keep each rule's brace on its own line, as below.
  ===========================================================================
--}}
    .tl {
        --tl-label-w: 156px;   /* width of the left-hand book-label column */
        --tl-row-h: 34px;      /* height of each book row */
        --tl-bar-h: 16px;      /* thickness of each bar */
        --tl-event-label-w: 120px;  /* width each event label wraps within — tweak to taste */
        --tl-event-gap: 10px;       /* min horizontal gap before two labels get bumped to separate lanes */
        --tl-hl-pad: 0.6rem;        /* tl-fix r5: breathing room the current-row highlight adds before the book name and after the bar */
        --tl-tick-gap: 6px;         /* tl-fix r8: min space between two axis labels before the left one is hidden */
        font-family: var(--sans);
        margin: 0.5rem 0 1rem;
    }

    /* Chart zone: everything except the legend. Fluid width, never scrolls.
       overflow:hidden is a safety net — it guarantees the PAGE never grows a
       horizontal scrollbar if a tick/event/date label gets positioned hard
       against the right edge (that label clips instead of pushing the page
       wide). Keep your outermost ticks/events a little inside the range in the
       JSON and nothing ever clips. */
    /* tl-fix r5: the pad/negative-margin pair carves a gutter INSIDE the
       clip edge: all content stays at exactly the same x as before, but
       the border box now reaches --tl-hl-pad further left, so the
       current-row highlight can bleed past the book names without being
       eaten by this overflow:hidden. */
    /* tl-fix r5.1: gutter on BOTH sides — the right pair mirrors the left,
       so a bar that runs to the chart's right edge keeps its trailing
       highlight pad instead of clipping flush. Content position and width
       are untouched; only the clip edge moves out. */
    .tl-chart {
        position: relative; overflow: hidden;
        padding-left: var(--tl-hl-pad);
        margin-left: calc(-1 * var(--tl-hl-pad));
        padding-right: var(--tl-hl-pad);
        margin-right: calc(-1 * var(--tl-hl-pad));
    }

    /* Legend */
    .tl-legend { display: flex; flex-wrap: wrap; gap: 0.35rem 1.1rem; margin-bottom: 1.1rem; font-size: 0.8rem; color: var(--muted); }
    .tl-legend span { display: inline-flex; align-items: center; gap: 0.4rem; }
    .tl-legend i { width: 12px; height: 12px; border-radius: 3px; box-shadow: inset 0 0 0 1px rgba(42,31,23,.18); }

    /* Axis (rendered both above AND below the chart) */
    .tl-axis { display: grid; grid-template-columns: var(--tl-label-w) 1fr; align-items: end; }
    .tl-axis.bottom { align-items: start; margin-top: 0.4rem; }
    .tl-axis-scale { position: relative; height: 1.1rem; }
    .tl-tick { position: absolute; transform: translateX(-50%); font-size: 0.75rem; color: var(--muted); white-space: nowrap; }

    /* Body: grid lines + event lines + book rows */
    .tl-body { position: relative; padding-top: 1.25rem; }
    .tl-grid { position: absolute; left: var(--tl-label-w); right: 0; top: 0; bottom: 0; pointer-events: none; }
    .tl-gridline { position: absolute; top: 0; bottom: 0; width: 1px; background: var(--rule); opacity: 0.55; }
    /* tl-fix r4: the event line is a painted gradient, not a dashed border.
       The old zero-width element whose only ink was a fractional-width
       dashed border-left (1.5px) fell victim to mobile rasterizers — both
       Blink and WebKit drop sub-integer dashed borders on zero-boxes at
       some DPR/zoom combos, so the lines vanished on phones while desktop
       drew them fine. A background gradient on a real 2px box is painted
       deterministically on every engine, and the dash rhythm (4px ink /
       4px gap) is ours to tune. */
    .tl-event {
        position: absolute; top: 0; bottom: 0; width: 2px;
        margin-left: -1px;   /* centre the 2px line on its position */
        background: repeating-linear-gradient(to bottom,
            var(--accent) 0 4px, transparent 4px 8px);
        opacity: 0.7;
    }

    .tl-row { display: grid; grid-template-columns: var(--tl-label-w) 1fr; align-items: center; height: var(--tl-row-h); }
    /* tl-fix r5: the wash is no longer the row's background — it's a
       positioned pseudo-element sized to the CURRENT BAR. Left edge:
       --tl-hl-pad before the book name, into the .tl-chart gutter. Right
       edge: the bar's end plus the same pad — the end arrives as
       --tl-hl-end, a 0–1 fraction of the track width, set inline by the
       partial. Missing variable falls back to 1 (full row).
       Paint-order note (supersedes the r4 note): as a positioned box the
       wash now sits OVER the gridlines and label text instead of under
       them — at 7% alpha that is imperceptible — while the bars, living
       in .tl-track (positioned, later in the DOM), still paint on top.
       pointer-events:none keeps the wash from stealing hovers/clicks
       from anything beneath it. */
    .tl-row.current { position: relative; }
    .tl-row.current::before {
        content: "";
        position: absolute;
        top: 0; bottom: 0;
        left: calc(-1 * var(--tl-hl-pad));
        width: calc(2 * var(--tl-hl-pad) + var(--tl-label-w)
               + (100% - var(--tl-label-w)) * var(--tl-hl-end, 1));
        background: color-mix(in srgb, var(--accent) 7%, transparent);
        border-radius: 6px;
        pointer-events: none;
    }
    /* tl-fix r7: names never wrap — one line at every width. Overflowing
       fulls get swapped to their mid label by the fit pass; ellipsis is
       the last-resort safety (this was mobile-only before, now global —
       it also covers the instant before the fit pass first runs). */
    .tl-book {
        padding-right: 0.9rem; line-height: 1.15; min-width: 0;
        overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
    }
    .tl-name { font-family: var(--serif); font-size: 0.98rem; color: var(--ink); text-decoration: none; }
    a.tl-name:hover { color: var(--accent); text-decoration: underline; }
    .tl-row.current .tl-name { color: var(--accent); font-weight: 700; }

    /* tl-fix r7: up to THREE labels per book. Full shows by default; mid
       (canon.php home_short_names) shows when the fit pass tags .is-mid on
       an overflowing desktop row; short is the mobile block's business at
       the foot of this file. */
    .tl-name .tl-name-short,
    .tl-name .tl-name-mid { display: none; }
    .tl-name.is-mid .tl-name-full { display: none; }
    .tl-name.is-mid .tl-name-mid { display: inline; }

    .tl-track { position: relative; height: 100%; }
    /* tl-fix r10: bars are popover triggers. overflow:hidden is gone — the
       seg label clips itself, and the bar needs to let its hit area (below)
       spill out. */
    .tl-bar {
        position: absolute; top: 50%; transform: translateY(-50%);
        height: var(--tl-bar-h); min-width: 3px; border-radius: 3px;
        box-shadow: inset 0 0 0 1px rgba(42,31,23,.18);
        cursor: pointer;
        transition: filter .12s ease;
    }
    /* tl-fix r10: an invisible, larger tap target — the full row height,
       and at least 24px wide, centred on the bar. A 3px sliver like
       1 Clement is otherwise nearly untappable on a phone. Touching
       segments (Torah's J/E | P | R) keep their own widths, so their
       targets don't overlap unless a segment is under 24px. */
    .tl-bar::after {
        content: "";
        position: absolute;
        top: calc((var(--tl-row-h) - var(--tl-bar-h)) / -2);
        bottom: calc((var(--tl-row-h) - var(--tl-bar-h)) / -2);
        left: 50%;
        width: max(100%, 24px);
        transform: translateX(-50%);
    }
    .tl-bar:hover,
    .tl-bar.is-open { filter: brightness(1.12); }
    .tl-bar:focus { outline: none; }
    .tl-bar:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    .tl-seg-label {
        display: block; height: 100%;
        font-family: var(--sans); font-weight: 600;
        font-size: 9px; line-height: var(--tl-bar-h);
        text-align: center; color: #fff;
        white-space: nowrap; overflow: hidden;
        pointer-events: none;   /* hover/click fall through to the bar's popover trigger */
    }
    .tl-row.current .tl-bar { box-shadow: inset 0 0 0 1px rgba(42,31,23,.28), 0 0 0 2px rgba(107,31,31,.28); }

    /* Event labels — a row BELOW the bottom ticks. */
    .tl-events { display: grid; grid-template-columns: var(--tl-label-w) 1fr; margin-top: 0.3rem; }
    .tl-events-scale { position: relative; min-height: 1.1rem; }
    .tl-event-label {
        position: absolute; top: 0;
        width: var(--tl-event-label-w);
        transform: translateX(-50%);
        font-size: 0.72rem; line-height: 1.25; color: var(--accent);
        text-align: center;
    }
    /* tl-fix r9: label text carries a page-coloured backing so a connector
       passing through this lane ducks BEHIND the words. The backing hugs
       the ink, not the 120px box: the name is inline (one backing strip
       per wrapped line; box-decoration-break gives every line its own
       padding), and the date is display:table, which shrinks a block to
       its text while margin:auto keeps it centred. */
    .tl-event-name {
        background: var(--bg);
        padding: 0.05em 3px;
        -webkit-box-decoration-break: clone;
        box-decoration-break: clone;
    }
    .tl-event-date {
        display: table; margin: 0 auto;
        padding: 0 3px;
        background: var(--bg);
        color: var(--muted);
    }

    /* tl-fix r9: the connector — carries an event's dashed line from the
       chart body down to a label bumped into a lower lane. Sized by the
       lane-packing script (top, height, dash phase); zero height until
       then, and zero for lane-0 labels, which already sit right under the
       body. Rendered BEFORE the labels in the markup, so labels always
       paint over it.
       Dash note: a tiled 8px gradient rather than .tl-event's repeating
       gradient, because a tile shifts cleanly with background-position —
       that's how the script phase-matches the dashes to the line above.
       Keep the 4px/8px rhythm in sync with .tl-event (and DASH in the
       script). */
    .tl-event-conn {
        position: absolute; top: 0; height: 0;
        width: 2px; margin-left: -1px;
        background: linear-gradient(to bottom,
            var(--accent) 0 4px, transparent 4px 8px);
        background-size: 2px 8px;
        opacity: 0.7;
        pointer-events: none;
    }

    /* tl-fix r10 — segment popover interiors. The shell (.fn-pop chrome,
       chevron, lift, poke) lives in book.blade; like the definition panel,
       this one navigates nowhere, so it drops the pointer cursor. */
    .fn-pop.tl-pop { cursor: default; }
    .tlp-head {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: 0.6rem;
    }
    .tlp-book { font-weight: 600; color: var(--accent); }
    .tlp-label {
        font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em;
        color: var(--muted);
        border: 1px solid var(--rule); border-radius: 4px;
        padding: 0 0.3rem;
        white-space: nowrap;
    }
    .tlp-full  { display: block; margin-top: 0.3rem; color: var(--ink); }
    .tlp-range {
        display: block; margin-top: 0.3rem;
        font-size: 0.8rem; color: var(--muted);
        font-variant-numeric: tabular-nums;
    }

    .tl-text {
        font-family: var(--sans);
        font-size: 0.9rem; color: var(--ink);
        margin: 0 0 1.1rem;
    }

    /* tl-fix r4: the partial's fit script adds .is-clamped to a final tick
       whose centred label would spill past the chart's right edge (where
       .tl-chart's overflow:hidden would eat the era suffix). Right-anchoring
       pulls the whole label inside; the tiny alignment drift versus its
       gridline only ever occurs at widths where the alternative was a
       clipped label. */
    .tl-tick.is-clamped { transform: translateX(-100%); }

    /* tl-fix r8: a tick label that would collide with its right-hand
       neighbour is hidden by the fit pass. visibility (not display) keeps
       its box measurable for the next pass; its gridline is untouched, so
       the grid rhythm survives even where a label doesn't. */
    .tl-tick.is-thinned { visibility: hidden; }

    /* tl-fix r4: MOBILE — short book names, and the freed-up label column
       hands its width to the chart track.
       KNOBS: the breakpoint, and --tl-label-w (fit to your longest
       short_name; text-overflow below catches any stragglers). */
    @media (max-width: 600px) {
        .tl {
            --tl-label-w: 72px;
        }
        /* tl-fix r7: the .is-mid selector here matches the desktop swap
           rule's specificity, and this block sits later in the file — so
           mobile wins even if a resize carried a stale .is-mid across the
           breakpoint before the fit pass re-ran. (The old .tl-book
           ellipsis safety moved to the base rule.) */
        .tl-name .tl-name-full,
        .tl-name.is-mid .tl-name-mid { display: none; }
        .tl-name .tl-name-short { display: inline; }
    }