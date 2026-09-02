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
    Schema::create('book_timeline_event', function (Blueprint $table) {
        $table->id();
        $table->foreignId('book_id')->constrained()->cascadeOnDelete();
        $table->foreignId('timeline_event_id')->constrained()->cascadeOnDelete();
        $table->timestamps();

        $table->unique(['book_id', 'timeline_event_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_timeline_event');
    }
};
