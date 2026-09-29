<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One video (draft or published) animating one chapter.
 *
 * A video is pinned to the translation its voiceover follows exactly —
 * the watch page always renders the chapter text in THAT edition, no
 * matter which edition the reader arrived from.
 *
 * Multiple live videos per chapter are allowed; the lowest sort_order
 * plays by default (ties break on id). All rows are entered by hand —
 * there is no public write path to this table.
 */
class ChapterAnimation extends Model
{
    // The two classes of animation.
    public const ORIGIN_IN_HOUSE = 'in_house';   // made by/for megabible.net, on its channel
    public const ORIGIN_CROWD    = 'crowd';      // community-made, on the creator's channel

    // Lifecycle. 'removed' rather than deleted — the irrevocable-discretion
    // clause keeps history while the video stops rendering anywhere.
    public const STATUS_DRAFT   = 'draft';
    public const STATUS_LIVE    = 'live';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'book_id', 'chapter', 'translation_id',
        'youtube_id', 'title', 'origin',
        'creator_name', 'creator_channel_url',
        'duration_seconds', 'cues',
        'status', 'sort_order', 'published_at',
        'animation_request_id',
    ];

    protected $casts = [
        'chapter'          => 'integer',
        'duration_seconds' => 'integer',
        'sort_order'       => 'integer',
        // Cue objects: [ ['k'=>'v', 'v'=>1, 't'=>0.0], ... ]. 'k' is the cue
        // kind — only 'v' (verse start) exists today; 'p' (pause) and 's'
        // (stanza) are planned, which is why cues are objects, not pairs.
        'cues'             => 'array',
        'published_at'     => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AnimationRequest::class, 'animation_request_id');
    }

    /** Only videos the public may see. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_LIVE);
    }

    /**
     * Every live video for a chapter, default-first. The watch page plays
     * [0] and offers the rest as alternates.
     */
    public static function liveFor(int $bookId, int $chapter)
    {
        return static::live()
            ->where('book_id', $bookId)
            ->where('chapter', $chapter)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Does ANY live video exist for this chapter? The eyeball app in the
     * chapter head folder shows iff this is true — regardless of which
     * translation the reader is currently in.
     */
    public static function existsFor(int $bookId, int $chapter): bool
    {
        return static::live()
            ->where('book_id', $bookId)
            ->where('chapter', $chapter)
            ->exists();
    }
}