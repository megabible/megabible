{{--
    Vigil toggle — a candle (a vigil!). One icon that never changes shape; it
    just fills when the Vigil is the active mode, exactly like the parallel
    toggle. Shared circular chrome lives in layouts/app.blade.php under
    "vigil-toggle".

    Params:
      $href    string  destination — the OTHER mode of the current page.
      $label   string  accessible label + tooltip.
      $active  bool    optional (default false). true on Vigil pages → the
                       button renders "pressed" (filled). false on reader pages
                       → outline, meaning "enter the Vigil".
--}}
@php($active = $active ?? false)

<a class="vigil-toggle{{ $active ? ' is-active' : '' }}"
   href="{{ $href }}"
   aria-label="{{ $label }}"
   title="{{ $label }}"
   @if ($active) aria-pressed="true" @endif>
    {{-- Candle: a flame over a taper. currentColor so it inverts when pressed. --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        {{-- flame --}}
        <path d="M12 2.5c1.9 2 3 3.6 3 5.2a3 3 0 0 1-6 0c0-1.1.5-2.1 1.3-3.1"></path>
        {{-- taper body --}}
        <rect x="9" y="11" width="6" height="9.5" rx="1.2"></rect>
        {{-- base --}}
        <line x1="7.5" y1="21" x2="16.5" y2="21"></line>
    </svg>
</a>