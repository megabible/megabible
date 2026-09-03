{{--
    End-of-chapter footnotes list.

    Rendered INSIDE .reading, after the reading flow — the notes sit under the
    last verse, above the single footer/colophon rule. This partial draws no
    rules of its own.

    One row per note, in reading order, matching the superscript letters in the
    text. Anatomy of a row:

      .fn-line   inline wrapper around the whole row content. The selection
                 highlight paints on THIS, not the block row, so the background
                 hugs the text per line box (same fix as the verse highlight)
                 instead of smearing to the container edge.
      letter     plain text, NOT a link (the in-text marker already points
                 here; this end is the destination, via id="fn-a").
      verse      the jump-back link: ?v={n} — query-only href that resolves
                 against the current path. The page script intercepts the
                 click (ensures the verse is selected + smooth-scrolls up);
                 the href itself is the no-JS / new-tab fallback and
                 deliberately carries NO #hash.
      anchor     bold lead-in: the word(s) the note glosses (anchor_text).
      text       the note body.

    CLICK CONTRACT (wired in the chapter script, driven by data-verse):
      row body   focus this note's collection — drop any existing selection,
                 select just this verse (lighting it and every note tied to
                 it). Click again while it's the sole selection: clear all.
      verse link jump up to the verse, preserving the current selection.

    Expects:
      $footnotes  array of ['marker'=>string, 'verse'=>int, 'anchor'=>?string,
                            'text'=>string], the chapter's notes in order.
                  Renders nothing at all when empty.
      $idPrefix   string, optional. Same contract as reading-flow.
--}}
@if (! empty($footnotes))
    <section class="footnotes" aria-label="Footnotes">
        <h2 class="footnotes-title">Footnotes</h2>
        @foreach ($footnotes as $note)
            <div class="footnote-row" id="{{ $idPrefix ?? '' }}fn-{{ $note['marker'] }}" data-verse="{{ $note['verse'] }}"><span class="fn-line"><span class="fn-letter">{{ $note['marker'] }}</span><a class="fn-verse" href="?v={{ $note['verse'] }}" data-verse="{{ $note['verse'] }}" title="Back to verse {{ $note['verse'] }}">{{ $note['verse'] }}</a>
                @if (! empty($note['anchor']))<span class="fn-anchor">{{ $note['anchor'] }}</span> —
                @endif{{ $note['text'] }}</span></div>
        @endforeach
    </section>
@endif