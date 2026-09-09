<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Scroll r2: anonymous like counters for pericope feed posts — the
// book_visits pattern applied to affection. One row per LIKE KEY: a post's
// verse refs, sorted, joined, translation-free (MBPericope.likeKey — e.g.
// "Gen.1.1+Rom.8.28-30"), so two editions of one verse share a count and a
// grouped post counts apart from its members. Hits only. No IP, no hash of
// a person, no cookie — nothing personal by construction. Dedup is
// client-side (localStorage mbLikes.v1: this device's likes), so "hits" ≈
// devices that currently like it. Unlike decrements, floored at zero,
// unverified — the same trust level as every other beacon counter here.
//
// like_hash (sha256 of like_key) carries the unique index: a group key can
// run past InnoDB's utf8mb4 key-length ceiling, and a fixed 64-char ascii
// column indexes small and fast. like_key itself is stored legible, for
// Tinker and for future "most-liked verses" reads.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verse_likes', function (Blueprint $table) {
            $table->id();
            $table->char('like_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('like_key', 1000);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique('like_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verse_likes');
    }
};
