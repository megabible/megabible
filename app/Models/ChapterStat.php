<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Computed per-chapter counts for the animation planning pages, built by
 * `php artisan mb:chapter-stats`. One row per (book, chapter); the
 * translation_id records which edition the counts came from (KJV where it
 * carries the chapter, else the standard fallback chain).
 *
 * NOTHING here is hand-entered — the whole table is rebuildable at any
 * time. Hand data (complexity ratings) lives in chapter_complexities
 * precisely so a rebuild can never touch it.
 *
 * Runtime is DERIVED at render time from word_count + the WPM knobs in
 * config/animations.php (house rule: derive display values from raw data,
 * never store them) — so retuning the knobs retunes every page instantly,
 * no rebuild.
 */
class ChapterStat extends Model
{
    protected $fillable = [
        'book_id', 'chapter', 'translation_id', 'verse_count', 'word_count',
    ];

    protected $casts = [
        'chapter'     => 'integer',
        'verse_count' => 'integer',
        'word_count'  => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    /**
     * Estimated narration runtime as [$lowSeconds, $highSeconds].
     * Fast narration → the low bound; slow narration → the high bound.
     */
    public function runtimeRange(): array
    {
        $fast = max(1, (int) config('animations.narration_wpm_fast', 145));
        $slow = max(1, (int) config('animations.narration_wpm_slow', 120));

        return [
            (int) round($this->word_count / $fast * 60),
            (int) round($this->word_count / $slow * 60),
        ];
    }

    /**
     * The range as a human label: "≈ 4–5 min", or "≈ 45 sec" when both
     * bounds round to under a minute, or "≈ 4 min" when they agree.
     */
    public function runtimeLabel(): string
    {
        [$lo, $hi] = $this->runtimeRange();

        if ($hi < 60) {
            return '≈ ' . $hi . ' sec';
        }

        $loMin = max(1, (int) round($lo / 60));
        $hiMin = max(1, (int) round($hi / 60));

        return $loMin === $hiMin
            ? "≈ {$loMin} min"
            : "≈ {$loMin}–{$hiMin} min";
    }
}
