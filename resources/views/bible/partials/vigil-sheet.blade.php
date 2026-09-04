{{--
    VIGIL SHEET  ·  resources/views/bible/partials/vigil-sheet.blade.php
    ------------------------------------------------------------------
    fold-unify r3

    The candle app in the head folder. r3: no longer a bare navigation
    anchor — it's a <details> app like the pericope scissors and the Aa,
    and its panel is .ps-panel's twin (same width, chrome, offset, z-index),
    hanging beneath the pill. Opening the candle no longer toggles the
    Vigil; it opens this sheet, and the sheet's one action does.

    TWO MODES (prop `mode`):

      enter   Reader-side pages (chapter, book). The candle is a ghost; the
              sheet's action carries you INTO the Vigil. On the chapter
              reader, when verses are selected, the sheet names the verse
              the Vigil will start on and the action link carries ?v=<low>.

      exit    Vigil-side pages (vigil, vigil-book). The Vigil IS the active
              mode, so the candle wears .has-dot (the same accent dot the
              folder shows when a tool inside is switched on — see
              head-folder). The sheet's action carries you back to the
              reader. The page that knows the armed verse (vigil.blade)
              rewrites the action link's href at click time; this partial
              owns none of that.

    PROPS
      mode          'enter' | 'exit'   (default 'enter')
      href          destination of the sheet's action link
      lead          one-line description in the sheet body
      actionLabel   text on the action link
      versePrefix   enter+chapter only: e.g. "Micah 6:" or "Jude " — the
                    partial appends the low selected verse to it and shows a
                    live "Starts at …" line. Omit on pages with no verse
                    selection (book, and both exit pages).

    The candle keeps id="app-vigil" on the <details> (parallel to
    id="app-pericope"); the action link carries .vs-action so a page script
    can find it. All sheet styling is scoped .vs-* and ships here, the way
    text-settings ships its own — the panel chrome mirrors .ps-panel so the
    three sheets read as one family.
--}}

@props([
    'mode'        => 'enter',
    'href'        => '#',
    'lead'        => '',
    'actionLabel' => 'Begin Vigil',
    'versePrefix' => null,
])

@php
    // exit pages are the active mode → the candle wears the accent dot.
    $vigilActive = $mode === 'exit';
    // Only the chapter reader (enter + a versePrefix) wires the live target.
    $vigilTarget = $mode === 'enter' && filled($versePrefix);
@endphp

<details class="vigil-app" id="app-vigil">
    <summary class="fld-app{{ $vigilActive ? ' has-dot' : '' }}"
             aria-label="{{ $mode === 'exit' ? 'Typing Vigil (active)' : 'Typing Vigil' }}"
             title="Typing Vigil">
        {{-- The candle — the same glyph on every page. --}}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5c1.9 2 3 3.6 3 5.2a3 3 0 0 1-6 0c0-1.1.5-2.1 1.3-3.1"/><rect x="9" y="11" width="6" height="9.5" rx="1.2"/><line x1="7.5" y1="21" x2="16.5" y2="21"/></svg>
    </summary>

    <div class="vs-panel" role="group" aria-label="Typing Vigil">
        <div class="vs-head">
            <span class="vs-title">Typing Vigil</span>
        </div>

        @if ($lead)
            <p class="vs-sub">{{ $lead }}</p>
        @endif

        @if ($vigilTarget)
            {{-- Hidden until a selection exists; the script fills the ref and
                 reveals it, and points the action link at that verse. --}}
            <p class="vs-target" hidden>Starts at <strong class="vs-target-ref"></strong></p>
        @endif

        <a class="vs-action" href="{{ $href }}">
            @if ($mode === 'exit')
                <svg class="vs-action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            @else
                <svg class="vs-action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5c1.9 2 3 3.6 3 5.2a3 3 0 0 1-6 0c0-1.1.5-2.1 1.3-3.1"/><rect x="9" y="11" width="6" height="9.5" rx="1.2"/><line x1="7.5" y1="21" x2="16.5" y2="21"/></svg>
            @endif
            <span>{{ $actionLabel }}</span>
        </a>
    </div>
