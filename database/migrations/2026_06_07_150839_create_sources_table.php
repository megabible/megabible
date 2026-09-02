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
    Schema::create('sources', function (Blueprint $table) {
        $table->id();
        $table->string('slug', 80)->unique();          // future /sources#anchor-john
        $table->string('citation');                      // full formatted citation text
        $table->string('author')->nullable();
        $table->string('title')->nullable();
        $table->unsignedSmallInteger('year')->nullable();
        $table->string('publisher')->nullable();
        $table->string('url', 700)->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
