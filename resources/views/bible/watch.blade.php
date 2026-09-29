@extends('layouts.app')

{{-- watch r2 (sync): the player docks at the top while the text scrolls
     beneath it, the narrated verse highlights and stays centred, and the
     cue data rides an inline variable for watch-sync.js. r1 shipped the
     facade over static text with the standard sticky chapter head; r2
     retires the sticky HEAD on this page — the player is the fixture worth
     pinning here, and two stacked sticky elements would have to chase the
     head's runtime shrink machinery for no reader benefit. --}}

@section('title', 'Watch ' . $refBook . ($refChapter !== null ? ' ' . $refChapter : '') . ' — ' . $translation->abbreviation . ' — MEGABIBLE.net')

@section('styles')
{{--
  Canonical URL = the bare watch page, no ?video= selection — same policy as
  the reader's ?v= links: every video permutation is the same page of text,
  so search engines get one clean URL.
--}}
<link rel="canonical" href="{{ route('bible.watch', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $chapter]) }}">
{{-- Vendored facade styles (public/css). A separate stylesheet, not an
     include: it is a third-party file kept byte-identical for upgrades. --}}
<link rel="stylesheet" href="{{ asset('css/lite-yt-embed.css') }}?v={{ filemtime(public_path('css/lite-yt-embed.css')) }}">
<style>
    @include('bible.partials.sticky-head')

    /* THIS PAGE'S HEAD DOES NOT PIN. The include above is kept for its
       typography and corner-cluster layout; position:relative (a) unsticks
       it and (b) keeps it the positioning context .head-actions anchors to
       — static would hand that anchor to the container and strand the
       folder. The sentinel div and sticky-head.js are simply absent. The
       page's own sticky fixture is the player dock below. */
    .chapter-head { position: relative; --mb-head-reserve: 4.5rem; }

    /* Back-to-reading row — the reader's hub-back row, same seat. */
    .hub-back-row {
        font-family: var(--sans); font-size: .82rem;
        margin: 0 0 1.2rem;
    }
    .hub-back { color: var(--muted); text-decoration: none; }
    .hub-back:hover { color: var(--accent); }

    @include('bible.partials.reading-styles')

    /* ======================================================================
       WATCH DOCK — the player pins while the text scrolls beneath it
       ----------------------------------------------------------------------
       Sticky at the very top (the head scrolls away above it). Only the
       facade lives inside; the credit line and alternates sit in .watch-meta
       BELOW the dock so they scroll away with the page instead of eating
       player-height forever. background var(--bg) so text never ghosts
       through the dock's side gutters while sliding under.
       ====================================================================== */
    .watch-dock {
        position: sticky; top: 0; z-index: 30;
        background: var(--bg);
        padding: .35rem 0 .55rem;
        margin: 0;
    }
    .watch-dock lite-youtube {
        max-width: 100%;
        border-radius: 8px;
        overflow: hidden;
    }
    /* Until the custom element upgrades (JS still parsing), reserve the
       16:9 box so the page never jumps. The vendor's own sizing takes over
       on upgrade; aspect-ratio here only guards the pre-upgrade frame. */
    .watch-dock lite-youtube:not(.lyt-activated) {
        aspect-ratio: 16 / 9;
    }
    /* Short viewports (landscape phones): a full-width 16:9 dock would
       swallow the screen. Cap the player by HEIGHT via its width — the
       vendor's ratio box follows width, so this is the one safe lever. */
    @media (max-height: 520px) {
        .watch-dock lite-youtube {
            max-width: calc(52vh * 16 / 9);
            margin: 0 auto;
        }
    }

    .watch-meta { margin: 0 0 1.9rem; }

    /* Credit line: title/creator on the left, script edition on the right.
       Wraps as one column on narrow screens. */
    .watch-credit {
        display: flex; flex-wrap: wrap; gap: .25rem .9rem;
        align-items: baseline; justify-content: space-between;
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: .6rem 0 0; letter-spacing: .02em; line-height: 1.5;
    }
    .watch-credit a { color: var(--accent); text-decoration: none; }
    .watch-credit a:hover { text-decoration: underline; }
    .watch-credit .origin-badge {
        font-size: .68rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .1em; color: var(--muted);
        border: 1px solid var(--rule); border-radius: 999px;
        padding: .1rem .55rem; margin-right: .35rem; white-space: nowrap;
    }

    /* Alternate animations of this same chapter, when they exist. */
    .watch-alts {
        font-family: var(--sans); font-size: .82rem; color: var(--muted);
        margin: .55rem 0 0; line-height: 1.6;
    }
    .watch-alts a { color: var(--accent); text-decoration: none; }
    .watch-alts a:hover { text-decoration: underline; }

    /* ======================================================================
       SYNC HIGHLIGHT — the narrated verse
       ----------------------------------------------------------------------
       The same text-hugging carriers the reader's Focus mode uses: prose
       verses are inline spans (background already hugs), poetry verses are
       block paragraphs whose inner .vt span carries the paint so a short
       line never trails a bar of empty highlight. watch-sync.js moves the
       .is-playing class; every visual lives here.
       ====================================================================== */
    .reading p:not(.poetry) .verse,
    .reading p.poetry .vt {
        border-radius: 4px;
        padding: 0 .1em;
        margin: 0 -.1em;
        transition: background-color .25s ease;
        -webkit-box-decoration-break: clone;
                box-decoration-break: clone;
    }
    .reading p:not(.poetry) .verse.is-playing,
    .reading p.poetry.verse.is-playing .vt {
        background: var(--rule);
    }

    /* Verses turn into seek targets only once the player is live —
       watch-sync.js adds .sync-live to body at that moment, so the pointer
       cursor appears exactly when a tap will actually do something. */
    .sync-live .reading .verse { cursor: pointer; }

    /* The resume pill: appears when the user scrolls away from the
       narration; one tap re-centres and re-follows. Fixed above everything
       on the page (the dock is 30). */
    .watch-follow-pill {
        position: fixed; left: 50%; bottom: 1.1rem;
        transform: translateX(-50%); z-index: 70;
        font-family: var(--sans); font-size: .82rem; letter-spacing: .02em;
        color: var(--bg); background: var(--accent);
        border: none; border-radius: 999px;
        padding: .55rem 1.1rem; cursor: pointer;
        box-shadow: 0 8px 28px rgba(42,31,23,.25);
    }
    .watch-follow-pill:hover { filter: brightness(1.12); }
    .watch-follow-pill[hidden] { display: none; }
</style>
@endsection

@section('content')
    <div class="chapter-head">
        <div class="head-actions">
            <x-head-folder persist="reader">
                {{-- Back to reading: the eyeball's inverse — an open book.
                     One-shot app (plain anchor). --}}
                <a class="fld-app" href="{{ $readUrl }}"
                   aria-label="Read this chapter" title="Read this chapter">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg>
                </a>
                @include('bible.partials.text-settings')
            </x-head-folder>
        </div>

        <div class="chapter-head-top">
            <h1>{{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</h1>
        </div>
    </div>

    <p class="hub-back-row"><a class="hub-back" href="{{ $readUrl }}">&larr; Read {{ $refBook }}@if ($refChapter !== null) {{ $refChapter }}@endif</a></p>

    <div class="watch-dock">
        {{-- The facade. Nothing YouTube loads until the user clicks play,
             except the poster thumbnail (served from i.ytimg.com — a static
             image, the one pre-click external fetch this page makes). The
             js-api attribute routes activation through the IFrame API and
             exposes getYTPlayer(), which watch-sync.js consumes strictly
             AFTER the user's own click. The anchor inside is the no-JS /
             crawler fallback; the library converts it into the play button
             when it boots. --}}
        <lite-youtube videoid="{{ $video->youtube_id }}" js-api params="rel=0"
                      title="{{ $video->title ?? ($refBook . ($refChapter !== null ? ' ' . $refChapter : '')) }}">
            <a class="lyt-playbtn" href="https://www.youtube.com/watch?v={{ $video->youtube_id }}">
                <span class="lyt-visually-hidden">Play: {{ $video->title ?? ($refBook . ($refChapter !== null ? ' ' . $refChapter : '')) }}</span>
            </a>
        </lite-youtube>
    </div>

    <div class="watch-meta">
        <p class="watch-credit">
            <span>
                <span class="origin-badge">{{ $isCrowd ? 'Community' : 'In-house' }}</span>
                @if ($isCrowd && $video->creator_name)
                    Animated by
                    @if ($video->creator_channel_url)
                        <a href="{{ $video->creator_channel_url }}" rel="noopener" target="_blank">{{ $video->creator_name }}</a>
                    @else
                        {{ $video->creator_name }}
                    @endif
                @else
                    A MEGABIBLE.net original
                @endif
            </span>
            <span>Narration follows the {{ $translation->abbreviation }}</span>
        </p>

        @if ($alternates !== [])
            <p class="watch-alts">Also animated:
                @foreach ($alternates as $alt)
                    <a href="{{ $alt['url'] }}">{{ $alt['label'] }}</a>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    </div>

    {{-- The chapter text, in the video's own script edition — the reader's
         exact flow partial, so every verse fragment carries data-verse (the
         sync's highlight/seek handle) and the id anchors. No footnote
         markers here: the watch page is for following along, and the empty
         notes map keeps the flow clean. --}}
    <div class="reading">
        @include('bible.partials.reading-flow', ['layout' => $layout, 'linkTranslation' => strtolower($translation->abbreviation)])
    </div>
@endsection

@section('scripts')
{{-- Cue data for watch-sync.js: one prebuilt variable, shaped and sorted in
     the controller, echoed whole — the only pattern a json directive is
     safe with (comma-bearing expressions inside it compile to mangled PHP).
     An empty list renders [] and the sync script stands down by itself. --}}
<script>window.mbWatchCues = @json($cues);</script>
<script src="{{ asset('js/lite-yt-embed.js') }}?v={{ filemtime(public_path('js/lite-yt-embed.js')) }}" defer></script>
<script src="{{ asset('js/watch-sync.js') }}?v={{ filemtime(public_path('js/watch-sync.js')) }}" defer></script>
@endsection
