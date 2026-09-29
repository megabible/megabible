<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One request to animate one chapter. TRACKING ONLY — this table holds
 * NO identity of any kind, by design: no name, email, channel, portfolio,
 * or any column that could carry one. Applicant identity lives entirely
 * in the site owner's mailbox; rows here are created by hand after an
 * email arrives (the public form writes nothing to the database).
 *
 * The public planning page reads exactly two things from this model:
 * the current status label and how long ago it last changed.
 */
class AnimationRequest extends Model
{
    public const STATUS_PENDING     = 'pending';      // logged, not yet vetted
    public const STATUS_APPROVED    = 'approved';     // green-lit, work not started
    public const STATUS_IN_PROGRESS = 'in_progress';  // artist reports active work
    public const STATUS_SUBMITTED   = 'submitted';    // video delivered, under review
    public const STATUS_LIVE        = 'live';         // published as a chapter_animation
    public const STATUS_DECLINED    = 'declined';
    public const STATUS_WITHDRAWN   = 'withdrawn';

    /** Every status, for validation in commands / the manager. */
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED, self::STATUS_LIVE, self::STATUS_DECLINED,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * Statuses the planning pages count as "this chapter is spoken for".
     * Declined/withdrawn requests leave the chapter open again.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED,
        self::STATUS_IN_PROGRESS, self::STATUS_SUBMITTED,
    ];

    protected $fillable = [
        'book_id', 'chapter', 'translation_id', 'status',
    ];

    protected $casts = [
        'chapter' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(AnimationRequestUpdate::class)->orderByDesc('created_at');
    }

    /** The newest status-log entry — its created_at is the public "3 weeks ago". */
    public function latestUpdate(): HasOne
    {
        return $this->hasOne(AnimationRequestUpdate::class)->latestOfMany();
    }

    public function animations(): HasMany
    {
        return $this->hasMany(ChapterAnimation::class);
    }

    /**
     * Move to a new status AND log it in one call, so the request row and
     * its history can never drift apart. `$note` is internal bookkeeping —
     * never rendered publicly.
     */
    public function transitionTo(string $status, ?string $note = null): AnimationRequestUpdate
    {
        $this->update(['status' => $status]);

        return $this->updates()->create([
            'status' => $status,
            'note'   => $note,
        ]);
    }
}
