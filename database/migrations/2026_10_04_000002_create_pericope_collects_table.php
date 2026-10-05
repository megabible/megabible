<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pc-collected r1: anonymous per-book daily COLLECTED-VERSE counters —
// the book_visits table shape with scrim_plays volume semantics. One row
// per (book, day); hits = verses landed on pericope boards that day
// through the store's addCards() path. Imports of shared boards and card
// duplicates deliberately don't count (collecting is the reader choosing
// a verse, not copying a board), and removals never decrement — this is
// an activity counter, not a live inventory. No IP, no hash, no cookie —
// nothing personal by construction; board contents themselves never
// touch the server. See PericopeController::collected().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pericope_collects', function (Blueprint $table) {
            $table->id();
            $table->string('osis', 16);        // Book.osis_id — survives renames
            $table->date('collect_date');
            $table->unsignedInteger('hits')->default(0);   // verses collected that day
            $table->timestamps();

            $table->unique(['osis', 'collect_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pericope_collects');
    }
};
