{{--
    Parallel ⇄ single toggle. A circular control beside the chapter title.

    $href   string  destination — the OTHER view
    $active bool    true on the parallel view (button shows filled/"on")
    $label  string  accessible label + tooltip

    Styled by .parallel-toggle in layouts/app.blade.php (shared chrome, alongside
    the chapter-nav arrows).
--}}
<a class="parallel-toggle{{ ($active ?? false) ? ' is-active' : '' }}"
   href="{{ $href }}"
   aria-label="{{ $label }}"
   title="{{ $label }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3"  y="4" width="7" height="16" rx="1.5"></rect>
        <rect x="14" y="4" width="7" height="16" rx="1.5"></rect>
    </svg>
</a>