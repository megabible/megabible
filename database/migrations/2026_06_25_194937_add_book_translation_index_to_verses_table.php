<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verses', function (Blueprint $table) {
            // Lets the homepage's DISTINCT (book_id, translation_id) lookup run as
            // an index-only scan instead of touching every verse row. Leads with
            // book_id because that's the column the DISTINCT groups on first.
            $table->index(['book_id', 'translation_id'], 'verses_book_translation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('verses', function (Blueprint $table) {
            $table->dropIndex('verses_book_translation_idx');
        });
    }
};