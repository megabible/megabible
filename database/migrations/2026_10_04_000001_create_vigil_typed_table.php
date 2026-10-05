<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// vg-typed r1: anonymous per-book daily TYPED-VERSE counters — the
// book_visits table shape with scrim_plays volume semantics. One row per
// (book, day); hits = verses completed in the vigil that day. Every
// completion counts (retypes and multipliers included — they're real
// typing); there is deliberately NO per-device dedup, unlike the seen
// pill. No IP, no hash, no cookie — nothing personal by construction.
// The client batches completions (~12s window + tab-hide flush) and
// POSTs {osis, count}; see TypingController::vigilTyped().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vigil_typed', function (Blueprint $table) {
            $table->id();
            $table->string('osis', 16);        // Book.osis_id — survives renames
            $table->date('typed_date');
            $table->unsignedInteger('hits')->default(0);   // verses typed that day
            $table->timestamps();

            $table->unique(['osis', 'typed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vigil_typed');
    }
};