</details>

<style>
    /* ─── Vigil sheet: .ps-panel's twin ─────────────────────────────── */
    .vs-panel {
        position: absolute; right: 0; top: calc(100% + 10px); z-index: 80;
        width: 236px; padding: .9rem;
        background: var(--bg); border: 1px solid var(--rule); border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
        text-align: left; cursor: default;
    }
    .vs-head { margin-bottom: .2rem; }
    .vs-title {
        font-family: var(--sans); font-weight: 700; font-size: .95rem; color: var(--ink);
    }
    .vs-sub {
        margin: 0 0 .7rem; font-family: var(--sans); font-size: .85rem; color: var(--muted);
    }
    .vs-target {
        margin: 0 0 .7rem; font-family: var(--sans); font-size: .82rem; color: var(--muted);
    }
    .vs-target strong { color: var(--ink); font-weight: 600; }

    /* Accent pill action — the same chrome as the pericope panel's Create. */
    .vs-action {
        display: flex; align-items: center; justify-content: center; gap: .45rem;
        width: 100%; box-sizing: border-box;
        padding: .6rem 1rem; border: 1px solid var(--accent); border-radius: 999px;
        background: var(--accent); color: #fff; cursor: pointer;
        font-family: var(--sans); font-size: .88rem; font-weight: 600;
        text-decoration: none; transition: filter .12s;
    }
    .vs-action:hover { filter: brightness(1.08); }
    .vs-action:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .vs-action-ico { width: 17px; height: 17px; display: block; flex: 0 0 auto; }
</style>

@if ($vigilTarget)
<script>
    (function () {
        /* fold-unify r3 — live "starts at" target for the chapter reader.
           Reads the selection focus-synthesis.js publishes (window.MBFocusHand
           now, the mb:focus-change event as it changes) and points the Begin
           link at the lowest selected verse. No engine on a page => no hand =>
           the link stays the plain chapter route, which is correct. */
        var root = document.getElementById('app-vigil');
        if (!root) return;
        var link   = root.querySelector('.vs-action');
        var target = root.querySelector('.vs-target');
        var refEl  = root.querySelector('.vs-target-ref');
        if (!link || !target || !refEl) return;

        var baseHref   = link.getAttribute('href');
        var versePrefix = @json($versePrefix);

        // Lowest selected verse across the hand's cards (each card is a
        // contiguous run; card.v1 is that run's low verse).
        function lowVerse(hand) {
            if (!hand || !hand.count || !hand.cards || !hand.cards.length) return null;
            var lo = Infinity;
            for (var i = 0; i < hand.cards.length; i++) {
                var v = hand.cards[i].v1;
                if (typeof v === 'number' && v < lo) lo = v;
            }
            return isFinite(lo) ? lo : null;
        }

        function apply(hand) {
            var lo = lowVerse(hand);
            if (lo === null) {
                link.href = baseHref;
                target.hidden = true;
                return;
            }
            var sep = baseHref.indexOf('?') === -1 ? '?' : '&';
            link.href = baseHref + sep + 'v=' + lo;
            refEl.textContent = versePrefix + lo;
            target.hidden = false;
        }

        // React to changes while open, and refresh whatever the latest hand
        // is each time the sheet is opened (it may have opened after a
        // selection was already made).
        document.addEventListener('mb:focus-change', function (e) { apply(e.detail); });
        root.addEventListener('toggle', function () { if (root.open) apply(window.MBFocusHand); });

        // A selection can exist at boot (arrived with ?v=). focus-synthesis is
        // deferred, so its hand may not be published yet when this parse-time
        // script runs; the event above catches its first publish either way.
        apply(window.MBFocusHand);
    })();
</script>
@endif
