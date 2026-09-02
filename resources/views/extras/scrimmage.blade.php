@extends('layouts.app')

@section('title', 'Typing Scrimmage — MEGABIBLE.net')

{{--
  =====================================================================
  SCRIMMAGE BUILDER  ·  /extras/scrimmage
  ---------------------------------------------------------------------
  The entry hall. Pick a verse, see it, build the scrim — which is a
  REAL PAGE at /extras/scrimmage/{t}/{b}/{c}/{v}, rendered server-side
  by its own blade (scrimmage-verse). This page never
  shows a scrim; a URL carrying a verse never reaches this page.

  That split is why a refresh on a scrim no longer flashes: the old
  single blade hid BOTH screens until JS decided which to show, and
  the scrim screen additionally waited on a /challenge round-trip
  before it could paint. Now each URL renders exactly one thing, and
  the scrim's data arrives inside its own HTML.

  The picker never loads verse text (its outline endpoint is structure
  only), so the preview borrows the /challenge endpoint — one cached
  fetch per verse whose `variants` carry every edition's text, making
  the picker's translation pills instant.

  FUTURE NEIGHBOURS: the daily challenge and triad builders belong on
  this page, beside the verse picker — this is the room for choosing.
  =====================================================================
--}}
@section('styles')
<style>
    .sc-hero { margin: 0 0 1.4rem; }
    .sc-hero h1 { font-size: 2.4rem; font-weight: 400; margin: 0; letter-spacing: -.01em; }

    .sc-card {
        border: 1px solid var(--rule); border-radius: 8px;
        padding: 1.1rem 1.2rem 1.2rem;
        background: var(--bg);
        font-family: var(--sans);
    }
    .sc-label {
        display: block; font-size: .72rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); margin: 0 0 .35rem;
    }
    .sc-hint { font-size: .78rem; color: var(--muted); margin-top: .35rem; min-height: 1.1em; font-family: var(--sans); }
    .sc-hint.err { color: var(--accent); font-style: italic; }

    /* Verse preview: the chosen verse in reading dress, above the button. */
    .sc-preview {
        font-family: var(--reading-family);
        font-size: var(--reading-size);
        line-height: var(--reading-leading);
        color: var(--ink);
        border-left: 3px solid var(--accent);
        padding: .15rem 0 .15rem .9rem;
        margin: .9rem 0 .2rem;
    }
    .sc-preview.is-loading { color: var(--muted); font-style: italic; }

    .sc-btn {
        font-family: var(--sans); font-size: .92rem; font-weight: 700;
        color: #fff; background: var(--accent);
        border: 1px solid var(--accent); border-radius: 999px;
        padding: .55rem 1.4rem; cursor: pointer;
        transition: filter .12s, transform .14s ease;
    }
    .sc-btn:hover { filter: brightness(1.12); transform: scale(1.03); }
    .sc-btn:disabled { opacity: .45; cursor: default; transform: none; filter: none; }
    .sc-actions { margin-top: 1.2rem; }

    /* ---- Daily card ---------------------------------------------------- */
    .sc-dailycard { margin-bottom: 1.1rem; border-left: 3px solid var(--accent); }
    .sc-daily-ref {
        font-family: var(--reading-family); font-size: 1.25rem;
        color: var(--ink); margin: .3rem 0 .1rem;
    }
    .sc-daily-sub { font-size: .8rem; color: var(--muted); font-family: var(--sans); }
    .sc-daily-sub em { font-style: italic; }
    .sc-daily-go {
        display: inline-block; margin-top: .7rem;
        font-family: var(--sans); font-size: .88rem; font-weight: 700;
        color: #fff; background: var(--accent);
        border: 1px solid var(--accent); border-radius: 999px;
        padding: .45rem 1.2rem; text-decoration: none;
        transition: filter .12s, transform .14s ease;
    }
    .sc-daily-go:hover { filter: brightness(1.12); transform: scale(1.03); }
    .sc-daily-arch {
        margin-left: .9rem; font-family: var(--sans); font-size: .84rem;
        color: var(--accent); text-decoration: none;
    }
    .sc-daily-arch:hover { text-decoration: underline; }
    .sc-daily-status {
        margin-left: .7rem; font-family: var(--sans); font-size: .8rem;
        color: var(--accent); font-weight: 600;
    }
</style>
@endsection

