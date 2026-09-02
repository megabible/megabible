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
    Schema::create('translations', function (Blueprint $table) {
        $table->id();
        $table->string('abbreviation', 20)->unique();
        $table->string('name');
        $table->string('language', 10)->default('en');
        $table->unsignedSmallInteger('year_published')->nullable();
        $table->text('description')->nullable();
        $table->string('license', 100)->nullable();
        $table->string('source_url', 500)->nullable();
        $table->boolean('has_apocrypha')->default(false);
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
