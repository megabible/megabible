{{--
    Text Settings — the "Aa" button + dropdown panel.

    A <details> popover (same pattern as the QuickNav / translation switcher).
    The trigger mirrors the .parallel-toggle chrome; the panel holds:

        Text Settings                    (↺ reset)
        [ A− ] [ A+ ] [ spacing ]
        [ Serif ] [ Sans ]      ← swapped for a pressed [Monospace] in Terminal
        [ Parchment ] [ Midnight ] [ Pure ]
        [ TERMINAL ]            ← only rendered once unlocked
        ☑ Headings
        ☑ Verse numbers
        ☑ Footnotes
        › Acts of the User          ← link out, hidden via $tsLinks

    All *visual state* (pressed, grayed-out, icons, Terminal variants) is pure
    CSS keyed off the data-* attributes the head script keeps on <html> — the
    script below only forwards clicks to window.MB.reader and handles
    open/close. No state lives here.

    Include once per page, directly AFTER the parallel-toggle include, inside
    .chapter-head-top (chapter view) or .parallel-head-top (parallel view).
--}}

<details class="text-settings" id="{{ $tsId ?? 'text-settings' }}">
    <summary class="ts-trigger" aria-label="Text settings" title="Text settings">
        <span class="ts-aa" aria-hidden="true">Aa</span>
    </summary>

    <div class="ts-panel" role="group" aria-label="Text settings">

        {{-- ── Header: title + reset ─────────────────────────────── --}}
        <div class="ts-head">
            <span class="ts-title">Text Settings</span>
            <button type="button" class="ts-reset"
                    aria-label="Reset text settings to defaults"
                    title="Reset to defaults">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                    <path d="M3 3v5h5"></path>
                </svg>
            </button>
        </div>

        {{-- ── Row 1: size down / size up / spacing cycle ─────────── --}}
        <div class="ts-row ts-row-3">
            <button type="button" class="ts-btn ts-smaller" aria-label="Smaller text" title="Smaller text">
                <span class="ts-a ts-a-sm" aria-hidden="true">A</span>
            </button>
            <button type="button" class="ts-btn ts-larger" aria-label="Larger text" title="Larger text">
                <span class="ts-a ts-a-lg" aria-hidden="true">A</span>
            </button>
            <button type="button" class="ts-btn ts-spacing" aria-label="Line spacing" title="Line spacing">
                {{-- Three icons, always 3 lines; the gap widens each step.
                     CSS shows the one matching data-spacing. --}}
                <svg class="ts-sp ts-sp-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true">
                    <line x1="4" y1="8"  x2="20" y2="8"></line>
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <line x1="4" y1="16" x2="20" y2="16"></line>
                </svg>
                <svg class="ts-sp ts-sp-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true">
                    <line x1="4" y1="6"  x2="20" y2="6"></line>
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <line x1="4" y1="18" x2="20" y2="18"></line>
                </svg>
                <svg class="ts-sp ts-sp-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true">
                    <line x1="4" y1="4"  x2="20" y2="4"></line>
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <line x1="4" y1="20" x2="20" y2="20"></line>
                </svg>
            </button>
        </div>

        {{-- ── Row 2: serif / sans (or forced monospace in Terminal) ── --}}
        <div class="ts-row ts-row-font">
            <button type="button" class="ts-btn ts-font-serif" title="Serif body text">
                <span class="ts-font-sample ts-sample-serif" aria-hidden="true">Serif</span>
            </button>
            <button type="button" class="ts-btn ts-font-sans" title="Sans-serif body text">
                <span class="ts-font-sample ts-sample-sans" aria-hidden="true">Sans</span>
            </button>
            {{-- Shown only in Terminal: permanently pressed, not a control. --}}
            <button type="button" class="ts-btn ts-font-mono" disabled
                    title="Terminal mode uses monospace">
                <span class="ts-font-sample ts-sample-mono" aria-hidden="true">Monospace</span>
            </button>

        </div>

        {{-- ── Row 3: themes ──────────────────────────────────────── --}}
        <div class="ts-row ts-row-themes">
            <button type="button" class="ts-btn ts-theme ts-theme-parchment" title="Parchment theme">
                <span class="ts-swatch" aria-hidden="true"></span>Parchment
            </button>
            <button type="button" class="ts-btn ts-theme ts-theme-midnight" title="Midnight theme">
                <span class="ts-swatch" aria-hidden="true"></span>Midnight
            </button>
            <button type="button" class="ts-btn ts-theme ts-theme-pure" title="Pure theme">
                <span class="ts-swatch" aria-hidden="true"></span>Pure
            </button>
            {{-- Shown only in Terminal: permanently pressed, not a control. --}}
            <button type="button" class="ts-btn ts-theme ts-theme-terminal"
                    title="Terminal theme — click to toggle">
                <svg class="ts-term-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="5 8 9 12 5 16"></polyline>
                    <line x1="12" y1="17" x2="18" y2="17"></line>
                </svg>Terminal
            </button>
        </div>

        {{-- ── Row 4: visibility toggles — suppressed via $tsChecks on pages
             (like the scrimmage) that have none of these to toggle. ── --}}
        @if ($tsChecks ?? true)
        <div class="ts-checks">
            <button type="button" class="ts-check ts-check-headings" role="switch">
                <span class="ts-box" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </span>
                Headings
            </button>
            <button type="button" class="ts-check ts-check-verses" role="switch">
                <span class="ts-box" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </span>
                Verse numbers
            </button>
            <button type="button" class="ts-check ts-check-footnotes" role="switch">
                <span class="ts-box" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </span>
                Footnotes
            </button>
        </div>
        @endif

        {{-- ── Row 5: page links — suppressed via $tsLinks on the pages the
             links themselves point at, so the panel never offers a trip to
             where the reader already is. ── --}}
        @if ($tsLinks ?? true)
        <div class="ts-links">
            <a class="ts-link" href="{{ route('extras.acts') }}"
               title="A record of your actions on MEGABIBLE.net">
                <svg class="ts-link-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
                Acts of the User
            </a>
        </div>
        @endif

    </div>
