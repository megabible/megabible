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
        font-family: var(--sans);
        margin: 0.5rem 0 1rem;
    }

    /* Chart zone: everything except the legend. Fluid width, never scrolls.
       overflow:hidden is a safety net — it guarantees the PAGE never grows a
       horizontal scrollbar if a tick/event/date label gets positioned hard
       against the right edge (that label clips instead of pushing the page
       wide). Keep your outermost ticks/events a little inside the range in the
       JSON and nothing ever clips. */
    .tl-chart { position: relative; overflow: hidden; }

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
    /* tl-fix r4: the current book's whole row gets a soft accent wash. The
       gridlines and event dashes live in .tl-grid, which is POSITIONED, so
       they paint above this background — no z-index needed. */
    .tl-row.current {
        background: color-mix(in srgb, var(--accent) 7%, transparent);
        border-radius: 6px;
    }
    .tl-book { padding-right: 0.9rem; line-height: 1.15; min-width: 0; }
    .tl-name { font-family: var(--serif); font-size: 0.98rem; color: var(--ink); text-decoration: none; }
    a.tl-name:hover { color: var(--accent); text-decoration: underline; }
    .tl-row.current .tl-name { color: var(--accent); font-weight: 700; }

    /* tl-fix r4: two labels per book — full name and the Book row's
       short_name — swapped by the mobile block at the foot of this file. */
    .tl-name .tl-name-short { display: none; }

    .tl-track { position: relative; height: 100%; }
    .tl-bar {
        position: absolute; top: 50%; transform: translateY(-50%);
        height: var(--tl-bar-h); min-width: 3px; border-radius: 3px;
        overflow: hidden;
        box-shadow: inset 0 0 0 1px rgba(42,31,23,.18);
    }
    .tl-seg-label {
        display: block; height: 100%;
        font-family: var(--sans); font-weight: 600;
        font-size: 9px; line-height: var(--tl-bar-h);
        text-align: center; color: #fff;
        white-space: nowrap; overflow: hidden;
        pointer-events: none;   /* hover still hits the bar's title tooltip */
    }
    .tl-row.current .tl-bar { box-shadow: inset 0 0 0 1px rgba(42,31,23,.28), 0 0 0 2px rgba(107,31,31,.28); }

    /* Date printed just to the RIGHT of each bar (was under the name before). */
    .tl-bar-date {
        position: absolute; top: 50%; transform: translateY(-50%);
        padding-left: 0.45rem; font-size: 0.74rem; color: var(--muted); white-space: nowrap;
    }

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
    .tl-event-date { display: block; color: var(--muted); }

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

    /* tl-fix r4: MOBILE — short book names, and the freed-up label column
       hands its width to the chart track.
       KNOBS: the breakpoint, and --tl-label-w (fit to your longest
       short_name; text-overflow below catches any stragglers). */
    @media (max-width: 600px) {
        .tl {
            --tl-label-w: 72px;
        }
        .tl-name .tl-name-full { display: none; }
        .tl-name .tl-name-short { display: inline; }
        /* Safety: a short_name longer than the column ellipsizes instead of
           running under the bars. */
        .tl-book { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    }