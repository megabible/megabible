@php
    // Parse a leading "chapter" or "chapter:verse" out of the ref so we can link it.
    $chapter = null; $verse = null;
    $refStr  = $node['ref'] ?? '';
    if ($refStr !== '' && preg_match('/^(\d+)(?::(\d+))?/', $refStr, $m)) {
        $chapter = (int) $m[1];
        $verse   = isset($m[2]) ? (int) $m[2] : null;
    }

    // hub-xref r3: outline refs join the verse popover system — the same
    // data-xref-* contract HubProse stamps in prose, read by the same
    // xref-popover.js engine (its selector is the data attribute, not a
    // class, so .outline-ref keeps its own chrome untouched).
    //
    // The run for the preview fetch:
    //   "4:1-34"     same-chapter range  → "1-34" (the engine trims the fetch)
    //   "22:1-24:53" cross-chapter range → "1" (single-chapter reader rule,
    //                 the second colon is the tell)
    //   "3:1"        single verse        → "1"
    //   "3"          chapter only        → no attrs, no popover (as before)
    // The dash may be a hyphen, en dash, or em dash — outline data carries
    // all three.
    $xv = null;
    if ($verse !== null) {
        if (preg_match('/^\d+:\d+\s*[-\x{2013}\x{2014}]\s*(\d+)(:\d+)?\s*$/u', $refStr, $r)) {
            $xv = empty($r[2]) ? $verse . '-' . (int) $r[1] : (string) $verse;
        } else {
            $xv = (string) $verse;
        }
    }
@endphp
<li class="outline-item">
    <div class="outline-row">
        <span class="outline-title">{{ $node['title'] ?? '' }}</span>
        @if (! empty($node['ref']))
            @if ($chapter)
                <a class="outline-ref"
                   href="{{ route('bible.chapter', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $chapter]) }}{{ $verse ? '#v'.$verse : '' }}"
                   @if ($xv !== null)
                   data-xref-book="{{ $book->slug }}"
                   data-xref-chapter="{{ $chapter }}"
                   data-xref-v="{{ $xv }}"
                   @endif>{{ $node['ref'] }}</a>
            @else
                <span class="outline-ref">{{ $node['ref'] }}</span>
            @endif
        @endif
    </div>
    @if (! empty($node['children']))
        <ol class="outline-children">
            @foreach ($node['children'] as $child)
                @include('bible.partials.outline-node', ['node' => $child, 'translation' => $translation, 'book' => $book])
            @endforeach
        </ol>
    @endif
</li>
