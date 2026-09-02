<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('book_intros', function (Blueprint $table) {
        $table->smallInteger('timeline_start')->nullable()->after('place_written');
        $table->smallInteger('timeline_end')->nullable()->after('timeline_start');
        $table->json('timeline_books')->nullable()->after('timeline_end'); // OSIS ids of sibling books
        $table->json('outline')->nullable()->after('timeline_books');       // nested outline tree
    });
}



    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('book_intros', function (Blueprint $table) {
        $table->dropColumn(['timeline_start', 'timeline_end', 'timeline_books', 'outline']);
    });
}
};
