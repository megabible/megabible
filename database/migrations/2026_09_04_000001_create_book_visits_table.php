<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bk-seen r1: anonymous per-book daily visit counters — the scrim_plays
// pattern applied to reading. One row per (book, day); hits only. No IP,
// no hash, no cookie — nothing personal by construction. Dedup is
// client-side (localStorage mbSeen.v1: one beacon per device per book per
// day), so "hits" ≈ unique devices that day. The book hub's readers pill
// sums the last seven days.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_visits', function (Blueprint $table) {
            $table->id();
            $table->string('osis', 16);        // Book.osis_id — survives renames
            $table->date('visit_date');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['osis', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_visits');
    }
};