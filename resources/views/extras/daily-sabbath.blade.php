@extends('layouts.app')

@section('title', 'The Sabbath — Daily Scrimmage — MEGABIBLE.net')

{{--
  =====================================================================
  THE SABBATH  ·  /extras/scrimmage/daily, on Saturdays
  ---------------------------------------------------------------------
  No verse is chosen for a Saturday, ever — the ledger simply has no
  row, the picker refuses to create one, and this page says why. The
  daily returns at Sunday 00:00 site time.
  =====================================================================
--}}
@section('styles')
<style>
    .sb-hero { margin: 0 0 .2rem; }
    .sb-hero h1 { font-size: 2rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }
    .sb-when {
        display: block; font-family: var(--sans); font-size: .78rem;
        text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
        margin: .2rem 0 1rem;
    }
    .sb-card {
        border: 1px solid var(--rule); border-left: 3px solid var(--accent);
        border-radius: 8px; background: var(--bg);
        padding: 1rem 1.2rem 1.1rem; max-width: 42rem;
        font-family: var(--sans); font-size: .92rem; line-height: 1.65;
        color: var(--ink);
    }
    .sb-verse {
        font-family: var(--reading-family); font-size: 1.05rem;
        color: var(--muted); font-style: italic; margin: .8rem 0 0;
    }
    .sb-links { margin-top: 1.1rem; font-family: var(--sans); font-size: .86rem; }
    .sb-links a { color: var(--accent); text-decoration: none; margin-right: 1.2rem; }
    .sb-links a:hover { text-decoration: underline; }
</style>
@endsection

@section('content')
    <div class="sb-hero">
        <h1>The Sabbath</h1>
        <span class="sb-when">Daily Scrimmage &middot; {{ $label }}</span>
    </div>

    <div class="sb-card">
        There is no daily verse today. The daily rests on the sabbath, and the
        scrimboards rest with it &mdash; you may still type any scrimmage you
        like, but no score is kept and no name is set. Everything returns at
        midnight tonight, boards restored, last week&rsquo;s champions crowned.
        <p class="sb-verse">
            &ldquo;Six days shall work be done: but the seventh day is the sabbath
            of rest.&rdquo; &mdash; Leviticus 23:3
        </p>
    </div>

    <div class="sb-links">
        <a href="{{ route('typing.scrimmage') }}">Scrimmage builder</a>
        <a href="{{ route('typing.scrimmage.daily.archive') }}">The daily archive</a>
        <a href="{{ route('typing.scrimmage.boards') }}">Scrimboards</a>
    </div>
@endsection
