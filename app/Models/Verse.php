<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verse extends Model
{
    protected $fillable = [
        'translation_id', 'book_id', 'chapter', 'verse_number',
        'text', 'starts_paragraph', 'format', 'osis_ref', 'sort_key',
    ];

    protected $casts = [
        'chapter'          => 'integer',
        'verse_number'     => 'integer',
        'sort_key'         => 'integer',
        'starts_paragraph' => 'boolean',
        // `format` is stored as JSON and surfaces as a PHP array (or null).
        // Each element is ['s' => style, 't' => text], e.g. ['s' => 'q1', 't' => '…'].
        'format'           => 'array',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}