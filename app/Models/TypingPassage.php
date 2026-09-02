<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored RANKED pull. See the typing_passages migration for the full why.
 */
class TypingPassage extends Model
{
    protected $fillable = [
        'translation_id', 'book_id',
        'chapter_start', 'verse_start', 'chapter_end', 'verse_end',
        'reference_label', 'text', 'text_hash',
        'word_count', 'char_count', 'mode',
        'times_served', 'is_curated',
    ];

    protected $casts = [
        'chapter_start' => 'integer',
        'verse_start'   => 'integer',
        'chapter_end'   => 'integer',
        'verse_end'     => 'integer',
        'word_count'    => 'integer',
        'char_count'    => 'integer',
        'times_served'  => 'integer',
        'is_curated'    => 'boolean',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scores()
    {
        return $this->hasMany(TypingScore::class);
    }
}
