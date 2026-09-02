<?php

namespace App\Console\Commands;

use App\Models\DailyVerse;
use App\Support\DailyVersePicker;
use App\Support\Sabbath;
use Illuminate\Console\Command;

/**
 * scrim:daily-pick — fill the calendar ahead.
 *
 * Walks from today forward, and for every date with no verse yet, writes the
 * one DailyVersePicker::choose gives it. Dates that already have a verse are
 * left alone, which is what makes this safe to run nightly AND makes your
 * curated days (scrim:daily-set) untouchable once set.
 *
 * Running ahead matters: it means the daily page is a single indexed read on
 * the morning, never a pool scan, and it gives you a visible queue to curate
 * against — `php artisan scrim:daily-pick --days=30` then look at what's
 * coming and override the days you have plans for.
 *
 * Scheduled nightly (see routes/console.php). Safe by hand any time:
 *   php artisan scrim:daily-pick --days=30
 *   php artisan scrim:daily-pick --dry-run
 */
class PickDailyVerses extends Command
{
    protected $signature = 'scrim:daily-pick
                            {--days=14 : How many days ahead to fill, counting today}
                            {--dry-run : Show what would be chosen without writing}';

    protected $description = 'Fill upcoming days with daily-scrimmage verses (no repeats, ever)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry  = (bool) $this->option('dry-run');

        $filled  = 0;
        $skipped = 0;

        for ($i = 0; $i < $days; $i++) {
            // Every date is normalised through the picker so the site clock
            // is the only clock in play — never the server's local zone.
            $date = DailyVersePicker::normaliseDate(now()->addDays($i));

            if (DailyVerse::where('date', $date)->exists()) {
                $skipped++;
                continue;
            }

            // The sabbath: no verse, chosen or generated. Named in the
            // output so a 14-day fill reading "12 filled" isn't a mystery.
            if (Sabbath::dateIsSabbath($date)) {
                $this->line("{$date}  \u{00B7}  sabbath \u{2014} no daily verse");
                continue;
            }

            try {
                $pick = DailyVersePicker::choose($date);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            $label = $pick['book_slug'] . ' ' . $pick['chapter'] . ':' . $pick['verse'];
            $tier  = $pick['tier'] === 0 ? '' : ' (tier ' . $pick['tier'] . ')';

            if ($dry) {
                $this->line("would set {$date}  →  {$label}{$tier}");
                $filled++;
                continue;
            }

            // firstOrCreate rather than create: harmless if a fallback wrote
            // this same date a moment ago (it would have chosen the same
            // verse — the picker is deterministic).
            DailyVerse::firstOrCreate(
                ['date' => $date],
                [
                    'book_slug' => $pick['book_slug'],
                    'chapter'   => $pick['chapter'],
                    'verse'     => $pick['verse'],
                    'source'    => 'generated',
                ]
            );

            $this->line("{$date}  →  {$label}{$tier}");
            $filled++;
        }

        $verb = $dry ? 'Would fill' : 'Filled';
        $this->info("{$verb} {$filled} day(s); {$skipped} already had a verse.");
        $this->line('Ledger now holds ' . DailyVerse::count() . ' day(s) of history and queue.');

        return self::SUCCESS;
    }
}
