{{--
    Shared chapter reading flow.

    Walks the element list from App\Support\ChapterLayout::build() and renders it:
    headings, prose paragraphs (each verse its own inline .verse span), poetry
    lines, and stanza breaks. Used by the single-translation chapter view AND by
    each column of the parallel view.

    Expects:
      $layout           array   the element list from ChapterLayout::build()
      $idPrefix         string  optional. Prepended to each verse-anchor id so the
                                parallel view can render two columns without
                                colliding duplicate ids (e.g. 'kjv-' => id="kjv-v3").
                                The single chapter view omits it, keeping the bare
                                id="v3" its Focus/Synthesis ?v= deep-links rely on.
      $linkTranslation  string  optional. The reader's translation slug (e.g. 'kjv').
                                When set, reference headings (r/mr/sr) become links
                                into that translation. Omitted → plain text.
      $linkMode         string  optional. 'reader' (default) | 'vigil'. Chooses the
                                URL shape reference headings link to — the vigil
                                links into /extras/vigil/… with no
                                ?v= selection. Ignored unless $linkTranslation set.

    FOOTNOTE MARKERS: fragments may carry a 'notes' key (attached by
    ChapterLayout to the LAST fragment of an annotated verse). Each note renders
    as a superscript letter INSIDE the verse span, immediately after the text —
    an <a> to the matching #fn-{marker} row in the end-of-chapter footnotes
    list. The fn() helper below keeps the two inline call sites identical.
--}}
@php
    // Superscript marker run for one fragment's notes. Rendered with no
    // whitespace control characters so the letters hug the last word.
    $fnPrefix = $idPrefix ?? '';
    $fn = function (?array $notes) use ($fnPrefix) {
        if (empty($notes)) return '';
        $out = '<sup class="fn-markers">';
        foreach ($notes as $note) {
            $m = e($note['marker']);
            $out .= '<a class="fn-marker" href="#' . e($fnPrefix) . 'fn-' . $m . '">' . $m . '</a>';
        }
        return $out . '</sup>';
    };
    $linkMode = $linkMode ?? 'reader';
@endphp
@foreach ($layout as $el)
    @switch($el['type'])

        @case('heading')
            @if (! empty($linkTranslation) && in_array($el['kind'], ['r', 'mr', 'sr'], true))
                <div class="heading {{ $el['kind'] }} lvl-{{ $el['level'] }}">{!! \App\Support\ReferenceLinker::linkify($el['text'], $linkTranslation, $linkMode) !!}</div>
            @else
                <div class="heading {{ $el['kind'] }} lvl-{{ $el['level'] }}">{{ $el['text'] }}</div>
            @endif
            @break

        @case('stanza')
            <div class="stanza-break"></div>
            @break

        {{-- Poetry line: the whole <p> is one verse fragment (so the full line is
             tappable); the inner .vt span carries the highlight, hugging the text. --}}
        @case('poetry')
            <p class="poetry {{ $el['style'] }} verse" data-verse="{{ $el['vn'] }}"><span class="vt">@if (! is_null($el['n']))<span class="verse-number" id="{{ $idPrefix ?? '' }}v{{ $el['n'] }}">{{ $el['n'] }}</span>@endif{{ $el['text'] }}{!! $fn($el['notes'] ?? null) !!}</span></p>
            @break

        {{-- Prose paragraph: each verse is its own inline .verse span so verses
             sharing a paragraph can be highlighted independently. --}}
        @case('para')
            <p class="{{ $el['style'] }}">@foreach ($el['verses'] as $v)<span class="verse" data-verse="{{ $v['vn'] }}">@if (! is_null($v['n']))<span class="verse-number" id="{{ $idPrefix ?? '' }}v{{ $v['n'] }}">{{ $v['n'] }}</span>@endif{{ $v['text'] }}{!! $fn($v['notes'] ?? null) !!}</span> @endforeach</p>
            @break

    @endswitch
@endforeach
