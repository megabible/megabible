<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Heading extends Model
{
    protected $fillable = [
        'translation_id', 'book_id', 'chapter', 'before_verse', 'kind', 'level', 'text',
    ];

    protected $casts = [
        'chapter'      => 'integer',
        'before_verse' => 'integer',
        'level'        => 'integer',
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