@extends('layouts.app')

@section('title', 'Daily Archive — Typing Scrimmage — MEGABIBLE.net')

{{--
  =====================================================================
  DAILY ARCHIVE — INDEX  ·  /extras/scrimmage/daily/archive
  ---------------------------------------------------------------------
  Every day that has happened, newest first, read from the LEDGER —
  so a day nobody played still appears, because its verse still had its
  one turn in the century. Days past but not yet frozen say so; normal
  for a few minutes after midnight, a symptom if it lasts.

  No JS. Prev/next are custom markup rather than Laravel's paginator
  views, which are Tailwind-flavoured and would render unstyled here.
  =====================================================================
--}}
@section('styles')
<style>
    .ar-hero { margin: 0 0 .3rem; }
    .ar-hero h1 { font-size: 2.4rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }
    .ar-sub {
        font-family: var(--sans); font-size: .86rem; color: var(--muted);
        margin: .2rem 0 1.5rem; max-width: 46rem; line-height: 1.55;
    }

    .ar-day {
        border: 1px solid var(--rule); border-radius: 8px;
        background: var(--bg);
        padding: .8rem 1rem .85rem;
        margin-bottom: .7rem;
        font-family: var(--sans);
        display: flex; flex-wrap: wrap; align-items: baseline; gap: .3rem 1rem;
    }
    .ar-date {
        flex: 0 0 7.5rem;
        font-size: .78rem; color: var(--muted);
        font-variant-numeric: tabular-nums;
    }
    .ar-verse { flex: 1 1 12rem; min-width: 0; font-size: 1rem; }
    .ar-verse a { color: var(--ink); text-decoration: none; }
    .ar-verse a:hover { color: var(--accent); text-decoration: underline; }

    .ar-champ { flex: 0 0 auto; font-size: .86rem; color: var(--ink); }
    .ar-champ b { color: var(--accent); letter-spacing: .04em; }
    .ar-turnout { flex: 0 0 auto; font-size: .78rem; color: var(--muted); }
    .ar-quiet { flex: 1 1 auto; font-size: .82rem; color: var(--muted); font-style: italic; }

    /* The curator's line, when a day was chosen on purpose. */
    .ar-note {
        flex: 1 1 100%; font-size: .8rem; color: var(--muted);
        font-style: italic; margin-top: .1rem;
    }

    .ar-pager {
        display: flex; justify-content: space-between; align-items: center;
        margin-top: 1.2rem; font-family: var(--sans); font-size: .86rem;
    }
    .ar-pager a { color: var(--accent); text-decoration: none; }
    .ar-pager a:hover { text-decoration: underline; }
    .ar-pager span { color: var(--muted); }

    .ar-foot {
        font-family: var(--sans); font-size: .8rem; color: var(--muted);
        margin-top: 1.6rem; line-height: 1.6;
    }
    .ar-foot a { color: var(--accent); text-decoration: none; }
    .ar-foot a:hover { text-decoration: underline; }
</style>
@endsection

@section('content')
    <div class="ar-hero">
        <h1>Daily Archive</h1>
    </div>
    <p class="ar-sub">
        One verse a day from a corpus of over 40,000.
        @if ($daysDone)
            <br><strong>{{ number_format($daysDone) }}</strong>
            {{ $daysDone === 1 ? 'day' : 'days' }} since day 1.
        @endif
    </p>

    @forelse ($rows as $r)
        <div class="ar-day">
            <span class="ar-date">{{ $r['label'] }}</span>
            <span class="ar-verse"><a href="{{ $r['dayUrl'] }}">{{ $r['verse'] }}</a></span>

            @if ($r['champion'])
                <span class="ar-champ">
                    <b>{{ $r['champion']['name'] }}</b>
                    &middot; {{ number_format($r['champion']['score'], 2) }} marks
                    @if ($r['champion']['tx'])
                        <span class="ar-turnout">{{ strtoupper($r['champion']['tx']) }}</span>
                    @endif
                </span>
                <span class="ar-turnout">
                    {{ $r['seats'] }} {{ $r['seats'] === 1 ? 'name' : 'names' }}
                </span>
            @elseif ($r['frozen'])
                <span class="ar-quiet">No name was claimed.</span>
            @else
                <span class="ar-quiet">No one played this day.</span>
            @endif

            @if ($r['note'])
                <span class="ar-note">&ldquo;{{ $r['note'] }}&rdquo;</span>
            @endif
        </div>
    @empty
        <div class="ar-day">
            <span class="ar-quiet">
                No days have finished yet.
            </span>
        </div>
    @endforelse

    <div class="ar-pager">
        @if ($paginator->previousPageUrl())
            <a href="{{ $paginator->previousPageUrl() }}">&larr; Newer days</a>
        @else
            <span>&larr; Newer days</span>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}">Older days &rarr;</a>
        @else
            <span>Older days &rarr;</span>
        @endif
    </div>

    <p class="ar-foot">
        <a href="{{ route('typing.scrimmage.daily') }}">Today&rsquo;s daily</a>
        &middot; <a href="{{ route('typing.scrimmage.boards') }}">Scrimboards</a>
        &middot; <a href="{{ route('typing.scrimmage') }}">Scrimmage builder</a>
    </p>
@endsection
