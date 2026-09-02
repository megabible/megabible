<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * typing_scores — the high-score board for RANKED rounds only.
 *
 * No user accounts (per the spec): a score is just a typed-in handle plus the
 * metrics. We keep a salted hash of the IP for light abuse-throttling and to
 * spot obvious flooding — never the raw IP.
 *
 * Every metric here is computed SERVER-SIDE from the raw counts the browser
 * reports (keystrokes, errors, duration). We don't trust the browser's WPM math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('typing_scores', function (Blueprint $table) {
            $table->id();

            // The exact passage that was typed. Nullable so a score survives even
            // if a passage row is ever pruned; the label is copied below too.
            $table->foreignId('typing_passage_id')->nullable()
                  ->constrained('typing_passages')->nullOnDelete();

            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();

            // Denormalised so the board renders without joins and stays readable
            // even if the passage is later edited or removed.
            $table->string('reference_label', 120);
            $table->string('mode', 20);          // sprint | standard | endurance
            $table->string('difficulty', 10);    // normal | hard

            $table->string('player_name', 24);

            // The three headline metrics (server-computed).
            $table->decimal('gross_wpm', 6, 2);
            $table->decimal('net_wpm', 6, 2);
            $table->decimal('accuracy', 5, 2);   // 0.00–100.00

            // Raw inputs, kept for verification / future re-scoring.
            $table->unsignedSmallInteger('char_count');
            $table->unsignedInteger('total_keystrokes');
            $table->unsignedSmallInteger('error_count');
            $table->unsignedInteger('duration_ms');

            // Salted hash of the submitter's IP. Privacy-friendly; lets us
            // throttle/spot floods without storing anyone's address.
            $table->char('ip_hash', 64)->nullable();

            $table->timestamps();

            // The board is always "top N for this mode + difficulty, by net_wpm".
            $table->index(['mode', 'difficulty', 'net_wpm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typing_scores');
    }
};
