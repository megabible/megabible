<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A translator or editorial footnote, anchored to the end of one verse.
 *
 * Per-translation, like Heading (the WEB's notes are part of the WEB; they are
 * never shared across editions the way shared_headings sets are). Footnotes are
 * an overlay: they reference a verse by (translation, book, chapter, number)
 * and never depend on the verse's text content.
 *
 * `sequence`    order within the verse (1-based) — drives the a, b, c markers.
 * `kind`        'note' today; reserved for future types ('xref', 'editorial').
 * `source_key`  attribution key into config/footnote_sources.php, same pattern
 *               as SharedHeading::source_key + config/heading_sources.php.
 * `anchor_text` the word(s) the note glosses, captured from the source USFM;
 *               shown as the bold lead-in inside the note popover.
 */
class Footnote extends Model
{
    protected $fillable = [
        'translation_id', 'book_id', 'chapter', 'verse_number',
        'sequence', 'kind', 'source_key', 'anchor_text', 'text',
    ];

    protected $casts = [
        'chapter'      => 'integer',
        'verse_number' => 'integer',
        'sequence'     => 'integer',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * All notes for one chapter of one translation, in reading order.
     * The single query the chapter page needs:
     *
     *   $footnotes = Footnote::forChapter($translation->id, $book->id, $chapter)->get();
     */
    public function scopeForChapter(Builder $q, int $translationId, int $bookId, int $chapter): Builder
    {
        return $q->where('translation_id', $translationId)
                 ->where('book_id', $bookId)
                 ->where('chapter', $chapter)
                 ->orderBy('verse_number')
                 ->orderBy('sequence');
    }

    /**
     * Per-chapter letter marker for the note at 0-based position $i in the
     * chapter's reading order: a…z, then aa, ab, … (bijective base-26, the
     * traditional print convention). Markers restart every chapter because the
     * caller indexes from that chapter's own note list.
     *
     *   marker(0) => 'a'   marker(25) => 'z'   marker(26) => 'aa'
     */
    public static function marker(int $i): string
    {
        $s = '';
        $i++;                                   // 1-based for bijective base-26
        while ($i > 0) {
            $i--;
            $s = chr(97 + ($i % 26)) . $s;      // 97 = 'a'
            $i = intdiv($i, 26);
        }
        return $s;
    }
}
