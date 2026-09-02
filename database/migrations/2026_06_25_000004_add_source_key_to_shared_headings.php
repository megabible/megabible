<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `source_key` to `shared_headings`.
 *
 * Until now a shared set had a single source, so set_key alone credited it.
 * Adding deuterocanon headings to the 'en-standard' set breaks that assumption:
 * the 66 Protestant books come from the Berean Standard Bible, but the deutero
 * headings are authored elsewhere (your own work, another edition, …).
 *
 * source_key is a per-row attribution override that resolves to
 * config/heading_sources.php. It is NULLABLE: existing BSB rows leave it null
 * and keep falling back to the set's own credit, so nothing already imported
 * changes. Only rows that set it (the deutero ones) get a different credit line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_headings', function (Blueprint $table) {
            $table->string('source_key', 48)->nullable()->after('set_key');
        });
    }

    public function down(): void
    {
        Schema::table('shared_headings', function (Blueprint $table) {
            $table->dropColumn('source_key');
        });
    }
};
