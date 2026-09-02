<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `shared_headings` mirrors the `headings` table, but swaps the per-translation
 * `translation_id` for a `set_key` ('en-standard', 'es-standard', ...). One row
 * here is shared by every translation whose `heading_set` matches.
 *
 * These are the curated SECTION headings (kinds s/ms/mr/r/sr/sp). Descriptive
 * Psalm titles (kind 'd') are NOT stored here — they stay per-translation in
 * `headings`, because they legitimately differ between translations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_headings', function (Blueprint $table) {
            $table->id();
            $table->string('set_key', 32);
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('before_verse');   // heading sits ABOVE this verse
            $table->string('kind', 4);                       // s, ms, mr, r, sr, sp
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('text', 255);
            $table->timestamps();

            // One heading per (set, anchor, kind, level). Guards the hand-edited
            // file against accidental exact-duplicate rows.
            $table->unique(
                ['set_key', 'book_id', 'chapter', 'before_verse', 'kind', 'level'],
                'uk_shared_heading'
            );

            // The read path in showChapter filters on exactly these three.
            $table->index(['set_key', 'book_id', 'chapter'], 'ix_shared_heading_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_headings');
    }
};
