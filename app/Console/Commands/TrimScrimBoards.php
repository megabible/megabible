<?php

namespace App\Console\Commands;

use App\Models\TypingScore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * scrim:trim — THE SABBATH CUT.
 *
 * Once a week, in the sabbath's opening minutes (Saturday 00:06 site time —
 * six past, so tokens minted Friday 23:59 can file their last honest scores
 * first): every scrimmage board is cut to its top typing.board_size rows,
 * and EVERY standing row is stamped survived_trim_at = now.
 *
 * Two deliberate changes from the old nightly semantics:
 *
 *   WEEKLY, NOT NIGHTLY. A board now accumulates Sunday through Friday and
 *   is cut once. Surviving the cut means surviving a WEEK — the crown got
 *   heavier on purpose.
 *
 *   CHAMPIONS ON EVERY BOARD. The stamp used to be earned only "when the
 *   knife actually fell"; now every row standing at the cut is stamped,
 *   crowded board or not. Under weekly semantics the crown means "stood at
 *   the sabbath cut", and seven names who stood all week earned it exactly
 *   as ten-of-forty did. The crown holds all week even as the new week's
 *   scores push a champion down the board — because the stamp lives on the
 *   ROW, and only two things remove it: a takeover (the score path clears
 *   the stamp — the new holder of the name carried nothing through last
 *   week) or falling below the line at the NEXT cut.
 *
 * The boards' Sunday "restoration" is not this command and is not any
 * command: sabbath visibility is a veil in the read paths (see
 * App\Support\Sabbath), and it lifts at Sunday 00:00 by itself. What this
 * command leaves standing Saturday morning IS what Sunday reveals.
 *
 * DAILY BOARDS ARE UNTOUCHED, as ever — the challenge_mode filter spares
 * them, and the archive command owns their ending.
 *
 * ORDERING (must match the board endpoint exactly): final_score desc,
 * accuracy desc, then created_at ASC — a tie at the cutoff keeps the row
 * that was set FIRST (the incumbent defends).
 *
 * Scheduled weekly in routes/console.php. Safe by hand any time:
 * `php artisan scrim:trim --dry-run`.
 */
class TrimScrimBoards extends Command
{
    protected $signature = 'scrim:trim {--dry-run : Report what would be cut without deleting anything}';

    protected $description = 'The weekly sabbath cut: every scrimboard to its top rows, every survivor crowned';

    public function handle(): int
    {
        $keep = (int) config('typing.board_size');
        $dry  = (bool) $this->option('dry-run');

        // ---- 1. Cut the crowded boards -----------------------------------
        $crowded = TypingScore::query()
            ->where('challenge_mode', 'scrimmage')
            ->whereNotNull('challenge_key')
            ->select('challenge_key', DB::raw('COUNT(*) as n'))
            ->groupBy('challenge_key')
            ->having('n', '>', $keep)
            ->get();

        $boards = 0;
        $cut    = 0;

        foreach ($crowded as $row) {
            $key = $row->challenge_key;

            // The survivors — the same sort the board renders with, so what
            // players SEE as the top ten is exactly what lives.
            $keepIds = TypingScore::where('challenge_key', $key)
                ->orderByDesc('final_score')
                ->orderByDesc('accuracy')
                ->orderBy('created_at')          // tie at the cutoff: incumbent wins
                ->limit($keep)
                ->pluck('id');

            if ($dry) {
                $would = (int) $row->n - $keepIds->count();
                $this->line("would cut {$key}: -{$would}, keep {$keepIds->count()}");
                $boards++;
                $cut += $would;
                continue;
            }

            $cut += TypingScore::where('challenge_key', $key)
                ->whereNotIn('id', $keepIds)
                ->delete();
            $boards++;
        }

        // ---- 2. Crown everything left standing ---------------------------
        // ALL scrimmage rows, cut board or quiet one: standing at the
        // sabbath cut is what the crown means now. One UPDATE, not a loop.
        if ($dry) {
            $standing = TypingScore::where('challenge_mode', 'scrimmage')
                ->whereNotNull('challenge_key')->count()
                - $cut;   // rows that would remain after the cuts above
            $this->info("Would cut {$boards} crowded board(s) (-{$cut} rows) and crown ~{$standing} standing row(s).");
            return self::SUCCESS;
        }

        $crowned = TypingScore::where('challenge_mode', 'scrimmage')
            ->whereNotNull('challenge_key')
            ->update(['survived_trim_at' => now()]);

        $this->info("Sabbath cut: {$boards} crowded board(s) trimmed (-{$cut} rows); {$crowned} row(s) crowned for the week.");

        return self::SUCCESS;
    }
}
