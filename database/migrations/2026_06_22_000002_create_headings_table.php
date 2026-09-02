<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Headings that sit BETWEEN verses: USFM section headings (\s, \s1, \s2…),
 * psalm / descriptive titles (\d), parallel-passage refs (\r), section
 * references (\sr), major-section heads (\ms), Song-of-Songs speakers (\sp).
 *
 * Kept in their own table (rather than crammed onto a verse) because a heading
 * is positioned *before* a verse, can stack (a \ms then \s then \r before the
 * same verse), and has its own kind/level. `before_verse` is the verse number
 * the heading precedes; the reader emits it just before rendering that verse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('before_verse'); // heading renders just before this verse
            $table->string('kind', 10)->default('s');     // s | d | r | sr | ms | mr | sp
            $table->unsignedTinyInteger('level')->default(1);
            $table->text('text');
            $table->timestamps();

            // Fast lookup of every heading in a chapter, in document order.
            $table->index(['translation_id', 'book_id', 'chapter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headings');
    }
};
