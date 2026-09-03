{{--
  QuickNav popup panel — the shared inside of every .qn <details>.

  Rendered in two places:
    • the header logo trigger (layouts.app) — opens on the book grid;
    • the book-name trigger in the chapter + vigil readers — opens straight
      to the CURRENT book's chapter grid.

  $quicknav is supplied by QuicknavComposer, which is bound to THIS view name in
  AppServiceProvider — so the data is guaranteed wherever the panel is included,
  even from a child view whose own scope doesn't carry $quicknav.

  The optional $open* variables pre-fill Screen 2 for the book-name trigger so it
  works before the script runs (and so the hub + chapter links are real anchors
  in the HTML, which is good for SEO). The header include omits them and gets an
  empty Screen 2 that the script fills on demand when a book is tapped.

  Params (all optional; supplied only by the book-name trigger):
    $openName      string  book display name, shown as the Screen 2 title
    $openTitleUrl  string  href for that title (the book hub page)
    $openBase      string  chapter-link prefix; a chapter link is $openBase.'/'.n
    $openChapters  int     how many chapter buttons to draw (>0 → pre-fill Screen 2)
    $openChapterOffset int added to each chapter NUMBER for display only
                          (150 for the Five Psalms of David → 151..155); href
                          still uses the real 1-based n. Default 0.
--}}
@php($openChapters = $openChapters ?? 0)
@php($openChapterOffset = $openChapterOffset ?? 0)

<div class="qn-panel">
    {{-- Screen 1: the whole Bible, split by testament, each book tinted by its
         canon section colour. Identical in both triggers. --}}
    <div class="qn-books">
        @foreach ($quicknav as $testament)
            <section class="qn-testament">
                <h3 class="qn-testament-title">{{ $testament['label'] }}</h3>
                <div class="qn-grid">
                    @foreach ($testament['books'] as $bk)
                        @if ($bk['available'])
                            <button type="button" class="qn-book"
                                    style="--bk:var(--tl-{{ $bk['color'] }})"
                                    data-name="{{ $bk['name'] }}"
                                    data-url="{{ $bk['url'] }}"
                                    data-chapters="{{ $bk['chapters'] }}"
                                    data-chapter-offset="{{ $bk['offset'] ?? 0 }}">{{ $bk['label'] }}</button>
                        @else
                            <span class="qn-book qn-soon">{{ $bk['label'] }}</span>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    {{-- Screen 2: chapters for one book. Pre-filled here when the book-name
         trigger passes $open* (so it works with no JS and ships real links);
         otherwise left empty for the script to fill when a book is tapped on
         Screen 1. The script rebuilds this identically on open, so the two paths
         never disagree. --}}
    <div class="qn-chapters">
        <div class="qn-chap-head">
            <button type="button" class="qn-back">&larr; All books</button>
            <a class="qn-chap-title"
               href="{{ $openChapters > 0 ? $openTitleUrl : '#' }}">{{ $openChapters > 0 ? $openName : '' }}</a>
        </div>
        <div class="qn-chap-grid">
            @if ($openChapters > 0)
                @for ($n = 1; $n <= $openChapters; $n++)
                    <a class="qn-chap" href="{{ $openBase . '/' . $n }}">{{ $n + $openChapterOffset }}</a>
                @endfor
            @endif
        </div>
    </div>
</div>