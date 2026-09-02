<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TimelineEvent extends Model
{
    protected $fillable = ['slug', 'label', 'date_display', 'date_sort', 'kind'];

    protected $casts = ['date_sort' => 'integer'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withTimestamps();
    }
}
