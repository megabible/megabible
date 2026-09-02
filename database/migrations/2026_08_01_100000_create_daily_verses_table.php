<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daily_verses — THE LEDGER.
 *
 * One row per calendar day, forever: the verse the whole site scrims that
 * day. This table is not a cache and not a log. It is the record of which
 * verses have had their day, and it is the ONLY thing preventing a repeat.
 * At one verse a day the corpus (43,144 verses in the Protestant 66 alone)
 * runs to roughly the year 2143. Do not truncate it casually.
 *
 * `date` is the primary contract: unique, and interpreted in the SITE clock
 * (typing.board_trim.timezone), the same midnight the trim and the archive
 * run on. One date, one verse, everywhere on earth.
 *
 * NO TRANSLATION COLUMN, deliberately. A daily challenge is a VERSE, not an
 * edition; the page renders whichever English edition the reader prefers and
 * every edition shares one board (see Challenge::canonical). The book_slug /
 * chapter / verse triple is the whole identity.
 *
 * `source` records how the row got here:
 *   generated — scrim:daily-pick chose it from the unused pool.
 *   curated   — scrim:daily-set, i.e. you chose it on purpose.
 *   fallback  — nobody had chosen one when the day arrived, so the
 *               deterministic picker resolved it on the fly and persisted
 *               it (see DailyVersePicker::forDate). A day of these means
 *               the scheduler is not running.
 *
 * `note` is the curator's line — shown on the daily card when present
 * ("Christmas morning", "the first verse I ever memorised"). Null for
 * generated days, which is nearly all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_verses', function (Blueprint $table) {
            $table->id();

            // The day itself. UNIQUE is the no-two-verses-per-day contract;
            // firstOrCreate leans on it to settle concurrent fallbacks.
            $table->date('date')->unique();

            // The verse. Denormalised slug rather than a book_id FK: this
            // table must stay readable and diffable for a century, and a
            // slug survives a reseed that renumbers ids.
            $table->string('book_slug', 64);
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse');

            $table->string('source', 12)->default('generated');
            $table->string('note', 200)->nullable();

            $table->timestamps();

            // THE NO-REPEAT INDEX. Every pick queries "is this verse already
            // spoken for?" against exactly this triple, once per candidate
            // scan — it wants to be an index seek, not a scan, for the next
            // hundred years.
            $table->index(['book_slug', 'chapter', 'verse'], 'ix_daily_verse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_verses');
    }
};
