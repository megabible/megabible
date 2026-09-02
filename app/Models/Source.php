<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Source extends Model
{
    protected $fillable = ['slug', 'citation', 'author', 'title', 'year', 'publisher', 'url'];

    protected $casts = ['year' => 'integer'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)
            ->withPivot('note', 'sort_order')
            ->withTimestamps();
    }
}
