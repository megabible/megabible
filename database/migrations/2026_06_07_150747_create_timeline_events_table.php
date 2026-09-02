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
    Schema::create('timeline_events', function (Blueprint $table) {
        $table->id();
        $table->string('slug', 80)->unique();        // future /timeline/{slug}
        $table->string('label');                      // "Crucifixion of Jesus"
        $table->string('date_display', 100)->nullable(); // "c. 30–33 CE"
        $table->smallInteger('date_sort')->nullable();   // 31 — for positioning (negative = BCE)
        $table->string('kind', 40)->default('event');
        $table->timestamps();

        $table->index('date_sort');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
