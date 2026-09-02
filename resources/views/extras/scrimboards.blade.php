@extends('layouts.app')

@section('title', 'Scrimboards — Typing Scrimmage — MEGABIBLE.net')

{{--
  =====================================================================
  SCRIMBOARD HUB  ·  /extras/scrimmage/scrimboards
  ---------------------------------------------------------------------
  The commons. Trending verses (scrimmage + daily plays summed — total
  interest in the words) and the hottest boards (scrimmage keys, ranked
  by plays, top rows previewed). Everything here is server-rendered from
  a per-period cache; there is no JS on this page at all, and the period
  filter is four honest links.

  All counts come from scrim_plays — the anonymous counters — which is
  why this page can exist: the nightly trim deletes board rows, but the
  plays ledger remembers every completed round forever.

  Per-language (…/scrimboard-en); the language switcher arrives with
  megabiblia.net.
  =====================================================================
--}}
@section('styles')
<style>
    .hub-hero { margin: 0 0 .4rem; }
    .hub-hero h1 { font-size: 2.4rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }
    .hub-sub {
        font-family: var(--sans); font-size: .86rem; color: var(--muted);
        margin: .2rem 0 1.2rem;
    }

    /* ---- Period filter: four links, one bold ---------------------------- */
    .hub-periods { margin: 0 0 1.4rem; font-family: var(--sans); font-size: .84rem; }
    .hub-periods a {
        display: inline-block;
        color: var(--muted); text-decoration: none;
        border: 1px solid var(--rule); border-radius: 999px;
        padding: .3rem .9rem; margin-right: .4rem;
    }
    .hub-periods a:hover { color: var(--ink); }
    .hub-periods a.is-active {
        color: var(--accent); border-color: var(--accent); font-weight: 700;
    }

    .hub-label {
        display: block; font-size: .72rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); margin: 0 0 .55rem;
        font-family: var(--sans);
    }

    /* ---- Trending list -------------------------------------------------- */
    .hub-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: 1rem 1.2rem 1.1rem; background: var(--bg);
        font-family: var(--sans);
        margin-bottom: 1.6rem;
    }
    .hub-trend { list-style: none; margin: 0; padding: 0; counter-reset: trend; }
    .hub-trend li {
        display: flex; align-items: baseline; gap: .7rem;
        padding: .42rem 0; border-bottom: 1px solid var(--rule);
        font-size: .92rem;
    }
    .hub-trend li:last-child { border-bottom: none; }
    .hub-trend li::before {
        counter-increment: trend; content: counter(trend);
        flex: 0 0 1.6rem; text-align: right;
        color: var(--muted); font-variant-numeric: tabular-nums; font-size: .82rem;
    }
    .hub-trend a { color: var(--ink); text-decoration: none; }
    .hub-trend a:hover { color: var(--accent); text-decoration: underline; }
    .hub-plays {
        margin-left: auto; flex: 0 0 auto;
        color: var(--muted); font-size: .8rem; font-variant-numeric: tabular-nums;
    }

    /* ---- Board cards ---------------------------------------------------- */
    .hub-boards {
        display: grid; gap: 1rem;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    }
    .hub-board {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .9rem 1rem 1rem; background: var(--bg);
    }
    .hub-board-ref { font-size: 1.02rem; margin: 0 0 .1rem; }
    .hub-board-ref a { color: var(--ink); text-decoration: none; }
    .hub-board-ref a:hover { color: var(--accent); }
    .hub-board-meta { font-size: .76rem; color: var(--muted); margin: 0 0 .55rem; }

    .hub-board table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .hub-board th {
        text-align: left; font-size: .66rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
        padding: .15rem .3rem; border-bottom: 1px solid var(--rule);
    }
    .hub-board td { padding: .28rem .3rem; border-bottom: 1px solid var(--rule); }
    .hub-board tr:last-child td { border-bottom: none; }
    .hub-board .num { text-align: right; font-variant-numeric: tabular-nums; }
    .hub-held { color: var(--accent); margin-right: .2rem; }

    .hub-board-link { margin-top: .55rem; font-size: .8rem; text-align: right; }
    .hub-board-link a { color: var(--accent); text-decoration: none; }
    .hub-board-link a:hover { text-decoration: underline; }

    .hub-empty { color: var(--muted); font-style: italic; font-size: .88rem; padding: .4rem 0; }

    .hub-back { display: inline-block; margin-top: 1.4rem; font-family: var(--sans);
        font-size: .84rem; color: var(--accent); text-decoration: none; }
    .hub-back:hover { text-decoration: underline; }
