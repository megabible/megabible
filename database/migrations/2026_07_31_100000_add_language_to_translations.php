<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LANGUAGE JOINS THE CHALLENGE IDENTITY.
 *
 * Scrimboards are per-LANGUAGE, not per-translation: every English edition
 * of a verse shares one board (as before), and Spanish editions — when they
 * arrive — spawn their own. That split lives in the challenge key
 * (Challenge::canonical renders scrimmage|en|romans.8.1|d20), and the key
 * reads its language from translations.language.
 *
 * THE COLUMN ALREADY EXISTED — varchar(10) NOT NULL default 'en', already
 * holding clean ISO 639-1 codes on every imported edition. This migration
 * therefore does almost nothing to it: no type change (varchar(10) is the
 * BETTER type, leaving room for regional codes like pt-br), no rewriting of
 * values that are already correct. It exists to (a) guarantee the column is
 * present on any database that lacks it, (b) normalise stray values if a
 * hand-edit ever introduces one, and (c) carry the typing_scores reset that
 * the new key scheme requires.
 *
 * THE CONTRACT the rest of the system relies on:
 *     translations.language is a lowercase, trimmed, non-empty language code.
 * scrim_plays.lang mirrors it as varchar(10) — same width, no truncation.
 *
 * IDEMPOTENT: safe on a fresh database, safe when the column already exists,
 * safe to re-run after a partial failure.
 *
 * PRE-LAUNCH SANDBOX: typing_scores is truncated. Adding lang to the
 * canonical string changes every scrimmage challenge key, orphaning every
 * existing board row — none worth migrating (announced house rule: no
 * backwards compatibility until go-live). Same precedent as the dial-names
 * migration. Done BEFORE scrim_plays exists, so no analytics row ever
 * accrues against a dead key.
 */
return new class extends Migration
{
    /**
     * Full names → ISO 639-1, for the case where a seeder or hand-edit puts
     * a human-readable value in. Matched against the lowercased, trimmed
     * stored value. Extend when a new language is imported.
     */
    private const NORMALISE = [
        'english' => 'en',
        'eng'     => 'en',
        'spanish' => 'es',
        'espanol' => 'es',
        'español' => 'es',
        'spa'     => 'es',
        'greek'   => 'el',
        'hebrew'  => 'he',
        'latin'   => 'la',
    ];

    public function up(): void
    {
        // Clean slate under the new key scheme; also resets auto-increment.
        // Idempotent by nature — truncating an empty table is a no-op.
        DB::table('typing_scores')->truncate();

        // ---- 1. The column exists, or we create it ------------------------
        // varchar(10), matching what the table already carries — NOT char(2).
        if (! Schema::hasColumn('translations', 'language')) {
            Schema::table('translations', function (Blueprint $table) {
                $table->string('language', 10)->default('en')->after('abbreviation');
            });
        }

        // ---- 2. Normalise, gently -----------------------------------------
        // A no-op on the current data (every edition already reads 'en').
        // Only lowercases, trims, and expands a known full name; anything
        // else is left EXACTLY as found, so a future 'pt-br' survives.
        // Empty is the one value that can't stand — it would render an empty
        // segment in the challenge key.
        foreach (DB::table('translations')->select('id', 'language')->get() as $row) {
            $raw = strtolower(trim((string) $row->language));
            $iso = self::NORMALISE[$raw] ?? $raw;

            if ($iso === '') {
                $iso = 'en';
            }

            if ($iso !== $row->language) {
                DB::table('translations')->where('id', $row->id)
                    ->update(['language' => $iso]);
            }
        }

        // No type change: varchar(10) NOT NULL default 'en' is already the
        // shape we want, and narrowing it would only cost future headroom.
    }

    public function down(): void
    {
        // The column is NOT dropped: it predates this migration.
        // The truncate is one-way (sandbox rule). Nothing to reverse.
    }
};
