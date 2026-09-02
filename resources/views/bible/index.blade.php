@extends('layouts.app')

{{-- Sets the <title> in the layout. Home keeps the default, but this is here
     so you can see the pattern; other pages will set something specific. --}}
@section('title', 'MEGABIBLE.net')

{{-- HOME-PAGE-ONLY CSS. This gets injected into the layout's <head> wherever
     @yield('styles') sits, so it loads after (and can override) the base styles. --}}
@section('styles')
<style>
    /* Homepage First Testament/Second Testament titles and blurb styles */
    .testament{margin-bottom:1rem;}
    .testament-title{font-size:2.2rem;font-weight:400;letter-spacing:-.01em;margin:1.8rem 0 .3rem;}
    .testament:first-of-type .testament-title{margin-top:0;}
    .testament-blurb{font-family:var(--sans);font-size:.82rem;color:var(--muted);margin:0 0 .65rem;letter-spacing:.02em;line-height:1.5;}
    .testament-blurb:last-of-type{margin-bottom:1.4rem;}
    

    .section-head{color:var(--accent);font-size:1.3rem;font-weight:600;margin:2.3rem 0 .8rem;letter-spacing:.01em;}
    .section-head .sub{font-style:italic;font-weight:400;color:var(--muted);font-size:1rem;margin-left:.45rem;}

    /* Subgroup label — sits below a section-head, above its own book grid. */
    .subgroup-head{font-family:var(--sans);font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:.9rem 0 .65rem;}

    .book-grid{list-style:none;margin:0;padding:0;display:grid;gap:.5rem;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));}
    .book{display:block;text-decoration:none;border:1px solid var(--rule);border-radius:5px;padding:.55rem .8rem;font-size:1.05rem;line-height:1.3;background:var(--bg);transition:background .12s,border-color .12s,color .12s;}
    .book.live{color:var(--ink);}
    .book.live:hover{background:var(--accent);color:#fff;border-color:var(--accent);}
    .book.soon{color:var(--soon);border-style:dashed;cursor:default;}

    /* Short label is hidden until the page drops below the container's max
       width (820px). Below that, any book WITH a short name swaps to it —
       keeping every button one row tall. */
    .bk-short{display:none;}

    @media (max-width:420px){
        .book.has-short .bk-full  {display:none;}
        .book.has-short .bk-short {display:inline;}
    }

    @media (max-width:560px){
        .book-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}
    }
</style>
@endsection

{{-- THE PAGE BODY. This gets injected into the layout wherever @yield('content')
     sits — i.e. between the shared header and the shared footer. --}}
@section('content')
    @php
        // Per-book short labels for narrow buttons (config/canon.php).
        // Keeps long names like "1 Thessalonians" from wrapping to two rows.
        $homeShortNames = config('canon.home_short_names', []);
        $homeNames      = config('canon.home_names', []);
    @endphp

    @foreach ($testaments as $testament)
        <section class="testament">
            <h2 class="testament-title">{{ $testament['label'] }}</h2>
            @php
                // A blurb may be a single string OR an array of paragraphs.
                // Casting to an array lets both shapes render identically, so
                // any section still using a plain string keeps working.
                $blurbs = array_filter(
                    (array) ($testament['blurb'] ?? []),
                    fn ($p) => trim((string) $p) !== ''
                );
            @endphp
            @foreach ($blurbs as $para)
                <p class="testament-blurb">{{ $para }}</p>
            @endforeach

            @foreach ($testament['sections'] as $sectionKey)
                @php $section = $sections[$sectionKey] ?? null; @endphp
                @continue (! $section)

                <h3 class="section-head">
                    {{ $section['label'] }}
                    @if (!empty($section['subtitle']))
                        <span class="sub">{{ $section['subtitle'] }}</span>
                    @endif
                </h3>

                @php
                    // Normalize: a flat section ('books') becomes a single unlabelled
                    // group, so the markup below can treat every section identically.
                    $groups = $section['subgroups'] ?? [
                        ['label' => null, 'books' => $section['books'] ?? []],
                    ];
                @endphp

                @foreach ($groups as $group)
                    @if (!empty($group['label']))
                        <h4 class="subgroup-head">{{ $group['label'] }}</h4>
                    @endif

                    <ul class="book-grid">
                        @foreach ($group['books'] as $slug)
                            @php $book = $books->get($slug); @endphp
                            @if ($book)
                                @php
                                    // If a short name is defined for this book, render BOTH
                                    // labels and let CSS choose by width; otherwise just the
                                    // full name. e() escapes the text exactly like {{ }} does.
                                    $full  = $homeNames[$book->slug] ?? $book->name;
                                    $short = $homeShortNames[$book->slug] ?? null;
                                    $label = $short
                                        ? '<span class="bk-full">'.e($full).'</span><span class="bk-short">'.e($short).'</span>'
                                        : e($full);

                                    // A book links wherever it actually exists. $linkTranslation
                                    // holds the chosen translation slug per book_id: the reader's
                                    // current translation if it has the book, otherwise the best
                                    // available fallback (e.g. KJV reader clicking Psalm 151 lands
                                    // in WEB). No key here = no verses anywhere yet = "soon".
                                    $linkTo = $linkTranslation[$book->id] ?? null;
                                @endphp
                                @if ($linkTo)
                                    <li><a class="book live{{ $short ? ' has-short' : '' }}"
                                           href="{{ route('bible.book', [$linkTo, $book->slug]) }}">{!! $label !!}</a></li>
                                @else
                                    <li><span class="book soon{{ $short ? ' has-short' : '' }}">{!! $label !!}</span></li>
                                @endif
                            @endif
                        @endforeach
                    </ul>
                @endforeach
            @endforeach
        </section>
    @endforeach
@endsection