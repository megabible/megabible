<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script>
        /* =====================================================================
        READER SETTINGS — theme + text preferences, persisted to localStorage.

        This runs SYNCHRONOUSLY in <head>, before the page paints, so a saved
        theme (e.g. Midnight) is applied to <html> immediately and the reader
        never sees a flash of the default Parchment first.

        Everything is expressed as data-* attributes on <html>; the CSS in the
        stylesheet maps those attributes to the actual colours / sizes. No other
        script needs to run for the settings to take effect — this is the single
        source of truth, exposed as window.MB.reader for the settings panel.
        ===================================================================== */
        (function () {
            const KEY  = 'mb.reader';
            const ROOT = document.documentElement;

            // Defaults + allowed ranges — the one place these live.
            const DEFAULTS = {
                theme:            'parchment',  // parchment | midnight | pure | terminal
                size:             2,            // 0..4  (2 = default)
                spacing:          0,            // 0..2  (0 = default)
                font:             'serif',      // serif | sans (ignored while terminal)
                verseNumbers:     true,
                headings:         true,
                footnotes:        true,
                terminalUnlocked: false,        // set true once the easter egg is found
            };
            const SIZE_MIN = 0, SIZE_MAX = 4, SPACING_STEPS = 3;

            function load() {
                let saved = {};
                try { saved = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) {}
                return Object.assign({}, DEFAULTS, saved);
            }

            let state = load();

            // Safety net: never honour a persisted Terminal theme for someone who
            // hasn't actually unlocked it (e.g. copied localStorage, cleared unlock).
            if (state.theme === 'terminal' && !state.terminalUnlocked) {
                state.theme = 'parchment';
            }

            function apply() {
                ROOT.dataset.theme        = state.theme;
                ROOT.dataset.size         = state.size;
                ROOT.dataset.spacing      = state.spacing;
                ROOT.dataset.font         = state.font;
                ROOT.dataset.verseNumbers = state.verseNumbers ? 'on' : 'off';
                ROOT.dataset.headings     = state.headings ? 'on' : 'off';
                ROOT.dataset.footnotes    = state.footnotes ? 'on' : 'off';
                ROOT.dataset.terminalUnlocked = state.terminalUnlocked ? 'yes' : 'no';
            }

            function save() {
                try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
            }

            function notify() {
                document.dispatchEvent(new CustomEvent('mb:reader-change', { detail: { ...state } }));
            }

            function set(patch) { Object.assign(state, patch); apply(); save(); notify(); }

            // Public API — the settings panel (Step 2) drives everything through this.
            window.MB = window.MB || {};
            window.MB.reader = {
                get: () => ({ ...state }),
                limits: { SIZE_MIN, SIZE_MAX, SPACING_STEPS },

                sizeUp()   { if (state.size < SIZE_MAX) set({ size: state.size + 1 }); },
                sizeDown() { if (state.size > SIZE_MIN) set({ size: state.size - 1 }); },
                atSizeMax: () => state.size >= SIZE_MAX,
                atSizeMin: () => state.size <= SIZE_MIN,

                cycleSpacing() { set({ spacing: (state.spacing + 1) % SPACING_STEPS }); },
                setFont(f)     { set({ font: f }); },

                setTheme(t) {
                    if (t === 'terminal' && !state.terminalUnlocked) return; // locked
                    set({ theme: t });
                },

                toggleVerseNumbers() { set({ verseNumbers: !state.verseNumbers }); },
                toggleHeadings()     { set({ headings: !state.headings }); },
                toggleFootnotes()    { set({ footnotes: !state.footnotes }); },

                // Easter egg: unlock + drop straight into Terminal.
                unlockTerminal() { set({ terminalUnlocked: true, theme: 'terminal' }); },
                // Toggling Terminal OFF from the panel returns to Parchment.
                exitTerminal()   { if (state.theme === 'terminal') set({ theme: 'parchment' }); },

                // Reset TEXT/theme settings to default — but keep the unlock, so the
                // reader doesn't lose the easter egg they already found.
                reset() {
                    const unlocked = state.terminalUnlocked;
                    state = Object.assign({}, DEFAULTS, { terminalUnlocked: unlocked });
                    apply(); save(); notify();
                },
            };

            apply();   // first paint, before <body> exists
        })();
    </script>    
    
    <title>@yield('title', 'MEGABIBLE.net')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Forum:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        /* =========================================================
           SHARED: design tokens + base + header/footer chrome.
           ========================================================= */
        :root {
            --bg:#f4efe6; --ink:#2a1f17; --muted:#8a7560; --accent:#6b1f1f;
            --rule:#d8cdb8; --panel:#ece4d4; --soon:#a99a82;
            --serif:'Iowan Old Style','Palatino Linotype','Book Antiqua',Palatino,Georgia,serif;
            --sans:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
            --wordmark-font:'Forum', var(--serif);

            /* ---- TITLE LEADING KNOB ------------------------------------
               Line gap when a page-title H1 wraps to two lines (long book
               names on mobile). Applied to every h1 site-wide by one rule
               in the base styles. Smaller = tighter. Body text is untouched
               (it uses --reading-leading). */
            --title-leading:1.15;

            /* Timeline bar palette — parchment-friendly. */
            --tl-clay:#7c624f;       --tl-slate:#6f7a82;        --tl-gold:#c2952f;
            --tl-plum:#a28db8;       --tl-terracotta:#a8553a;   --tl-teal:#4f7d6e;
            --tl-royal:#5b4f80;      --tl-olive:#74804f;        --tl-crimson:#8a2f3c;
            --tl-indigo:#312146;     --tl-moss:#364621;         --tl-navy:#354f73;

            /* Reader text settings — consumed only by .reading, so chrome never
               resizes. Defaults here; data-* on <html> overrides them below. */
            --reading-size:19px;
            --reading-leading:1.65;
            --reading-family:var(--serif);

            /* Input/field surface — tokenised so dark themes aren't stuck white. */
            --field:#fff;
        }

        /* =========================================================
           READER SETTINGS → data-* on <html> map to tokens.
           Ordering matters: the Terminal theme sits LAST so its
           monospace --reading-family wins over the serif/sans choice.
           ========================================================= */

        /* ---- Text size (verse body text only) ---- */
        :root[data-size="0"]{ --reading-size:16px;   }
        :root[data-size="1"]{ --reading-size:17.5px; }
        :root[data-size="2"]{ --reading-size:19px;   }  /* default */
        :root[data-size="3"]{ --reading-size:21px;   }
        :root[data-size="4"]{ --reading-size:23px;   }

        /* ---- Line spacing ---- */
        :root[data-spacing="0"]{ --reading-leading:1.65; }  /* default */
        :root[data-spacing="1"]{ --reading-leading:1.9;  }
        :root[data-spacing="2"]{ --reading-leading:2.3;  }

        /* ---- Serif / sans body ---- */
        :root[data-font="sans"]{ --reading-family:var(--sans); }

        /* ---- Reading-text opt-in ----------------------------------------
           The single consumer of the three --reading-* tokens above. Any
           block of body text that should obey the reader's Text Settings
           carries .reader-text. .reading (the chapter verse columns) listed alongside it so the readers keep working with
           no markup change.

           Chrome — headers, infoboxes, timelines, outlines, footers — simply
           doesn't carry the class, and therefore never resizes. Opting a new
           page in is a markup change only; no new CSS. */
        .reader-text,
        .reading {
            font-family: var(--reading-family);
            font-size:   var(--reading-size);
            line-height: var(--reading-leading);
        }

        /* ---- Verse numbers / headings visibility ---- */
        :root[data-verse-numbers="off"] .reading .verse-number { display:none; }
        :root[data-headings="off"]      .reading .heading       { display:none; }
        :root[data-footnotes="off"]     .reading .fn-markers,
        :root[data-footnotes="off"]     .reading .footnotes     { display:none; }

        /* ---- THEME: Midnight (warm near-black, parchment-toned ink) ---- */
        :root[data-theme="midnight"]{
            --bg:#1a1410; --ink:#e8ddc9; --muted:#9c8a72; --accent:#cf9b6b;
            --rule:#3a2f25; --panel:#241c15; --soon:#6b5b47; --field:#241c15;
        }

        /* ---- THEME: Pure (clean white, accessibility-safe contrast) ---- */
        :root[data-theme="pure"]{
            --bg:#ffffff; --ink:#1a1a1a; --muted:#5a5a5a; --accent:#7a1414;
            --rule:#dddddd; --panel:#f2f2f2; --soon:#8a8a8a; --field:#ffffff;
        }

        /* ---- THEME: Terminal (secret — black + phosphor green, monospace) ----
           Everything mono, site-wide. Because every font-family in the site
           resolves through --serif / --sans / --wordmark-font (verified: zero
           hardcoded stacks), overriding those three flips the WHOLE interface —
           not just the reader. Placed last so it wins over the serif/sans rule. */
        :root[data-theme="terminal"]{
            --bg:#000000; --ink:#33ff66; --muted:#1f9e40; --accent:#7dff7d;
            --rule:#0f3d1e; --panel:#0a1f0f; --soon:#155a2a; --field:#0a1f0f;

            --mono:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
            --serif:var(--mono);
            --sans:var(--mono);
            --wordmark-font:var(--mono);
            --reading-family:var(--mono);
        }

        /* ---- Canon / timeline palette per theme ---- */

        /* Midnight — luminous so bars read on warm near-black. */
        :root[data-theme="midnight"]{
            --tl-clay:#c9a25e;      --tl-slate:#9aacb8;         --tl-gold:#e0b34e;
            --tl-plum:#7893c0;      --tl-terracotta:#d68a63;    --tl-teal:#6fb59f;
            --tl-royal:#6e6ed6;     --tl-olive:#aab878;         --tl-crimson:#d46e7c;
            --tl-indigo:#6b6192;    --tl-moss:#678a3c;          --tl-navy:#4f9ed0;
        }

        /* Pure — no change from default parchment palette. */
        :root[data-theme="pure"]{
            --tl-clay:#7c624f;       --tl-slate:#6f7a82;        --tl-gold:#c2952f;
            --tl-plum:#a28db8;       --tl-terracotta:#a8553a;   --tl-teal:#4f7d6e;
            --tl-royal:#5b4f80;      --tl-olive:#74804f;        --tl-crimson:#8a2f3c;
            --tl-indigo:#312146;     --tl-moss:#364621;         --tl-navy:#354f73;
        }

        /* Terminal — phosphor CRT: green-dominant with amber/cyan for separation. */
        :root[data-theme="terminal"]{
            --tl-clay:#2f8f3f;      --tl-slate:#3fb8c0;         --tl-gold:#0f3d1e;
            --tl-plum:#4f9ed0;      --tl-terracotta:#3f8f6a;    --tl-teal:#8fbf3f;
            --tl-royal:#7890f0;     --tl-olive:#2fb37a;         --tl-crimson:#c9552f;
            --tl-indigo:#4fb59a;    --tl-moss:#1f7a35;          --tl-navy:#2f5ec9;
        }

        *{box-sizing:border-box;}
        body{background:var(--bg);color:var(--ink);font-family:var(--serif);font-size:19px;line-height:1.65;margin:0;}
        /* Every page-title h1 wraps with --title-leading, not body leading. */
        h1{line-height:var(--title-leading);}
        .container{max-width:820px;margin:0 auto;padding:.7rem 1.5rem 6rem;}

        /* ---- Header ---- */
        .site-header{display:flex;align-items:center;gap:1.1rem;padding-bottom:.7rem;margin-bottom:1.5rem;border-bottom:1px solid var(--rule);}

        /* Brand cluster (logo + wordmark) — left aligned with the page content. */
        .brand{display:flex;align-items:center;gap:1.1rem;min-width:0;}
        
        .logo{
            width:50px;height:50px;border-radius:50%;flex:0 0 50px;display:block;
            background:url('{{ asset('images/MEGABIBLE_LOGO_256.png') }}') center/cover no-repeat;
        }
        :root[data-theme="midnight"] .logo{ background-image:url('{{ asset('images/MEGABIBLE_LOGO_256.png') }}'); }
        :root[data-theme="pure"]     .logo{ background-image:url('{{ asset('images/MEGABIBLE_LOGO_256.png') }}'); }
        :root[data-theme="terminal"] .logo{ background-image:url('{{ asset('images/MEGABIBLE_LOGO_256_terminal.png') }}'); }

        .wordmark-link{text-decoration:none;color:inherit;min-width:0;}
        .wordmark{line-height:1.05;}
        .wordmark .name{font-family:var(--wordmark-font);font-size:1.8rem;font-weight:600;letter-spacing:.01em;}
        .wordmark .tld{font-size:1.5rem;color:var(--accent);}
        .wordmark .tag{display:block;margin-top:.4rem;font-family:var(--sans);font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);}
        .wordmark .tag a{margin-top:.4rem;font-family:var(--sans);font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);text-decoration:none;}

        /* Search — pinned right. */
        .site-search{margin-left:auto;display:flex;align-items:center;gap:.45rem;width:340px;max-width:42%;}
        .site-search-input{
            flex:1 1 auto;min-width:0;
            background:var(--field);color:var(--ink);
            border:1px solid var(--rule);border-radius:8px;
            padding:.55rem .85rem;
            font-family:var(--sans);font-size:.95rem;line-height:1.2;
            outline:none;transition:border-color .12s,box-shadow .12s;
        }
        .site-search-input::placeholder{color:var(--soon);}
        /* Hide the native clear (×) button Chrome/Edge/Safari add to type=search inputs. */
        .site-search-input::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none;}
        .site-search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}
        .site-search-button{
            flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;
            width:42px;height:42px;
            background:var(--accent);color:#fff;
            border:1px solid var(--accent);border-radius:8px;
            cursor:pointer;padding:0;transition:filter .12s;
        }
        .site-search-button:hover{filter:brightness(1.12);}
        .site-search-button svg{display:block;}

        /* ---- Footer ---- */
        footer{margin-top:4rem;padding-top:1.5rem;border-top:1px solid var(--rule);font-family:var(--sans);font-size:.8rem;color:var(--muted);line-height:1.7;}
        footer a{color:var(--accent);text-decoration:none;}

        .footer-colophon{margin-bottom:.6rem;color:var(--muted);}
        .footer-colophon strong { color: var(--ink); font-weight: 600; }

        .footer-inner{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem 1.5rem;}
        .footer-text{flex:1 1 auto;}

        .footer-social{display:flex;align-items:center;gap:.4rem;margin-left:auto;}
        .footer-social a,
        .footer-social button{
            display:inline-flex;align-items:center;justify-content:center;
            width:50px;height:50px;
            color:var(--muted);background:none;
            border:none;border-radius:8px;
            padding:0;cursor:pointer;text-decoration:none;
            transition:color .12s,background .12s;
        }
        .footer-social a:hover,
        .footer-social button:hover{color:var(--accent);background:var(--panel);}
        .footer-social a:focus-visible,
        .footer-social button:focus-visible{outline:none;color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}
        .footer-social svg{display:block;width:20px;height:20px;}

        .footer-social .soc-ico{
            width:35px;height:35px;display:block;
            background-color:var(--muted);
            -webkit-mask-position:center; mask-position:center;
            -webkit-mask-size:contain;   mask-size:contain;
            -webkit-mask-repeat:no-repeat; mask-repeat:no-repeat;
            transition:background-color .12s;
        }
        .footer-social a:hover .soc-ico,
        .footer-social button:hover .soc-ico{ background-color:var(--accent); }

        .soc-youtube{ -webkit-mask-image:url('{{ asset('images/youtube.svg') }}'); mask-image:url('{{ asset('images/youtube.svg') }}'); }
        .soc-insta  { -webkit-mask-image:url('{{ asset('images/instagram.svg') }}');   mask-image:url('{{ asset('images/instagram.svg') }}'); }
        .soc-tiktok { -webkit-mask-image:url('{{ asset('images/tiktok.svg') }}');  mask-image:url('{{ asset('images/tiktok.svg') }}'); }
        .soc-discord{ -webkit-mask-image:url('{{ asset('images/discord.svg') }}'); mask-image:url('{{ asset('images/discord.svg') }}'); }
        .soc-email  { -webkit-mask-image:url('{{ asset('images/newsletter.svg') }}');   mask-image:url('{{ asset('images/newsletter.svg') }}'); }

        .chapter-nav{
            /* Resting spot, measured from the TOP of the viewport. Because the
               arrows are position:fixed, this is a share of viewport height and
               is therefore identical on every chapter — which is the point. It
               used to be 70%, which let --nav-ceiling win on short chapters and
               made the arrows jump around between, say, Psalms 1-10. */
            --nav-drop:25%;

            /* Hard floor: the arrows' CENTRE may never rise above this. Guards
               against short viewports where --nav-drop would put them under the
               sticky chapter head (.chapter-head is z-index 30, these are 40, so
               they'd paint straight over it). Roughly: pinned-head height + half
               an arrow + breathing room. Turn UP if you ever see an overlap. */
            --nav-floor:120px;

            --nav-reach:466px;

            /* Ceiling set by JS to keep the arrows off the footer on very short
               chapters. 100% = no limit. With --nav-drop at 50% this almost
               never binds any more; it stays as a safety net. */
            --nav-ceiling:100%;

            /* clamp(MIN, VAL, MAX): floor beats drop beats ceiling. If a page is
               ever so short that the ceiling drops below the floor, the floor
               wins and the arrows may touch the footer — preferred to having
               them cover the chapter title. */
            position:fixed;
            top:clamp(var(--nav-floor), var(--nav-drop), var(--nav-ceiling));
            transform:translateY(-50%);
            z-index:40;

            display:inline-flex;align-items:center;justify-content:center;
            width:48px;height:48px;border-radius:50%;
            color:var(--muted);background:var(--bg);
            border:1px solid var(--rule);text-decoration:none;
            transition:color .12s,background .12s,border-color .12s;
        }

        .chapter-nav:hover,
        .chapter-nav.is-active{color:#fff;background:var(--accent);border-color:var(--accent);}
        .chapter-nav:focus-visible{outline:none;color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}

        .chapter-nav.prev{left:max(1rem, calc(50% - var(--nav-reach)));}
        .chapter-nav.next{right:max(1rem, calc(50% - var(--nav-reach)));}
        .chapter-nav svg{display:block;width:26px;height:26px;}

        /* Vigil toggle — the candle. Bordered 40px circle, same as .ts-trigger:
           outline by default (reader: "enter the Vigil"), filled when pressed
           via .is-active (any Vigil page).*/
        .vigil-toggle{
            flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;
            width:40px;height:40px;border-radius:50%;
            color:var(--muted);background:var(--bg);
            border:1px solid var(--rule);text-decoration:none;
            transition:color .12s,background .12s,border-color .12s;
        }
        .vigil-toggle:hover{color:#fff;background:var(--accent);border-color:var(--accent);}
        .vigil-toggle:focus-visible{outline:none;color:var(--accent);box-shadow:0 0 0 3px rgba(107,31,31,.12);}
        .vigil-toggle.is-active{color:#fff;background:var(--accent);border-color:var(--accent);}
        .vigil-toggle.is-active:hover{filter:brightness(1.12);}
        .vigil-toggle svg{display:block;width:22px;height:22px;}


        /* =========================================================
           Translation switcher — pill button + dropdown.
           ========================================================= */
        .tx{position:relative;display:inline-block;font-family:var(--sans);margin-bottom:.1rem;}
        .tx-pill{
            display:inline-flex;align-items:center;gap:.4rem;
            padding:.25rem .7rem;
            border:1px solid var(--rule);border-radius:999px;
            background:var(--bg);color:var(--ink);
            font-size:.85rem;font-weight:600;
            cursor:pointer;user-select:none;list-style:none;
        }
        .tx-pill::-webkit-details-marker{display:none;}
        .tx-pill:hover{background:var(--panel);}
        .tx-caret{font-size:.7em;color:var(--muted);transition:transform .15s ease;}
        .tx[open] .tx-caret{transform:rotate(180deg);}
        .tx-menu{
            position:absolute;top:calc(100% + .4rem);left:0;
            min-width:220px;
            background:var(--bg);border:1px solid var(--rule);border-radius:8px;
            box-shadow:0 8px 24px rgba(42,31,23,.12);
            padding:.35rem;z-index:50;
        }
        .tx-option{
            display:grid;grid-template-columns:1.4rem 1fr auto;
            align-items:center;gap:.5rem;
            padding:.45rem .6rem;border-radius:5px;
            text-decoration:none;color:var(--ink);
            font-size:.9rem;white-space:nowrap;
        }
        a.tx-option:hover{background:var(--panel);}
        .tx-check{color:var(--accent);text-align:center;font-size:.85rem;}
        .tx-name{color:var(--ink);}
        .tx-option.is-current{font-weight:600;cursor:default;}
        .tx-year{color:var(--muted);font-variant-numeric:tabular-nums;}

        /* =========================================================
           QuickNav — logo-triggered popup to jump to any book/chapter.
           Built on <details>/<summary> like the translation switcher.
           The two-screen swap (books → chapters), the on-demand chapter
           buttons, and the click-away/Escape/reset are in the script below.
           Each book button is tinted by its canon section: the colour name
           comes from config/canon.php and is injected per-button as --bk.
           ========================================================= */
        .qn{position:relative;display:inline-block;}
        .qn-trigger{
            display:flex;align-items:center;justify-content:center;
            width:50px;height:50px;                 /* box = the logo exactly, no baseline gap */
            list-style:none;cursor:pointer;border-radius:50%;outline:none;
        }
        .qn-trigger::-webkit-details-marker{display:none;}   /* hide disclosure triangle */
        .qn-trigger:focus-visible{box-shadow:0 0 0 3px rgba(107,31,31,.25);}

        /* Book-name QuickNav trigger (chapter + parallel reader heads). Reuses
           the popup, but the trigger is the <h1> book title rather than the
           circular logo, so it needs its own marker-hiding + focus ring instead
           of .qn-trigger's circle. */
        .qn-book-trigger{
            display:inline-block;list-style:none;cursor:pointer;
            border-radius:6px;outline:none;
        }
        .qn-book-trigger::-webkit-details-marker{display:none;}
        .qn-book-trigger:focus-visible{box-shadow:0 0 0 3px rgba(107,31,31,.25);}

        .qn-panel{
            position:absolute;top:calc(100% + .3rem);left:0;
            width:min(680px, calc(100vw - 2rem));
            /* SAFETY NET (desktop): grow to fit the chapters, so a normal-height
               window never shows an internal scrollbar. The cap is generous —
               ~100dvh minus room for the header — so it only bites on very short
               windows, where scrolling is the graceful fallback. */
            max-height:calc(100vh  - 7rem);   /* fallback for browsers w/o dvh */
            max-height:calc(100dvh - 7rem);
            overflow-y:auto;
            background:var(--bg);border:1px solid var(--rule);border-radius:10px;
            box-shadow:0 12px 32px rgba(42,31,23,.18);
            padding:1rem 1.1rem;z-index:60;
            font-family:var(--sans);
        }

        /* Themed scrollbar for the panel. It only ever appears when the panel
           actually scrolls: always on the mobile sheet, and on desktop only if
           the safety-net cap engages on a short window. Built from the theme
           tokens so Midnight / Terminal get matching bars. */
        .qn-panel{scrollbar-width:thin;scrollbar-color:var(--rule) transparent;}
        .qn-panel::-webkit-scrollbar{width:10px;}
        .qn-panel::-webkit-scrollbar-track{background:transparent;}
        .qn-panel::-webkit-scrollbar-thumb{
            background:var(--rule);border-radius:6px;
            border:2px solid var(--bg);          /* inset from the panel edge */
        }
        .qn-panel::-webkit-scrollbar-thumb:hover{background:var(--muted);}

        /* ---- Screen 1: the book grid ---- */
        .qn-testament{margin-bottom:1.1rem;}
        .qn-testament:last-child{margin-bottom:0;}
        .qn-testament-title{
            font-family:var(--serif);font-size:1.15rem;font-weight:600;
            color:var(--accent);margin:0 0 .6rem;
        }
        .qn-grid{display:flex;flex-wrap:wrap;gap:.4rem;}
        .qn-book{
            --bk:var(--tl-clay);                 /* fallback; overridden inline per book */
            font:inherit;font-size:.82rem;font-weight:600;
            color:#fff;background:var(--bk);
            border:1px solid rgba(0,0,0,.14);border-radius:6px;
            padding:.32rem .6rem;cursor:pointer;
            transition:filter .12s;white-space:nowrap;
        }
        .qn-book:hover{filter:brightness(1.1);}
        .qn-book:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.25);}
        .qn-soon{                                /* a book with no text loaded yet */
            color:var(--soon);background:transparent;
            border:1px dashed var(--rule);cursor:default;
        }
        .qn-soon:hover{filter:none;}

        /* ---- Screen 2: the chapter grid ---- */
        .qn-chapters{display:none;}
        .qn.show-chapters .qn-books{display:none;}
        .qn.show-chapters .qn-chapters{display:block;}
        .qn-chap-head{display:flex;align-items:center;gap:.7rem;margin-bottom:.9rem;}
        .qn-back{
            display:inline-flex;align-items:center;gap:.3rem;
            font:inherit;font-size:.85rem;font-weight:600;
            color:var(--accent);background:none;border:none;cursor:pointer;padding:.2rem .1rem;
        }
        .qn-back:hover{text-decoration:underline;}
        .qn-chap-title{font-family:var(--serif);font-size:1.15rem;font-weight:600;color:var(--ink);text-decoration:none;}
        .qn-chap-title:hover{color:var(--accent);text-decoration:underline;}
        .qn-chap-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(44px,1fr));gap:.4rem;}
        .qn-chap{
            display:flex;align-items:center;justify-content:center;
            aspect-ratio:1/1;text-decoration:none;
            color:var(--ink);background:var(--panel);
            border:1px solid var(--rule);border-radius:6px;
            font-size:.9rem;font-weight:600;
            transition:background .12s,color .12s,border-color .12s;
        }
        .qn-chap:hover{background:var(--accent);color:#fff;border-color:var(--accent);}

        /* =================================================================
        PSEUDO-SYSTEM DIALOGS  (mbNotify / mbConfirm — see public/js/dialog.js)
        A full-screen scrim dims the site; a themed card sits on top. Every
        colour comes from a custom property, so the dialog repaints with the
        active theme with no per-theme rules of its own. Global because the
        reader FAB, the Pericope sheet, and the Acts page all use it.
        ================================================================= */
        .mb-dialog-scrim {
            position: fixed; inset: 0; z-index: 9999;      /* above any sticky header — raise if needed */
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            background: rgba(0, 0, 0, .55);                 /* BACKDROP DIM — knob */
            opacity: 0; transition: opacity .14s ease;
        }
        .mb-dialog-scrim.is-open { opacity: 1; }

        .mb-dialog {
            width: 100%; max-width: 26rem;                  /* DIALOG WIDTH — knob */
            background: var(--bg);
            border: 1px solid var(--rule); border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, .35);
            padding: 1.4rem 1.5rem 1.2rem;
            font-family: var(--sans);
            transform: translateY(8px) scale(.98);
            transition: transform .14s ease;
        }
        .mb-dialog-scrim.is-open .mb-dialog { transform: none; }

        .mb-dialog-msg p { margin: 0 0 .7rem; color: var(--ink); font-size: .92rem; line-height: 1.55; }
        .mb-dialog-msg p:first-child { font-family: var(--serif); font-size: 1.15rem; margin-bottom: .5rem; }
        .mb-dialog-msg p:last-child  { margin-bottom: 0; }

        .mb-dialog-check { color: var(--accent); font-weight: 700; margin-left: .2em; }

        .mb-dialog-buttons {
            display: flex; justify-content: flex-end; gap: .6rem;
            margin-top: 1.3rem;
        }
        .mb-dialog-btn {
            font-family: var(--sans); font-size: .84rem; font-weight: 600;
            border-radius: 999px; padding: .45rem 1.15rem; cursor: pointer;
            transition: color .12s, background .12s, border-color .12s, filter .12s;
        }
        .mb-dialog-btn.is-primary { color: #fff; background: var(--accent); border: 1px solid var(--accent); }
        .mb-dialog-btn.is-primary:hover { filter: brightness(1.08); }
        .mb-dialog-btn.is-ghost { color: var(--muted); background: none; border: 1px solid var(--rule); }
        .mb-dialog-btn.is-ghost:hover { color: var(--accent); border-color: var(--accent); }

    @media (prefers-reduced-motion: reduce) {
        .mb-dialog-scrim, .mb-dialog { transition: none; }
        .mb-dialog { transform: none; }
    }

    /* ---- Pericope panel (public/js/pericope-sheet.js) ----
       A <details> app in the head folder; the panel is .ts-panel's twin —
       same width, chrome, offset and z-index — and hangs beneath the pill
       (the folder makes inner details position:static, and caps the
       panel's height so a long list scrolls inside itself). */
    .ps-panel {
        position: absolute; right: 0; top: calc(100% + 10px); z-index: 80;
        width: 236px; padding: .9rem;
        background: var(--bg); border: 1px solid var(--rule); border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
        text-align: left; cursor: default;
    }
    .ps-head { margin-bottom: .2rem; }
    .ps-title {
        display: inline-flex; align-items: center; gap: .2rem;
        font-family: var(--sans); font-weight: 700; font-size: .95rem; color: var(--ink);
        text-decoration: none;
    }
    a.ps-title:hover { color: var(--accent); }
    .ps-title-arrow { width: 18px; height: 18px; display: block; opacity: .55; }
    .ps-sub {
        margin: 0 0 .7rem; font-family: var(--sans); font-size: .85rem; color: var(--muted);
    }
    .ps-added { color: var(--ink); }
    .ps-error { color: var(--accent); }
    .ps-open { color: var(--accent); font-weight: 600; }

    .ps-list { display: flex; flex-direction: column; gap: .3rem; margin: 0 0 .7rem; }
    /* Phones: five rows, then the list scrolls inside itself so the name
       field below stays reachable above the keyboard. 2.1rem ≈ one row
       (.55rem padding ×2 + the .88rem line); 4 gaps of .3rem between. */
    @media (max-width: 520px) {
        .ps-list { max-height: calc(5 * 2.1rem + 4 * .3rem); overflow-y: auto; overscroll-behavior: contain; }
    }
    .ps-board {
        display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        width: 100%; box-sizing: border-box; text-align: left;
        padding: .55rem .8rem; border: 1px solid var(--rule); border-radius: 9px;
        background: var(--panel); color: var(--ink); cursor: pointer;
        font-family: var(--sans); font-size: .88rem; font-weight: 600;
        text-decoration: none;
        transition: border-color .12s, color .12s;
    }
    .ps-board:hover { border-color: var(--accent); color: var(--accent); }
    .ps-board:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    /* min-width:0 lets the name shrink below its text so the ellipsis can
       fire; without it a flex item refuses to go narrower than its content. */
    .ps-board-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ps-board-count {
        flex: 0 0 auto; display: inline-flex; align-items: center; gap: .25rem;
        color: var(--muted); font-size: .8rem;
    }
    .ps-board:hover .ps-board-count { color: var(--accent); }
    /* The row that just took the hand: accent border, check before the count. */
    .ps-board.is-done { border-color: var(--accent); color: var(--accent); }
    .ps-board.is-done .ps-board-count { color: var(--accent); }
    .ps-check { width: 14px; height: 14px; display: block; }

    .ps-empty {
        margin: 0 0 .7rem; color: var(--muted);
        font-family: var(--sans); font-size: .85rem; font-style: italic;
    }

    .ps-new { display: flex; gap: .5rem; border-top: 1px solid var(--rule); padding-top: .7rem; }
    .ps-input {
        flex: 1 1 auto; min-width: 0;
        padding: .5rem .7rem; border: 1px solid var(--rule); border-radius: 9px;
        background: var(--bg); color: var(--ink);
        font-family: var(--sans); font-size: .9rem;
    }
    .ps-input:focus { outline: none; border-color: var(--accent); }
    .ps-create {
        flex: 0 0 auto;
        padding: .5rem 1rem; border: 1px solid var(--accent); border-radius: 999px;
        background: var(--accent); color: #fff; cursor: pointer;
        font-family: var(--sans); font-size: .84rem; font-weight: 600;
        transition: filter .12s;
    }
    .ps-create:hover { filter: brightness(1.08); }

        /* ---- Responsive chrome ---- */
        @media (max-width:690px){
            .footer-text{flex:1 1 100%;}
            .footer-social{margin-left:-7px;}
            /* QuickNav on phones: a FIXED sheet pinned just below the header that
               never exceeds the viewport. It scrolls INTERNALLY (themed scrollbar
               above) instead of running off the bottom of the screen the way the
               old auto-height popover did. position:fixed is safe here — the
               sticky chapter head uses will-change:opacity (not transform), so it
               doesn't trap fixed descendants (verified in sticky-head). */
            .qn-panel{
                --qn-sheet-top:4.75rem;               /* clears the 50px logo + header pad; tune here */
                position:fixed;
                top:var(--qn-sheet-top);
                left:1.5rem;right:1.5rem;width:auto;  /* 1.5rem gutter each side (3rem total) */
                max-height:calc(100vh  - var(--qn-sheet-top) - 1rem);   /* fallback */
                max-height:calc(100dvh - var(--qn-sheet-top) - 1rem);
                overflow-y:auto;
                overscroll-behavior:contain;          /* sheet scroll doesn't chain to the page */
                padding:.9rem;
                z-index:100;                          /* above footnote popover (90) + FAB (70) */
            }
            .qn-chap-grid{grid-template-columns:repeat(auto-fill,minmax(40px,1fr));}
        }

        @media (max-width:560px){
            .site-header{gap:1.1rem;flex-wrap:nowrap;}
            .logo{width:40px;height:40px;border-radius:50%;flex:0 0 40px;display:block;}
            .brand{flex:0 0 50%;max-width:50%;min-width:0;gap:.5rem;}
            .wordmark{ transform:translateY(2px); }   /* mobile optical nudge — tune the px */
            .wordmark .name{font-size:1.4rem;}
            .wordmark .tld{font-size:.9rem;}
            .site-search{flex:0 0 40%;max-width:40%;width:auto;}
            .site-search-button{width:38px;height:38px;}
            .chapter-nav{--nav-drop:60%;width:40px;height:40px;}
            .chapter-nav.prev{left:.4rem;}
            .chapter-nav.next{right:.4rem;}
            .chapter-nav svg{width:22px;height:22px;}
        }  
    </style>

    @yield('styles')
</head>
<body>
<div class="container">

    {{-- ============ SHARED HEADER (every page) ============ --}}
    <header class="site-header">
        <div class="brand">
            {{-- The logo now opens the QuickNav popup (jump to any book/chapter).
                 The wordmark text is the "go home" link. $quicknav is provided to
                 this layout on every page by QuicknavComposer. --}}
            <details class="qn" id="quicknav">
                <summary class="qn-trigger" aria-label="Quick navigation — jump to any book and chapter">
                    <span class="logo" aria-hidden="true"></span>
                </summary>

                @include('bible.partials.quicknav-panel')
            </details>

            <a class="wordmark-link" href="{{ url('/') }}">
                <div class="wordmark">
                    <span class="name">MEGABIBLE<span class="tld">.net</span></span>
                </div>
            </a>
        </div>

        {{-- Site search. --}}
        <form class="site-search" role="search" aria-label="Search MegaBible"
              action="{{ route('search') }}" method="GET">
            <input class="site-search-input" type="search" name="q" placeholder="Search…"
                   aria-label="Search MegaBible" autocomplete="off"
                   value="{{ session('searched_query', request('q', '')) }}">
            <button class="site-search-button" type="submit" aria-label="Search">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle>
                    <line x1="16.5" y1="16.5" x2="21" y2="21"></line>
                </svg>
            </button>
        </form>
    </header>

    {{-- ============ PAGE CONTENT ============ --}}
    @yield('content')

    {{-- ============ SHARED FOOTER ============ --}}
    <footer>
        <div class="footer-inner">
            <div class="footer-text">
                @hasSection('footer-colophon')
                    <div class="footer-colophon">@yield('footer-colophon')</div>
                @endif
                MEGABIBLE.net &mdash; free, ad-free, donation-supported<br>
                <a href="{{ route('about') }}">About</a> &middot; <a href="{{ route('support') }}">Support</a> &middot; <a href="{{ route('privacy') }}">Privacy &amp; Terms</a>
            </div>

            <nav class="footer-social" aria-label="MEGABIBLE.net social links">
                <a href="https://www.youtube.com/@MEGABIBLE" target="_blank" rel="noopener" aria-label="MEGABIBLE.net on YouTube" title="YouTube">
                    <span class="soc-ico soc-youtube" aria-hidden="true"></span>
                </a>
                <a href="https://www.instagram.com/megabibledotnet" target="_blank" rel="noopener" aria-label="MEGABIBLE on Instagram" title="Instagram">
                    <span class="soc-ico soc-insta" aria-hidden="true"></span>
                </a>
                <a href="#" target="_blank" rel="noopener" aria-label="MEGABIBLE on TikTok" title="TikTok">
                    <span class="soc-ico soc-tiktok" aria-hidden="true"></span>
                </a>
                <a href="https://discord.gg/UGNCFD3e" target="_blank" rel="noopener" aria-label="MEGABIBLE on Discord" title="Discord">
                    <span class="soc-ico soc-discord" aria-hidden="true"></span>
                </a>
                <button type="button" aria-label="Join our newsletter" title="Join our newsletter"
                        onclick="megabibleNewsletter()">
                    <span class="soc-ico soc-email" aria-hidden="true"></span>
                </button>
            </nav>
        </div>
    </footer>

</div>

<script>
    /* MBActs — the append-only act log (mbActs.v1). One writer for every
       kind of act the site ever records; today only the scrimmage calls
       it, and vigil events are DERIVED (never logged) from mbVigil.v1.
       Capped FIFO so it can never bloat storage. If anonymous analytics
       are ever wired, the single line lives here — event NAMES only,
       never payloads. */
    window.MBActs = (function () {
        const KEY = 'mbActs.v1';
        const CAP = 1000;
        function read() {
            try { return JSON.parse(localStorage.getItem(KEY)) || []; }
            catch (e) { return []; }
        }
        function log(type, payload) {
            const ev = Object.assign({ t: type, ts: Date.now() }, payload || {});
            try {
                const list = read();
                list.push(ev);
                if (list.length > CAP) list.splice(0, list.length - CAP);
                localStorage.setItem(KEY, JSON.stringify(list));
            } catch (e) { /* storage blocked or full — the act goes unrecorded */ }
            // Analytics hook (later, one line): count the act type, nothing more.
            // if (window.plausible) plausible('act:' + type);
            return ev;
        }
        return { KEY: KEY, log: log, read: read };
    })();

    // Placeholder newsletter signup.
    function megabibleNewsletter() {
        const email = window.prompt('Enter your email to join the MEGABIBLE.net newsletter:');
        if (email) {
            window.alert('Thanks! We\'ll add ' + email + ' to the newsletter soon. (Not wired up yet.)');
        }
    }

    // Translation switcher: click-away closes it (the <details> handles
    // open/close, and Escape now lives in shortcuts.js).
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.tx[open]').forEach(function (d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });

    // QuickNav: one popup can now appear in more than one place (the header logo
    // and the book-name header in the readers). Each .qn <details> handles its
    // own open/close natively; this wires the two-screen swap (books ⇄ chapters),
    // builds chapter buttons on demand, and does click-away / Escape / reset.
    //
    // A .qn may declare a "home" chapter screen via data-open-* (the book-name
    // trigger): it opens straight to that book's chapters instead of the grid.
    (function () {
        const navs = document.querySelectorAll('.qn');
        if (!navs.length) return;

        navs.forEach(function (qn) {
            const panel     = qn.querySelector('.qn-panel');
            const backBtn   = qn.querySelector('.qn-back');
            const chapTitle = qn.querySelector('.qn-chap-title');
            const chapGrid  = qn.querySelector('.qn-chap-grid');
            if (!panel) return;

            // Book-name trigger? Then its home screen is this book's chapters.
            const home = qn.dataset.openChapters ? {
                name:  qn.dataset.openName || '',
                title: qn.dataset.openTitleUrl || '#',   // the book hub link
                base:  qn.dataset.openBase || '',        // chapter = base + '/' + n
                count: parseInt(qn.dataset.openChapters, 10) || 0,
                offset: parseInt(qn.dataset.openChapterOffset, 10) || 0,
            } : null;

            function showBooks() {
                qn.classList.remove('show-chapters');
                panel.scrollTop = 0;
            }

            // offset is added to the DISPLAYED chapter number only (150 for the
            // Five Psalms of David → 151..155); the href keeps the real n.
            function openChapters(name, titleUrl, chapterBase, count, offset) {
                offset = offset || 0;
                chapTitle.textContent = name;
                chapTitle.href = titleUrl;
                chapGrid.innerHTML = '';             // rebuild fresh each time
                for (let n = 1; n <= count; n++) {
                    const a = document.createElement('a');
                    a.className = 'qn-chap';
                    a.textContent = n + offset;
                    a.href = chapterBase + '/' + n;
                    chapGrid.appendChild(a);
                }
                qn.classList.add('show-chapters');
                panel.scrollTop = 0;
            }

            // Reset to this instance's home screen: the grid, or (book trigger)
            // its own book's chapters.
            function resetHome() {
                if (home) openChapters(home.name, home.title, home.base, home.count, home.offset);
                else      showBooks();
            }

            // Delegated click on a book button (Screen 1). Book buttons always
            // land in the single reader — data-url is the book's single-view hub.
            qn.addEventListener('click', function (e) {
                const book = e.target.closest('.qn-book');
                if (!book || book.classList.contains('qn-soon')) return;

                const url    = book.dataset.url;
                const count  = parseInt(book.dataset.chapters, 10) || 0;
                const offset = parseInt(book.dataset.chapterOffset, 10) || 0;

                // Single-chapter books jump straight to chapter 1 — no chapter screen.
                if (count <= 1) { window.location.href = url + '/1'; return; }

                openChapters(book.dataset.name, url, url, count, offset);
            });

            // Back button returns to the book grid.
            if (backBtn) backBtn.addEventListener('click', showBooks);

            // On open: close any other open quicknav, then land on the home
            // screen. Resetting on close too pre-arranges the home screen while
            // hidden, so the next open never flashes a stale screen.
            qn.addEventListener('toggle', function () {
                if (qn.open) {
                    navs.forEach(function (other) {
                        if (other !== qn) other.removeAttribute('open');
                    });
                }
                resetHome();
            });
        });

        // Click outside any open quicknav closes it.
        document.addEventListener('click', function (e) {
            navs.forEach(function (qn) {
                if (qn.open && !qn.contains(e.target)) qn.removeAttribute('open');
            });
        });

        // Escape handling now lives in shortcuts.js (closes one level at a time).
    })();

    // Chapter-nav arrows: they float at --nav-drop (70% down the viewport) so
    // they stay reachable while you read. On very short chapters the footer sits
    // high on the screen, and the arrows would otherwise be drawn *below* it. We
    // measure the footer and, when it's in the way, lower the arrows' ceiling so
    // they tuck into the gap between the last verse and the footer instead.
    (function () {
        const arrows = document.querySelectorAll('.chapter-nav');
        const footer = document.querySelector('footer');
        if (!arrows.length || !footer) return;   // no-op on pages without arrows

        // Breathing room (px) between the bottom of an arrow and the footer's
        // top rule. Turn this UP to push the arrows higher off the footer on
        // short chapters; DOWN toward 0 to let them sit closer. This is the
        // value to tweak — check both mobile and desktop after changing it.
        const FOOTER_GAP = 16;

        function clampArrows() {
            const half      = arrows[0].offsetHeight / 2;          // 24px desktop / 20px mobile
            const footerTop = footer.getBoundingClientRect().top;  // footer top, in viewport px
            const ceiling   = footerTop - FOOTER_GAP - half;       // highest the arrows' centre may go
            arrows.forEach(a => a.style.setProperty('--nav-ceiling', ceiling + 'px'));
        }

        // Re-clamp on load, resize, and scroll. requestAnimationFrame keeps the
        // scroll handler from doing layout work more than once per frame.
        let ticking = false;
        function onEvent() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () { clampArrows(); ticking = false; });
        }

        clampArrows();
        window.addEventListener('resize', onEvent, { passive: true });
        window.addEventListener('scroll',  onEvent, { passive: true });
        })();
