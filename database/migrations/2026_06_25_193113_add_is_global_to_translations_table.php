<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            // Whether this translation can serve as the reader's sticky, site-wide
            // default. TRUE for full-canon translations (KJV, WEB, RV1909) the
            // reader moves through book to book. FALSE for single-work editions
            // (e.g. R.H. Charles' 1 Enoch) that should be readable but must never
            // hijack the reader's remembered translation across the rest of the site.
            //
            // Defaults TRUE so every translation you've already seeded (KJV, WEB,
            // etc.) stays a valid default with no backfill. Mark one-off editions
            // FALSE explicitly when you seed them.
            $table->boolean('is_global')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });
    }
};