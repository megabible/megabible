@extends('layouts.app')

@section('title', 'Search: ' . $q . ' — MEGABIBLE.net')

@php
    $isReferences  = $isReferences ?? false;
    $truncated     = $truncated ?? false;
    $refsTruncated = $refsTruncated ?? false;
@endphp

@section('styles')
<style>
    .search-head { margin: .5rem 0 1.2rem; }
    .search-head h1 { font-size: 1.9rem; font-weight: 400; margin: 0 0 .3rem; }
    .search-head .meta { font-family: var(--sans); font-size: .85rem; color: var(--muted); line-height: 1.85; }
    .search-head .meta strong { color: var(--ink); font-weight: 600; }
    /* The translation switcher sits inline where the abbreviation used to.
       vertical-align keeps the bordered pill centred on the muted sentence. */
    .search-head .meta .tx { vertical-align: middle; }

    /* Ceiling notice — printed only when a search actually hit a limit, so the
       counts above are never quietly wrong. */
    .search-cap {
        margin: .6rem 0 0;
        padding-left: .6rem;
        border-left: 2px solid var(--rule);
        font-family: var(--sans); font-size: .82rem; line-height: 1.6;
        color: var(--muted);
    }
    .search-cap strong { color: var(--ink); font-weight: 600; }

    /* ---- Book filter (mirrors the quicknav book buttons, minus the testament
       headers). One chip per book anywhere in the result set — NOT just the
       books on this page — tinted by its canon section colour, tallied with
       that book's true total. Each chip is a link that toggles its own slug in
       ?book=, so the filter is a real server-side query, shareable and able to
       reach results that sit past the paging ceiling. ---- */
    .book-filter {
        display: flex; flex-wrap: wrap; gap: .4rem;
        margin: 0 0 1.6rem;
    }
    .book-filter-btn {
        --bk: var(--tl-clay);                    /* overridden inline per book */
        display: inline-block;
        font-family: var(--sans); font-size: .82rem; font-weight: 600;
        color: #fff; background: var(--bk); text-decoration: none;
        border: 1px solid rgba(0,0,0,.14); border-radius: 6px;
        padding: .32rem .6rem; cursor: pointer;
        transition: filter .12s, opacity .12s, box-shadow .12s; white-space: nowrap;
    }
    .book-filter-btn:hover { filter: brightness(1.1); }
    .book-filter-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(107,31,31,.25); }
    /* Engaged chip: a subtle inner ring so it reads as "on". */
    .book-filter-btn.is-active { box-shadow: inset 0 0 0 2px rgba(255,255,255,.6); }
    /* While any filter is active, fade the chips that aren't engaged. */
    .book-filter.has-active .book-filter-btn:not(.is-active) { opacity: .32; }
    /* Verse tally printed inside each chip, e.g. "Gen 5". A faint translucent
       chip so the number reads as secondary to the book name yet stays legible
       on every canon-section colour. */
    .book-filter-count {
        margin-left: .4em; padding: 0 .34em;
        border-radius: 4px; background: rgba(255,255,255,.22);
        font-weight: 700; font-variant-numeric: tabular-nums;
    }
    /* "Show all" — the way back out of a filter. Deliberately unpainted so it
       reads as chrome rather than as another book. */
    .book-filter-clear {
        font-family: var(--sans); font-size: .82rem; font-weight: 600;
        color: var(--muted); background: none; text-decoration: none;
        border: 1px dashed var(--rule); border-radius: 6px;
        padding: .32rem .6rem; white-space: nowrap;
    }
    .book-filter-clear:hover { color: var(--accent); border-color: var(--accent); }

    /* ---- Result groups ---- */
    .result-group { margin-bottom: 1.8rem; }
    .result-group-title {
        display: inline-block; margin: 0 0 .7rem;
        font-family: var(--serif); font-size: 1.3rem; font-weight: 600;
        color: var(--ink); text-decoration: none;
    }
    .result-group-title:hover { color: var(--accent); text-decoration: underline; }

    .results { display: flex; flex-direction: column; gap: .9rem; }
    .result-card {
        border: 1px solid var(--rule); border-radius: 10px;
        background: var(--panel); padding: .9rem 1.1rem;
    }

    /* The reference and the copy button share one row so the copy sits in the
       card's top-right corner, mirroring the Synthesis cards in the reader. */
    .result-ref-row {
        display: flex; align-items: center; gap: .5rem;
    }
    .result-ref {
        font-family: var(--sans); font-size: .82rem; font-weight: 600;
        color: var(--accent); text-decoration: none; letter-spacing: .01em;
    }
    .result-ref:hover { text-decoration: underline; }

    /* Copy-verse button — mirrors .synthesis-copy on the reader's Synthesis
       cards. Note: it's a rounded square (6px), NOT a circle, to match that
       button exactly. Change border-radius to 50% if you want a true circle. */
    .result-copy {
        margin-left: auto; flex: 0 0 auto;
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px;
        border: none; border-radius: 6px;
        background: none; color: var(--muted); cursor: pointer;
        transition: color .12s, background .12s;
    }
    .result-copy:hover { color: var(--accent); background: var(--bg); }
    .result-copy.is-done { color: var(--accent); }
    .result-copy svg { display: block; }

    .result-text { margin: .35rem 0 0; line-height: 1.55; }
    .result-text mark {
        background: rgba(107,31,31,.16); color: inherit;
        border-radius: 3px; padding: 0 .08em;
    }

    /* ---- Pagination ---- */
    .search-pager {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
        gap: .35rem; margin: 2.2rem 0 .6rem;
    }
    .search-pager a, .search-pager span {
        font-family: var(--sans); font-size: .85rem; font-weight: 600;
        min-width: 2.1rem; text-align: center;
        padding: .35rem .6rem; border-radius: 6px;
        border: 1px solid var(--rule); color: var(--accent); text-decoration: none;
        font-variant-numeric: tabular-nums;
    }
    .search-pager a:hover { background: var(--panel); border-color: var(--accent); }
    .search-pager .is-current {
        background: var(--accent); border-color: var(--accent); color: #fff; cursor: default;
    }
    .search-pager .is-disabled { color: var(--muted); opacity: .4; cursor: default; }
    .search-pager-note {
        text-align: center; font-family: var(--sans); font-size: .8rem;
        color: var(--muted); margin: 0 0 2rem;
    }

    .search-empty {
        border: 1px dashed var(--rule); border-radius: 10px;
        padding: 1.6rem; text-align: center; color: var(--muted); font-family: var(--sans);
    }
    .search-empty a { color: var(--accent); text-decoration: none; }
