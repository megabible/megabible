{{--
    Floating chapter-navigation arrows, pinned to the left and right edges of
    the text column and vertically positioned (à la bible.com). Positioned and
    styled in app.blade.php under "Floating chapter-navigation arrows".

    Expects a $nav array from BibleController::chapterNav() with three keys, any
    of which may be null:

      prev   → left chevron (previous chapter, or back to the hub from ch. 1).
      next   → right chevron (next chapter). Null at the last chapter.
      rewind → "return to book hub" button. Set only at the last chapter, where
               it takes the right slot the next arrow vacated. A null side
               renders no button — that's how we stop at the first chapter (no
               left) and hand the last chapter's right slot to the rewind.
--}}
@if (! empty($nav['prev']))
    <a class="chapter-nav prev" href="{{ $nav['prev'] }}" rel="prev" aria-label="Previous chapter">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
    </a>
@endif

@if (! empty($nav['next']))
    <a class="chapter-nav next" href="{{ $nav['next'] }}" rel="next" aria-label="Next chapter">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
    </a>
@elseif (! empty($nav['rewind']))
    <a class="chapter-nav next" href="{{ $nav['rewind'] }}" rel="up" aria-label="Back to chapter list">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
            <path d="M3 3v5h5"></path>
        </svg>
    </a>
@endif