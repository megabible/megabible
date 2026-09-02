<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `heading_set` to translations.
 *
 * A translation points at a named set of curated section headings
 * (e.g. 'en-standard', 'es-standard') stored in `shared_headings`. Several
 * translations that share versification can point at the same set, so a
 * heading is authored once and shows up everywhere it applies. NULL means
 * "this translation gets no shared section headings" (its own Psalm titles,
 * which live in `headings`, are unaffected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->string('heading_set', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('heading_set');
        });
    }
};
