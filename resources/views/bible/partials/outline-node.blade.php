@php
    // Parse a leading "chapter" or "chapter:verse" out of the ref so we can link it.
    $chapter = null; $verse = null;
    if (! empty($node['ref']) && preg_match('/^(\d+)(?::(\d+))?/', $node['ref'], $m)) {
        $chapter = (int) $m[1];
        $verse   = isset($m[2]) ? (int) $m[2] : null;
    }
@endphp
<li class="outline-item">
    <div class="outline-row">
        <span class="outline-title">{{ $node['title'] ?? '' }}</span>
        @if (! empty($node['ref']))
            @if ($chapter)
                <a class="outline-ref"
                   href="{{ route('bible.chapter', ['translation' => strtolower($translation->abbreviation), 'book' => $book->slug, 'chapter' => $chapter]) }}{{ $verse ? '#v'.$verse : '' }}">{{ $node['ref'] }}</a>
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