</style>
@endsection

@section('content')
    <div class="hub-hero">
        <h1>Scrimboards</h1>
    </div>
    <p class="hub-sub">
        Where the typing is. Play counts are anonymous &mdash; rounds, never people
        &mdash; and the boards run Sunday through Friday, cut at the sabbath, top
        {{ (int) config('typing.board_size') }} crowned and defending.
    </p>

    @if ($sabbath)
        <p class="hub-sub" style="color:var(--accent)">
            <strong>The boards rest today.</strong> Standings are veiled until midnight;
            the counts below keep tallying, because typing never stopped.
        </p>
    @endif

    {{-- The period filter: real links, cached per period server-side. --}}
    <nav class="hub-periods" aria-label="Time period">
        @foreach (['week' => 'This week', 'month' => 'This month', 'year' => 'This year', 'all' => 'All time'] as $p => $labelTxt)
            <a href="{{ route('typing.scrimmage.boards', $p === 'all' ? [] : ['period' => $p]) }}"
               @class(['is-active' => $period === $p])>{{ $labelTxt }}</a>
        @endforeach
    </nav>

    <div class="hub-card">
        <span class="hub-label">Most played verses</span>
        @if (count($trending))
            <ol class="hub-trend">
                @foreach ($trending as $t)
                    <li>
                        <a href="{{ $t['boardUrl'] }}">{{ $t['label'] }}</a>
                        <span class="hub-plays">{{ number_format($t['plays']) }} {{ $t['plays'] === 1 ? 'play' : 'plays' }}</span>
                    </li>
                @endforeach
            </ol>
        @else
            <div class="hub-empty">No rounds recorded in this period yet. Somebody type something.</div>
        @endif
    </div>

    <span class="hub-label">Hottest boards</span>
    @if (count($boards))
        <div class="hub-boards">
            @foreach ($boards as $b)
                <div class="hub-board">
                    <div class="hub-board-ref"><a href="{{ $b['boardUrl'] }}">{{ $b['label'] }}</a></div>
                    <div class="hub-board-meta">
                        {{ number_format($b['plays']) }} {{ $b['plays'] === 1 ? 'play' : 'plays' }}
                        &middot; {{ $b['names'] }} {{ $b['names'] === 1 ? 'name' : 'names' }} seated
                    </div>

                    @if (count($b['rows']))
                        <table>
                            <thead>
                                <tr><th class="num">#</th><th>Name</th><th class="num">Net</th><th class="num">Acc</th><th class="num">TR</th><th class="num">Marks</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($b['rows'] as $i => $r)
                                    <tr>
                                        <td class="num">{{ $i + 1 }}</td>
                                        <td>@if ($r['held'])<span class="hub-held" title="Survived the nightly trim — stands until unseated">&#9733;</span>@endif{{ $r['name'] }}</td>
                                        <td class="num">{{ number_format($r['net'], 1) }}</td>
                                        <td class="num">{{ number_format($r['acc'], 1) }}%</td>
                                        <td class="num">{{ strtoupper($r['tx']) }}</td>
                                        <td class="num">{{ number_format($r['score'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @elseif ($sabbath)
                        <div class="hub-empty">Resting &mdash; standings return at midnight.</div>
                    @else
                        <div class="hub-empty">Played, never claimed &mdash; every round bounced or walked away. An open throne.</div>
                    @endif

                    <div class="hub-board-link">
                        <a href="{{ $b['boardUrl'] }}">Full board &rarr;</a>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="hub-card"><div class="hub-empty">No boards in this period yet.</div></div>
    @endif

    <a class="hub-back" href="{{ route('typing.scrimmage') }}">&larr; Scrimmage builder</a>
    <a class="hub-back" style="margin-left:1.2rem"
       href="{{ route('typing.scrimmage.daily.archive') }}">Daily archive &rarr;</a>
@endsection
