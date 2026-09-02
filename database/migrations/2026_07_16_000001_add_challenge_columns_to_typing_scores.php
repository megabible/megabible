<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (challenge engine): extend typing_scores for URL-defined challenges.
 *
 * A challenge no longer references a stored typing_passage row — its identity
 * IS its parameters (mode + translation + refs [+ duration]), canonicalised
 * and hashed into `challenge_key` by App\Support\Challenge. Scores hang off
 * that key; a challenge "exists" the moment its first score lands.
 *
 * Legacy prototype rows keep working untouched: their new columns stay NULL,
 * and `mode`/`difficulty` (now nullable for challenge rows, which have no
 * normal/hard concept) retain their old values.
 *
 * formula_version pins each row to the DifficultyRater version that produced
 * its modifier, so recalibrating the formula later never corrupts old boards —
 * they can be re-scored or segregated by version instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('typing_scores', function (Blueprint $table) {
            // sha1 of the canonical challenge string. NULL on legacy rows.
            $table->char('challenge_key', 40)->nullable()->after('typing_passage_id');

            // triad | time_attack | daily (daily lands in phase 7).
            $table->string('challenge_mode', 20)->nullable()->after('challenge_key');

            // The headline number: net_wpm × (accuracy/100)² × difficulty_modifier.
            $table->decimal('final_score', 8, 2)->nullable()->after('accuracy');

            // The modifier applied, and which formula version computed it.
            $table->decimal('difficulty_modifier', 5, 3)->nullable()->after('final_score');
            $table->unsignedTinyInteger('formula_version')->nullable()->after('difficulty_modifier');

            // Time-attack duration tier in seconds (10 | 20 | 40). NULL for triad.
            $table->unsignedSmallInteger('duration_config')->nullable()->after('formula_version');

            // The canonical params, verbatim, so any score row can reconstruct
            // its challenge (share URL, board page) without joins or guesswork.
            $table->json('params_json')->nullable()->after('duration_config');

            // Boards: per-challenge (the share-link loop) and global per-mode.
            $table->index(['challenge_key', 'final_score']);
            $table->index(['challenge_mode', 'final_score']);
        });

        // Challenge rows have no legacy tier or normal/hard difficulty.
        Schema::table('typing_scores', function (Blueprint $table) {
            $table->string('mode', 20)->nullable()->change();
            $table->string('difficulty', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('typing_scores', function (Blueprint $table) {
            $table->dropIndex(['challenge_key', 'final_score']);
            $table->dropIndex(['challenge_mode', 'final_score']);
            $table->dropColumn([
                'challenge_key', 'challenge_mode', 'final_score',
                'difficulty_modifier', 'formula_version',
                'duration_config', 'params_json',
            ]);
        });

        Schema::table('typing_scores', function (Blueprint $table) {
            $table->string('mode', 20)->nullable(false)->change();
            $table->string('difficulty', 10)->nullable(false)->change();
        });
    }
};
