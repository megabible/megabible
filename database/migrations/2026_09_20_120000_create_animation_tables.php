<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ANIMATION PROGRAM — the whole schema in one migration (anim r1).
 *
 * Five tables, three concerns:
 *
 *   THE REQUEST PIPELINE (tracking only — NO PII, by design)
 *     animation_requests         one row per chapter someone applied to
 *                                animate. Columns for identity DO NOT EXIST:
 *                                names, emails, channels live only in the
 *                                site owner's mailbox. Rows are created by
 *                                hand (artisan / the local-only manager),
 *                                never by the public form.
 *     animation_request_updates  the status log behind each request. The
 *                                public planning page shows ONLY the status
 *                                label + its age; `note` is internal.
 *
 *   WATCH MODE (the videos)
 *     chapter_animations         one row per draft/published video.
 *
 *   PLANNING-PAGE DATA
 *     chapter_stats              computed per chapter (verse/word counts)
 *                                by `php artisan mb:chapter-stats`. Fully
 *                                rebuildable — nothing hand-entered here.
 *     chapter_complexities       hand-rated 1–10 difficulty, imported from
 *                                a TSV by `mb:animation-complexity`. Kept
 *                                OUT of chapter_stats so a stats rebuild
 *                                can never wipe the hand ratings.
 *
 * CREATION ORDER MATTERS: animation_requests must exist before
 * chapter_animations, whose animation_request_id foreign key points at it.
 *
 * Status/origin fields are plain strings + model constants, not MySQL
 * enums — statuses will be revised often, and ALTERing an enum column is
 * a migration every time. The models are the source of truth for values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- The request pipeline (NO PII COLUMNS EXIST) ----------------
        Schema::create('animation_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');

            // Which edition the artist intends to narrate. Nullable: it can
            // be logged before that's settled.
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();

            // 'pending' | 'approved' | 'in_progress' | 'submitted' | 'live'
            // | 'declined' | 'withdrawn' — see AnimationRequest::STATUS_*.
            $table->string('status', 16)->default('pending');

            $table->timestamps();

            // Planning pages group by chapter. NOT unique — multiple
            // animations of one chapter are allowed by program rule.
            $table->index(['book_id', 'chapter']);
        });

        // ---- Status log per request -------------------------------------
        Schema::create('animation_request_updates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('animation_request_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16);

            // INTERNAL bookkeeping only. The public planning page renders
            // the status label + how long ago — never this text. (House
            // habit regardless: no identifying details in notes.)
            $table->text('note')->nullable();

            $table->timestamps();
        });

        // ---- The videos -------------------------------------------------
        Schema::create('chapter_animations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');

            // The translation the voiceover follows EXACTLY (program rule).
            // A video is pinned to one edition; the watch page renders the
            // chapter text in this translation regardless of what the
            // reader was reading when they tapped the eyeball.
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();

            // The 11-char YouTube video id (not a URL). Unique enforces the
            // one-video-one-chapter program rule at the schema level.
            $table->string('youtube_id', 16)->unique();
            $table->string('title')->nullable();

            // 'in_house' | 'crowd' — see ChapterAnimation::ORIGIN_*.
            $table->string('origin', 16)->default('in_house');

            // Public attribution for crowd videos — intentional, entered by
            // hand at publish time. Null for in-house videos.
            $table->string('creator_name')->nullable();
            $table->string('creator_channel_url')->nullable();

            $table->unsignedSmallInteger('duration_seconds')->nullable();

            // Verse-level cues, array of objects (NOT bare pairs) so finer
            // cue kinds can be added later without a migration:
            //   [ {"k":"v","v":1,"t":0.0}, {"k":"v","v":2,"t":14.5}, ... ]
            // k = cue kind ('v' = verse start; 'p' pause / 's' stanza later).
            $table->json('cues')->nullable();

            // 'draft' | 'live' | 'removed' — see ChapterAnimation::STATUS_*.
            // 'removed' (rather than deleting) is the irrevocable-discretion
            // clause made durable: history stays, the video just stops
            // rendering anywhere.
            $table->string('status', 16)->default('draft');

            // Lowest sort_order among a chapter's live videos plays by
            // default; ties break on id. Set by hand — in-house first is a
            // publishing habit, not a schema rule, so it stays overridable.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamp('published_at')->nullable();

            // Optional link back to the request that produced this video.
            $table->foreignId('animation_request_id')
                ->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The watch page's one lookup: chapter → its live videos.
            $table->index(['book_id', 'chapter', 'status']);
        });

        // ---- Computed chapter stats (rebuildable, never hand-edited) ----
        Schema::create('chapter_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');

            // Provenance: which edition the counts were computed from —
            // KJV where it carries the chapter, else the site's standard
            // fallback chain. The planning guide labels this so artists
            // never guess whose word count they're reading.
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('verse_count');
            $table->unsignedInteger('word_count');

            $table->timestamps();

            // One stats row per chapter, from the chosen script edition.
            $table->unique(['book_id', 'chapter']);
        });

        // ---- Hand-rated complexity (survives every stats rebuild) -------
        Schema::create('chapter_complexities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter');

            // 1 = a quiet genealogy, 10 = Revelation. Hand-rated, revised
            // freely; the TSV in the repo is the editing surface.
            $table->unsignedTinyInteger('rating');

            $table->timestamps();

            $table->unique(['book_id', 'chapter']);
        });
    }

    public function down(): void
    {
        // Children first: chapter_animations points at animation_requests.
        Schema::dropIfExists('chapter_complexities');
        Schema::dropIfExists('chapter_stats');
        Schema::dropIfExists('chapter_animations');
        Schema::dropIfExists('animation_request_updates');
        Schema::dropIfExists('animation_requests');
    }
};
