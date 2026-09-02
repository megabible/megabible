<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('verses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
        $table->foreignId('book_id')->constrained()->cascadeOnDelete();
        $table->unsignedSmallInteger('chapter');
        $table->unsignedSmallInteger('verse_number');
        $table->text('text');
        $table->string('osis_ref', 50);
        $table->unsignedBigInteger('sort_key');
        $table->timestamps();

        $table->unique(['translation_id', 'book_id', 'chapter', 'verse_number'], 'uk_verse_location');
        $table->index('osis_ref');
        $table->index(['translation_id', 'sort_key']);
        $table->fullText('text');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verses');
    }
};
