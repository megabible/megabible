<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shared revision of a short-linked pericope (short-link r1).
 *
 * blob is the frozen p1 share string exactly as the client encoded it —
 * the server never parses it beyond the mint-time shape check, never
 * rewrites it, and serves it back verbatim for the client's own
 * decodeShare() to rebuild. Retention is capped per code
 * (config pericope_share.max_versions): re-sharing past the cap prunes
 * the oldest rows, and a pinned ?v= that got pruned falls back to latest.
 */
class PericopeShareVersion extends Model
{
    /** Every column except id/timestamps — verified in Tinker (house rule). */
    protected $fillable = ['pericope_share_id', 'version', 'blob'];

    public function share(): BelongsTo
    {
        return $this->belongsTo(PericopeShare::class, 'pericope_share_id');
    }
}
