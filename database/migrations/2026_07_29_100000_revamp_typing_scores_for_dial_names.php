<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL NAMES + KING-OF-THE-HILL BOARDS.
 *
 * Names are now exactly four characters, A–Z / 0–9, picked on four dials.
 * One row per (challenge_key, player_name): a better score under the same
 * name TAKES OVER the existing row instead of stacking a duplicate.
 *
 * New columns:
 *   claim_count      — how many holders this name has had on this board
 *                      (1 = first claim; every successful takeover bumps it).
 *   first_claimed_at — when the name FIRST appeared on this board; survives
 *                      takeovers ("contested since …" in the row popover).
 *   survived_trim_at — stamped by scrim:trim on rows that outlived a nightly
 *                      cut. NULL = never trimmed around. Cleared on takeover
 *                      (the new holder hasn't defended anything yet).
 *
 * PRE-LAUNCH SANDBOX: the table is truncated — every existing row carries a
 * free-text name the new char(4) column can't hold, and none of them are
 * worth migrating. (Announced house rule: no backwards compatibility until
 * go-live.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clean slate under the new name rules. TRUNCATE also resets the
        // auto-increment, so the reborn board starts at id 1.
        DB::table('typing_scores')->truncate();

        Schema::table('typing_scores', function (Blueprint $table) {
            // Four characters, always. Server validates ^[A-Z0-9]{4}$ for
            // challenge rows; the legacy prototype's names are coerced to
            // fit (see TypingController::cleanName) until it's retired.
            $table->string('player_name', 4)->change();

            $table->unsignedSmallInteger('claim_count')->default(1)->after('player_name');
            $table->timestamp('first_claimed_at')->nullable()->after('claim_count');
            $table->timestamp('survived_trim_at')->nullable()->after('first_claimed_at');

            // THE DEDUP CONTRACT, enforced at the schema level: one row per
            // name per board. Legacy prototype rows have challenge_key NULL,
            // and MySQL permits any number of NULLs in a unique index, so
            // the old game is unaffected.
            $table->unique(['challenge_key', 'player_name'], 'uk_board_name');

            // Board reads and the nightly trim both sort this way.
            $table->index(['challenge_key', 'final_score'], 'ix_board_rank');
        });
    }

    public function down(): void
    {
        Schema::table('typing_scores', function (Blueprint $table) {
            $table->dropUnique('uk_board_name');
            $table->dropIndex('ix_board_rank');
            $table->dropColumn(['claim_count', 'first_claimed_at', 'survived_trim_at']);
            $table->string('player_name', 24)->change();
        });
    }
};
