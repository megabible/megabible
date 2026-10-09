<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One minted short-link code (short-link r1).
 *
 * megabible.net/{code} → the latest version's blob, served into the
 * shared-import shell. The secret_hash is the sha256 of the capability
 * secret the minting browser holds in localStorage — re-share and delete
 * requests prove ownership by presenting the secret, never by identity.
 */
class PericopeShare extends Model
{
    /** Every column except id/timestamps — verified in Tinker (house rule). */
    protected $fillable = ['code', 'secret_hash'];

    public function versions(): HasMany
    {
        return $this->hasMany(PericopeShareVersion::class);
    }

    /** The row the bare code serves: the newest shared revision. */
    public function latestVersion(): ?PericopeShareVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }
}
