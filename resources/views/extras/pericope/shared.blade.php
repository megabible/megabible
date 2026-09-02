@extends('layouts.app')

{{--
    SHARED PERICOPE  ·  /extras/pericope/shared  ·  S2 of the share plan
    --------------------------------------------------------------------
    The receiving end of a share link. The board travels ENTIRELY in the
    URL fragment, which never reaches this server — so this page is the
    thinnest shell on the site: a progress panel, an error panel, and the
    script (public/js/pericope-share.js) that decodes location.hash,
    imports the board into the visitor's own localStorage, fetches verse
    text by reference, and redirects to the new board. No fragment, a
    damaged fragment, or a newer format all land on the error panel with
    a way back to the hub.
--}}

@section('title', 'Shared pericope — MEGABIBLE')

@section('styles')
<style>
    .pbi-panel {
        max-width: 26rem; margin: 5rem auto 0; text-align: center;
    }
    .pbi-panel h1 {
        font-size: 1.9rem; font-weight: 400; letter-spacing: -.01em;
        color: var(--ink); margin: 0 0 .6rem;
    }
    .pbi-panel p {
        font-family: var(--sans); font-size: .92rem; color: var(--muted);
        line-height: 1.55; margin: 0 0 .5rem;
    }
    .pbi-detail { min-height: 1.4em; }
    /* The quiet pulse while verses arrive. */
    .pbi-pulse {
        display: inline-block; width: 10px; height: 10px; border-radius: 50%;
        background: var(--accent); margin-bottom: 1rem;
        animation: pbi-pulse 1.1s ease-in-out infinite;
    }
    @keyframes pbi-pulse { 0%, 100% { opacity: .25; } 50% { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .pbi-pulse { animation: none; opacity: .6; } }
    .pbi-back {
        display: inline-flex; align-items: center; gap: .35rem; margin-top: 1.2rem;
        font-family: var(--sans); font-size: .88rem; color: var(--muted); text-decoration: none;
    }
    .pbi-back:hover { color: var(--accent); }
</style>
@endsection

@section('content')
    <div class="pbi-panel" id="pbi-root">
        <span class="pbi-pulse" aria-hidden="true"></span>
        <h1 id="pbi-progress">Rebuilding this pericope&hellip;</h1>
        <p>It was shared as a link — every card, group and placement lives in
           the address itself, and it is being copied into this browser now.</p>
        <p class="pbi-detail" id="pbi-detail"></p>
    </div>

    <div class="pbi-panel" id="pbi-error" hidden>
        <h1>This link didn&rsquo;t open</h1>
        <p id="pbi-error-msg"></p>
        <a class="pbi-back" href="{{ $hubUrl }}">&larr; All pericopes</a>
    </div>
@endsection

@section('scripts')
{{-- One config object, one plain variable (never a comma-bearing
     expression, which the compiler would split and silently truncate). --}}
<script>window.MBPericopeSharedConfig = @json($sharedConfig);</script>
<script src="{{ asset('js/pericope-share.js') }}?v={{ filemtime(public_path('js/pericope-share.js')) }}" defer></script>
@endsection
