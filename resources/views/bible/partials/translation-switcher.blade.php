{{--
  Translation switcher — a pill button that opens a dropdown of every
  translation the current book/chapter is available in. Built on the native
  <details>/<summary> element, so open/close needs no JavaScript.

  The .tx* styles and the click-away/Escape script both live in app.blade.php,
  so this partial is markup only — and editing it updates EVERY page that
  includes it.

  Required data (passed in by the including view):
    $translation       — the Translation being read now (shown as the active row)
    $otherTranslations — Collection of the OTHER translations to offer
    $switchRoute       — route name a row should link to ('bible.book' | 'bible.chapter')
    $switchParams      — that route's params EXCEPT 'translation'
                         (e.g. ['book' => $book->slug]  or
                          ['book' => $book->slug, 'chapter' => $chapter])

  Each option links to $switchRoute with the same params plus its own
  translation, so picking one keeps you in place and only swaps the translation.
--}}
@php
    // One ordered list of every translation on offer, current one included,
    // sorted by the same sort_order the controller uses. concat() returns a
    // NEW collection — it does not mutate $otherTranslations.
    $allTranslations = $otherTranslations
        ->concat([$translation])
        ->sortBy('sort_order')
        ->values();
@endphp

<details class="tx">
    <summary class="tx-pill">
        {{ $translation->name }}
        <span class="tx-caret">▾</span>
    </summary>

    <div class="tx-menu">
        @foreach ($allTranslations as $tx)
            @if ($tx->id === $translation->id)
                {{-- Active translation: checkmark, no link (you're already here). --}}
                <span class="tx-option is-current" aria-current="true">
                    <span class="tx-check">✓</span>
                    <span class="tx-name">{{ $tx->name }}</span>
                    @if ($tx->year_published)
                        <span class="tx-year">{{ $tx->year_published }}</span>
                    @endif
                </span>
            @else
                <a class="tx-option"
                   href="{{ route($switchRoute, array_merge($switchParams, ['translation' => strtolower($tx->abbreviation)])) }}">
                    <span class="tx-check"></span>
                    <span class="tx-name">{{ $tx->name }}</span>
                    @if ($tx->year_published)
                        <span class="tx-year">{{ $tx->year_published }}</span>
                    @endif
                </a>
            @endif
        @endforeach
    </div>
</details>