<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VERSE LIKE  ·  one anonymous aggregate counter per like key (scroll r2)
 * -----------------------------------------------------------------------
 * The like key is MBPericope.likeKey(post): the post's verse refs sorted
 * and joined, translation-free ("Gen.1.1+Rom.8.28-30"). like_hash is
 * sha256(like_key) and carries the unique index (see the migration for
 * why). hits is the count; it only moves through PericopeController's
 * atomic statements — never read-modify-write this model in a handler.
 *
 * No relationships, no per-user rows, nothing personal: the row knows a
 * passage is liked N times and cannot know by whom.
 */
class VerseLike extends Model
{
    protected $fillable = ['like_hash', 'like_key', 'hits'];

    protected $casts = [
        'hits' => 'integer',
    ];
}
