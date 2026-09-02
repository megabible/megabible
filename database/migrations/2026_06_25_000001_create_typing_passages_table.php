<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * typing_passages — every passage the Bible-Typing game has ever served in a
 * RANKED round, plus the curation flags that let an admin promote the good ones.
 *
 * Why store these at all?
 *   1. The leaderboard anchors each score to the EXACT text that was typed
 *      (text changes = scores aren't comparable), so we need the text on hand.
 *   2. You wanted a record of every machine-built pull, the ability to re-use a
 *      great one instead of regenerating, and eventually to "lock" generation
 *      once a pool of curated favourites exists. This table is that record.
 *
 * Dedupe: identical pulls collapse onto one row via `text_hash`. We don't insert
 * a fresh row every single round — we bump `times_served`. So the table grows
 * with VARIETY, not with play count, and popular pulls float up naturally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('typing_passages', function (Blueprint $table) {
            $table->id();

            // Which edition this text is from. Drives the "difficulty" flavour
            // (WEB = Normal, KJV = Hard) but stored as a real FK either way.
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();

            // Where the chunk lives, so it can be reconstructed / linked back to
            // the reader later. A chunk may span chapters but never books.
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter_start');
            $table->unsignedSmallInteger('verse_start');
            $table->unsignedSmallInteger('chapter_end');
            $table->unsignedSmallInteger('verse_end');

            // Human-readable reference, e.g. "Romans 8:28–30" or "Psalms 23:1–6".
            $table->string('reference_label', 120);

            // The exact text the player types. Denormalised on purpose: instant
            // re-use, and the leaderboard is meaningless unless the text is fixed.
            $table->text('text');

            // sha1 of the normalised text. Lets identical pulls share one row.
            $table->char('text_hash', 40)->unique();

            $table->unsignedSmallInteger('word_count');
            $table->unsignedSmallInteger('char_count');

            // Which game shape produced this: sprint | standard | endurance.
            // (Free Play is never stored — it's unranked practice.)
            $table->string('mode', 20);

            // How many ranked rounds have served this exact pull. Popularity.
            $table->unsignedInteger('times_served')->default(1);

            // Admin flag: "this is a keeper." When generation is locked, the
            // selector serves only from the curated pool.
            $table->boolean('is_curated')->default(false);

            $table->timestamps();

            // The selector filters on (translation, mode[, book]) and may prefer
            // curated rows — this index covers those lookups.
            $table->index(['translation_id', 'mode', 'is_curated']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typing_passages');
    }
};
