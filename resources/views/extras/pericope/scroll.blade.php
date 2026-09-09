@extends('layouts.app')

{{--
    PERICOPE SCROLL  ·  /extras/pericope/{slug}/scroll  ·  scroll r3
    ----------------------------------------------------------------
    The board as a feed, on its own page. A read-only sibling of the grid
    (which stays the source of truth at /extras/pericope/{slug}): no
    editing scripts, no grid markup — public/js/pericope-scroll.js
    resolves the slug against the store (loaded by the layout), paints
    posts from MBPericope.feed(), and fills the head's name and subtitle.

    The head folder carries this page's four apps: home (top of feed),
    zoom (the future 3-across profile view), share (link + QR, with the
    Scroll pill and the scroll-settings gear), and Aa. The view pill under
    the subtitle is plain navigation back to the grid; choosing writes the
    view preference the grid shell's dispatcher reads.

    Style partials are BARE CSS included inside this page's one open
    style block (the present-styles convention). present-styles rides
    along for its font-faces — the four faces the feed picks from — until
    the font manager extracts a fonts partial.
--}}

@section('title', 'Pericope — MEGABIBLE.net')

@section('styles')
<style>
    @include('bible.partials.sticky-head')
    @include('bible.partials.present-styles')
    @include('bible.partials.scroll-styles')
</style>
@endsection

@section('content')
    {{-- The shell (shown when the slug resolves; the script unhides it). --}}
    <div id="pb-shell" hidden>
        <div class="chapter-head-sentinel"></div>

        <div class="chapter-head">
            <div class="head-actions">
                {{-- The apps folder IS the corner cluster (components/head-folder),
                     as on the grid — but with the scroll page's own apps. Home
                     starts enabled here (the top of the feed always exists);
                     pericope-scroll.js wires all four. --}}
                {{-- persist="reader": one folder memory with the grid page
                     and the reading pages. --}}
                <x-head-folder persist="reader">
                    <button type="button" class="fld-app" id="pb-home" aria-label="Back to the top of the feed" title="Top">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4.5 20.5 19.5H3.5Z"/></svg>
                    </button>
                    <button type="button" class="fld-app" id="pb-zoom" aria-label="Zoomed-out view" title="Zoom out" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.2" y2="16.2"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    </button>
                    {{-- Share: the same panel shape as the grid's, filled by
                         pericope-scroll.js on open — the Scroll pill at its
                         top holds the gear that flips to scroll settings. --}}
                    <details class="pb-share" id="pb-share">
                        <summary class="fld-app" aria-label="Share this pericope" title="Share">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="14"/></svg>
                        </summary>
                        <div class="pbs-panel" role="group" aria-label="Share this pericope"></div>
                    </details>
                    @include('bible.partials.text-settings', ['tsChecks' => false])
                </x-head-folder>
            </div>

            <div class="chapter-head-top">
                <div class="pb-head">
                    <h1 class="pb-name" id="pb-name"></h1>
                </div>
            </div>

            {{-- The view pill: the translation switcher's anatomy verbatim.
                 Scroll is home here (a span with the check), Grid a plain
                 link; choosing Grid writes the preference before
                 navigating. --}}
            <details class="tx pb-view" id="pb-view">
                <summary class="tx-pill" aria-label="Change view">
                    <span class="pb-view-label">Scroll</span>
                    <span class="tx-caret" aria-hidden="true">&#9662;</span>
                </summary>
                <div class="tx-menu" role="menu" aria-label="Board view">
                    <span class="tx-option is-current" role="menuitemradio" aria-checked="true">
                        <span class="tx-check" aria-hidden="true">&#10003;</span>
                        <span class="tx-name">Scroll</span>
                    </span>
                    <a class="tx-option" role="menuitemradio" aria-checked="false" data-view="grid" href="{{ $gridUrl }}">
                        <span class="tx-check" aria-hidden="true"></span>
                        <span class="tx-name">Grid</span>
                    </a>
                </div>
            </details>
        </div>

        <a class="pb-back" href="{{ $hubUrl }}">&larr; All pericopae</a>

        {{-- Scroll r4: on desktop the feed sits in a 470px column with the
             RECENT RAIL beside it — the visitor's other boards as
             "accounts" (dot, name, cards · updated), straight from the
             store's index. pericope-scroll.js fills it; CSS hides it
             below the desktop breakpoint, so phones never see it. --}}
        <div id="pb-feed">
            <div class="pbf-feed"></div>
            <aside class="pbf-rail" id="pb-rail" hidden aria-label="Recent pericopae"></aside>
        </div>

        <div class="pb-empty" id="pb-empty" hidden>
            <h2>This pericope is empty</h2>
            <p>Add verses while reading: select a verse, open the folder, and choose the scissors. The feed builds itself from the board.</p>
            <a href="{{ $gridUrl }}">Open the board &rarr;</a>
        </div>
    </div>

    {{-- Shown when the slug doesn't resolve on this device. --}}
    <div class="pb-missing" id="pb-missing" hidden>
        <h2>Pericope not found</h2>
        <p>It may have been deleted, or this address was made in a different browser. Pericopes live only on the device that created them &mdash; the Share link is how a board travels.</p>
        <a href="{{ $hubUrl }}">&larr; Back to your pericopes</a>
    </div>
@endsection

@section('scripts')
{{-- One config object, one plain variable (never a comma-bearing
     expression, which the compiler would split and silently truncate).
     Deferred AFTER app.blade's deferred pericope-store.js, so the store
     exists when pericope-scroll.js runs. --}}
<script>window.MBPericopeBoardConfig = @json($boardConfig);</script>
<script src="{{ asset('js/sticky-head.js') }}?v={{ filemtime(public_path('js/sticky-head.js')) }}" defer></script>
<script src="{{ asset('js/pericope-scroll.js') }}?v={{ filemtime(public_path('js/pericope-scroll.js')) }}" defer></script>
@endsection