</script>

{{-- Themed alert/confirm replacement (mbNotify / mbConfirm / mbDialog). Loaded
     before shortcuts.js and before @yield('scripts') so every page — and every
     page-level script block — can call it. Like shortcuts.js, the filemtime
     cache-bust means public/js/dialog.js MUST exist on disk before this renders. --}}
<script src="{{ asset('js/dialog.js') }}?v={{ filemtime(public_path('js/dialog.js')) }}" defer></script>

{{-- Pericope: the localStorage store (foundational — hub/board pages will use
     it too) then the "Add to Pericope" sheet. Store first so the sheet can use
     it; both defer, so they run before the reader's focus-synthesis.js. --}}
<script>window.MB_PERICOPE_BASE = @json(route('extras.pericope'));</script>
<script src="{{ asset('js/pericope-store.js') }}?v={{ filemtime(public_path('js/pericope-store.js')) }}" defer></script>
<script src="{{ asset('js/pericope-sheet.js') }}?v={{ filemtime(public_path('js/pericope-sheet.js')) }}" defer></script>

{{-- Global desktop keyboard shortcuts (j/k + arrows, t, /, Escape). Loaded on
     every page; it no-ops on pages without chapter arrows or a search box.
     The filemtime cache-bust means this file MUST exist on disk before this
     Blade renders — deploy public/js/shortcuts.js first. --}}
<script src="{{ asset('js/shortcuts.js') }}?v={{ filemtime(public_path('js/shortcuts.js')) }}" defer></script>

@yield('scripts')
</body>
</html>