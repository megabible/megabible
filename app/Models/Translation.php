<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Translation extends Model
{
    protected $fillable = [
        'abbreviation', 'name', 'language', 'year_published',
        'description', 'license', 'source_url', 'has_apocrypha', 'sort_order','heading_set',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'has_apocrypha' => 'boolean',
        'year_published' => 'integer',
        'sort_order' => 'integer',
    ];

    public function verses(): HasMany
    {
        return $this->hasMany(Verse::class);
    }

    // Lookup by URL slug (the abbreviation in lowercase)
    public static function findBySlug(string $slug): ?self
    {
        return static::whereRaw('LOWER(abbreviation) = ?', [strtolower($slug)])->first();
    }
}
