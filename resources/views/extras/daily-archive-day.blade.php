@extends('layouts.app')

@section('title', $verse . ' — ' . $label . ' — Daily Archive — MEGABIBLE.net')

{{--
  =====================================================================
  ONE FROZEN DAY  ·  /extras/scrimmage/daily/archive/{date}
  ---------------------------------------------------------------------
  The field exactly as it stood at the freeze. Nothing on this page is
  recomputed: ranks, names, marks, and editions are read verbatim from
  daily_snapshot_entries, which is why the page will render identically
  in fifty years after reseeds and formula bumps.

  The top rows wear the accent — the ones etched in glory — but the
  WHOLE field is here, one row per player, however many showed up.
  =====================================================================
--}}
@section('styles')
<style>
    .dy-hero { margin: 0 0 .2rem; }
    .dy-hero h1 { font-size: 2rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }
    .dy-when {
        display: block; font-family: var(--sans); font-size: .78rem;
        text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
        margin: .2rem 0 0;
    }
    .dy-note {
        font-family: var(--sans); font-size: .86rem; color: var(--muted);
        font-style: italic; margin: .55rem 0 0;
    }
    .dy-meta {
        font-family: var(--sans); font-size: .84rem; color: var(--muted);
        margin: .7rem 0 1rem;
    }
    .dy-actions { margin: 0 0 1.3rem; font-family: var(--sans); font-size: .86rem; }
    .dy-actions a { color: var(--accent); text-decoration: none; margin-right: 1.2rem; }
    .dy-actions a:hover { text-decoration: underline; }

    .dy-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: .9rem 1.1rem 1rem; background: var(--bg);
        font-family: var(--sans); overflow-x: auto;
    }
    .dy-card table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    .dy-card th {
        text-align: left; font-size: .68rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
        padding: .2rem .4rem; border-bottom: 1px solid var(--rule); white-space: nowrap;
    }
    .dy-card td {
        padding: .34rem .4rem; border-bottom: 1px solid var(--rule); white-space: nowrap;
    }
    .dy-card tr:last-child td { border-bottom: none; }
    .dy-card .num { text-align: right; font-variant-numeric: tabular-nums; }

    /* Etched: the rows that took the day. */
    .dy-card tr.dy-top td { color: var(--ink); }
    .dy-card tr.dy-top .dy-name { color: var(--accent); font-weight: 700; letter-spacing: .04em; }
    .dy-card tr.dy-first td { font-size: .96rem; }
    /* The line where glory ends and the rest of the field begins. */
    .dy-card tr.dy-cut td { border-bottom: 2px solid var(--rule); }

    .dy-empty { color: var(--muted); font-style: italic; padding: .5rem .1rem; }
    .dy-foot {
        font-family: var(--sans); font-size: .78rem; color: var(--muted); margin-top: .9rem;
    }
</style>
@endsection

@section('content')
    <div class="dy-hero">
        <h1>{{ $verse }}</h1>
        <span class="dy-when">Daily &middot; {{ $label }}</span>
        @if ($note)
            <p class="dy-note">&ldquo;{{ $note }}&rdquo;</p>
        @endif
    </div>

    @if (! $frozen)
        <p class="dy-meta">
            This day is over. Check back shortly.
        </p>
        <div class="dy-actions">
            <a href="{{ $archiveUrl }}">&larr; Daily archive</a>
        </div>
    @else
        <p class="dy-meta">
            @if (count($rows))
                {{ count($rows) }} {{ count($rows) === 1 ? 'name' : 'names' }} etched
            @else
                No one claimed a name on this day.
            @endif
        </p>

        <div class="dy-actions">
            @if ($playUrl)
                <a href="{{ $playUrl }}">Scrim this verse</a>
            @endif
            <a href="{{ $boardUrl }}">View scrimboard</a>
            <a href="{{ $archiveUrl }}">Daily archive</a>
        </div>

        <div class="dy-card">
            @if (count($rows))
                <table>
                    <thead>
                        <tr>
                            <th class="num">#</th><th>Name</th>
                            <th class="num">Net WPM</th><th class="num">Acc</th>
                            <th class="num">Wraps</th><th class="num">TR</th>
                            <th class="num">Marks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr @class([
                                'dy-top'   => $r['rank'] <= $topN,
                                'dy-first' => $r['rank'] === 1,
                                'dy-cut'   => $r['rank'] === $topN && count($rows) > $topN,
                            ])>
                                <td class="num">{{ $r['rank'] }}</td>
                                <td class="dy-name">{{ $r['name'] }}</td>
                                <td class="num">{{ number_format($r['net'], 1) }}</td>
                                <td class="num">{{ number_format($r['acc'], 1) }}%</td>
                                <td class="num">{{ $r['wraps'] }}</td>
                                <td class="num">{{ strtoupper($r['tx']) }}</td>
                                <td class="num">{{ number_format($r['score'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="dy-empty">An empty board.</div>
            @endif
        </div>

        <p class="dy-foot">
            Nothing on this page is recomputed. Ranks, marks, and editions are as they
            stood when the day ended.
        </p>
    @endif
@endsection
