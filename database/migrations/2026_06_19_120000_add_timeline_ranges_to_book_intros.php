<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            // Numeric start/end years for the timeline bar.
            // Signed integers so BC dates can be stored as negatives.
            $table->integer('dating_start')->nullable()->after('dating_sort');
            $table->integer('dating_end')->nullable()->after('dating_start');

            // The CURRENT book's bar colour — a palette key like "terracotta".
            // (Sibling books get their colour from the groups below instead.)
            $table->string('timeline_color', 40)->nullable()->after('timeline_end');

            // Colour groups for this book's timeline. JSON shape:
            //   [{ "label": "...", "color": "...", "books": ["OSIS", ...] }, ...]
            // The union of every group's books is the set of siblings drawn.
            $table->json('timeline_groups')->nullable()->after('timeline_books');
        });
    }

    public function down(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            $table->dropColumn([
                'dating_start',
                'dating_end',
                'timeline_color',
                'timeline_groups',
            ]);
        });
    }
};
