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
    Schema::create('manuscripts', function (Blueprint $table) {
        $table->id();
        $table->string('slug', 60)->unique();              // future /manuscripts/p52
        $table->string('name');
        $table->string('siglum', 30)->nullable();          // 𝔓52, ℵ, B …
        $table->enum('kind', ['papyrus', 'codex', 'majuscule', 'minuscule', 'other'])->default('other');
        $table->string('date_display', 100)->nullable();   // "c. 125 CE"
        $table->smallInteger('date_sort')->nullable();     // 125 — for ordering
        $table->text('description')->nullable();
        $table->timestamps();

        $table->index('date_sort');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manuscripts');
    }
};
