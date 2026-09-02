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
    Schema::create('book_intros', function (Blueprint $table) {
        $table->id();
        $table->foreignId('book_id')->unique()->constrained()->cascadeOnDelete();

        // Prose body (Markdown)
        $table->text('summary')->nullable();
        $table->text('authorship_note')->nullable();

        // Short "infobox" fields
        $table->string('traditional_author')->nullable();
        $table->string('scholarly_view')->nullable();
        $table->string('dating', 100)->nullable();        // display string, e.g. "c. 90–110 CE"
        $table->smallInteger('dating_sort')->nullable();   // signed, for timelines later (negative = BCE)
        $table->string('language', 50)->nullable();
        $table->string('genre', 50)->nullable();
        $table->string('place_written', 100)->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_intros');
    }
};
