<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// hub-src r2: the Excerpt system. `excerpt` is markdown shown when a book
// has no Overview yet; `excerpt_source` is the slug of the source it was
// pulled from (must appear in that book's sources[] list — the importer
// warns when it doesn't).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            $table->text('excerpt')->nullable()->after('summary');
            $table->string('excerpt_source')->nullable()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('book_intros', function (Blueprint $table) {
            $table->dropColumn(['excerpt', 'excerpt_source']);
        });
    }
};