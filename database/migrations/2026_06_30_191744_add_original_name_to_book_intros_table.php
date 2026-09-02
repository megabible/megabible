<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            // Original-language title of the book (Hebrew for first testament,
            // Greek for second). Only books with the data populate these;
            // every other row stays null.
            $table->string('original_name')->nullable()->after('book_id');
            $table->string('original_name_transliteration')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'original_name_transliteration']);
        });
    }
};