</details>

<style>
    /* ─── Trigger: mirrors .parallel-toggle chrome ─────────────────── */
    .text-settings { position: relative; flex: 0 0 auto; }
    .text-settings > summary { list-style: none; }
    .text-settings > summary::-webkit-details-marker { display: none; }

    .ts-trigger{
        display:inline-flex;align-items:center;justify-content:center;
        width:40px;height:40px;border-radius:50%;cursor:pointer;
        color:var(--muted);background:var(--bg);
        border:1px solid var(--rule);
        transition:color .12s,background .12s,border-color .12s;
        user-select:none;
    }
    .ts-trigger:hover{color:var(--bg);background:var(--accent);border-color:var(--accent);}
    .ts-trigger:focus-visible{outline:none;color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}
    .text-settings[open] .ts-trigger{color:var(--bg);background:var(--accent);border-color:var(--accent);}
    .ts-aa{font-family:var(--serif);font-size:1.05rem;font-weight:600;line-height:1;}

    /* Right-align: the trigger claims the flexible gap unless the parallel
       toggle (which already carries margin-left:auto) sits before it. */
    .chapter-head-top  .text-settings,
    .parallel-head-top .text-settings { margin-left:auto; }
    .chapter-head-top  .parallel-toggle ~ .text-settings,
    .parallel-head-top .parallel-toggle ~ .text-settings { margin-left:0; }

    /* ─── Panel ────────────────────────────────────────────────────── */
    .ts-panel{
        position:absolute;right:0;top:calc(100% + 10px);z-index:80;
        width:300px;padding:1rem;
        background:var(--bg);border:1px solid var(--rule);border-radius:12px;
        box-shadow:0 12px 32px rgba(0,0,0,.18);
    }

    .ts-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;}
    .ts-title{font-family:var(--sans);font-weight:700;font-size:.95rem;color:var(--ink);}
    .ts-reset{
        display:inline-flex;align-items:center;justify-content:center;
        width:32px;height:32px;border-radius:50%;cursor:pointer;
        color:var(--muted);background:var(--bg);border:1px solid var(--rule);
        transition:color .12s,background .12s,border-color .12s;
    }
    .ts-reset:hover{color:var(--bg);background:var(--accent);border-color:var(--accent);}
    .ts-reset svg{width:16px;height:16px;display:block;}

    /* ─── Shared button chrome ─────────────────────────────────────── */
    .ts-row{display:grid;gap:.5rem;margin-bottom:.5rem;}
    .ts-row-3{grid-template-columns:repeat(3,1fr);}
    .ts-row-font{grid-template-columns:repeat(2,1fr);}
    .ts-row-themes{
        grid-template-columns:repeat(3,1fr);
        border-top:1px solid var(--rule);   /* divider from the serif / sans row above */
        padding-top:.6rem;                    /* gap between the divider and the swatches */
    }

    .ts-btn{
        display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
        min-height:44px;padding:.4rem .5rem;border-radius:8px;cursor:pointer;
        font-family:var(--sans);font-size:.8rem;color:var(--ink);
        background:var(--panel);border:1px solid var(--rule);
        transition:color .12s,background .12s,border-color .12s,opacity .12s;
    }
    .ts-btn:hover{border-color:var(--accent);}
    .ts-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.12);}
    .ts-btn.is-on{color:var(--bg);background:var(--accent);border-color:var(--accent);}
    .ts-btn svg{width:20px;height:20px;display:block;}

    /* ─── Row 1 state (pure CSS, keyed off <html> data-*) ──────────── */
    .ts-a{font-family:var(--serif);font-weight:600;line-height:1;}
    .ts-a-sm{font-size:.85rem;}
    .ts-a-lg{font-size:1.3rem;}

    :root[data-size="0"] .ts-smaller,
    :root[data-size="4"] .ts-larger{opacity:.35;pointer-events:none;}

    /* Show exactly the spacing icon matching data-spacing. The base hide uses
       two classes (.ts-spacing .ts-sp) so it outranks `.ts-btn svg{display:block}`,
       which was otherwise forcing all three icons visible. */
    .ts-spacing .ts-sp{display:none;}
    :root[data-spacing="0"] .ts-spacing .ts-sp-0{display:block;}
    :root[data-spacing="1"] .ts-spacing .ts-sp-1{display:block;}
    :root[data-spacing="2"] .ts-spacing .ts-sp-2{display:block;}

    /* ─── Row 2 state: serif/sans pressed; Terminal swaps to mono ──── */
    .ts-font-sample{font-size:1.05rem;}
    .ts-sample-serif{font-family:var(--serif);}
    .ts-sample-sans{font-family:var(--sans);}
    .ts-sample-mono{font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;}

    :root[data-font="serif"] .ts-font-serif,
    :root[data-font="sans"]  .ts-font-sans{color:var(--bg);background:var(--accent);border-color:var(--accent);}

    .ts-font-mono{display:none;grid-column:1 / -1;}
    :root[data-theme="terminal"] .ts-font-serif,
    :root[data-theme="terminal"] .ts-font-sans{display:none;}
    :root[data-theme="terminal"] .ts-font-mono{
        display:inline-flex;cursor:default;
        color:var(--bg);background:var(--accent);border-color:var(--accent);
    }

    /* ─── Row 3 state: swatches + active theme; Terminal row ───────── */
    .ts-theme{flex-direction:column;gap:.3rem;padding:.5rem .3rem;font-size:.72rem;}
    .ts-swatch{width:22px;height:22px;border-radius:50%;display:block;}
    .ts-theme-parchment .ts-swatch{background:#f7f1e3;}
    .ts-theme-midnight  .ts-swatch{background:#1a1410;}
    .ts-theme-pure      .ts-swatch{background:#ffffff;}
    .ts-theme-terminal  .ts-swatch{background:#33ff66;}

    :root[data-theme="parchment"] .ts-theme-parchment,
    :root[data-theme="midnight"]  .ts-theme-midnight,
    :root[data-theme="pure"]      .ts-theme-pure,
    :root[data-theme="terminal"]  .ts-theme-terminal{color:var(--bg);background:var(--accent);border-color:var(--accent);}

    .ts-theme-terminal{
        display:none;grid-column:1 / -1;flex-direction:row;letter-spacing:.08em;
        font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        letter-spacing:.06em;
    }
    :root[data-terminal-unlocked="yes"] .ts-theme-terminal{display:inline-flex;}

    /* ─── Row 4 state: checks ──────────────────────────────────────── */
    .ts-checks{border-top:1px solid var(--rule);padding-top:.6rem;display:grid;gap:.25rem;}
    .ts-check{
        display:flex;align-items:center;gap:.6rem;width:100%;
        padding:.4rem .2rem;border:none;background:none;cursor:pointer;
        font-family:var(--sans);font-size:.85rem;color:var(--ink);text-align:left;
    }
    .ts-box{
        width:20px;height:20px;border-radius:5px;flex:0 0 20px;
        display:inline-flex;align-items:center;justify-content:center;
        background:var(--accent);border:1px solid var(--accent);color:var(--bg);
        transition:background .12s,border-color .12s;
    }
    .ts-box svg{width:13px;height:13px;}
    :root[data-verse-numbers="off"] .ts-check-verses .ts-box,
    :root[data-headings="off"]      .ts-check-headings .ts-box,
    :root[data-footnotes="off"]     .ts-check-footnotes .ts-box{
        background:var(--bg);border-color:var(--rule);
    }
    :root[data-verse-numbers="off"] .ts-check-verses .ts-box svg,
    :root[data-headings="off"]      .ts-check-headings .ts-box svg,
    :root[data-footnotes="off"]     .ts-check-footnotes .ts-box svg{visibility:hidden;}

    /* ─── Row 5: page links ────────────────────────────────────────── */
    .ts-links{
        border-top:1px solid var(--rule);
        margin-top:.6rem;      /* knob: gap above the divider */
        padding-top:.6rem;     /* knob: gap below the divider */
        display:grid;gap:.25rem;
    }
    .ts-link{
        display:flex;align-items:center;gap:.6rem;width:100%;
        /* Same geometry as .ts-check: .2rem padding, a 20px leading slot,
           then a .6rem gap — so the chevron lands in the checkbox column
           and "Acts of the User" starts under "Headings". No border here
           (the check rows have none either), which keeps them flush. */
        padding:.45rem .2rem;
        border-radius:8px;text-decoration:none;
        font-family:var(--sans);font-size:.85rem;color:var(--ink);
        transition:color .12s,background .12s;
    }
    .ts-link:hover{color:var(--bg);background:var(--accent);}
    .ts-link:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.12);}
    .ts-link-arrow{
        width:20px;height:20px;flex:0 0 20px;display:block;   /* matches .ts-box */
        opacity:.55;           /* knob: chevron weight */
    }
</style>

<script>
    /* Clicks → MB.reader. All visual state is CSS-driven off <html> data-*,
       so there is nothing to re-render here. */
    (function () {
        const root = document.getElementById('{{ $tsId ?? 'text-settings' }}');
        if (!root || !window.MB || !window.MB.reader) return;
        const R = window.MB.reader;

        const on = (sel, fn) => {
            const el = root.querySelector(sel);
            if (el) el.addEventListener('click', fn);
        };

        on('.ts-reset', () => {
            // Reset every text preference to defaults, but KEEP the current
            // theme — flipping someone's Midnight back to Parchment on a
            // "reset text" click is jarring and rarely what they meant.
            const keepTheme = R.get().theme;
            R.reset();
            if (R.get().theme !== keepTheme) R.setTheme(keepTheme);
        });
        on('.ts-smaller',        () => R.sizeDown());
        on('.ts-larger',         () => R.sizeUp());
        on('.ts-spacing',        () => R.cycleSpacing());
        on('.ts-font-serif',     () => R.setFont('serif'));
        on('.ts-font-sans',      () => R.setFont('sans'));
        on('.ts-theme-parchment',() => R.setTheme('parchment'));
        on('.ts-theme-midnight', () => R.setTheme('midnight'));
        on('.ts-theme-pure',     () => R.setTheme('pure'));
        on('.ts-theme-terminal', () => {
            R.get().theme === 'terminal' ? R.exitTerminal() : R.setTheme('terminal');
        });
        on('.ts-check-headings',  () => R.toggleHeadings());
        on('.ts-check-verses',    () => R.toggleVerseNumbers());
        on('.ts-check-footnotes', () => R.toggleFootnotes());

        // Close on outside click or Escape (panel itself stays open while
        // the reader plays with settings — that's the ESV behaviour too).
        document.addEventListener('click', (e) => {
            if (root.open && !root.contains(e.target)) root.open = false;
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && root.open) root.open = false;
        });
    })();
</script>