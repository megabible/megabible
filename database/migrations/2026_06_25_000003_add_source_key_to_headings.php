<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `source_key` to the per-translation `headings` table.
 *
 * Lets hand-authored headings (Psalm titles, 1 Enoch section heads from
 * R. H. Charles, original MegaBible headings, …) carry an attribution key that
 * resolves to config/heading_sources.php for the chapter colophon. NULL means
 * "no separate credit" — e.g. Psalm titles that are simply part of the base
 * translation and already covered by the translation's own source line.
 *
 * The shared_headings table needs no equivalent yet: those rows are credited by
 * their set_key, because each shared set currently comes from a single source.
 * (If a set ever mixes sources — e.g. BSB for the 66 books plus a different
 *  source for deuterocanon in the same set — add source_key here too.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('headings', function (Blueprint $table) {
            $table->string('source_key', 48)->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('headings', function (Blueprint $table) {
            $table->dropColumn('source_key');
        });
    }
};
