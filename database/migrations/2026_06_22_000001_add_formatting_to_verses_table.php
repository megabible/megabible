<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path B formatting columns on `verses`.
 *
 *   starts_paragraph  — true when a \p / \m / etc. marker introduced this verse,
 *                       so the reader knows where to open a new <p> in narrative.
 *   format            — NULL for a plain prose verse (the common case: text lives
 *                       in `text` and that's all we need). For anything with
 *                       internal structure — poetry lines, a prose lead that
 *                       breaks into verse, stanza breaks — this holds an ordered
 *                       list of blocks: [{"s":"q1","t":"Blessed is the man"}, …].
 *                       `text` always holds the clean, concatenated, searchable
 *                       wording regardless, so search / parallel view never touch
 *                       this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verses', function (Blueprint $table) {
            $table->boolean('starts_paragraph')->default(false)->after('text');
            $table->json('format')->nullable()->after('starts_paragraph');
        });
    }

    public function down(): void
    {
        Schema::table('verses', function (Blueprint $table) {
            $table->dropColumn(['starts_paragraph', 'format']);
        });
    }
};
