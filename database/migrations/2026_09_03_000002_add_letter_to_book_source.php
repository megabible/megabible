<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// hub-src r2: per-book marker letter for a source. Inline "(a)" tokens in
// the intro fields resolve against this. Nullable — a source can sit in
// the bibliography without ever being cited inline.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_source', function (Blueprint $table) {
            $table->string('letter', 1)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('book_source', function (Blueprint $table) {
            $table->dropColumn('letter');
        });
    }
};