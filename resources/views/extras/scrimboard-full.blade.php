@extends('layouts.app')

@section('title', $label . ' — Scrimboard — MEGABIBLE.net')

{{--
  =====================================================================
  ONE FULL BOARD  ·  /extras/scrimmage/{b}/{c}/{v}/scrimboard-{lang}
  ---------------------------------------------------------------------
  The whole intra-day field for one verse in one LANGUAGE — every name
  up to board_cap, in the board's own order (marks, then accuracy, then
  whoever got there first). Server-rendered, uncached: this is the page
  people refresh to watch a duel, and it changes on every submission.

  No translation in the URL, and none needed: the challenge key comes
  from (lang, book, chapter, verse) alone, and the TR column shows which
  edition each row actually typed. The -es shape is reserved; until
  Spanish imports land it renders the coming-soon state below.
  =====================================================================
--}}
@section('styles')
<style>
    .fb-hero { margin: 0 0 .2rem; }
    .fb-hero h1 { font-size: 2rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }
    .fb-mode {
        display: block; font-family: var(--sans); font-size: .78rem;
        text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
        margin: .15rem 0 0;
    }
    .fb-meta {
        font-family: var(--sans); font-size: .84rem; color: var(--muted);
        margin: .6rem 0 1rem;
    }
    .fb-actions { margin: 0 0 1.3rem; font-family: var(--sans); font-size: .86rem; }
    .fb-actions a { color: var(--accent); text-decoration: none; margin-right: 1.2rem; }
    .fb-actions a:hover { text-decoration: underline; }
    .fb-play {
        display: inline-block;
        color: #fff !important; background: var(--accent);
        border: 1px solid var(--accent); border-radius: 999px;
        padding: .4rem 1.1rem; font-weight: 700;
        transition: filter .12s, transform .14s ease;
    }
    .fb-play:hover { filter: brightness(1.12); transform: scale(1.03); text-decoration: none !important; }

    .fb-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .9rem 1.1rem 1rem; background: var(--bg);
        font-family: var(--sans);
        overflow-x: auto;
    }
    .fb-card table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    .fb-card th {
        text-align: left; font-size: .68rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
        padding: .2rem .4rem; border-bottom: 1px solid var(--rule);
        white-space: nowrap;
    }
    .fb-card td {
        padding: .34rem .4rem; border-bottom: 1px solid var(--rule);
        white-space: nowrap;
    }
    .fb-card tr:last-child td { border-bottom: none; }
    .fb-card .num { text-align: right; font-variant-numeric: tabular-nums; }
    .fb-held { color: var(--accent); margin-right: .2rem; }
    .fb-when { color: var(--muted); font-size: .78rem; }

    .fb-empty { color: var(--muted); font-style: italic; padding: .5rem .1rem; }

    .fb-foot {
        font-family: var(--sans); font-size: .78rem; color: var(--muted);
        margin-top: .8rem;
    }
</style>
@endsection

@section('content')
    <div class="fb-hero">
        <h1>{{ $label }}</h1>
        <span class="fb-mode">
            Scrimboard &mdash; {{ $lang === 'en' ? 'English' : 'Spanish' }},
            all editions, one board
        </span>
    </div>

    @if ($comingSoon)
        {{-- The reserved shape: a real page whose day hasn't come. --}}
        <p class="fb-meta">
            Spanish scrimboards arrive with
            <strong>megabiblia.net</strong> &mdash; the verse is waiting, and so is the board.
        </p>
        <div class="fb-actions">
            <a href="{{ $hubUrl }}">&larr; All scrimboards</a>
        </div>
    @else
        <p class="fb-meta">
            {{ number_format($plays) }} {{ $plays === 1 ? 'play' : 'plays' }} on this board all-time
            @unless ($sabbath)
                &middot; {{ count($rows) }} {{ count($rows) === 1 ? 'name' : 'names' }} seated
            @endunless
            &middot; the sabbath cut keeps the top {{ (int) config('typing.board_size') }}
        </p>

        <div class="fb-actions">
            <a class="fb-play" href="{{ $playUrl }}">Type this scrim &rarr;</a>
            <a href="{{ $readerUrl }}">Read it in context</a>
            <a href="{{ $hubUrl }}">All scrimboards</a>
        </div>

        <div class="fb-card">
            @if (count($rows))
                <table>
                    <thead>
                        <tr>
                            <th class="num">#</th><th>Name</th>
                            <th class="num">Net WPM</th><th class="num">Acc</th>
                            <th class="num">TR</th><th class="num">Marks</th>
                            <th>Set</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $r)
                            <tr>
                                <td class="num">{{ $i + 1 }}</td>
                                <td>@if ($r['held'])<span class="fb-held" title="Survived the nightly trim — stands until unseated">&#9733;</span>@endif{{ $r['name'] }}</td>
                                <td class="num">{{ number_format($r['net'], 1) }}</td>
                                <td class="num">{{ number_format($r['acc'], 1) }}%</td>
                                <td class="num">{{ strtoupper($r['tx']) }}</td>
                                <td class="num">{{ number_format($r['score'], 2) }}</td>
                                <td class="fb-when">{{ $r['when'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif ($sabbath)
                <div class="fb-empty">
                    The boards rest today. This one&rsquo;s standings are veiled until
                    midnight &mdash; uncut, unchanged, merely unseen.
                </div>
            @else
                <div class="fb-empty">No one has typed this scrimmage yet. Be the first.</div>
            @endif
        </div>

        <p class="fb-foot">
            &#9733; marks a row crowned at the sabbath cut &mdash; it stands until unseated,
            all week, even as new scores push past it. Names are four-character masks,
            one seat per name; a better score under the same name takes the seat over.
        </p>
    @endif
@endsection
