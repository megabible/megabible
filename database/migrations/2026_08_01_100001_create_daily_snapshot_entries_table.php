<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daily_snapshot_entries — SET IN STONE.
 *
 * A daily board is never trimmed. At the midnight archive the ENTIRE field
 * is copied here — one row per player, whether the day drew one scrim or ten
 * thousand — the ranks are frozen, and the live typing_scores rows are
 * deleted. What lands in this table is the permanent record of that day.
 * Nothing recomputes it; nothing re-ranks it. See ArchiveDailyBoards.
 *
 * WHY A SEPARATE TABLE rather than a flag on typing_scores: the live board
 * is hot, small, and constantly written; the archive is cold, unbounded, and
 * written once a day. They also disagree about what a row MEANS — a live row
 * is a seat that can be taken over, an archived row is a result. Different
 * lifecycles, different tables.
 *
 * FULLY DENORMALISED, on purpose. An archive row must still render in 2074
 * when the translation has been reseeded, the verse re-imported, and the
 * difficulty formula bumped four times. Every column needed to draw the row
 * is copied in; there are no joins and no foreign keys.
 *
 * `rank` is the placement as it stood at the freeze, computed with the same
 * ordering the live board renders with (final_score desc, accuracy desc,
 * created_at asc — the incumbent holds a tie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_snapshot_entries', function (Blueprint $table) {
            $table->id();

            // ---- Which board -------------------------------------------------
            $table->date('date');                       // the day it was won
            $table->string('lang', 10)->default('en');  // mirrors translations.language
            $table->char('challenge_key', 40);          // the key this board hung off

            // The verse, readable forever.
            $table->string('book_slug', 64);
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse');
            $table->string('reference_label', 120);     // "Psalms 138:2"

            // ---- The result --------------------------------------------------
            $table->unsignedSmallInteger('rank');
            $table->char('player_name', 4);
            $table->decimal('final_score', 8, 2);
            $table->decimal('net_wpm', 6, 2);
            $table->decimal('accuracy', 5, 2);
            $table->unsignedSmallInteger('wraps')->default(0);
            $table->unsignedSmallInteger('best_combo')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);

            // The edition they typed, as an abbreviation — NOT a translation_id.
            // A future reseed can renumber ids; "KJV" is still "KJV".
            $table->string('translation_abbr', 20)->nullable();

            // Provenance of the scoring maths, so a re-scoring era can tell
            // which rows were produced under which formula.
            $table->decimal('difficulty_modifier', 5, 3)->nullable();
            $table->unsignedSmallInteger('formula_version')->nullable();

            // When the round was actually run (the live row's created_at).
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            // IDEMPOTENT ARCHIVING: one row per name per board per day, so a
            // second run of scrim:daily-archive cannot duplicate a field.
            $table->unique(['date', 'lang', 'player_name'], 'uk_snapshot_seat');

            // The archive browses by day and renders in rank order.
            $table->index(['date', 'lang', 'rank'], 'ix_snapshot_rank');

            // "Every day this verse has been the daily" — the future corpus
            // progress tracker reads this way.
            $table->index(['book_slug', 'chapter', 'verse'], 'ix_snapshot_verse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_snapshot_entries');
    }
};
