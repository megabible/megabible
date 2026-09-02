<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Manuscript extends Model
{
    protected $fillable = [
        'slug', 'name', 'siglum', 'kind', 'date_display', 'date_sort', 'description',
    ];

    protected $casts = [
        'date_sort' => 'integer',
    ];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)
            ->withPivot('note')
            ->withTimestamps();
    }
}
