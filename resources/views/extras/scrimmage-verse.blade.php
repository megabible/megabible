@extends('layouts.app')

@section('title', ($daily ? 'Daily Scrimmage — ' : '') . $scrim['reference'] . ' (' . strtoupper($scrim['translation']) . ') — Typing Scrimmage — MEGABIBLE.net')

{{--
  =====================================================================
  THE SCRIM  ·  /extras/scrimmage/{t}/{b}/{c}/{v}
  ---------------------------------------------------------------------
  One verse, the universal 20-second clock. The verse WRAPS — finish it
  and it repeats — and v2 scoring pays escalating wrap bonuses plus a
  perfect-round multiplier (see DifficultyRater; the estimate below
  mirrors it exactly). Points are presented as MARKS (cosmetic name
  only; the code and DB still say score).

  SERVER-RENDERED FIRST PAINT. The controller resolves the challenge
  and hands this blade the whole payload — reference, every edition's
  text, difficulty, and sealed tokens — as ONE variable. The header,
  clock, and typebox are in the initial HTML; the first round is ready
  before any fetch happens. (The old single-blade page hid everything
  until JS chose a screen, then waited on /challenge to paint — that
  was the refresh flash.)

  ONE SCRIM PER VERSE, EVERY EDITION INSIDE IT. The payload's
  `variants` array carries each translation with its own text,
  difficulty modifier, and sealed token, so the switcher swaps
  editions instantly — no reload, no flicker. The challenge key is
  translation-agnostic (see Challenge::canonical), so ALL editions
  share one SCRIMBOARD; each row shows its edition in the TR column,
  and the edition's difficulty rides in its per-text modifier.

  TOKEN FRESHNESS. Tokens are minted when this HTML is generated, so a
  page left open a long time holds a stale one. The server's TTL
  rejection is never shown to the user: submitScore re-mints silently
  via /challenge and resubmits once. (Retype-free — the round's raw
  counts are unchanged; only the envelope was old.)

  TOP-RIGHT CORNER: the share button (phase 1 = link + copy) beside
  the Aa text-settings trigger.

  DIAL NAMES. A name is exactly FOUR characters, A–Z / 0–9, picked on
  four dials in the claim row — a carat above and below each, click
  (or hold) to cycle. The dials also take the keyboard: arrows spin
  and hop, typing a letter sets a dial and advances, Enter claims.
  Dials pre-set to a random 4-letter Bible name (config
  typing.default_names), so Enter alone claims under a default.
  The server holds the same contract: names failing [A-Z0-9]{4} are
  422'd regardless of what the client sends.

  ONE NAME PER BOARD — THE TAKEOVER. A board holds ONE row per name;
  a better score under a held name SEIZES the seat (claim_count bumps,
  first_claimed_at survives — the popover tells the name's history).
  A WORSE score is never an error: the server answers held:true with
  the defender's numbers and the DUEL PLAQUE renders under the board —
  beat the score or dial a different name. Ties keep the incumbent.

  NIGHTLY TRIM + SURVIVORS. scrim:trim cuts every board over
  typing.board_size down to its top rows at midnight Mountain time;
  survivors carry a stamp the board renders as the ★ champion mark,
  and — only when the knife actually fell last night — the
  "yesterday's top 10" caption. Intra-day the board accepts up to
  typing.board_cap rows; a full board only admits a score that beats
  its floor.

  CENSORED NAMES render struck-through and blurred with their config
  replacement beside them (typing.censor). The DB keeps what was
  typed; the map is consulted at serve time, so a config edit
  retro-censors live boards.

  THE SCRIMBOARD renders in two phases: while the claim row awaits a
  name it's a compact 3-column table (# / Name / Marks) showing the
  top rows plus YOUR glowing dial row slotted at its real rank —
  nothing to overflow on mobile. Once claimed it becomes the full
  table (# / Name / Net WPM / Acc / TR / Marks), refreshed IN PLACE
  so the confirm never flashes. Hovering a row floats a
  footnote-style popover (date · errors · combo · wraps, plus the
  name's holder history) on fine-pointer devices.
  =====================================================================
--}}
@section('styles')
<style>
    /* ---- CENSOR KNOBS ------------------------------------------------------
       One place for every censored name on the page: board rows, the duel
       plaque, and the rank line all render through nameHtml().
         blur      : raise until the word is unreadable at a glance (2–5px).
         fade      : how far the word recedes behind the strike.
         thickness : weight of the strike line.
         nudge     : strike height. Negative lifts it; caps read best a hair
                     above the box's true middle, hence the default. */
    .sc-page {
        --sc-cens-blur:      1.5px;
        --sc-cens-fade:      .55;
        --sc-cens-thickness: 3px;
        --sc-cens-nudge:     -.05em;
    }

    /* This page's [hidden] toggles must always win — display:flex on a class
       otherwise outranks the browser's built-in [hidden]{display:none}. */
    .sc-page [hidden] { display: none !important; }

    /* ---- Corner slot: share + Aa ------------------------------------------
       Absolutely anchored to .sc-head's top-right — the reader's .head-actions
       pattern — instead of floating at the top of the page. A wrapping
       reference can never shove the buttons around, and the .55rem offset is
       the same number the reader and the vigil use, so all three pages agree.
       POSITION KNOBS: top / right. right:0 = the container content edge (this
       head is not full-bleed, so no -1.5rem compensation is needed). */
    .sc-head .sc-settings-slot {
        position: absolute; top: .55rem; right: 0; z-index: 60;
        display: flex; gap: .5rem; align-items: center;
    }

    .sc-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: 1.1rem 1.2rem 1.2rem;
        background: var(--bg);
        font-family: var(--sans);
    }
    .sc-label {
        display: block; font-size: .72rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); margin: 0 0 .35rem;
    }
    .sc-input {
        font-family: var(--sans); font-size: .95rem;
        color: var(--ink); background: var(--bg);
        border: 1px solid var(--rule); border-radius: 6px;
        padding: .5rem .65rem;
    }
    .sc-input:focus { outline: none; border-color: var(--accent); }

    /* ---- Header: verse H1 on top, mustache + switcher below ---------------
       position:relative makes this the anchor for the corner slot above.
       RESERVE KNOB: padding-right ≈ corner cluster width (2 buttons here). */
    .sc-head { position: relative; margin: 0 0 .7rem; padding-right: 6.5rem; }
    .sc-head h1 {
        font-family: var(--serif); font-size: 2.1rem; font-weight: 400;
        margin: 0 0 .2rem; letter-spacing: -.01em;
    }
    /* MUSTACHE — under the title, so the title starts flush with the top of
       the head and the corner buttons line up with it. */
    .sc-mode {
        display: block;
        color: var(--accent); font-family: var(--sans);
        font-size: .76rem; font-weight: 700;
        letter-spacing: .12em; text-transform: uppercase;
        margin: 0;
    }
    /* The H1 IS a link into the reader — quiet until hovered. */
    .sc-title-link { color: inherit; text-decoration: none; transition: color .12s; }
    .sc-title-link:hover { color: var(--accent); }

    #sc-txswitch { margin-top: .35rem; min-height: 1.6rem; }
    /* Single-edition verse: the pill renders but isn't a dropdown. */
    #sc-txswitch .tx-solo { cursor: default; }

    .sc-back {
        display: inline-block; font-family: var(--sans); font-size: .82rem;
        color: var(--muted); text-decoration: none; margin-bottom: .6rem;
    }
    .sc-back:hover { color: var(--accent); }

    /* Stat line: live face (clock + counters + combo) ⇄ done face (results) */
    .sc-statline { font-family: var(--sans); margin-bottom: .8rem; }

    .sc-stat-live { display: flex; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
    .sc-clock {
        font-size: 3rem; font-weight: 700; color: var(--accent);
        font-variant-numeric: tabular-nums; line-height: 1;
    }
    .sc-clock.is-ending { animation: sc-pulse .5s ease-in-out infinite; }
    @keyframes sc-pulse { 50% { opacity: .45; } }
    .sc-clock-unit { font-size: .82rem; color: var(--muted); }
    .sc-live { color: var(--muted); font-size: .82rem; font-variant-numeric: tabular-nums; }
    .sc-live b { color: var(--ink); }

    /* Combo badge: PERFECT ×n while clean, breaks on the first error. */
    .sc-combo {
        font-size: .74rem; font-weight: 700; letter-spacing: .06em;
        color: var(--accent); border: 1px solid var(--accent);
        border-radius: 999px; padding: .2rem .7rem;
        transition: opacity .3s, color .2s, border-color .2s;
    }
    .sc-combo.is-broken { color: var(--muted); border-color: var(--rule); }

    /* Done face: marks on the first line, the stat chips on their own line. */
    .sc-stat-done { display: flex; flex-direction: column; align-items: flex-start; gap: .55rem; }
    .sc-final-line { display: inline-flex; align-items: baseline; }
    .sc-final { font-size: 3rem; font-weight: 700; color: var(--accent); line-height: 1; font-variant-numeric: tabular-nums; }
    .sc-final-unit {
        font-family: var(--sans); font-size: .85rem; font-weight: 600;
        color: var(--muted); margin-left: .35rem;
        letter-spacing: .04em;
    }
    .sc-chips { display: flex; gap: .45rem; flex-wrap: wrap; }
    .sc-chip {
        font-size: .74rem; color: var(--muted);
        border: 1px solid var(--rule); border-radius: 999px;
        padding: .25rem .7rem; font-variant-numeric: tabular-nums;
    }
    .sc-chip b { color: var(--ink); }
    .sc-chip.bonus b { color: var(--accent); }

    /* ---- Typebox + gamefeel layer ----------------------------------------- */
    .sc-typewrap { position: relative; }
    /* Obeys the reader's text settings (size, spacing, serif/sans, theme);
       ×1.1 keeps the scrim target a touch more prominent than body reading. */
    .sc-typebox {
        font-family: var(--reading-family);
        font-size: calc(var(--reading-size) * 1.1);
        line-height: var(--reading-leading);
        position: relative; cursor: text;
        transition: opacity .3s ease;
    }
    .sc-ch { position: relative; white-space: pre-wrap; color: var(--soon); }
    .sc-ch.ok { color: var(--ink); }
    .sc-ch.cur::before {
        content: ""; position: absolute;
        left: -1px; top: .08em; bottom: .08em; width: 2px;
        background: var(--accent);
        animation: sc-blink 1s steps(2) infinite;
    }
    @keyframes sc-blink { 50% { opacity: 0; } }

    /* Bad keystroke: the current character judders left-right. Animated via
       `left`, NOT transform — .sc-ch is an inline box, and transforms are
       ignored on inline elements (which is why the old shake never showed). */
    .sc-ch.err { animation: sc-shake .2s ease; color: var(--accent); }
    @keyframes sc-shake {
        20%, 60% { left: -2.5px; }
        40%, 80% { left: 2.5px; }
    }
    @media (prefers-reduced-motion: reduce) { .sc-ch.err { animation: none; } }

    /* Round-complete overlay: translucent "⟳ SCRIM" stamp over the dimmed
       text (verse stays readable through it); solid on hover; whole layer
       is the rerun click target. */
    .sc-typewrap.is-done .sc-typebox { opacity: .4; }
    .sc-typewrap.is-done .sc-ch.cur::before { display: none; }
    .sc-done-stamp {
        position: absolute; inset: 0; z-index: 4;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
    }
    .sc-done-pill {
        display: inline-flex; align-items: center; gap: .5rem;
        font-family: var(--sans); font-size: .85rem; font-weight: 700;
        letter-spacing: .1em;
        color: var(--accent);
        background: color-mix(in srgb, var(--panel) 60%, transparent);
        border: 1px solid var(--accent); border-radius: 999px;
        padding: .5rem 1.2rem;
        box-shadow: 0 4px 16px rgba(0, 0, 0, .15);
        transition: background .15s ease;
    }
    .sc-done-stamp:hover .sc-done-pill { background: var(--panel); }
    .sc-done-pill svg { width: 16px; height: 16px; display: block; }

    /* ---- Daily dress ---------------------------------------------------
       The three banner states (fresh / played / stale) share one look; JS
       shows exactly one at a time via showDailyState(). */
    .sc-daily {
        border: 1px solid var(--rule); border-left: 3px solid var(--accent);
        border-radius: 8px;
        padding: .8rem 1rem;
        margin: 0 0 1.1rem;
        font-family: var(--sans); font-size: .86rem; line-height: 1.5;
        color: var(--ink); background: var(--bg);
    }
    .sc-daily-flag {
        display: inline-block;
        font-size: .62rem; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: var(--accent);
        border: 1px solid var(--accent); border-radius: 3px;
        padding: .1rem .4rem; margin-right: .5rem;
        vertical-align: 1px;
    }
    /* The curator's line — server-rendered, escaped by Blade on the way in. */
    .sc-daily-note { margin-top: .35rem; font-style: italic; color: var(--muted); }
    .sc-daily-links { margin-top: .45rem; }
    .sc-daily-links a { color: var(--accent); text-decoration: none; margin-right: 1.1rem; }
    .sc-daily-links a:hover { text-decoration: underline; }

    /* The sealed-board line inside the board card. */
    .sc-sealed-note {
        font-family: var(--sans); font-size: .84rem; color: var(--muted);
        padding: .55rem .2rem; font-style: italic;
    }
    .sc-sealed-note b { color: var(--ink); font-style: normal; }

    .sc-fullboard-link {
        font-family: var(--sans); font-size: .82rem;
        padding: .5rem .2rem 0; text-align: right;
    }
    .sc-fullboard-link a { color: var(--accent); text-decoration: none; }
    .sc-fullboard-link a:hover { text-decoration: underline; }

    /* ---- The sabbath -----------------------------------------------------
       Same frame as the daily banner: this is the other day-shaped thing
       that changes what a round MEANS. */
    .sc-sabbath {
        border: 1px solid var(--rule); border-left: 3px solid var(--accent);
        border-radius: 8px;
        padding: .8rem 1rem;
        margin: 0 0 1.1rem;
        font-family: var(--sans); font-size: .86rem; line-height: 1.5;
        color: var(--ink); background: var(--bg);
    }
    .sc-sabbath-flag {
        display: inline-block;
        font-size: .62rem; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: var(--accent);
        border: 1px solid var(--accent); border-radius: 3px;
        padding: .1rem .4rem; margin-right: .5rem; vertical-align: 1px;
    }
    .sc-sabbath-links { margin-top: .45rem; }
    .sc-sabbath-links a { color: var(--accent); text-decoration: none; margin-right: 1.1rem; }
    .sc-sabbath-links a:hover { text-decoration: underline; }

    /* The rest-day message where the board would be. */
    .sc-rested {
        font-family: var(--sans); font-size: .84rem; color: var(--muted);
        font-style: italic; padding: .55rem .2rem;
    }

    /* Rising toast on each wrap — the little "that was worth more" moment. */
    .sc-toast {
        position: absolute; z-index: 5;
        font-family: var(--sans); font-size: .9rem; font-weight: 700;
        color: var(--accent); pointer-events: none; white-space: nowrap;
        animation: sc-rise .9s ease-out forwards;
    }
    @keyframes sc-rise {
        from { opacity: 1; transform: translateY(0); }
        to   { opacity: 0; transform: translateY(-2.2rem); }
    }
    @media (prefers-reduced-motion: reduce) { .sc-toast { animation-duration: .01s; } }

    .sc-capture {
        position: absolute; width: 1px; height: 1px; top: 0; left: 0;
        opacity: 0; pointer-events: none; border: 0; padding: 0; resize: none;
    }
    .sc-play-hint { font-family: var(--sans); font-size: .8rem; color: var(--muted); margin-top: 1rem; }

    /* ---- Scrimboard (post-round only) -------------------------------------- */
    #sc-boardcard { margin-top: 1.8rem; }
    #sc-board-body { overflow-x: auto; }
    .sc-board table { width: 100%; border-collapse: collapse; font-family: var(--sans); font-size: .85rem; }
    .sc-board th {
        text-align: left; font-size: .68rem; text-transform: uppercase;
        letter-spacing: .06em; color: var(--muted); font-weight: 600;
        padding: .3rem .5rem; border-bottom: 1px solid var(--rule);
    }
    .sc-board td { padding: .4rem .5rem; border-bottom: 1px solid var(--rule); font-variant-numeric: tabular-nums; }
    .sc-board td.num, .sc-board th.num { text-align: right; }
    .sc-board .empty { color: var(--muted); font-style: italic; padding: .6rem .5rem; }
    .sc-board .gap td { color: var(--muted); border-bottom: 0; padding: .15rem .5rem; }

    /* Dial names are always 4 characters — a whisper of tracking reads well. */
    .sc-board td.sc-nm { letter-spacing: .04em; }

    /* Champion mark: this row survived a nightly trim; stands until unseated. */
    .sc-held { color: var(--accent); margin-right: .3rem; font-size: .8em; cursor: default; }

    /* Caption under the board title — ONLY when a trim actually fell last
       night (server decides; see trimmed_last_night). */
    .sc-board-note {
        font-family: var(--sans); font-size: .76rem; color: var(--muted);
        margin: -.1rem 0 .45rem;
    }

    /* OUTER: never blurred. Carries the strike and the copy-protection.
       No trailing margin — nothing follows a censored name any more, and a
       margin here would just pad the board's Name column. */
    .sc-cens {
        position: relative;
        display: inline-block;
        letter-spacing: .05em;
        user-select: none;
    }
    /* INNER: the only thing that blurs. */
    .sc-cens-word {
        display: inline-block;
        filter: blur(var(--sc-cens-blur));
        opacity: var(--sc-cens-fade);
    }
    /* THE STRIKE: drawn by the clean parent, so it stays razor-sharp no
       matter how high the blur goes. Placed with margin-top rather than a
       transform — house rule, and one less inline-element trap to remember.
       The negative left/right overhang lets the line clear the blur halo,
       which bleeds a few px past the glyphs. */
    .sc-cens::after {
        content: "";
        position: absolute;
        left: -.08em; right: -.08em;
        top: 50%;
        margin-top: calc(var(--sc-cens-thickness) / -2 + var(--sc-cens-nudge));
        height: var(--sc-cens-thickness);
        background: var(--accent);
        border-radius: 1px;
        pointer-events: none;
    }
    .sc-cens-alt { font-weight: 600; }

    /* YOUR row — the claim row before submitting, your placed row after.
       (tr box-shadow is the glow; the td borders + tint are the Safari-proof
       fallback, since shadows on collapsed table rows are flaky there.) */
    .sc-board tr.entry, .sc-board tr.mine {
        box-shadow: 0 0 10px 2px color-mix(in srgb, var(--accent) 40%, transparent);
    }
    .sc-board tr.entry td, .sc-board tr.mine td {
        background: color-mix(in srgb, var(--accent) 8%, var(--bg));
        border-top: 1px solid var(--accent);
        border-bottom: 1px solid var(--accent);
    }
    .sc-board tr.entry td.sc-name-cell { white-space: nowrap; }

    /* ---- The four dials -----------------------------------------------------
       Each column: carat up / character cell / carat down. Click or HOLD a
       carat to cycle A–Z0–9; the cells take the keyboard too (arrows spin
       and hop, typing sets + advances, Enter claims).
       SIZE KNOBS: --sc-dial-w (column width), the cell height, carat height. */
    .sc-dials {
        --sc-dial-w: 32px;
        display: inline-flex; gap: .35rem; vertical-align: middle;
    }
    .sc-dial { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .sc-dial-btn {
        width: var(--sc-dial-w); height: 20px; padding: 0;
        display: flex; align-items: center; justify-content: center;
        background: none; border: 0; border-radius: 5px;
        color: var(--muted); cursor: pointer;
        touch-action: manipulation;      /* no double-tap zoom while spinning */
        -webkit-tap-highlight-color: transparent;
    }
    .sc-dial-btn:hover  { color: var(--accent); }
    .sc-dial-btn:active { color: var(--accent); }
    .sc-dial-btn svg { width: 15px; height: 15px; display: block; }
    .sc-dial-ch {
        width: var(--sc-dial-w); height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-family: var(--sans); font-size: 1.05rem; font-weight: 700;
        color: var(--ink); background: var(--bg);
        border: 1px solid var(--rule); border-radius: 6px;
        user-select: none; cursor: default;
        font-variant-numeric: tabular-nums;
    }
    .sc-dial-ch:focus {
        outline: none; border-color: var(--accent);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 25%, transparent);
    }
    /* Spin feedback: a tiny hop in the spin direction. */
    .sc-dial-ch.bump-up { animation: sc-bump-up .14s ease; }
    .sc-dial-ch.bump-dn { animation: sc-bump-dn .14s ease; }
    @keyframes sc-bump-up { 40% { transform: translateY(-3px); } }
    @keyframes sc-bump-dn { 40% { transform: translateY(3px); } }
    @media (prefers-reduced-motion: reduce) {
        .sc-dial-ch.bump-up, .sc-dial-ch.bump-dn { animation: none; }
    }

    /* The claim check: inline right beside the dials, breathing until
       clicked. */
    .sc-claim {
        width: 30px; height: 30px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        vertical-align: middle; margin-left: .5rem;
        color: #fff; background: var(--accent);
        border: 1px solid var(--accent); cursor: pointer;
        box-shadow: 0 0 10px 2px color-mix(in srgb, var(--accent) 45%, transparent);
        animation: sc-claim-pulse 1.5s ease-in-out infinite;
    }
    .sc-claim svg { width: 15px; height: 15px; display: block; }
    .sc-claim:disabled { animation: none; opacity: .55; cursor: default; }
    @keyframes sc-claim-pulse {
        0%, 100% { transform: scale(1); }
        50%      { transform: scale(.84); }
    }
    @media (prefers-reduced-motion: reduce) { .sc-claim { animation: none; } }

    .sc-rank-msg { font-family: var(--sans); font-size: .85rem; margin-top: .6rem; min-height: 1.2em; }

    /* ---- The duel plaque -----------------------------------------------------
       Shown when a submitted score fails to unseat the name's holder. Not an
       error, not a system prompt — a challenge. Slides in under the board. */
    .sc-duel {
        margin-top: .8rem;
        padding: .8rem 1rem;
        border: 1px solid var(--accent);
        border-left-width: 4px;
        border-radius: 8px;
        background: color-mix(in srgb, var(--accent) 6%, var(--bg));
        font-family: var(--sans); font-size: .85rem; color: var(--muted);
        animation: sc-duel-in .25s ease;
    }
    @keyframes sc-duel-in {
        from { opacity: 0; transform: translateY(-4px); }
    }
    @media (prefers-reduced-motion: reduce) { .sc-duel { animation: none; } }
    .sc-duel b { color: var(--ink); }
    .sc-duel-head {
        display: flex; align-items: baseline; gap: .45rem; flex-wrap: wrap;
        color: var(--ink); font-weight: 600; margin-bottom: .3rem;
    }
    .sc-duel-swords { color: var(--accent); }
    .sc-duel-sub  { font-size: .78rem; line-height: 1.5; }
    .sc-duel-hist { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* ---- Row-detail popover: the footnote panel's dress, minus the click.
       (Mirrors .fn-pop in reading-styles; non-interactive, so no hover
       keep-alive dance is needed.) ---------------------------------------- */
    .sc-pop {
        position: absolute; z-index: 90;
        padding: .55rem .7rem;
        background: var(--bg);
        border: 1px solid var(--rule);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .16);
        font-family: var(--sans);
        font-size: .8rem;
        line-height: 1.45;
        color: var(--muted);
        white-space: nowrap;
        pointer-events: none;
        font-variant-numeric: tabular-nums;
    }
    .sc-pop b { color: var(--ink); font-weight: 600; }
    .sc-pop::after {
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
</style>
@endsection

@section('content')
<div class="sc-page">
    <div class="sc-head">
        {{-- Corner cluster: share (this page has something to share) beside
             the Aa trigger, pinned to the head's top-right so it sits level
             with the H1 the way the reader's does. Visibility checks
             suppressed — no verse numbers, headings, or footnotes here. --}}
        <div class="sc-settings-slot">
            @include('bible.partials.scrimmage-share')
            @include('bible.partials.text-settings', ['tsChecks' => false])
        </div>

        {{-- Verse-reference H1 (a live link into the reader), mode mustache
             beneath it, switcher below that. All server-rendered: no JS is
             needed for the header to exist. --}}
        <h1><a class="sc-title-link" id="sc-title" href="{{ $readerUrl }}">{{ $scrim['reference'] }}</a></h1>
        <span class="sc-mode">{{ $daily ? 'Daily Scrimmage — ' . $daily['label'] : 'Typing Scrimmage' }}</span>
        <div id="sc-txswitch"></div>
    </div>

    <a class="sc-back" href="{{ route('typing.scrimmage') }}">&larr; {{ $daily ? 'Scrimmage builder' : 'Build a new scrimmage' }}</a>

    @if ($sabbath)
    {{-- ANNOUNCED BEFORE THE ROUND, never after. The server refuses sabbath
         scores regardless; this is the page being honest in advance rather
         than letting a player find out by being turned away. --}}
    <div class="sc-sabbath">
        <span class="sc-sabbath-flag">Sabbath</span>
        The racers rest on the Sabbath.
        <div class="sc-sabbath-links">
            <a href="{{ route('typing.scrimmage.daily.archive') }}">The daily archive &rarr;</a>
        </div>
    </div>
    @endif

    @if ($daily)
    {{-- THE THREE BANNER STATES. Server renders all three, "fresh" showing;
         JS (bootDaily) swaps to played/stale from the one-shot flag and the
         rollover clock. The one-shot warning lives HERE, before the first
         keystroke — a rule announced after the round would be a trap. --}}
    <div class="sc-daily" id="sc-daily-banner">
        <span class="sc-daily-flag">Daily</span>
        <p>The whole earth types this verse today.</p>
        @if ($daily['note'])
            <div class="sc-daily-note">&ldquo;{{ $daily['note'] }}&rdquo;</div>
        @endif
        <div class="sc-daily-links">
            <a href="{{ $daily['practiceUrl'] }}">Type the regular scrim &rarr;</a>
        </div>
    </div>

    <div class="sc-daily" id="sc-daily-played" hidden>
        <span class="sc-daily-flag">complete</span>
        <p>You have submitted a daily record: <b id="sc-daily-mymarks">&mdash;</b>.</p>
        <div class="sc-daily-links">
            <a href="{{ $daily['practiceUrl'] }}">Type the regular scrim &rarr;</a>
            <a href="{{ route('typing.scrimmage.daily.archive') }}">Past dailies &rarr;</a>
        </div>
    </div>

    <div class="sc-daily" id="sc-daily-stale" hidden>
        <span class="sc-daily-flag">fin</span>
        A new daily is upon us.
        <div class="sc-daily-links">
            <a href="{{ route('typing.scrimmage.daily') }}">Go to today&rsquo;s daily &rarr;</a>
            <a href="{{ route('typing.scrimmage.daily.archive') }}">The archive &rarr;</a>
        </div>
    </div>
    @endif

    <div class="sc-statline">
        {{-- Live face: the clock, counters, and the combo badge. --}}
        <div class="sc-stat-live" id="sc-stat-live">
            <span><span class="sc-clock" id="sc-clock">{{ $scrim['duration'] }}</span>
            <span class="sc-clock-unit">seconds</span></span>
            <span class="sc-live">chars <b id="sc-live-chars">0</b> &middot; errors <b id="sc-live-errors">0</b></span>
            <span class="sc-combo" id="sc-combo" hidden></span>
        </div>
        {{-- Done face: marks line, then the chips on their own line. --}}
        <div class="sc-stat-done" id="sc-stat-done" hidden>
            <span class="sc-final-line">
                <span class="sc-final" id="sc-final">&mdash;</span>
                <span class="sc-final-unit">marks</span>
            </span>
            <span class="sc-chips" id="sc-chips"></span>
        </div>
    </div>

    <div class="sc-typewrap" id="sc-typewrap">
        {{-- The verse is in the HTML. JS re-renders it as per-character spans
             on boot, but there is never an empty frame. --}}
        <div class="sc-typebox" id="sc-typebox">{{ $scrim['text'] }}</div>
        {{-- Round-complete stamp: translucent, solid on hover, reruns on click. --}}
        <div class="sc-done-stamp" id="sc-done" hidden>
            <span class="sc-done-pill">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                    <path d="M21 3v5h-5"></path>
                </svg>SCRIM
            </span>
        </div>
        <textarea class="sc-capture" id="sc-capture"
                  autocomplete="off" autocorrect="off" autocapitalize="off"
                  spellcheck="false" aria-label="Typing input"></textarea>
    </div>
    {{-- Pre-round coaching only: hidden the moment the round completes. --}}
    <p class="sc-play-hint" id="sc-play-hint">@if ($sabbath){{
        'Clock counts down on first keystroke. Esc to reset.'
    }}@elseif ($daily){{
        'Clock counts down on first keystroke. Esc to reset.'
    }}@else{{
        'Clock counts down on first keystroke. Esc to reset.'
    }}@endif</p>

    {{-- The SCRIMBOARD — hidden until the round finishes; hidden again on rerun.
         The note appears only when last night's trim actually fell on this
         board; the duel plaque only when a held name repels a challenge. --}}
    <div class="sc-card sc-board" id="sc-boardcard" hidden>
        <span class="sc-label" id="sc-board-title">@if ($sabbath){{
            'The racers rest'
        }}@elseif ($daily){{
            'Daily board hidden until midnight'
        }}@else{{
            'Scrimboard for ' . $scrim['reference']
        }}@endif</span>
        {{-- The crown caption. The trim is WEEKLY now, so this stands all
             week rather than for a day (server side: trimmedSinceLastCut). --}}
        <div class="sc-board-note" id="sc-board-note" hidden><span class="sc-held">&#9733;</span>Last week&rsquo;s top {{ (int) config('typing.board_size') }} &mdash; crowned at the sabbath cut, standing until unseated.</div>
        <div id="sc-board-body"></div>
        <div class="sc-duel" id="sc-duel" hidden></div>
        <div class="sc-rank-msg" id="sc-rank"></div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        'use strict';

        /* ---- Server constants (single-variable json only) ----------------
           SCRIM is the whole resolved challenge: identity, duration, and a
           `variants` array carrying every edition's text, difficulty, and
           sealed token. Nothing is fetched to paint this page. */
        const SCRIM         = @json($scrim);
        const DEFAULT_NAMES = @json($defaultNames);
        const CSRF          = @json(csrf_token());
        const URL_RESOLVE   = @json(route('typing.challenge'));
        const URL_BOARD     = @json(route('typing.challenge.board'));
        const URL_SCORE     = @json(route('typing.score'));
        const URL_PLAYED    = @json(route('typing.played'));
        const BOARD_SIZE    = {{ (int) config('typing.board_size') }};
        const BOARD_CAP     = {{ (int) config('typing.board_cap') }};
        /* Mirrors DifficultyRater::ERROR_CHARS (v3) — what one uncorrected
           error costs in characters, against the 5-char standard word.
           Change it there, change it here, bump VERSION there. */
        const ERROR_CHARS = 2;
        /* URL shapes from the router — placeholders swapped client-side, so
           renaming a route never means editing a path string in here. */
        const SCRIM_URL     = @json($scrimUrlPattern);
        const READER_URL    = @json($readerUrlPattern);
        /* The full-board page's URL shape, and how many rows a scrim page
           shows before deferring to it. The board endpoint still returns up
           to BOARD_CAP rows — the slice is purely presentation, so YOUR row
           can slot past the cut with its true rank intact. */
        const BOARD_PAGE    = @json($boardUrlPattern);
        const BOARD_SHOW    = {{ (int) $boardShow }};
        const BASE_TITLE    = 'Typing Scrimmage \u2014 MEGABIBLE.net';
        /* Server-decided, never computed from the browser's clock: the
           sabbath is one moment worldwide (site clock), and a device set to
           Tokyo must not observe a different day than the boards do. */
        const SABBATH       = @json($sabbath);
        /* Null on ordinary scrims. On the daily: {date, label, note,
           rolloverAtMs, practiceUrl}. date is THE SERVER'S day — the
           one-shot flag stores it verbatim, and never a client-computed
           "today" (Tokyo and Texas must spend the same date). rolloverAtMs
           is epoch, so Date.now() comparisons need no timezone maths. */
        const DAILY         = @json($daily);
        const ROLLOVER_MS   = DAILY ? DAILY.rolloverAtMs : 0;

        /* The dial alphabet — the ONLY characters a name can hold. Order is
           the spin order: A..Z then 0..9, wrapping. */
        const DIAL_SET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        const $ = function (id) { return document.getElementById(id); };
        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        /**
         * A name as it should APPEAR anywhere on this page. `censored` is the
         * server's flag (a hit against typing.censor); on a hit the name
         * renders blurred inside a clean wrapper that draws the strike, and
         * nothing else — no replacement, so the board's Name column stays
         * one name wide on narrow screens.
         *
         * role="img" + aria-label is what a screen reader gets: announcing
         * the blurred slur defeats the point, and announcing nothing would
         * leave the row nameless.
         */
        function nameHtml(name, censored) {
            if (!censored) return esc(name);
            return '<span class="sc-cens" role="img" aria-label="censored name">' +
                       '<span class="sc-cens-word" aria-hidden="true">' + esc(name) + '</span>' +
                   '</span>';
        }

        /* The rank line takes plain text on error paths and MARKUP on claim
           paths (a censored name must blur there too). Two setters, so a
           server error string can never be interpreted as HTML. */
        function rankText(s) { $('sc-rank').textContent = s; }
        function rankHtml(h) { $('sc-rank').innerHTML = h; }

        /* =================================================================
           STATE — the payload is already here; `active` is just which
           edition is currently on the typebox. claimChars is the four dial
           characters for the round's claim (reset to a random Bible-name
           default each finished round).
           ================================================================= */
        let resolved   = SCRIM;
        let active     = SCRIM.translation;
        let round      = null;    // live round counters
        let phase      = 'ready';
        let claimChars = null;    // ['E','Z','R','A'] while a claim row is up

        /* ---- The remembered handle -----------------------------------------
           A dialed name is a mask, not an account: it lives in this browser
           only, and the server never learns two scrims share a person.
           Storage throws in Safari private mode and with cookies blocked,
           so every touch is wrapped — a failure just falls back to a random
           Bible name. KEY: add it to the Acts page's clear-all list. */
        const NAME_KEY = 'mbScrim.v1';

        function readName() {
            try {
                const raw = localStorage.getItem(NAME_KEY);
                if (!raw) return null;
                const n = (JSON.parse(raw) || {}).name;
                return /^[A-Z0-9]{4}$/.test(n) ? n : null;
            } catch (e) { return null; }
        }
        function rememberName(name) {
            try {
                localStorage.setItem(NAME_KEY, JSON.stringify({ name: name }));
            } catch (e) { /* storage unavailable — the dials still work */ }
        }

        /** The dials' opening position: your last handle, else a random
            4-letter Bible name from config typing.default_names. */
        function startingName() {
            const saved = readName();
            if (saved) return Array.from(saved);
            const pool = DEFAULT_NAMES.filter(function (n) {
                return /^[A-Z0-9]{4}$/.test(n);
            });
            const pick = pool.length
                ? pool[Math.floor(Math.random() * pool.length)]
                : 'EZRA';
            return Array.from(pick);
        }

        function params() {
            return {
                t: active,
                b: resolved.params.b,
                c: resolved.params.c,
                v: resolved.params.v,
            };
        }
        function apiQuery(p) {
            // The daily carries its date; the server's ledger guard checks
            // it against daily_verses, and after midnight refuses the mint
            // (which is how a stale page's silent re-mint fails politely).
            const mode = DAILY ? 'daily' : 'scrimmage';
            const date = DAILY ? '&date=' + encodeURIComponent(DAILY.date) : '';
            return 'mode=' + mode + date + '&t=' + encodeURIComponent(p.t) +
                   '&b=' + encodeURIComponent(p.b) + '&c=' + p.c + '&v=' + p.v;
        }
        function fill(pattern, p) {
            return pattern
                .replace('__T__', encodeURIComponent(p.t))
                .replace('__B__', encodeURIComponent(p.b))
                .replace('__C__', p.c)
                .replace('__V__', p.v);
        }
        function scrimHref(p)  { return fill(SCRIM_URL, p); }
        function readerHref(p) { return fill(READER_URL, p); }

        /**
         * The share URL — this page's, plus &score= bragging once a round
         * has one. Feeds round.shareUrl and the share panel (phase 1: the
         * copyable link; phase 2 will hang the PNG card off the same hook).
         */
        function syncShare(score) {
            if (DAILY) return;               // no share panel on the daily
            let url = location.origin + scrimHref(params());
            if (score) url += '?score=' + score;
            if (round) round.shareUrl = url;
            if (window.MBScrimShare) MBScrimShare.setUrl(url);
        }

        function variantOf(slug) {
            return (resolved.variants || []).find(function (v) { return v.slug === slug; });
        }
        /** Envelope fields + one edition's text / modifier / token. */
        function roundData(slug) {
            return Object.assign({}, resolved, variantOf(slug) || {});
        }

        /* =================================================================
           DAILY MACHINERY — the one-shot flag, the pending claim, the
           three banner states, and the boot that arbitrates them.

           TWO KEYS, one rule each:

           mbDaily.v1          {date, marks} — the SPENT SHOT. Written the
                               moment a round completes (finishRound →
                               showResults), not at claim: "finish, don't
                               claim, retry" must not be a loophole. The
                               date is the SERVER'S; comparison is string
                               equality, never clock maths. This is a
                               ritual, not a lock — localStorage can be
                               cleared, and the board's one-seat-per-name
                               rule is the real backstop.

           mbDailyPending.v1   an unclaimed finished round: token + the raw
                               counters postScore needs + the display
                               numbers. Lets a reload inside the token TTL
                               restore the claim plaque instead of eating
                               the shot silently. Cleared on claim, on a
                               new date, or on expiry.

           An entry also goes to MBActs ('daily') for the Acts feed — the
           flag key is separate because the gate wants an O(1) read with a
           schema this page owns, not a scan of a growing log.

           Both keys ride the Acts page's clear-all for free (it wipes the
           whole origin).
           ================================================================= */
        const DAILY_KEY   = 'mbDaily.v1';
        const DAILY_PEND  = 'mbDailyPending.v1';
        const PEND_MAX_MS = 270000;    // 4m30s — inside the 5-min token TTL

        function dailyGateRead() {
            try {
                const g = JSON.parse(localStorage.getItem(DAILY_KEY));
                return (g && g.date) ? g : null;
            } catch (e) { return null; }
        }
        function dailySpend(marks) {
            try {
                localStorage.setItem(DAILY_KEY, JSON.stringify({
                    date:  DAILY.date,
                    marks: marks != null ? Math.round(marks * 100) / 100 : null,
                }));
            } catch (e) { /* private mode: the ritual simply isn't kept */ }
        }
        function dailyStash(net, acc) {
            try {
                localStorage.setItem(DAILY_PEND, JSON.stringify({
                    date: DAILY.date, savedAt: Date.now(),
                    token: round.data.token,
                    durationMs: round.durationMs, keystrokes: round.keystrokes,
                    errors: round.errors, chars: round.chars,
                    bestStreak: round.bestStreak, wraps: round.wraps,
                    est: round.est, net: net, acc: acc,
                }));
            } catch (e) {}
        }
        function dailyStashRead() {
            try {
                const p = JSON.parse(localStorage.getItem(DAILY_PEND));
                if (!p || p.date !== DAILY.date) return null;
                if (Date.now() - p.savedAt > PEND_MAX_MS) { dailyStashClear(); return null; }
                return p;
            } catch (e) { return null; }
        }
        function dailyStashClear() {
            try { localStorage.removeItem(DAILY_PEND); } catch (e) {}
        }

        /** Exactly one banner at a time. */
        function showDailyState(id) {
            ['sc-daily-banner', 'sc-daily-played', 'sc-daily-stale']
                .forEach(function (x) {
                    const el = $(x);
                    if (el) el.hidden = (x !== id);
                });
        }
        function showDailyPlayed(marks) {
            showDailyState('sc-daily-played');
            const el = $('sc-daily-mymarks');
            if (el) el.textContent = marks != null
                ? Number(marks).toFixed(2) + ' marks' : 'recorded';
        }

        /** The done-state without a round having run on THIS page load. */
        function dailyLock() {
            phase = 'done';
            $('sc-capture').disabled = true;
            $('sc-play-hint').hidden = true;
            $('sc-typewrap').classList.add('is-done');
        }

        /**
         * A reload inside the token window: rebuild enough of `round` for
         * entryRow (est) and postScore (token + raw counters), repaint the
         * done stat-line, and put the dials back up. The stashed token may
         * still expire mid-claim — submitScore's silent re-mint handles it,
         * and the ledger guard makes that re-mint valid only while the date
         * still holds.
         */
        function restorePending(p) {
            dailyLock();
            round = {
                data: { token: p.token, duration: SCRIM.duration },
                durationMs: p.durationMs, keystrokes: p.keystrokes,
                errors: p.errors, chars: p.chars, bestStreak: p.bestStreak,
                wraps: p.wraps, est: p.est,
            };
            claimChars = startingName();

            $('sc-stat-live').hidden = true;
            $('sc-stat-done').hidden = false;
            $('sc-final').textContent = p.est.toFixed(2);
            $('sc-chips').innerHTML =
                chip('net wpm', p.net.toFixed(1)) +
                chip('accuracy', p.acc.toFixed(1) + '%') +
                chip('chars', p.chars) +
                chip('errors', p.errors);

            showDailyPlayed(p.est);
            const el = $('sc-daily-mymarks');
            if (el) el.textContent = p.est.toFixed(2) +
                ' marks \u2014 unclaimed. Dial your name below.';

            $('sc-boardcard').hidden = false;
            loadBoard({ est: p.est });
        }

        /**
         * Midnight watcher. The page can't compute the SITE's midnight, but
         * it can compare epochs: past ROLLOVER_MS, the stale banner joins
         * whatever is on screen (it does not interrupt a round in progress
         * — a round begun before midnight legitimately finishes and files
         * on yesterday's board; that is what the archive's delay is for).
         */
        function armRollover() {
            setInterval(function () {
                if (Date.now() >= ROLLOVER_MS) {
                    const el = $('sc-daily-stale');
                    if (el) el.hidden = false;
                }
            }, 30000);
        }

        /**
         * The boot arbitration. True = this function owned the page (played
         * or stale: no live round); false = fresh shot, boot normally.
         */
        function bootDaily() {
            if (!DAILY) return false;
            armRollover();

            if (Date.now() >= ROLLOVER_MS) {     // loaded already-stale
                dailyLock();
                showDailyState('sc-daily-stale');
                return true;
            }

            const gate = dailyGateRead();
            if (!gate || gate.date !== DAILY.date) return false;   // fresh

            const p = dailyStashRead();          // played — claim restorable?
            if (p) { restorePending(p); return true; }

            dailyLock();
            showDailyPlayed(gate.marks);
            return true;
        }

        /* =================================================================
           v2 SCORE MATH — the client mirror of DifficultyRater (v2).
           Change the server, change this, bump the version there.
           ================================================================= */
        function wrapMult(wraps) {
            if (wraps < 1) return 1;
            return Math.min(1.5, 1 + 0.03 * wraps * (wraps + 1) / 2);
        }
        function perfectMult(wraps, errors) {
            if (errors > 0 || wraps < 1) return 1;
            return Math.min(1.5, 1 + 0.06 * wraps);
        }

        /* =================================================================
           TOKEN FRESHNESS — re-mint, never scold.

           Tokens are sealed at HTML-generation time, so a page left open
           past typing.token_ttl_ms holds a stale one. Rather than showing
           "that round has expired", we quietly fetch a fresh payload and
           hand back the token for the CURRENT edition. The typed counts
           are untouched — only the envelope was old.
           ================================================================= */
        function refreshPayload() {
            return fetch(URL_RESOLVE + '?' + apiQuery(params()))
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.error) return null;
                    resolved = j;
                    const v = variantOf(active);
                    if (round) round.data = Object.assign({}, resolved, v || {});
                    return v ? v.token : j.token;
                })
                .catch(function () { return null; });
        }

        /* =================================================================
           TRANSLATION SWITCHING — instant, client-side, no reload
           ================================================================= */

        /**
         * Same <details class="tx"> markup as bible.partials.translation-
         * switcher, built from the payload's variants — it inherits the
         * .tx* styles and the click-away/Escape handlers from layouts.app
         * (delegated on document, so dynamic markup is covered). Options
         * keep real hrefs for middle-click / no-JS, but a normal click is
         * intercepted and swapped in place.
         *
         * The single-edition pill still wears the .tx wrapper: the sans
         * font-family lives on .tx itself, so a bare pill would fall back
         * to the page serif (the Roberts-Donaldson bug).
         */
        function renderTxSwitch() {
            const host = $('sc-txswitch');
            const vars = resolved.variants || [];
            host.innerHTML = '';
            if (!vars.length) return;

            const cur = variantOf(active) || vars[0];

            if (vars.length < 2) {
                host.innerHTML = '<span class="tx"><span class="tx-pill tx-solo">' +
                                 esc(cur.name) + '</span></span>';
                return;
            }

            let menu = '';
            vars.forEach(function (v) {
                const year = v.year
                    ? '<span class="tx-year">' + esc(String(v.year)) + '</span>' : '';
                if (v.slug === active) {
                    menu += '<span class="tx-option is-current" aria-current="true">' +
                            '<span class="tx-check">\u2713</span>' +
                            '<span class="tx-name">' + esc(v.name) + '</span>' + year + '</span>';
                } else {
                    const href = scrimHref({
                        t: v.slug, b: resolved.params.b, c: resolved.params.c, v: resolved.params.v,
                    });
                    menu += '<a class="tx-option" data-tx="' + esc(v.slug) + '" href="' + href + '">' +
                            '<span class="tx-check"></span>' +
                            '<span class="tx-name">' + esc(v.name) + '</span>' + year + '</a>';
                }
            });

            host.innerHTML =
                '<details class="tx"><summary class="tx-pill">' + esc(cur.name) +
                ' <span class="tx-caret">\u25BE</span></summary>' +
                '<div class="tx-menu">' + menu + '</div></details>';
        }

        $('sc-txswitch').addEventListener('click', function (e) {
            const opt = e.target.closest('[data-tx]');
            if (!opt) return;
            e.preventDefault();
            const d = $('sc-txswitch').querySelector('details.tx');
            if (d) d.removeAttribute('open');
            switchTranslation(opt.dataset.tx);
        });

        /**
         * Swap the active edition: URL + title + header link via
         * replaceState, pill re-render, fresh ready round on that edition's
         * own text and token. Nothing else moves — verse identity, clock,
         * and the (single, shared) board are all unchanged.
         */
        function switchTranslation(slug) {
            if (slug === active || !variantOf(slug)) return;
            if (round && round.timer) clearInterval(round.timer);
            active = slug;
            history.replaceState(null, '', scrimHref(params()));
            document.title = resolved.reference + ' (' + slug.toUpperCase() + ') \u2014 ' + BASE_TITLE;
            $('sc-title').href = readerHref(params());
            renderTxSwitch();
            readyRound(roundData(slug));
            syncShare(null);
        }

        /* =================================================================
           THE ROUND — ready → running → done
           ================================================================= */
        function readyRound(data) {
            if (round && round.timer) clearInterval(round.timer);
            phase = 'ready';
            round = {
                data: data,
                target: Array.from(data.text),
                spans: [], idx: 0,
                chars: 0, errors: 0, keystrokes: 0, wraps: 0,
                streak: 0, bestStreak: 0,
                comboAlive: true, comboTimer: null,
                startedAt: null, timer: null, durationMs: 0,
            };
            renderPass();

            $('sc-stat-live').hidden = false;
            $('sc-stat-done').hidden = true;
            $('sc-clock').textContent = data.duration;
            $('sc-clock').classList.remove('is-ending');
            $('sc-live-chars').textContent = '0';
            $('sc-live-errors').textContent = '0';
            $('sc-combo').hidden = true;
            $('sc-combo').classList.remove('is-broken');

            // A round in the ready state shows no board, no done chrome,
            // and the pre-round coaching line returns.
            $('sc-typewrap').classList.remove('is-done');
            $('sc-done').hidden = true;
            $('sc-play-hint').hidden = false;
            $('sc-boardcard').hidden = true;
            $('sc-board-note').hidden = true;
            $('sc-rank').textContent = '';
            hideDuel();
            hidePop();

            $('sc-capture').value = '';
            $('sc-capture').focus();
        }

        /**
         * A rerun re-mints tokens so the server wall-clock always measures
         * the round in front of it. The CURRENT round stays on screen while
         * that fetch runs — nothing blanks.
         */
        function newRound() {
            refreshPayload().then(function () {
                readyRound(roundData(active));
            });
        }

        function renderPass() {
            const box = $('sc-typebox');
            box.innerHTML = '';
            round.spans = [];
            round.target.forEach(function (ch) {
                const s = document.createElement('span');
                s.className = 'sc-ch';
                s.textContent = ch;
                box.appendChild(s);
                round.spans.push(s);
            });
            round.idx = 0;
            setCur();
        }
        function setCur() {
            round.spans.forEach(function (s) { s.classList.remove('cur'); });
            const cur = round.spans[round.idx];
            if (cur) cur.classList.add('cur');
        }

        const EQUIV = {
            '\u2018': "'", '\u2019': "'", '\u201A': "'", '\u02BC': "'", '`': "'", '\u00B4': "'",
            '\u201C': '"', '\u201D': '"', '\u201E': '"',
            '\u2013': '-', '\u2014': '-', '\u2010': '-', '\u2011': '-', '\u2212': '-',
            '\u00A0': ' ', '\n': ' ', '\r': ' ', '\t': ' ',
            '\u2026': '.',
        };
        function canon(ch) {
            ch = EQUIV[ch] || ch;
            const bare = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            return bare.length ? bare : ch;
        }

        function step(ch) {
            if (!round || phase === 'done') return;

            if (round.startedAt === null) {          // the clock begins NOW
                round.startedAt = performance.now();
                round.timer = setInterval(tick, 100);
                phase = 'running';
            }

            round.keystrokes++;
            const want = round.target[round.idx];
            const cur  = round.spans[round.idx];

            if (canon(ch) === canon(want)) {
                cur.classList.remove('cur', 'err');
                cur.classList.add('ok');
                round.chars++;
                round.streak++;                       // combo: clean run length
                if (round.streak > round.bestStreak) round.bestStreak = round.streak;
                round.idx++;
                if (round.idx >= round.target.length) wrapPass();
                else setCur();
            } else if (ch.trim() !== '' || canon(want) === ' ') {
                round.errors++;
                round.streak = 0;
                cur.classList.remove('err');
                void cur.offsetWidth;                 // restart the shake
                cur.classList.add('err');
                breakCombo();
            } else {
                round.keystrokes--;                  // stray space: forgiven entirely
            }
            $('sc-live-chars').textContent = round.chars;
            $('sc-live-errors').textContent = round.errors;
        }

        /* ---- Wrap: the escalating-bonus moment --------------------------- */
        function wrapPass() {
            round.wraps++;
            spawnToast('+wrap \u00D7' + round.wraps);
            if (round.comboAlive) {
                const badge = $('sc-combo');
                badge.hidden = false;
                badge.classList.remove('is-broken');
                badge.textContent = 'PERFECT \u00D7' + round.wraps;
            }
            renderPass();
        }

        function breakCombo() {
            if (!round.comboAlive) return;
            round.comboAlive = false;
            const badge = $('sc-combo');
            if (round.wraps >= 1 || !badge.hidden) {
                badge.hidden = false;
                badge.classList.add('is-broken');
                badge.textContent = 'combo broken';
                clearTimeout(round.comboTimer);
                round.comboTimer = setTimeout(function () { badge.hidden = true; }, 1400);
            }
        }

        function spawnToast(text) {
            const wrap = $('sc-typewrap');
            const cur  = round.spans[Math.min(round.idx, round.spans.length - 1)];
            const t = document.createElement('div');
            t.className = 'sc-toast';
            t.textContent = text;
            const wr = wrap.getBoundingClientRect();
            const cr = (cur || wrap).getBoundingClientRect();
            t.style.left = Math.max(0, cr.left - wr.left) + 'px';
            t.style.top  = Math.max(0, cr.top - wr.top - 8) + 'px';
            wrap.appendChild(t);
            setTimeout(function () { t.remove(); }, 950);
        }

        function tick() {
            const elapsed = performance.now() - round.startedAt;
            const total   = round.data.duration * 1000;
            const left    = Math.max(0, total - elapsed);
            $('sc-clock').textContent = (left / 1000).toFixed(1);
            if (left <= 3000) $('sc-clock').classList.add('is-ending');
            if (left <= 0) finishRound(elapsed);
        }

        function finishRound(elapsedMs) {
            clearInterval(round.timer);
            phase = 'done';
            $('sc-capture').blur();

            // The round is over: no clock (the results replace the stat line),
            // no cursor, no coaching line, and the "⟳ SCRIM" stamp over the
            // dimmed text.
            round.spans.forEach(function (s) { s.classList.remove('cur'); });
            $('sc-typewrap').classList.add('is-done');
            $('sc-done').hidden = !!DAILY;   // one shot: the ⟳ rerun stamp never shows
            $('sc-play-hint').hidden = true;

            round.durationMs = Math.round(Math.min(elapsedMs, round.data.duration * 1000 + 400));
            beaconPlayed();                          // the count, before any claim
            showResults();
        }

        /**
         * Tell the server this verse was PLAYED — the anonymous counter behind
         * the scrimboard hub's trending list. Fired once per finished round,
         * whatever it scored and whether or not a name is ever claimed.
         *
         * Deliberately NOT tied to the claim: a defended name can be
         * re-challenged all afternoon on one token, and a zero-score round
         * never submits at all. Only the end of a round means "played once".
         *
         * Fire and forget — no response is read, and a failure is invisible.
         * The server dedupes on the token, so an accidental double-fire (or a
         * retried request) counts once. Reruns re-mint the token and DO count
         * again: each finished scrim is its own play, matching the Acts log.
         */
        function beaconPlayed() {
            if (!round.data || !round.data.token) return;
            try {
                fetch(URL_PLAYED, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body:    JSON.stringify({
                        token:            round.data.token,
                        duration_ms:      round.durationMs,
                        total_keystrokes: round.keystrokes,
                    }),
                    keepalive: true,     // survives a tab closed on the results
                }).catch(function () {});
            } catch (e) { /* a counter never breaks a round */ }
        }

        function abandonRound() {
            if (phase !== 'running') return;
            clearInterval(round.timer);
            newRound();                              // fresh token, back to ready
        }

        const capture = $('sc-capture');
        capture.addEventListener('input', function () {
            const data = capture.value;
            capture.value = '';
            if (!data) return;
            Array.from(data).forEach(step);
        });
        capture.addEventListener('paste', function (e) { e.preventDefault(); });
        capture.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); abandonRound(); }
            if (e.key === 'Enter')  { e.preventDefault(); step(' '); }
        });

        // The typebox is always the way back in: ready → focus; done → rerun.
        // Except the daily: done is DONE — the shot is spent, and every
        // road back to a fresh round is closed on this page. (Escape mid-
        // round still abandons cleanly: an unfinished attempt is unspent.)
        $('sc-typebox').addEventListener('click', function () {
            if (phase === 'done') { if (!DAILY) newRound(); }
            else capture.focus();
        });
        $('sc-done').addEventListener('click', function () {
            if (phase === 'done' && !DAILY) newRound();
        });

        /* =================================================================
           RESULTS — swap into the stat line, reveal the SCRIMBOARD
           ================================================================= */
        function showResults() {
            const mins  = round.durationMs / 60000;
            const gross = (round.keystrokes / 5) / mins;
            const err   = (round.errors * ERROR_CHARS / 5) / mins;
            const net   = Math.max(0, gross - err);
            const acc   = round.keystrokes ? ((round.keystrokes - round.errors) / round.keystrokes) * 100 : 0;
            const wm    = wrapMult(round.wraps);
            const pm    = perfectMult(round.wraps, round.errors);
            const est   = net * Math.pow(acc / 100, 2) * round.data.difficulty_modifier * wm * pm;

            round.est = est;

            // THE SHOT IS SPENT — at completion, not at claim, so "finish,
            // don't claim, retry" is not a loophole. The pending stash is
            // what makes that fair: a reload inside the token window gets
            // the claim plaque back instead of losing the seat.
            if (DAILY) {
                dailySpend(est);
                if (Math.round(est * 100) / 100 > 0) dailyStash(net, acc);
            }

            // Record the act: one entry per COMPLETED round (reruns log
            // again — each finished scrim is its own deed; the daily can
            // only ever log one per date). The client estimate is what's
            // kept; the server's authoritative score differs only by
            // rounding drift.
            if (window.MBActs) {
                const act = {
                    ref:   resolved.reference,
                    tx:    active,
                    b:     resolved.params.b,
                    c:     resolved.params.c,
                    v:     resolved.params.v,
                    score: Math.round(est),
                    net:   Math.round(net * 10) / 10,
                    acc:   Math.round(acc * 10) / 10,
                };
                if (DAILY) act.date = DAILY.date;   // the server's day, verbatim
                MBActs.log(DAILY ? 'daily' : 'scrim', act);
            }

            $('sc-stat-live').hidden = true;
            $('sc-stat-done').hidden = false;
            $('sc-final').textContent = est.toFixed(2);
            $('sc-chips').innerHTML =
                chip('net wpm', net.toFixed(1)) +
                chip('accuracy', acc.toFixed(1) + '%') +
                chip('chars', round.chars) +
                chip('errors', round.errors) +
                chip('combo', round.bestStreak) +
                (round.wraps >= 1 ? chip('wraps', round.wraps) : '') +
                '<span class="sc-chip bonus" title="wraps \u00D7' + round.wraps +
                    ' \u00B7 wrap \u00D7' + wm.toFixed(2) + ' \u00B7 perfect \u00D7' + pm.toFixed(2) + '">' +
                    'bonus <b>\u00D7' + (wm * pm).toFixed(2) + '</b></span>';

            syncShare(Math.round(est));

            // No claim on the sabbath, whatever the score. The server would
            // refuse it anyway; offering dials that can only be turned away
            // would be a small cruelty at the end of a good round.
            const claimable = !SABBATH && Math.round(est * 100) / 100 > 0;

            // Fresh dials for a fresh claim — your remembered handle, or a
            // random Bible name. Null when there's nothing to claim, which
            // is also what keeps entryRow() from ever being built.
            claimChars = claimable ? startingName() : null;

            // The board exists only from here on: results first, then the
            // claim (or the explanation of why there isn't one).
            $('sc-boardcard').hidden = false;
            loadBoard(claimable ? { est: est } : null);
            if (!claimable) {
                if (SABBATH) {
                    rankText('No scores are saved on the Sabbath');
                } else {
                    rankText(DAILY
                        ? 'No marks saved. Try again.'
                        : 'No marks saved. Try again.');
                }
            }
            if (DAILY) showDailyPlayed(est);
        }

        function chip(label, value) {
            return '<span class="sc-chip">' + label + ' <b>' + value + '</b></span>';
        }

        /* =================================================================
           SCRIMBOARD — one board per verse (all editions), two phases:

             CLAIMING  (name still undialed)  → compact 3-column table
                       (# / Name / Marks): the top BOARD_SIZE rows plus YOUR
                       glowing dial row slotted at its real rank (below a
                       "…" gap when that rank is past the shown rows), so
                       nothing overflows narrow screens and the eye stays
                       on the dials + their breathing check.
             CLAIMED   (after submit)         → the full table
                       (# / Name / Net WPM / Acc / TR / Marks), every row
                       the server sent (up to BOARD_CAP), refreshed IN
                       PLACE — see loadBoard.
           ================================================================= */

        /**
         * A post-submit refresh keeps the existing table on screen until the
         * new data lands: blanking to a "Loading…" placeholder first is what
         * caused the confirm flash — the card collapsed around one line of
         * text, re-expanded a beat later, and overflow-x:auto flickered a
         * scrollbar through the height change. Only a first paint (no table
         * yet) shows the placeholder.
         */
        function loadBoard(entry) {
            const host = $('sc-board-body');
            hidePop();
            const refreshing = !!(entry && entry.mineRank);
            if (!refreshing) {
                host.innerHTML = '<div class="empty">Loading\u2026</div>';
            }
            fetch(URL_BOARD + '?' + apiQuery(params()))
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.error) { host.innerHTML = '<div class="empty">Board unavailable.</div>'; return; }

                    // SEALED (a daily whose day is still running): no rows —
                    // only how many showed up. With a fresh claim pending the
                    // dials still render (an empty board slots the entry row
                    // at #1, rank unknown); without one, the note stands
                    // alone.
                    if (j.sealed) {
                        const n = j.players || 0;
                        const who = n === 0
                            ? 'No names sealed yet \u2014 yours would be the first.'
                            : (n === 1 ? 'One name is' : n + ' names are') +
                              ' sealed on today\u2019s board.';
                        const note = '<div class="sc-sealed-note">' + who +
                            ' Ranks are revealed at the midnight freeze.</div>';
                        if (entry && typeof entry.est === 'number') {
                            renderBoard([], entry, {});
                            host.insertAdjacentHTML('beforeend', note);
                        } else {
                            host.innerHTML = note;
                        }
                        return;
                    }

                    // VEILED (sabbath): the rows exist, uncut, unseen. Said
                    // plainly, because an empty table would read as "nobody
                    // has ever typed this" — a lie about a busy board.
                    if (j.sabbath) {
                        host.innerHTML = '<div class="sc-rested">The racers rest on the Sabbath.</div>';
                        $('sc-board-note').hidden = true;
                        return;
                    }

                    if (!j.board) { host.innerHTML = '<div class="empty">Board unavailable.</div>'; return; }
                    renderBoard(j.board, entry, { trimmed: !!j.trimmed_last_night });
                })
                .catch(function () { host.innerHTML = '<div class="empty">Board unavailable.</div>'; });
        }

        /**
         * One real row. Detail fields ride as data-* for the hover popover.
         * The name cell wears the ★ champion mark on trim survivors, and the
         * struck-through + blurred original beside its replacement when the
         * server flagged the name (r.alt from typing.censor).
         */
        function boardRow(r, pos, mine, full) {
            let nm = '';
            if (r.held) {
                nm += '<span class="sc-held" title="Crowned at the sabbath cut \u2014 stands until unseated">\u2605</span>';
            }
            nm += nameHtml(r.name, r.censored);

            let tds = '<td class="num">' + pos + '</td><td class="sc-nm">' + nm + '</td>';
            if (full) {
                tds += '<td class="num">' + r.net_wpm + '</td>' +
                       '<td class="num">' + r.accuracy + '%</td>' +
                       '<td class="num">' + esc(r.tx || '') + '</td>';
            }
            tds += '<td class="num">' + r.final_score + '</td>';
            const dash = '\u2014';
            return '<tr' + (mine ? ' class="mine"' : '') +
                   ' data-when="'   + esc(r.when   || dash) + '"' +
                   ' data-errors="' + esc(String(r.errors != null ? r.errors : dash)) + '"' +
                   ' data-combo="'  + esc(String(r.combo  != null ? r.combo  : dash)) + '"' +
                   ' data-wraps="'  + esc(String(r.wraps  != null ? r.wraps  : dash)) + '"' +
                   ' data-claims="' + esc(String(r.claims != null ? r.claims : 1)) + '"' +
                   ' data-since="'  + esc(r.since || '') + '"' +
                   '>' + tds + '</tr>';
        }

        /**
         * entry.est      → fresh unclaimed score: compact table + glowing
         *                  claim row (the dials) with the breathing check.
         * entry.mineRank → just-submitted score: full table, YOUR row glowing.
         * meta.trimmed   → the nightly trim fell on this board last night;
         *                  the "yesterday's top 10" caption shows.
         */
        function renderBoard(rows, entry, meta) {
            const host     = $('sc-board-body');
            const claiming = !!(entry && typeof entry.est === 'number');
            const mineRank = entry && entry.mineRank ? entry.mineRank : null;
            const full     = !claiming;

            $('sc-board-note').hidden = !(meta && meta.trimmed);

            if (!rows.length && !claiming) {
                host.innerHTML = '<div class="empty">No one has typed this scrimmage yet. Be the first.</div>';
                return;
            }

            let body = '';

            if (claiming) {
                // Where does the fresh score slot in? First row it beats; a
                // spare seat while the board is under its intra-day cap;
                // otherwise below a "…" gap row.
                let insertIdx = rows.findIndex(function (r) { return entry.est > r.final_score; });
                if (insertIdx === -1 && rows.length < BOARD_CAP) insertIdx = rows.length;
                const placed = insertIdx !== -1;

                // Compact phase shows only the top rows — with the cap at
                // 100, painting the whole field around the dials would bury
                // them. Your row still slots at its REAL rank.
                const shown = rows.slice(0, BOARD_SIZE);

                shown.forEach(function (r, i) {
                    if (placed && i === insertIdx) body += entryRow(insertIdx + 1);
                    body += boardRow(r, i + (placed && i >= insertIdx ? 2 : 1), false, full);
                });
                if (placed && insertIdx >= shown.length) {
                    if (insertIdx > shown.length) {
                        body += '<tr class="gap"><td></td><td>\u2026</td><td></td></tr>';
                    }
                    body += entryRow(insertIdx + 1);
                }
                if (!placed) {
                    body += '<tr class="gap"><td></td><td>\u2026</td><td></td></tr>' + entryRow('\u2014');
                }
            } else {
                // The scrim page is a page you PLAY on — twenty rows give
                // the standings without burying the typebox under the whole
                // field. The full-board page holds everything. Your own row,
                // when it lands past the cut, still shows at its true rank
                // below a gap — the same courtesy the claiming phase pays.
                const shown = rows.slice(0, BOARD_SHOW);
                shown.forEach(function (r, i) {
                    body += boardRow(r, i + 1, mineRank !== null && (i + 1) === mineRank, full);
                });
                if (mineRank !== null && mineRank > shown.length && rows[mineRank - 1]) {
                    body += '<tr class="gap"><td colspan="6">\u2026</td></tr>' +
                            boardRow(rows[mineRank - 1], mineRank, true, full);
                }
            }

            const head = full
                ? '<th class="num">#</th><th>Name</th><th class="num">Net WPM</th>' +
                  '<th class="num">Acc</th><th class="num">TR</th><th class="num">Marks</th>'
                : '<th class="num">#</th><th>Name</th><th class="num">Marks</th>';

            host.innerHTML =
                '<table><thead><tr>' + head + '</tr></thead>' +
                '<tbody>' + body + '</tbody></table>';

            // The rest of the field lives on the full-board page. Never on
            // the daily (its board is sealed and this branch never runs for
            // it — but a guard is cheaper than a certainty).
            if (full && !DAILY && rows.length > BOARD_SHOW) {
                const p = params();
                const href = BOARD_PAGE
                    .replace('__B__', encodeURIComponent(p.b))
                    .replace('__C__', p.c)
                    .replace('__V__', p.v)
                    .replace('__L__', encodeURIComponent(resolved.lang || 'en'));
                host.insertAdjacentHTML('beforeend',
                    '<div class="sc-fullboard-link"><a href="' + href + '">' +
                    'View the full board \u2014 ' + rows.length + ' names \u2192</a></div>');
            }

            if ($('sc-dials')) {
                initDials();
                $('sc-submit').addEventListener('click', submitScore);
            }
        }

        /* =================================================================
           THE DIALS — four characters, A–Z / 0–9, carats above and below.

           Pointer: click spins once; press-and-HOLD repeats (400ms delay,
           then every 90ms). Keyboard, on the character cells: ArrowUp/Down
           spin, ArrowLeft/Right (and Backspace) hop between dials, typing
           a letter or digit sets the dial and advances, Enter claims.
           ================================================================= */

        const CHEV_UP =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<polyline points="6 15 12 9 18 15"></polyline></svg>';
        const CHEV_DN =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<polyline points="6 9 12 15 18 9"></polyline></svg>';

        /**
         * The claim row (compact phase): #, the four dials with the
         * breathing check right beside them, marks.
         */
        function entryRow(rank) {
            let dials = '';
            for (let i = 0; i < 4; i++) {
                dials +=
                    '<div class="sc-dial">' +
                    '<button type="button" class="sc-dial-btn" data-d="' + i + '" data-dir="1" ' +
                        'tabindex="-1" aria-label="Character ' + (i + 1) + ' up">' + CHEV_UP + '</button>' +
                    '<div class="sc-dial-ch" tabindex="0" data-d="' + i + '" ' +
                        'role="spinbutton" aria-label="Name character ' + (i + 1) + '">' +
                        esc(claimChars[i]) + '</div>' +
                    '<button type="button" class="sc-dial-btn" data-d="' + i + '" data-dir="-1" ' +
                        'tabindex="-1" aria-label="Character ' + (i + 1) + ' down">' + CHEV_DN + '</button>' +
                    '</div>';
            }
            return '<tr class="entry"><td class="num">' + rank + '</td>' +
                   '<td class="sc-name-cell">' +
                   '<div class="sc-dials" id="sc-dials" role="group" ' +
                       'aria-label="Dial your four-character name">' + dials + '</div>' +
                   '<button type="button" class="sc-claim" id="sc-submit" ' +
                   'aria-label="Claim your marks" title="Claim your marks">' +
                   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" ' +
                   'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                   '<polyline points="20 6 9 17 4 12"></polyline></svg>' +
                   '</button></td>' +
                   '<td class="num">' + round.est.toFixed(2) + '</td></tr>';
        }

        // Hold-to-repeat state lives at module scope so the document-level
        // release listeners (wired ONCE at boot — a release can land outside
        // the dial cluster) can always stop it.
        let dialHold = null;
        function stopDialHold() {
            if (!dialHold) return;
            clearTimeout(dialHold.t);
            clearInterval(dialHold.i);
            dialHold = null;
        }
        ['pointerup', 'pointercancel'].forEach(function (ev) {
            document.addEventListener(ev, stopDialHold);
        });

        function initDials() {
            const host  = $('sc-dials');
            const cells = host.querySelectorAll('.sc-dial-ch');

            function paint(i, dir) {
                const el = cells[i];
                el.textContent = claimChars[i];
                el.classList.remove('bump-up', 'bump-dn');
                void el.offsetWidth;                 // restart the hop
                el.classList.add(dir >= 0 ? 'bump-up' : 'bump-dn');
            }
            function spin(i, dir) {
                const at = DIAL_SET.indexOf(claimChars[i]);
                claimChars[i] = DIAL_SET[(at + dir + DIAL_SET.length) % DIAL_SET.length];
                paint(i, dir);
            }

            // Carats: spin on press, repeat on hold. preventDefault keeps
            // the press from selecting text or stealing focus mid-dial.
            host.addEventListener('pointerdown', function (e) {
                const btn = e.target.closest('.sc-dial-btn');
                if (!btn) return;
                e.preventDefault();
                const i = +btn.dataset.d, dir = +btn.dataset.dir;
                spin(i, dir);
                stopDialHold();
                dialHold = { i: null, t: setTimeout(function () {
                    dialHold.i = setInterval(function () { spin(i, dir); }, 90);
                }, 400) };
            });
            // Keyboard activation of a carat (Enter/Space fire click with
            // detail 0; pointer clicks were already handled above).
            host.addEventListener('click', function (e) {
                const btn = e.target.closest('.sc-dial-btn');
                if (!btn || e.detail !== 0) return;
                spin(+btn.dataset.d, +btn.dataset.dir);
            });

            // The cells take the keyboard: the fast path is just typing
            // the name — each key sets a dial and hops to the next.
            host.addEventListener('keydown', function (e) {
                const cell = e.target.closest('.sc-dial-ch');
                if (!cell) return;
                const i = +cell.dataset.d;
                if (e.key === 'ArrowUp')    { e.preventDefault(); spin(i, 1);  return; }
                if (e.key === 'ArrowDown')  { e.preventDefault(); spin(i, -1); return; }
                if (e.key === 'ArrowLeft')  { e.preventDefault(); if (cells[i - 1]) cells[i - 1].focus(); return; }
                if (e.key === 'ArrowRight') { e.preventDefault(); if (cells[i + 1]) cells[i + 1].focus(); return; }
                if (e.key === 'Backspace')  { e.preventDefault(); if (cells[i - 1]) cells[i - 1].focus(); return; }
                if (e.key === 'Enter')      { e.preventDefault(); submitScore(); return; }
                const ch = e.key.length === 1 ? e.key.toUpperCase() : '';
                if (ch && DIAL_SET.indexOf(ch) !== -1) {
                    e.preventDefault();
                    claimChars[i] = ch;
                    paint(i, 1);
                    (cells[i + 1] || cells[i]).focus();
                }
            });

            // Desktop lands ready to type a name; on touch, focusing a div
            // opens no keyboard, so this is harmless there.
            cells[0].focus();
        }

        /* =================================================================
           THE DUEL PLAQUE — a held name repelled the challenge. Not an
           error, not a system prompt: the defender's numbers and a dare.
           ================================================================= */
        function showDuel(name, j) {
            const host = $('sc-duel');
            const h    = j.holder || {};
            const shownName = h.censored ? nameHtml(name, true) : '<b>' + esc(name) + '</b>';
            const hist =
                (h.claims > 1 ? 'holder \u2116' + h.claims : 'first holder') +
                (h.since ? ' \u00B7 on this board since ' + esc(h.since) : '') +
                (h.when  ? ' \u00B7 set ' + esc(h.when) : '');
            host.innerHTML =
                '<span>' + shownName + ' defends this name with <b>' +
                Number(h.final_score).toFixed(2) + '</b> marks</span></div>' +
                '<div class="sc-duel-sub">Your ' + Number(j.score.final_score).toFixed(2) +
                ' doesn\u2019t unseat it \u2014 beat the score to take the name, or dial a different one.';
            host.hidden = false;
        }
        function hideDuel() {
            const d = $('sc-duel');
            d.hidden = true;
            d.innerHTML = '';
        }

        function postScore(name, token) {
            return fetch(URL_SCORE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({
                    token:            token,
                    player_name:      name,
                    duration_ms:      round.durationMs,
                    total_keystrokes: round.keystrokes,
                    error_count:      round.errors,
                    char_count:       round.chars,
                    best_combo:       round.bestStreak,
                }),
            }).then(function (r) { return r.json(); });
        }

        /**
         * Submit, and if the token had simply gone stale (page open past
         * token_ttl_ms), re-mint and resubmit ONCE before surfacing anything.
         * The user never sees an expiry and never retypes.
         *
         * Three non-error outcomes besides a clean claim:
         *   held       → the duel plaque (dial on; the check re-arms).
         *   takeover   → the seat is seized; the rank line brags the deed.
         *   board_full → the intra-day cap repelled a below-floor score.
         */
        function submitScore() {
            if (!claimChars) return;
            const name = claimChars.join('');
            if (!/^[A-Z0-9]{4}$/.test(name)) return;

            // Remembered on SUBMIT, not on success: the handle is your
            // choice of mask, and losing a duel on one board doesn't mean
            // you've stopped being EZRA. (Move this into the success branch
            // if you'd rather a defeated name not follow you.)
            rememberName(name);

            hideDuel();
            $('sc-submit').disabled = true;

            postScore(name, round.data.token)
                .then(function (j) {
                    if (j.error && /expired/i.test(j.error)) {
                        return refreshPayload().then(function (token) {
                            return token ? postScore(name, token) : j;
                        });
                    }
                    return j;
                })
                .then(function (j) {
                    if (j.error) {
                        rankText(j.error);
                        $('sc-submit').disabled = false;
                        return;
                    }

                    if (j.held) {
                        // SEALED DAILY: the duel plaque would print the
                        // defender's score, which is one row of the sealed
                        // board. All a challenger learns is that the name
                        // is spoken for. (The server already sent holder:
                        // null for daily — this branch just matches it.)
                        if (DAILY) {
                            rankText(name + ' is taken and defended on today\u2019s ' +
                                'board \u2014 and the seal keeps its marks hidden. ' +
                                'Dial another name.');
                        } else {
                            showDuel(name, j);
                        }
                        $('sc-submit').disabled = false;   // re-dial, re-dare
                        return;
                    }

                    if (j.no_score) {
                        rankText('This round scored no marks \u2014 nothing to claim.');
                        return;
                    }

                    if (j.board_full) {
                        rankText('The board is full at ' + j.cap + ' today \u2014 ' +
                            Number(j.score.final_score).toFixed(2) +
                            ' marks don\u2019t crack its floor (' + Number(j.floor).toFixed(2) +
                            '). Rerun faster, or catch the fresh board after midnight.');
                        return;
                    }

                    // Authoritative numbers replace the client estimate; the
                    // glowing row IS the rank announcement — a takeover earns
                    // one extra line of bragging, censored if the name is.
                    $('sc-final').textContent = j.score.final_score;
                    syncShare(Math.round(j.score.final_score));

                    // SEALED DAILY: no rank came back (the server withheld
                    // it) and no board renders. The seat is confirmed, the
                    // envelope stays shut, and the pending stash is spent.
                    if (DAILY) {
                        dailyStashClear();
                        dailySpend(j.score.final_score);   // authoritative marks
                        $('sc-board-body').innerHTML =
                            '<div class="sc-sealed-note">' +
                            nameHtml(name, j.censored) +
                            ' is etched on today\u2019s board with <b>' +
                            Number(j.score.final_score).toFixed(2) + '</b> marks.</div>';
                        rankText('');
                        showDailyPlayed(j.score.final_score);
                        return;
                    }

                    if (j.takeover) {
                        rankHtml('You took ' + nameHtml(name, j.censored) +
                                 ' \u2014 holder \u2116' + j.claims + '.');
                    } else {
                        rankText('');
                    }
                    loadBoard({ mineRank: j.rank });     // full board, YOUR row glowing
                })
                .catch(function () {
                    rankText('Could not reach the server.');
                    $('sc-submit').disabled = false;
                });
        }

        /* =================================================================
           ROW POPOVER — the footnote panel's dress on scrimboard rows.
           Non-interactive (pointer-events: none), so no marker→panel
           keep-alive dance is needed; desktop fine-pointers only.
           Second line: the NAME'S history — first holder or holder №n,
           and how long the name has sat on this board. Takeovers are
           visible on purpose: names are masks here, not identities.
           ================================================================= */
        let pop = null, popRow = null;

        function hidePop() {
            if (pop) { pop.remove(); pop = null; popRow = null; }
        }

        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            $('sc-board-body').addEventListener('mouseover', function (e) {
                const tr = e.target.closest('tr[data-when]');
                if (!tr) { hidePop(); return; }
                if (tr === popRow) return;
                hidePop();

                const claims = parseInt(tr.dataset.claims || '1', 10);
                let hist = claims > 1 ? 'holder \u2116' + claims + ' of this name' : 'first holder';
                if (tr.dataset.since) hist += ' \u00B7 on the board since ' + esc(tr.dataset.since);

                pop = document.createElement('div');
                pop.className = 'sc-pop';
                pop.innerHTML =
                    '<b>' + esc(tr.dataset.when) + '</b> \u00B7 errors ' + esc(tr.dataset.errors) +
                    ' \u00B7 combo ' + esc(tr.dataset.combo) + ' \u00B7 wraps ' + esc(tr.dataset.wraps) +
                    '<br>' + hist;
                document.body.appendChild(pop);
                popRow = tr;

                // Above the row, centred on the pointer, clamped to the
                // viewport; the chevron tracks the pointer even when clamped.
                const r  = tr.getBoundingClientRect();
                const w  = pop.offsetWidth;
                const h  = pop.offsetHeight;
                let left = e.clientX - w / 2 + window.scrollX;
                left = Math.max(window.scrollX + 8,
                       Math.min(left, window.scrollX + document.documentElement.clientWidth - w - 8));
                pop.style.left = left + 'px';
                pop.style.top  = (r.top + window.scrollY - h - 10) + 'px';
                pop.style.setProperty('--chev-x',
                    (e.clientX + window.scrollX - left) + 'px');
            });
            $('sc-boardcard').addEventListener('mouseleave', hidePop);
        }

        /* =================================================================
           BOOT — everything is already here; just wire it up.
           ================================================================= */
        // The switcher's option list is client-built (it needs the variants),
        // but the header, clock, and verse text are already on screen.
        renderTxSwitch();
        if (!DAILY && window.MBScrimShare) MBScrimShare.show();
        syncShare(null);
        // bootDaily arbitrates the daily's three states; true means it owns
        // the page (played or stale — no live round is armed). Ordinary
        // scrims, and a daily with an unspent shot, boot as ever.
        if (!bootDaily()) readyRound(roundData(active));
    })();
</script>
@endsection
