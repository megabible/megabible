<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a request's status log. Created through
 * AnimationRequest::transitionTo() so the parent's `status` column and
 * this history always agree.
 *
 * `note` is INTERNAL — the public planning page renders only the status
 * label and this row's age ("requested 3 weeks ago"), never the text.
 */
class AnimationRequestUpdate extends Model
{
    protected $fillable = [
        'animation_request_id', 'status', 'note',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(AnimationRequest::class, 'animation_request_id');
    }
}
