<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One original-language word (Hebrew / Aramaic / Greek) in reading order.
 * See the original_tokens migration for the full column story.
 */
class OriginalToken extends Model
{
    /** Bulk data, rebuildable from files — no created/updated bookkeeping. */
    public $timestamps = false;

    protected $fillable = [
        'book_id', 'chapter', 'verse', 'position',
        'lang', 'surface', 'translit', 'gloss', 'gloss_es',
        'strongs', 'morph', 'source_key',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** Tokens for one verse, in reading order. */
    public function scopeForVerse(Builder $q, int $bookId, int $chapter, int $verse): Builder
    {
        return $q->where('book_id', $bookId)
                 ->where('chapter', $chapter)
                 ->where('verse', $verse)
                 ->orderBy('position');
    }

    /**
     * The verse numbers within a chapter that HAVE tokens — the coverage
     * gate that decides which synthesis cards show the flip button.
     * Cheap (index-only) and cacheable per (book, chapter).
     */
    public static function coveredVerses(int $bookId, int $chapter): array
    {
        return static::where('book_id', $bookId)
            ->where('chapter', $chapter)
            ->distinct()
            ->orderBy('verse')
            ->pluck('verse')
            ->all();
    }

    /**
     * verse number => language code for every covered verse in a chapter.
     * Feeds the Synthesis flip button, which shows the language name up front
     * (before any tokens are fetched). A verse has one language in practice;
     * where a verse straddles a Hebrew→Aramaic switch (Daniel/Ezra), the
     * lowest position's language wins, which matches the card's first row.
     */
    public static function coveredLangs(int $bookId, int $chapter): array
    {
        return static::where('book_id', $bookId)
            ->where('chapter', $chapter)
            ->orderBy('verse')->orderBy('position')
            ->get(['verse', 'lang'])
            ->groupBy('verse')
            ->map(fn ($g) => $g->first()->lang)
            ->all();
    }
}
