<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Row-detail stats for the scrimboard popover.
     *
     * wraps       — the server-derived ⌊chars typed / verse length⌋ that
     *               already feeds the v2 wrap bonus; now kept, not discarded.
     * best_combo  — the round's longest clean keystroke run. Client-claimed
     *               (like error_count already is) and clamped to char_count;
     *               display-only, never part of scoring.
     *
     * Both nullable: pre-v4 rows simply show "—" in the popover.
     */
    public function up(): void
    {
        Schema::table('typing_scores', function (Blueprint $table) {
            $table->unsignedSmallInteger('wraps')->nullable()->after('duration_config');
            $table->unsignedSmallInteger('best_combo')->nullable()->after('wraps');
        });
    }

    public function down(): void
    {
        Schema::table('typing_scores', function (Blueprint $table) {
            $table->dropColumn(['wraps', 'best_combo']);
        });
    }
};