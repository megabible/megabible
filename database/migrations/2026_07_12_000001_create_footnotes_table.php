<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Translator / editorial footnotes, attached to the END of specific verses.
 *
 * Footnotes are inherently PER-TRANSLATION (the WEB's notes belong to the WEB,
 * Scrivener's notes belong to the KJVCPB), so this table parallels `headings`
 * rather than `shared_headings`: keyed by translation_id, never shared across
 * editions.
 *
 * Like headings, footnotes are a pure OVERLAY. Nothing here touches or depends
 * on the byte content of `verses.text` — verses stay the untouched atomic units
 * that search, permalinks, interlinear, and the typing game rely on. The one
 * soft link is `anchor_text` (see below), which is display-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footnotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse_number');

            // Order WITHIN the verse (1-based). Gen 1:20 in the eng-kjv file
            // carries four notes; sequence keeps them in document order and
            // drives the per-chapter letter markers (a, b, c… restarting each
            // chapter, wrapping to aa, ab… past z).
            $table->unsignedTinyInteger('sequence')->default(1);

            // Note category. 'note' for everything we import today. Reserved
            // for future voices sharing a chapter — e.g. 'xref' if \x cross
            // references ever get imported, or 'editorial' for original
            // MEGABIBLE notes — so the UI can give each kind its own marker
            // style (†/‡ or a theme color) without a schema change.
            $table->string('kind', 16)->default('note');

            // Attribution key into config/footnote_sources.php — same pattern
            // as shared_headings.source_key + config/heading_sources.php. The
            // colophon uses it to render "N footnotes from … · license · Source".
            // Nullable: a missing key falls back to crediting the translation.
            $table->string('source_key', 64)->nullable();

            // The last few words of verse text immediately PRECEDING the \f
            // marker in the source USFM — i.e. the word(s) the note glosses.
            // Captured at import, shown as the bold lead-in inside the note
            // popover ("bdellium — or, aromatic resin"). Display-only today;
            // it is also the upgrade path to in-text markers later (render-time
            // matching) without re-importing a single row. Null when the note
            // opens the verse or no anchor could be captured.
            $table->string('anchor_text', 255)->nullable();

            // The note itself: cleaned \ft content. Markers stripped, curly
            // quotes and inline Hebrew (from \+wh …\+wh*) preserved as-is.
            $table->text('text');

            $table->timestamps();

            // The one query the chapter page makes: all notes for a chapter of
            // one translation, ordered by verse + sequence.
            $table->index(['translation_id', 'book_id', 'chapter'], 'footnotes_chapter_idx');

            // One note per slot. This is what makes re-imports honest: the
            // importer deletes-then-inserts per book inside a transaction, and
            // this constraint catches any duplicate the parser might emit.
            $table->unique(
                ['translation_id', 'book_id', 'chapter', 'verse_number', 'sequence'],
                'footnotes_slot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footnotes');
    }
};
