<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A curated section heading shared by every translation in a heading_set.
 *
 * Exposes the same attributes the per-translation Heading model does
 * (kind, level, text, before_verse), so ChapterLayout::build() can consume a
 * merged collection of both without caring which table a heading came from.
 */
class SharedHeading extends Model
{
    protected $fillable = [
        'set_key', 'source_key', 'book_id', 'chapter', 'before_verse', 'kind', 'level', 'text',
    ];

    protected $casts = [
        'chapter'      => 'integer',
        'before_verse' => 'integer',
        'level'        => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}