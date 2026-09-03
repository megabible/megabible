<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookIntro extends Model
{
    protected $fillable = [
        'book_id', 'summary', 'excerpt', 'excerpt_source',   // hub-src r2
        'authorship_note', 'traditional_author',
        'scholarly_view', 'dating', 'dating_sort', 'dating_start', 'dating_end',
        'language', 'genre', 'place_written',
        'timeline_start', 'timeline_end', 'timeline_color',
        'timeline_books', 'timeline_groups', 'outline', 'timeline_text',
        'composition_layers','original_name','original_name_transliteration',
    ];

    protected $casts = [
        'dating_sort'       => 'integer',
        'dating_start'      => 'integer',
        'dating_end'        => 'integer',
        'timeline_start'    => 'integer',
        'timeline_end'      => 'integer',
        'timeline_books'    => 'array',
        'timeline_groups'   => 'array',
        'outline'           => 'array',
        'composition_layers'=> 'array',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}