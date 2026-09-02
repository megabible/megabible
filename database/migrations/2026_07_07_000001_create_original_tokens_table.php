<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original-language word tokens for the Synthesis interlinear (card back).
 *
 * One row = one written word of the original text, in reading order. Each
 * row carries its own transliteration and literal English gloss, so the
 * three interlinear rows align per-token with no separate mapping table.
 * TAGNT also ships a per-word Spanish gloss (100% coverage), stored in
 * gloss_es against the day Reina-Valera lands.
 *
 * `position` is the word's ORDER OF APPEARANCE within the verse (1-based),
 * not the source's word number. Verified against real TAHOT data: word
 * numbers restart mid-verse where Hebrew versification splits an English
 * verse (Num 26:1), and inserted words carry 4-digit numbers (Gen 4:8
 * "#0501"). File order is curated reading order, so a simple counter is
 * both simpler and more correct.
 *
 * Language-agnostic on purpose: Hebrew/Aramaic (TAHOT) and Greek NT
 * (TAGNT) load now; any future source (tagged LXX, Ge'ez Enoch, Latin
 * 2 Esdras) is more rows with a different lang + source_key. Books with
 * no rows never show the flip button.
 *
 * Rebuildable from megabible-data files via `import:interlinear` — no
 * timestamps, same policy as bulk verse data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('original_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse');

            // 1-based appearance order within the verse (see file docblock).
            $table->unsignedSmallInteger('position');

            // 'hbo' Hebrew, 'arc' Aramaic (Daniel/Ezra sections), 'grc'
            // Greek. Display name + RTL flag live in config/interlinear.php.
            $table->string('lang', 8);

            // The word as written: fully pointed + cantillated Hebrew
            // (morpheme slashes and escape backslashes stripped), accented
            // Greek. Store the richest form; display toggles can strip
            // points, never the reverse.
            $table->string('surface', 120);

            $table->string('translit', 120)->nullable();
            $table->string('gloss', 255)->nullable();

            // TAGNT ships a Spanish word-level gloss for every NT word;
            // NULL for TAHOT. Seed data for the Reina-Valera era.
            $table->string('gloss_es', 255)->nullable();

            // Raw dStrongs as shipped ("H9003/{H7225G}"; Greek compounds
            // joined "G1473+G2532"). Unused by the card today; the seed
            // for lexicon links + concordance.
            $table->string('strongs', 120)->nullable();

            // Raw morphology (OS-style Hebrew / Robinson Greek; Greek
            // compounds joined "P-1NS+CONJ").
            $table->string('morph', 120)->nullable();

            // Attribution key into config/interlinear.php ('tahot'|'tagnt').
            $table->string('source_key', 20);

            // Integrity + the hot lookup path: fetching a verse's tokens
            // is a left-prefix scan of this index.
            $table->unique(['book_id', 'chapter', 'verse', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('original_tokens');
    }
};