</style>
@endsection

@section('content')
<div class="search-head">
    <h1>Search</h1>
    <div class="meta">
        @if ($selectedTotal > 0)
            <strong>{{ number_format($selectedTotal) }}</strong>
            {{ $selectedTotal === 1 ? 'verse' : 'verses' }}
            @unless ($isReferences) matching “{{ $q }}” @endunless
            from
            <strong>{{ number_format($bookCount) }}</strong>
            {{ $bookCount === 1 ? 'book' : 'books' }}
            in
        @else
            @if ($isReferences)
                No verses found for “{{ $q }}” in
            @else
                No verses matching “{{ $q }}” in
            @endif
        @endif
        {{-- The shared switcher. It points back at the search route, so picking
             a translation re-runs THIS query there. switchParams is built in
             the controller and already carries the book filter (but not the
             page number — a new edition starts at page 1). --}}
        @include('bible.partials.translation-switcher', [
            'switchRoute'  => 'search',
            'switchParams' => $switchParams,
        ])
    </div>

    @if ($truncated)
        <p class="search-cap">
            You can page through the first <strong>{{ number_format($resultCap) }}</strong>
            of these, in canon order. To reach the rest, narrow the search with a
            book below — the limit then applies to that book alone.
        </p>
    @elseif ($refsTruncated)
        <p class="search-cap">
            Only the first <strong>{{ $refCap }}</strong> references in this query were looked up.
        </p>
    @endif
</div>

@if ($chips->count() > 1)
    <div class="book-filter {{ $activeBooks !== [] ? 'has-active' : '' }}" role="group" aria-label="Filter results by book">
        @foreach ($chips as $chip)
            <a class="book-filter-btn {{ $chip['is_active'] ? 'is-active' : '' }}"
               style="--bk:var(--tl-{{ $chip['color'] }})"
               href="{{ $chip['url'] }}"
               aria-pressed="{{ $chip['is_active'] ? 'true' : 'false' }}"
               title="{{ $chip['is_active'] ? 'Remove' : 'Show only' }} {{ $chip['book']->name }} ({{ number_format($chip['count']) }})">{{ $chip['book']->short_name ?: $chip['book']->name }}<span class="book-filter-count">{{ number_format($chip['count']) }}</span></a>
        @endforeach

        @if ($activeBooks !== [])
            <a class="book-filter-clear" href="{{ $clearUrl }}">Show all books</a>
        @endif
    </div>
