<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Book extends Model
{
    protected $fillable = [
    'osis_id', 'slug', 'name', 'short_name',
    'testament', 'canon_section', 'book_order', 'chapter_count',
    'section', 'section_order',
];

    protected $casts = [
        'book_order'    => 'integer',
        'chapter_count' => 'integer',
    ];

    public function verses(): HasMany
    {
        return $this->hasMany(Verse::class);
    }

    public function intro(): HasOne
    {
        return $this->hasOne(BookIntro::class);
    }

    public function manuscripts(): BelongsToMany
    {
        return $this->belongsToMany(Manuscript::class)
            ->withPivot('note')
            ->withTimestamps()
            ->orderBy('date_sort');
    }

    public function timelineEvents(): BelongsToMany
    {
        return $this->belongsToMany(TimelineEvent::class)
            ->withTimestamps()
            ->orderBy('date_sort');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class)
            ->withPivot('note', 'sort_order', 'letter')
            ->withTimestamps()
            ->orderBy('pivot_sort_order');
    }

    /**
     * Reader/search display parts for a chapter: [displayName, displayChapter].
     *
     * Normally just [name, chapter]. Books listed in
     * config('canon.reader_labels') by OSIS id override both — the Five
     * Psalms of David render as "Psalm 151".."Psalm 155" so the collection
     * reads as a continuation of the Psalter. DB chapters, URLs and routes
     * keep their real 1-based numbers; this is display only.
     *
     * Callers that must hide the chapter for single-chapter books (the reader
     * h1) handle that themselves; search always shows the number.
     */
    public function refParts(int $chapter): array
    {
        if ($o = config("canon.reader_labels.{$this->osis_id}")) {
            return [$o['name'], $chapter + ($o['chapter_offset'] ?? 0)];
        }

        return [$this->name, $chapter];
    }

    /**
     * How much to add to each chapter NUMBER when drawing chapter cells for
     * this book (book hub grid + quicknav Screen 2). 0 for every normal book;
     * 150 for the Five Psalms of David, so its five cells read 151..155 while
     * still linking to /1../5. Reads the same reader_labels config as
     * refParts(), so the three chip surfaces can never drift apart.
     */
    public function chapterCellOffset(): int
    {
        return (int) config("canon.reader_labels.{$this->osis_id}.chapter_offset", 0);
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function findByOsis(string $osis): ?self
    {
        return static::where('osis_id', $osis)->first();
    }
}
