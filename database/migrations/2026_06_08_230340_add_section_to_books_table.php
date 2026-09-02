<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Editorial grouping for the global TOC (torah, neviim, gospels, ...).
            // Independent of `book_order`, which stays the reading sequence.
            $table->string('section', 30)->nullable()->after('testament');
            $table->unsignedSmallInteger('section_order')->default(0)->after('section');
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropColumn(['section', 'section_order']);
        });
    }
};