@extends('layouts.app')

{{-- Descriptive title is good for SEO (per the spec). Includes the chapter #. --}}
@section('title', $refBook . ($refChapter !== null ? ' ' . $refChapter : '') . ' — ' . $translation->abbreviation . ' — MEGABIBLE.net')

{{--
  CHAPTER-READING-ONLY CSS. Injected at the layout's @yield('styles'), so it
  loads after the base styles and builds on the shared tokens (--bg, --ink,
  --accent, --muted, --rule, --panel, --serif, --sans) defined in app.blade.php.
--}}
@section('styles')
{{--
  Canonical URL = the bare chapter, with no ?v= selection. Focus/Synthesis adds
  a ?v= parameter for shareable verse selections, but every selection permutation
  is the same chapter of text, so we point search engines at the clean URL to
  avoid indexing thousands of near-duplicate selection links. This <link> lands
  in <head> because @yield('styles') is rendered there.
--}}
<link rel="canonical" href="{{ route('bible.chapter', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $chapter]) }}">
<style>
    @include('bible.partials.sticky-head')

    /* ---- Reader-specific head bits ---------------------------------------
       The corner cluster is the apps folder (components/head-folder): a single
       40px circle when shut, so the title only needs 4.5rem kept clear. The
       OPEN pill grows left over the title — by design, same as the board. --- */
    .chapter-head { --mb-head-reserve: 4.5rem; }

    /* Hub back link. Lives BELOW the head, on the scrolling surface, so it
       slides up under the sticky header along with the reading text.
       SPACING KNOB: the bottom margin. The air above it is --mb-head-gap. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 1.2rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    @include('bible.partials.reading-styles')

    /* ======================================================================
       FOCUS & SYNTHESIS MODE
       ----------------------------------------------------------------------
       A "verse" is a logical unit that may be spread across several DOM nodes
       (a prose run, then poetry lines, then more prose). Every node that holds
       verse text carries data-verse="N" and the .verse class. The JS groups
       nodes by that number so one tap selects the whole verse at once.

       Prose verses are INLINE spans inside a shared <p>; poetry verses are the
       block <p> itself. The rules below work for both. Everything is built on
       the shared theme tokens, so Focus Mode automatically follows Parchment /
       Midnight / Pure / Terminal with no per-theme overrides.
       ====================================================================== */

    /* Verses invite a tap. touch-action:pan-y keeps vertical scrolling native
       on mobile, so a swipe scrolls and only a real tap selects. The whole line
       (including any trailing empty space) stays a valid tap target. */
    .verse {
        cursor: pointer;
        touch-action: pan-y;
    }

    /* The highlight needs to HUG THE TEXT, not fill the line.

       Prose verses are inline <span>s, so a background already wraps tightly
       around the words. Poetry verses are block <p> elements, though, and a
       block's background would stretch to the full container width — leaving a
       bar of empty highlight after a short poetic line. So poetry carries an
       inner inline .vt span, and we highlight THAT instead of the block. The
       result hugs the text and wraps per line, exactly like prose. */

    /* Shared look for both highlight carriers (prose .verse spans + poetry .vt). */
    .reading p:not(.poetry) .verse,
    .reading p.poetry .vt {
        border-radius: 4px;
        padding: 0 .1em;
        margin: 0 -.1em;
        transition: background-color .15s ease;
        /* Even padding + rounded ends on each line when a long verse wraps. */
        -webkit-box-decoration-break: clone;
                box-decoration-break: clone;
    }

    /* Hover preview (light) on any not-yet-selected verse — stays available
       while you keep selecting, so reading and picking more verses is never
       obstructed. Driven by verse-hover.js, not :hover: the leading between
       two wrapped lines belongs to no inline box, so native :hover strobes on
       a slow drag. The class also lands on EVERY fragment of a verse split
       across prose and poetry, which :hover never did. */
    .reading p:not(.poetry) .verse.is-hover:not(.is-selected),
    .reading p.poetry.verse.is-hover:not(.is-selected) .vt {
        background: var(--panel);
    }

    /* Confirmed selection: the same highlight, a step darker so the chosen
       verses clearly stand out from the page (and from a mere hover preview).
       --rule is the parchment hairline tone — panel, but a touch deeper — and
       it's theme-defined, so this follows Parchment / Midnight / Pure / Terminal
       automatically. Nothing else is dimmed or blurred; the page stays fully
       readable. */
    .reading p:not(.poetry) .verse.is-selected,
    .reading p.poetry.verse.is-selected .vt {
        background: var(--rule);
    }

@include('bible.partials.fab-styles')

    /* ---- Synthesis view (the study board) ---- */
    .synthesis {
        position: fixed; inset: 0;
        z-index: 70;
        background: var(--bg);
        display: flex; flex-direction: column;
        opacity: 0; visibility: hidden;
        transition: opacity .28s ease, visibility .28s ease;
    }
    .synthesis.is-open { opacity: 1; visibility: visible; }

    /* Header: the bar + border span the full width, but the content inside is
       constrained to the same 680px column as the cards (and centred the same
       way), so the title and the close button line up with the card edges on
       desktop instead of sitting at the window edges. */
    .synthesis-head {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid var(--rule);
        background: var(--bg);
    }
    .synthesis-head-inner {
        max-width: 680px; margin: 0 auto;
        display: flex; align-items: flex-start; gap: 1rem;
    }
    .synthesis-meta { display: flex; flex-direction: column; gap: .45rem; }   /* room for the switcher pill */
    .synthesis-titleline { display: flex; align-items: baseline; gap: .5rem; }
    .synthesis-where {
        font-family: var(--sans); font-size: 1rem; font-weight: 600; color: var(--ink);
    }
    .synthesis-count {
        font-family: var(--sans); font-size: .9rem; color: var(--muted);
    }
    /* Header action cluster — copy-all, text settings, close. All three wear
       the reader button chrome: a bordered circle that fills with accent on
       hover, matching the Aa trigger the text-settings partial sits between. */
    .synthesis-actions {
        margin-left: auto; flex: 0 0 auto;
        display: flex; align-items: center; gap: .5rem;
    }
    .synthesis-act {
        display: inline-flex; align-items: center; justify-content: center;
        width: 40px; height: 40px;
        border: 1px solid var(--rule); border-radius: 50%;
        background: var(--bg); color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s, border-color .12s;
    }
    .synthesis-act:hover { color: var(--bg); background: var(--accent); border-color: var(--accent); }
    .synthesis-act:focus-visible { outline: none; color: var(--accent); box-shadow: 0 0 0 3px rgba(107,31,31,.12); }
    .synthesis-act svg { display: block; }
    /* Copy confirmation: force the light surface so the check reads even if the
       cursor is still hovering (which would otherwise fill the button accent). */
    .synthesis-act.is-done,
    .synthesis-act.is-done:hover {
        color: var(--accent); background: var(--bg); border-color: var(--accent);
    }

    .synthesis-body {
        flex: 1 1 auto; overflow-y: auto;
        padding: 1.5rem;
        overscroll-behavior: contain;         /* a drag that reaches the end of the
                                                 board never chains out to the page */
        -webkit-overflow-scrolling: touch;    /* keep momentum scrolling inside the board */
    }
    /* Single readable column, centred, in verse order. A deliberate choice over
       a masonry grid: it reads top-to-bottom like a study list and lets the
       "[ … ]" gap divider sit cleanly between non-contiguous runs. */
    .synthesis-cards {
        max-width: 680px; margin: 0 auto;
        display: flex; flex-direction: column; gap: 1.1rem;
    }
    .synthesis-card {
        background: var(--panel);
        border: 1px solid var(--rule);
        border-radius: 10px;
        padding: 1.1rem 1.25rem;
    }
    .synthesis-ref {
        display: flex; align-items: center; gap: .5rem;
        margin-bottom: .5rem;
        font-family: var(--sans); font-size: .78rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .05em;
        color: var(--accent);
    }
    .synthesis-copy {
        margin-left: auto;
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px;
        border: none; border-radius: 6px;
        background: none; color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .synthesis-copy:hover { color: var(--accent); background: var(--bg); }
    .synthesis-copy.is-done { color: var(--accent); }
    .synthesis-copy svg { display: block; }
    .synthesis-text {
        /* Front (translation) text follows the reader's Text Settings. The
           serif/sans toggle applies HERE only, via --reading-family; the
           interlinear rows below pin their own fonts and ignore it. */
        font-family: var(--reading-family);
        font-size: calc(var(--reading-size) * .92);   /* .92 keeps card text a hair under running text — tune knob */
        line-height: var(--reading-leading);
        white-space: pre-line;   /* honour the \n we insert between poetry lines */
    }
    /* Superscript verse number inside a combined (multi-verse) card. */
    .synthesis-vn {
        font-family: var(--sans);
        font-size: .62em; color: var(--accent); font-weight: 600;
        vertical-align: super; margin-right: .15em; line-height: 0;
    }
    /* Divider between non-contiguous verses, e.g. selecting 3 then 8. */
    .synthesis-gap {
        text-align: center;
        font-family: var(--sans); letter-spacing: .4em;
        color: var(--muted);
        padding: .2rem 0;
    }

    /* ======================================================================
       INTERLINEAR (original language) — the synthesis card's back face
       ----------------------------------------------------------------------
       Each covered card body becomes a two-face stage: the translation
       (front) cross-fades into the original/transliteration/literal trio
       (back) while the card's height animates to fit. The card itself —
       border, header, ref, copy button — never moves; only the body swaps.

       The stage animates HEIGHT between the two faces' natural heights. The
       inactive face is position:absolute (so it adds no height) and the
       active one is static (so it defines it); the JS keeps .is-active and
       an explicit px height in step around each flip.
       ====================================================================== */

    /* Flip button in the card head — same ghost treatment as .synthesis-copy.
       margin-left:auto pushes it (and the copy button after it) to the right. */
    .synthesis-flip {
        margin-left: auto;
        display: inline-flex; align-items: center; justify-content: center;
        gap: .3rem;
        height: 30px; padding: 0 .5rem;          /* was a 30px square; now hugs its label */
        border: none; border-radius: 6px;
        background: none; color: var(--muted); cursor: pointer;
        font-family: var(--sans); font-size: .72rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em;
        transition: color .12s, background .12s;
    }
    .synthesis-flip .flip-label { line-height: 1; }
    .synthesis-flip:hover { color: var(--accent); background: var(--bg); }
    .synthesis-flip[aria-pressed="true"] { color: var(--accent); }
    .synthesis-flip[disabled] { opacity: .45; cursor: default; }
    .synthesis-flip svg { display: block; }
    /* When a flip button is present it carries the auto margin, so the copy
       button (which normally pushes itself right) just sits beside it. */
    .synthesis-flip + .synthesis-copy { margin-left: 0; }

    /* ---- the two-face stage ---- */
    .card-faces {
        position: relative;
        overflow: hidden;                    /* clips the parked face */
        transition: height .45s cubic-bezier(.2,.8,.2,1);
    }
    .card-faces .face {
        width: 100%;
        position: absolute; top: 0; left: 0;
        transition: opacity .32s ease, transform .32s ease;
    }
    .card-faces .face.is-active { position: static; }
    .card-faces .face-front { opacity: 1; transform: none; }
    .card-faces .face-back  { opacity: 0; transform: translateY(8px);  pointer-events: none; }
    .synthesis-card.is-flipped .face-front { opacity: 0; transform: translateY(-8px); pointer-events: none; }
    .synthesis-card.is-flipped .face-back  { opacity: 1; transform: none; pointer-events: auto; }

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
    .iface { font-size: var(--reading-size); }
    .row-original { font-family: var(--serif); font-size: 1.14em; line-height: calc(var(--reading-leading) + .25); }
    .row-original[dir="rtl"] { text-align: right; }   /* Hebrew/Aramaic read right-to-left */
    .row-translit { font-family: var(--serif); font-style: italic; font-size: .82em; line-height: calc(var(--reading-leading) + .05); }
    .row-gloss    { font-family: var(--sans); font-size: .74em; line-height: calc(var(--reading-leading) + .05); color: var(--muted); }

    /* STEPBible marks syllables with periods ('be.re.Shit'); we show them
       as faint, raised interpuncts. Each is its own span (fillTranslit in
       focus-synthesis.js) because CSS can't target a character mid-text. */
    .syl-sep {
        opacity: .45;             /* fainter than the syllables around it */
        /* · (U+00B7) already sits near mid-height in most serifs; if yours
           sets it low, nudge: position: relative; top: -.04em; */
    }
    .iface .w.pin .syl-sep { opacity: .6; }   /* legible on the accent fill */

    /* Word chips: hover previews the word across all three rows; click pins
       it (multiple pins allowed) — the verse-focus interaction, one level
       down. Same hug-the-text treatment as verse highlights. */
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

    /* Respect users who'd rather not have motion. */
    @media (prefers-reduced-motion: reduce) {
        .verse, .fab, .synthesis,
        .card-faces, .card-faces .face { transition: none; }
    }
</style>
@endsection

{{--
  PAGE BODY. Injected at the layout's @yield('content') — between the shared
  site header and footer. No <div class="container"> here; the layout wraps
  this block in the single shared .container.
--}}
@section('content')
    {{-- Did we land straight on the study board? A switcher/shared link like
         ?v=1&view=synthesis should paint the board already covering the reader,
         so the reload never flashes the reader underneath while JS boots. We
         only honour it when a ?v= selection is actually present. --}}
    @php
        $bootSynthesis = request()->query('view') === 'synthesis'
            && filled(request()->query('v'));
    @endphp

    {{-- Invisible marker that sits just above the chapter head. When it
         clears the top of the viewport the head has pinned. Its height is
         set in the styles above and cancelled by a matching negative margin,
         so it occupies no visible space. --}}
    <div class="chapter-head-sentinel"></div>

    <div class="chapter-head">
        {{-- Corner cluster: the apps folder (components/head-folder). Pill
             order, left to right: scrim / pericope / vigil / Aa, then the
             folder circle. focus-synthesis.js finds the first three by id and
             never builds them — the Blade owns the markup, the engine owns
             the state. --}}
        <div class="head-actions">
            <x-head-folder persist="reader">
                {{-- Scrimmage: a navigation, so an <a>. A scrim is always ONE
                     verse (App\Support\Challenge), so the engine arms it —
                     href + aria-disabled=false — only while exactly one verse
                     is selected, and disarms it otherwise. Starts disarmed. --}}
                <a class="fld-app" id="app-scrim" aria-disabled="true" tabindex="-1"
                   aria-label="Type this verse in Scrimmage" title="Scrimmage this verse">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/><line x1="17.5" y1="15" x2="9" y2="15"/></svg>
                </a>
                {{-- Pericope: scissors. A panel beneath the pill, like Aa —
                     pericope-sheet.js fills .ps-panel on open. Always
                     openable: with nothing selected it's a browse list of
                     your pericopes; with verses in hand each row adds them. --}}
                <details class="pericope-app" id="app-pericope">
                    <summary class="fld-app" aria-label="Pericopes" title="Pericopes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
                    </summary>
                    <div class="ps-panel" role="group" aria-label="Pericopes"></div>
                </details>
                {{-- Vigil: the candle. The inline script below rewrites the
                     href at click time to carry the lowest selected verse. --}}
                <a class="fld-app" id="app-vigil"
                   href="{{ route('typing.vigil', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $chapter]) }}"
                   aria-label="Type this chapter (Vigil)" title="Vigil">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5c1.9 2 3 3.6 3 5.2a3 3 0 0 1-6 0c0-1.1.5-2.1 1.3-3.1"/><rect x="9" y="11" width="6" height="9.5" rx="1.2"/><line x1="7.5" y1="21" x2="16.5" y2="21"/></svg>
                </a>                
                @include('bible.partials.text-settings')
            </x-head-folder>
        </div>

        <div class="chapter-head-top">
            @php $txSlug = strtolower($translation->abbreviation); @endphp
            @if ($maxChapter > 1)
                {{-- Multi-chapter: the book title opens the QuickNav straight to
                     this book's chapter grid (pre-rendered in the panel below, so
                     it works with no JS and ships real hub + chapter links). --}}
                <details class="qn show-chapters"
                         data-open-name="{{ $book->name }}"
                         data-open-title-url="{{ route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]) }}"
                         data-open-base="{{ route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]) }}"
                         data-open-chapters="{{ $maxChapter }}"
                         data-open-chapter-offset="{{ $book->chapterCellOffset() }}">
                    <summary class="qn-book-trigger" aria-label="Jump to another chapter of {{ $book->name }}">
                        <h1><span class="book-link">{{ $refBook }} {{ $refChapter }}</span></h1>
                    </summary>
                    @include('bible.partials.quicknav-panel', [
                        'openName'     => $book->name,
                        'openTitleUrl' => route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]),
                        'openBase'     => route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]),
                        'openChapters' => $maxChapter,
                        'openChapterOffset' => $book->chapterCellOffset(),
                    ])
                </details>
            @else
                {{-- Single-chapter book: nothing to jump between, so keep the
                     plain link back to the book hub. --}}
                <h1>
                    <a class="book-link"
                       href="{{ route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]) }}">{{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</a>
                </h1>
            @endif

            </div>

        @include('bible.partials.translation-switcher', [
            'switchRoute'  => 'bible.chapter',
            'switchParams' => ['book' => $book->slug, 'chapter' => $chapter],
        ])

        </div>

    {{-- Breadcrumb back to the book hub. It lives BELOW the sticky head, not
         inside it, so it sits on the same scrolling surface as the verse text
         and slides up under the header with everything else rather than
         blinking out of existence when the head pins. --}}
    <p class="hub-back-row"><a class="hub-back"
        href="{{ route('bible.book', ['translation' => $txSlug, 'book' => $book->slug]) }}">&larr; Back to {{ $book->name }} Hub</a></p>

    <div class="reading" data-verse-hover>
        @include('bible.partials.reading-flow', ['layout' => $layout, 'linkTranslation' => strtolower($translation->abbreviation)])
        @include('bible.partials.footnotes-list', ['footnotes' => $chapterFootnotes ?? []])
    </div>

    @include('bible.partials.chapter-nav')

    {{--
      Synthesis study board. Hidden until the page script adds .is-open. It lives
      here in Blade (rather than in the script that builds the FAB) for one reason:
      it needs to @include the shared translation switcher — a server-rendered
      partial that can't exist inside a runtime JS string. The script just finds
      this element and toggles it.
    --}}
    <div class="synthesis{{ $bootSynthesis ? ' is-open' : '' }}" role="dialog" aria-modal="true" aria-label="Selected verses">
        <div class="synthesis-head">
            <div class="synthesis-head-inner">
                <div class="synthesis-meta">
                    <div class="synthesis-titleline">
                        <span class="synthesis-where">{{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</span>
                        <span class="synthesis-count"></span>
                    </div>
                    @include('bible.partials.translation-switcher', [
                        'switchRoute'  => 'bible.chapter',
                        'switchParams' => ['book' => $book->slug, 'chapter' => $chapter],
                    ])
                </div>
                <div class="synthesis-actions">
                    {{-- Copy every selected verse — identical output to the FAB's
                         copy button on the reader (selectionText() in the JS). --}}
                    <button type="button" class="synthesis-act synthesis-copyall"
                            aria-label="Copy all selected verses" title="Copy all">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    </button>

                    {{-- Text Settings, scoped to the board: size / spacing / font /
                         theme only. The visibility checks and Acts link are hidden
                         ($tsChecks/$tsLinks=false) — they'd act on the reader beneath
                         the board, which is meaningless here. The unique id keeps
                         this panel's script separate from the header's copy. --}}
                    @include('bible.partials.text-settings', [
                        'tsId'     => 'text-settings-synthesis',
                        'tsChecks' => false,
                        'tsLinks'  => false,
                    ])

                    <button type="button" class="synthesis-close synthesis-act" aria-label="Close study board">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="synthesis-body"><div class="synthesis-cards"></div></div>
    </div>
@endsection

{{--
  Page-specific script. Fills the @yield('scripts') slot at the bottom of
  app.blade.php, so the DOM is fully parsed by the time this runs.
--}}
@section('scripts')
<script src="{{ asset('js/verse-hover.js') }}?v={{ filemtime(public_path('js/verse-hover.js')) }}" defer></script>
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>

<script>
    (function () {
        document.addEventListener('click', function (e) {
            const t = e.target.closest('#app-vigil');
            if (!t) return;
            let min = Infinity;
            document.querySelectorAll('.verse.is-selected').forEach(function (v) {
                const n = parseInt(v.dataset.verse, 10);
                if (!isNaN(n) && n < min) min = n;
            });
            if (!isFinite(min)) return;
            try {
                const u = new URL(t.href, location.origin);
                u.searchParams.set('v', min);
                t.href = u.toString();
            } catch (err) { /* leave the href untouched */ }
        });
    })();
</script>

{{--
  FOCUS & SYNTHESIS MODE lives in public/js/focus-synthesis.js. This inline
  bridge is the ONLY Blade-computed context it needs; everything server-side
  is pre-computed in the controller (the comma-in-json trap: the compiler
  splits json directive arguments on commas, so any comma-bearing expression
  inside one compiles to mangled PHP — hence single variables only).

  Ordering guarantee: this inline script runs during document parse; the
  deferred external script runs after parse — so the context object always
  exists before the engine boots.

  Cache busting: the filemtime query string makes the asset URL change on
  every deploy of the file, so Cloudflare's edge cache (which DOES cache
  /js/*, unlike the cookie-carrying page routes) can never serve a stale
  engine against a newer page.
--}}
<script>
    window.MBFocusContext = {
        book:            @json($refBook),
        chapter:         {{ $refChapter ?? $chapter }},
        rawChapter:      {{ $chapter }},   {{-- raw route chapter; a Pericope card stores THIS, not the display chapter --}}
        multiChapter:    {{ $refChapter !== null ? 'true' : 'false' }},
        translation:     @json($translation->abbreviation),
        translationName: @json($translation->name),

        // Stable, display-independent keys for Pericope (added Phase 0).
        // A pericope card is keyed by (osis + raw chapter + verse + tx) — the
        // same triplet mbVigil.v1 uses — NOT by the display strings above, so a
        // card survives a book rename and resolves identically everywhere. The
        // display form ("Psalm 151:3") is rebuilt at read time from bookMeta,
        // exactly as the Acts feed's scrimRef() does. `chapter` above is the
        // DISPLAY chapter; `osis` + the reader's own data-verse give the raw
        // identity the card must store. focus-synthesis.js ignores fields it
        // doesn't read, so shipping this before Phase 1 breaks nothing.
        osis:            @json($book->osis_id),
        bookSlug:        @json($book->slug),
        txSlug:          @json(strtolower($translation->abbreviation)),

        interlinear:     @json($interlinearVerses ?? []),
        interlinearUrl:  @json($interlinearUrl ?? ''),
        scrimUrl:        @json($scrimUrl ?? ''),
    };
</script>
<script src="{{ asset('js/focus-synthesis.js') }}?v={{ filemtime(public_path('js/focus-synthesis.js')) }}" defer></script>
@include('bible.partials.footnote-popover')
@endsection

{{--
  Fills the footer slot in app.blade.php: the reading-specific line
  (verse count · license · source) just above the standard footer text.
--}}
@section('footer-colophon')
    @include('bible.partials.colophon', [
        'footnoteCredits' => $footnoteCredits ?? collect(),
        'headingCredits' => $headingCredits ?? collect(),
        'editions'       => collect([[
            'name'       => $translation->name,
            'license'    => $translation->license,
            'source_url' => $translation->source_url,
            'verseCount' => $verses->count(),
        ]]),
    ])
@endsection