@endif

@if ($groups->isNotEmpty())
    <div class="result-groups">
        @foreach ($groups as $group)
            @php $book = $group['book']; @endphp
            <section class="result-group" data-book="{{ $book->slug }}">
                <a class="result-group-title"
                   href="{{ route('bible.book', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug]) }}">
                    {{ $book->name }}
                </a>

                <div class="results">
                    @foreach ($group['verses'] as $v)
                        @php
                            [$rbName, $rbChap] = $book->refParts($v->chapter);
                            $refLabel = $rbName . ' ' . $rbChap . ':' . $v->verse_number;
                            $url      = route('bible.chapter', [
                                'translation' => strtolower($translation->abbreviation),
                                'book'        => $book->slug,
                                'chapter'     => $v->chapter,
                                'v'           => $v->verse_number,
                            ]);
                        @endphp
                        <article class="result-card">
                            <div class="result-ref-row">
                                <a class="result-ref" href="{{ $url }}">{{ $refLabel }}</a>
                                <button type="button" class="result-copy"
                                        aria-label="Copy verse" title="Copy verse"
                                        data-ref="{{ $refLabel }}"
                                        data-text="{{ $v->text }}">
                                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                </button>
                            </div>
                            <p class="result-text">{!! $v->highlighted !!}</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if ($pagination['total'] > 1)
        <nav class="search-pager" aria-label="Search result pages">
            @if ($pagination['prev'])
                <a href="{{ $pagination['prev'] }}" rel="prev" aria-label="Previous page">←</a>
            @else
                <span class="is-disabled" aria-hidden="true">←</span>
            @endif

            @foreach ($pagination['pages'] as $p)
                @if ($p['current'])
                    <span class="is-current" aria-current="page">{{ $p['n'] }}</span>
                @else
                    <a href="{{ $p['url'] }}" aria-label="Page {{ $p['n'] }}">{{ $p['n'] }}</a>
                @endif
            @endforeach

            @if ($pagination['next'])
                <a href="{{ $pagination['next'] }}" rel="next" aria-label="Next page">→</a>
            @else
                <span class="is-disabled" aria-hidden="true">→</span>
            @endif
        </nav>
    @endif

    <p class="search-pager-note">
        Showing {{ number_format($from) }}–{{ number_format($to) }}
        of {{ number_format($selectedTotal) }}
    </p>
@else
    <div class="search-empty">
        <p>Nothing matched your search in <strong>{{ $translation->abbreviation }}</strong>.</p>
        <p style="margin-top:.6rem">
            Try a reference like <em>John 1</em> or <em>Romans 8:28</em>,
            or <a href="{{ route('home') }}">browse all books</a>.
        </p>
    </div>
@endif
@endsection

@section('scripts')
<script>
/* ---- Copy verse (matches the reader's Synthesis copy button) -------------
   The book-filter script that used to live here is gone. It filtered the
   rendered rows in the browser and rewrote ?book= to match — which meant a
   book that wasn't on the page had its slug deleted from the URL. Filtering
   is now a real server-side query and the chips are plain links. ---------- */
(function () {
    const buttons = document.querySelectorAll('.result-copy');
    if (!buttons.length) return;

    const TRANSLATION = @json($translation->abbreviation);

    const ICON_COPY  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    // Copy to the clipboard, with a fallback for older / non-secure contexts.
    const copyToClipboard = async (text) => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_) { /* fall through to the legacy path */ }
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (_) {
            return false;
        }
    };

    const flashDone = (btn) => {
        btn.classList.add('is-done');
        btn.innerHTML = ICON_CHECK;
        clearTimeout(btn._doneTimer);
        btn._doneTimer = setTimeout(() => {
            btn.classList.remove('is-done');
            btn.innerHTML = ICON_COPY;
        }, 1400);
    };

    const verseBlock = (btn) => {
        const ref  = btn.dataset.ref || '';
        const text = btn.dataset.text || '';
        const head = ref ? ref + '\n' : '';
        return head + text + '\n\n— ' + TRANSLATION + ', MEGABIBLE.net';
    };

    buttons.forEach((btn) => {
        btn.addEventListener('click', async () => {
            const ok = await copyToClipboard(verseBlock(btn));
            if (ok) flashDone(btn);
        });
    });
})();
</script>
@endsection
