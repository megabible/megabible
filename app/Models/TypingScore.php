<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leaderboard entry. Metrics are server-computed; see TypingController@score.
 *
 * Two row species share this table:
 *   LEGACY (prototype): typing_passage_id + mode(sprint/…) + difficulty set;
 *                       challenge columns NULL.
 *   CHALLENGE (phase 3): challenge_key/mode/final_score/… set; passage NULL,
 *                        difficulty NULL (the modifier replaced it).
 *
 * DIAL-NAME ERA: player_name is exactly four characters (A–Z / 0–9) and
 * UNIQUE per challenge_key — one row per name per board, the better score
 * holding the seat. claim_count / first_claimed_at track the takeover
 * history; survived_trim_at marks rows that outlived a nightly scrim:trim.
 */
class TypingScore extends Model
{
    protected $fillable = [
        'typing_passage_id', 'translation_id',
        'reference_label', 'mode', 'difficulty', 'player_name',
        'gross_wpm', 'net_wpm', 'accuracy',
        'char_count', 'total_keystrokes', 'error_count', 'duration_ms',
        'ip_hash',
        // Challenge engine (phase 3). ALL new columns listed — the silent
        // mass-assignment failure is a documented house scar; verify with
        // Tinker after the first insert regardless.
        'challenge_key', 'challenge_mode', 'final_score',
        'difficulty_modifier', 'formula_version', 'duration_config',
        'params_json', 'wraps', 'best_combo',
        // Dial-name era (name takeovers + nightly trim).
        'claim_count', 'first_claimed_at', 'survived_trim_at',
    ];

    protected $casts = [
        'gross_wpm'           => 'float',
        'net_wpm'             => 'float',
        'accuracy'            => 'float',
        'char_count'          => 'integer',
        'total_keystrokes'    => 'integer',
        'error_count'         => 'integer',
        'duration_ms'         => 'integer',
        'final_score'         => 'float',
        'difficulty_modifier' => 'float',
        'formula_version'     => 'integer',
        'duration_config'     => 'integer',
        'params_json'         => 'array',
        'wraps'               => 'integer',
        'best_combo'          => 'integer',
        'claim_count'         => 'integer',
        'first_claimed_at'    => 'datetime',
        'survived_trim_at'    => 'datetime',
    ];

    public function passage(): BelongsTo
    {
        return $this->belongsTo(TypingPassage::class, 'typing_passage_id');
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }
}
