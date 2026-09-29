<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hand-rated 1–10 animation-complexity score for one chapter.
 * 1 = a quiet genealogy; 10 = Revelation.
 *
 * The editing surface is the TSV in storage/app/animations/, imported by
 * `php artisan mb:animation-complexity` — ratings are estimates, revised
 * freely and often. Chapters with no row yet render as "unrated" on the
 * planning pages, never as a fabricated number.
 */
class ChapterComplexity extends Model
{
    protected $fillable = [
        'book_id', 'chapter', 'rating',
    ];

    protected $casts = [
        'chapter' => 'integer',
        'rating'  => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
