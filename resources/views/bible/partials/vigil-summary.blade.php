{{--
  ===========================================================================
  VIGIL FOOT SUMMARY — shared styles
  ---------------------------------------------------------------------------
  Included INSIDE a page's <style> block, the same way reading-styles and
  sticky-head are. Raw CSS only, no <style> wrapper — the including page owns
  those tags. Pull it in with a Blade include of: bible.partials.vigil-summary

  The running totals that sit at the FOOT of a vigil listing page, under a
  hairline rule: a labelled meter with its percentage on the right, then a
  plain verse count beneath it.

      vigil-home.blade.php   totals for the whole canon  ("All books")
      vigil-book.blade.php   totals for one book         ("This book")

  Both pages use identical markup and differ only in their label text and
  their element ids. The percentage belongs in the label row, NOT in the
  sentence below it — that split is the whole shape of this component.

  MARKUP CONTRACT:

      <div class="vg-summary">
          <div class="vg-summary-gauge">
              <div class="vg-summary-row">
                  <span class="vg-summary-label">All books</span>
                  <span class="vg-summary-pct" id="…">—</span>
              </div>
              <div class="vg-summary-meter">
                  <div class="vg-summary-fill" id="…"></div>
              </div>
          </div>
          <div class="vg-summary-total" id="…"></div>
          <p class="vg-summary-note" id="…" hidden></p>      (optional)
      </div>

  The ids are the page's own business; nothing here selects on them. Each
  page's script finds its own fill and percentage elements by id and writes
  the width and the text.

  BLADE NOTE: never let two opening braces end up adjacent in this file — a
  minified at-rule wrapping a selector will do exactly that, and Blade reads a
  doubled opening brace as an echo tag and tries to compile the CSS. Keep each
  rule's brace on its own line, as below.
  ===========================================================================
--}}
/* ---- The block ---------------------------------------------------------- */
    .vg-summary {
        /* ---- KNOBS ---------------------------------------------------
           Override on .vg-summary in the page's own <style> block, AFTER
           this include. */
        --vg-summary-gap: 2.8rem;   /* air above the rule */
        --vg-summary-bar: 6px;      /* meter thickness */

        margin: var(--vg-summary-gap) 0 1.2rem;
        padding-top: 1.2rem;
        border-top: 1px solid var(--rule);
    }

/* ---- Labelled gauge: label + percentage on one baseline, meter beneath --- */
    .vg-summary-gauge { margin: 0; font-family: var(--sans); }

    /* Baseline alignment, not centre — the small uppercase label and the
       larger percentage read as one line that way. */
    .vg-summary-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        margin-bottom: .3rem;
    }
    .vg-summary-label {
        font-size: .78rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted);
    }
    .vg-summary-pct {
        font-size: .85rem; font-weight: 700;
        color: var(--accent);
        font-variant-numeric: tabular-nums;   /* digits don't jitter on update */
    }

    .vg-summary-meter {
        height: var(--vg-summary-bar);
        background: var(--rule);
        border-radius: 999px;
        overflow: hidden;                      /* clips the fill's own radius */
    }
    .vg-summary-fill {
        height: 100%; width: 0%;               /* the script sets the width */
        background: var(--accent);
        border-radius: 999px;
        transition: width .3s ease;
    }

/* ---- Lines beneath the gauge -------------------------------------------- */
    /* The plain count: "N of M verses typed". No percentage here — it lives
       in the label row above. */
    .vg-summary-total {
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: .9rem 0 0;
    }
    .vg-summary-total b { color: var(--accent); font-variant-numeric: tabular-nums; }

    /* Optional extra line under the total (the book page uses it for approx
       typing time). Hidden until its script has something to put in it. */
    .vg-summary-note {
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: .25rem 0 0;
    }
    .vg-summary-note b { color: var(--accent); font-variant-numeric: tabular-nums; }
    .vg-summary-note[hidden] { display: none; }

/* ---- Reduced motion ----------------------------------------------------- */
    @media (prefers-reduced-motion: reduce) {
        .vg-summary-fill { transition: none; }
    }
