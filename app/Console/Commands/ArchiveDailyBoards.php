<?php

namespace App\Console\Commands;

use App\Models\DailyVerse;
use App\Models\Translation;
use App\Models\TypingScore;
use App\Support\Challenge;
use App\Support\DailyVersePicker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * scrim:daily-archive — THE MIDNIGHT FREEZE.
 *
 * A daily board is never trimmed. When its day ends the WHOLE field is
 * copied into daily_snapshot_entries with its ranks frozen, and the live
 * rows are deleted. One player or ten thousand, everyone who showed up is in
 * the record; the top of it is etched for good.
 *
 * WHY IT RUNS A FEW MINUTES AFTER MIDNIGHT, not at it: a token minted at
 * 23:59 stays valid for typing.token_ttl_ms, and the DATE is baked into its
 * challenge key — so a round begun before midnight still submits to
 * yesterday's board for a few minutes afterwards. Freezing at 00:00 would
 * strand those honest scores. The scheduled delay (routes/console.php) is
 * set past the token TTL so the board is genuinely finished when the knife
 * falls.
 *
 * IDEMPOTENT. Snapshot seats are unique per (date, lang, player_name), and
 * live rows are deleted only after their board is safely written. Running it
 * twice archives nothing the second time; running it late archives every
 * unarchived day it finds, not just yesterday.
 *
 *   php artisan scrim:daily-archive              # every finished day
 *   php artisan scrim:daily-archive --date=2026-08-01
 *   php artisan scrim:daily-archive --dry-run
 */
class ArchiveDailyBoards extends Command
{
    protected $signature = 'scrim:daily-archive
                            {--date= : Archive one specific day (YYYY-MM-DD)}
                            {--dry-run : Report what would be frozen, write nothing}';

    protected $description = 'Freeze finished daily boards into the permanent archive';

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $today = DailyVersePicker::normaliseDate();

        // Which days are eligible: those with a verse, already past. TODAY is
        // never archived — its board is still being played.
        $days = DailyVerse::query()
            ->when($this->option('date'),
                fn ($q) => $q->where('date', DailyVersePicker::normaliseDate($this->option('date'))),
                fn ($q) => $q->where('date', '<', $today)
            )
            ->orderBy('date')
            ->get();

        if ($days->isEmpty()) {
            $this->info('No finished daily boards to archive.');
            return self::SUCCESS;
        }

        // Every language that has editions — each ran its own daily board.
        $langs = Translation::query()->distinct()->pluck('language')
            ->map(fn ($l) => strtolower(trim((string) $l)))
            ->filter()->unique()->values();

        $boards = 0;
        $seats  = 0;

        foreach ($days as $day) {
            $date = $day->date->toDateString();

            foreach ($langs as $lang) {
                $key = Challenge::dailyKey($date, $lang, $day->book_slug, $day->chapter, $day->verse);

                // Already frozen? Then the live rows (if any linger) are
                // leftovers from a re-run and can go.
                $frozen = DB::table('daily_snapshot_entries')
                    ->where('date', $date)->where('lang', $lang)->exists();

                // THE BOARD, in the exact order it rendered in: score, then
                // accuracy, then whoever got there first.
                $rows = TypingScore::where('challenge_key', $key)
                    ->orderByDesc('final_score')
                    ->orderByDesc('accuracy')
                    ->orderBy('created_at')
                    ->get();

                if ($rows->isEmpty()) {
                    continue;               // nobody played that day in that language
                }

                if ($frozen) {
                    if (! $dry) {
                        TypingScore::where('challenge_key', $key)->delete();
                    }
                    $this->line("{$date} [{$lang}] already frozen — cleared {$rows->count()} stray live row(s).");
                    continue;
                }

                if ($dry) {
                    $this->line("would freeze {$date} [{$lang}] {$day->label()}: {$rows->count()} seat(s).");
                    $boards++;
                    $seats += $rows->count();
                    continue;
                }

                // Abbreviations resolved once per board, not once per row.
                $abbr = Translation::whereIn('id', $rows->pluck('translation_id')->filter()->unique())
                    ->pluck('abbreviation', 'id');

                $now     = now();
                $payload = [];
                $rank    = 0;

                foreach ($rows as $r) {
                    $rank++;
                    $payload[] = [
                        'date'                => $date,
                        'lang'                => $lang,
                        'challenge_key'       => $key,
                        'book_slug'           => $day->book_slug,
                        'chapter'             => $day->chapter,
                        'verse'               => $day->verse,
                        'reference_label'     => $r->reference_label,
                        'rank'                => $rank,
                        'player_name'         => $r->player_name,
                        'final_score'         => $r->final_score,
                        'net_wpm'             => $r->net_wpm,
                        'accuracy'            => $r->accuracy,
                        'wraps'               => (int) ($r->wraps ?? 0),
                        'best_combo'          => (int) ($r->best_combo ?? 0),
                        'error_count'         => (int) ($r->error_count ?? 0),
                        'translation_abbr'    => $abbr[$r->translation_id] ?? null,
                        'difficulty_modifier' => $r->difficulty_modifier,
                        'formula_version'     => $r->formula_version,
                        'claimed_at'          => $r->created_at,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }

                // Write the field, THEN drop the live rows — in a transaction,
                // so a failure mid-freeze can never lose a day's results.
                DB::transaction(function () use ($payload, $key) {
                    foreach (array_chunk($payload, 500) as $chunk) {
                        DB::table('daily_snapshot_entries')->insert($chunk);
                    }
                    TypingScore::where('challenge_key', $key)->delete();
                });

                $this->line("froze {$date} [{$lang}] {$day->label()}: {$rank} seat(s).");
                $boards++;
                $seats += $rank;
            }
        }

        $verb = $dry ? 'Would freeze' : 'Froze';
        $this->info("{$verb} {$boards} board(s), {$seats} seat(s). Set in stone.");

        return self::SUCCESS;
    }
}