@section('content')
    <div class="sc-hero">
        <h1>Typing Scrimmage</h1>
    </div>

    {{-- TODAY'S DAILY — the doorway card. The ✓ badge is client-side: the
         one-shot flag lives in this browser's localStorage, and the server
         neither knows nor cares (see the daily page's own gate). --}}
    <div class="sc-card sc-dailycard">
        @if ($daily['sabbath'] ?? false)
            {{-- The rest day: no verse, no CTA, no ✓ badge. The builder
                 below still works — scrims may be played, just not scored. --}}
            <span class="sc-label">The Sabbath &mdash; a day of rest</span>
            <div class="sc-daily-ref">No daily verse today</div>
            <div class="sc-daily-sub">
                The daily and the scrimboards return at midnight. Scrimmages may
                still be typed today &mdash; nothing is scored, and no name is set.
            </div>
            <a class="sc-daily-arch" style="margin-left:0"
               href="{{ route('typing.scrimmage.daily.archive') }}">The daily archive &rarr;</a>
        @else
            <span class="sc-label">Today&rsquo;s Daily scrim</span>
            <div class="sc-daily-ref">{{ $daily['label'] }}</div>
            @if ($daily['note'])
                <div class="sc-daily-sub"><em>&ldquo;{{ $daily['note'] }}&rdquo;</em></div>
            @endif
            <a class="sc-daily-go" href="{{ $daily['url'] }}">SCRIM &rarr;</a>
            <a class="sc-daily-arch" href="{{ route('typing.scrimmage.daily.archive') }}">Past days</a>
            <span class="sc-daily-status" id="sc-daily-status"
                  data-date="{{ $daily['date'] }}" hidden></span>
        @endif
    </div>

    <div class="sc-card">
        <div class="sc-field">
            <span class="sc-label">Select a Bible book, chapter, and verse to build a scrim</span>
            @include('bible.partials.verse-picker')
            <div class="sc-hint" id="sc-ref-hint">{{ $error ?? '' }}</div>
            {{-- The chosen verse itself, in reading dress — updates live as
                 the picker's translation pills are clicked. --}}
            <div class="sc-preview" id="sc-preview" hidden></div>
        </div>

        <div class="sc-actions">
            <button type="button" class="sc-btn" id="sc-load" disabled>Build scrimmage &rarr;</button>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    (function () {
        'use strict';

        /* ---- Server constants (single-variable json only) ---------------- */
        const TRANSLATIONS = @json($translations);
        const URL_RESOLVE  = @json(route('typing.challenge'));
        const URL_OUTLINE  = @json(route('typing.outline'));
        /* The scrim page's URL shape, built by the router — placeholders
           swapped client-side. Rename the route and this follows; no path
           string is ever hardcoded here. */
        const SCRIM_URL    = @json($scrimUrlPattern);

        const $ = function (id) { return document.getElementById(id); };

        let picked = { t: null, b: null, c: null, v: null };

        function scrimHref(p) {
            return SCRIM_URL
                .replace('__T__', encodeURIComponent(p.t))
                .replace('__B__', encodeURIComponent(p.b))
                .replace('__C__', p.c)
                .replace('__V__', p.v);
        }

        /* =================================================================
           PREVIEW — the verse itself, before the build.

           /challenge returns every edition's text in `variants`, so ONE
           fetch per verse covers all the picker's translation pills; the
           payload is cached by verse, and pill clicks re-render instantly.
           ================================================================= */
        const previewCache = {};             // "b.c.v" → challenge payload

        function showPreview(pick) {
            const box = $('sc-preview');
            const key = pick.b + '.' + pick.c + '.' + pick.v;

            const render = function (payload) {
                const v = (payload.variants || []).find(function (x) { return x.slug === pick.t; });
                box.textContent = v ? v.text : payload.text;
                box.classList.remove('is-loading');
                box.hidden = false;
            };

            if (previewCache[key]) { render(previewCache[key]); return; }

            box.textContent = 'Loading verse\u2026';
            box.classList.add('is-loading');
            box.hidden = false;
            fetch(URL_RESOLVE + '?mode=scrimmage&t=' + encodeURIComponent(pick.t) +
                  '&b=' + encodeURIComponent(pick.b) + '&c=' + pick.c + '&v=' + pick.v)
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.error) { box.hidden = true; return; }
                    previewCache[key] = j;
                    // Only render if this verse is still the one picked.
                    if (picked.b === pick.b && picked.c === pick.c && picked.v === pick.v) render(j);
                })
                .catch(function () { box.hidden = true; });
        }

        /* ---- The shared verse-picker partial does the choosing ------------ */
        MBVersePicker({
            root:         document.getElementById('vp-root'),
            outlineUrl:   URL_OUTLINE,
            translations: TRANSLATIONS,
            onPick: function (pick) {
                picked = { t: pick.t, b: pick.b, c: pick.c, v: pick.v };
                $('sc-load').disabled = false;
                showPreview(picked);
            },
            onClear: function () {
                picked = { t: null, b: null, c: null, v: null };
                $('sc-load').disabled = true;
                $('sc-preview').hidden = true;
            },
        });

        /* Build: a real navigation to a real page. No screen swapping, no
           history games — the scrim renders itself, server-side. */
        $('sc-load').addEventListener('click', function () {
            if (!picked.t) return;
            window.location.href = scrimHref(picked);
        });

        /* ---- Daily card badge -------------------------------------------
           The one-shot flag (mbDaily.v1, written by the daily page) against
           the card's server-stamped date. A match means this browser has
           played today; the card still links through — the daily page shows
           the played state and the practice link. */
        (function () {
            const el = $('sc-daily-status');
            if (!el) return;
            try {
                const g = JSON.parse(localStorage.getItem('mbDaily.v1'));
                if (g && g.date === el.getAttribute('data-date')) {
                    el.textContent = g.marks != null
                        ? '\u2713 done today \u2014 ' + g.marks + ' marks'
                        : '\u2713 done today';
                    el.hidden = false;
                }
            } catch (e) { /* private mode: no badge, no harm */ }
        })();
    })();
</script>
@endsection
