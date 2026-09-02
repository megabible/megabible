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
    Schema::create('books', function (Blueprint $table) {
        $table->id();
        $table->string('osis_id', 10)->unique();
        $table->string('slug', 50)->unique();
        $table->string('name', 100);
        $table->string('short_name', 20);
        $table->enum('testament', ['OT', 'NT', 'AP', 'PS']);
        $table->string('canon_section', 50)->default('protestant');
        $table->unsignedSmallInteger('book_order');
        $table->unsignedSmallInteger('chapter_count')->nullable();
        $table->timestamps();

        $table->index('book_order');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
