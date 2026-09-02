<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scrim_plays — anonymous per-verse play counters, daily rollups.
 *
 * WHY THIS TABLE EXISTS: typing_scores cannot measure popularity. The
 * nightly scrim:trim deletes everything below the top ten, and a round
 * whose name fails to unseat a defender ("held") never writes a row at
 * all. This table is the durable record: one row per (challenge_key,
 * play_date), bumped atomically on every completed, plausible round —
 * claimed, takeover, held, board-full, and zero-score outcomes alike.
 * See TypingController::recordPlay for the single write path.
 *
 * ANONYMOUS BY CONSTRUCTION: counts only. No IP, no hash, no name, no
 * timestamp finer than the date. Nothing here is personal data.
 *
 * DENORMALISED ON PURPOSE: challenge_key is an opaque sha1, so book_slug/
 * chapter/verse ride along — the scrimboard hub turns a hot key back into
 * "Psalms 138:2" and its URL without parsing params_json out of score rows
 * that may have been trimmed away. mode and lang scope the hub's queries
 * (scrimmage vs daily; -en vs future -es boards).
 *
 * Daily granularity gives every hub filter as a SUM over a date range:
 * week/month/year bound play_date, all-time drops the bound. play_date is
 * the SITE clock (typing.board_trim.timezone) — the same midnight the trim
 * and the daily challenge run on.
 *
 * NO ELOQUENT MODEL, by design: a pure counter written via one raw upsert
 * (INSERT … ON DUPLICATE KEY UPDATE) and read via the query builder. No
 * $fillable to forget, no read-modify-write race to lose counts to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrim_plays', function (Blueprint $table) {
            $table->id();

            $table->char('challenge_key', 40);           // sha1 hex
            $table->string('mode', 12);                  // scrimmage | daily (later)
            // varchar(10), MIRRORING translations.language exactly — same
            // width, so a regional code (pt-br) can never be truncated on
            // its way into the counter. A narrower column here would be
            // rejected under strict mode and the play would vanish into
            // recordPlay's catch, costing analytics with no visible error.
            $table->string('lang', 10)->default('en');

            // The verse, human-readable — no key-reversal ever needed.
            $table->string('book_slug', 64);
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse');

            $table->date('play_date');                   // site-tz day bucket
            $table->unsignedInteger('plays')->default(1);

            $table->timestamps();

            // THE UPSERT CONTRACT: one row per board per day; the raw
            // INSERT … ON DUPLICATE KEY UPDATE in recordPlay lands here.
            $table->unique(['challenge_key', 'play_date'], 'uk_play_day');

            // Hub queries: trending scans a date range; the per-verse index
            // serves "this verse across languages/modes" lookups later.
            $table->index('play_date', 'ix_play_date');
            $table->index(['book_slug', 'chapter', 'verse'], 'ix_play_verse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrim_plays');
    }
};
