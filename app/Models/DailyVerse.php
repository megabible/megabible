<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day's verse. See the daily_verses migration for the full contract;
 * the short version is that this table is a permanent ledger, not a cache.
 *
 * HOUSE SCAR: every column below is in $fillable, because the picker writes
 * rows with firstOrCreate/updateOrCreate and a missing key is dropped
 * SILENTLY. Add a column, add it here, and verify with Tinker.
 */
class DailyVerse extends Model
{
    protected $fillable = [
        'date', 'book_slug', 'chapter', 'verse', 'source', 'note',
    ];

    protected $casts = [
        'date'    => 'date',
        'chapter' => 'integer',
        'verse'   => 'integer',
    ];

    /** "psalms.138.2" — the shape the challenge key and share URLs use. */
    public function ref(): string
    {
        return $this->book_slug . '.' . $this->chapter . '.' . $this->verse;
    }

    /** The Book row, resolved on demand (slug is the stored identity). */
    public function book(): ?Book
    {
        return Book::findBySlug($this->book_slug);
    }

    /** "Psalms 138:2", or the raw ref if the book has vanished from the canon. */
    public function label(): string
    {
        $book = $this->book();

        return ($book?->name ?? $this->book_slug) . ' ' . $this->chapter . ':' . $this->verse;
    }
}
