<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\DailyVerse;
use App\Models\Translation;
use App\Models\Verse;
use App\Support\DailyVersePicker;
use Illuminate\Console\Command;

/**
 * scrim:daily-set — the curator's override.
 *
 * The generated queue runs the corpus in a deterministic shuffle, which is
 * exactly right for 364 days a year. This is for the other one: a feast day,
 * a launch day, a verse that belongs to a date for reasons no algorithm will
 * ever infer.
 *
 *   php artisan scrim:daily-set 2026-12-25 luke.2.11 --note="Christmas morning"
 *   php artisan scrim:daily-set 2026-12-25 "luke 2:11"
 *
 * Once set, scrim:daily-pick will never touch that date — it only fills days
 * that are empty. Overriding a day that already has a GENERATED verse is
 * fine and expected; the displaced verse simply returns to the pool and gets
 * a later day. Overriding a day whose verse has already been PLAYED is
 * refused unless --force, because its board and its snapshot are already
 * hanging off the old verse's challenge key.
 */
class SetDailyVerse extends Command
{
    protected $signature = 'scrim:daily-set
                            {date : YYYY-MM-DD, in the site clock}
                            {reference : book.chapter.verse or "book chapter:verse"}
                            {--note= : A line shown on the daily card that day}
                            {--force : Allow overwriting a past or current day}';

    protected $description = 'Hand-pick the daily scrimmage verse for a given date';

    public function handle(): int
    {
        // ---- The date -------------------------------------------------------
        try {
            $date = DailyVersePicker::normaliseDate($this->argument('date'));
        } catch (\Throwable $e) {
            $this->error('Could not read that date. Use YYYY-MM-DD.');
            return self::FAILURE;
        }

        // Not even by hand, not even with --force: a sabbath date takes no
        // verse. (The rest of the observance can be toggled off in config;
        // if you ever do that, this guard honours the toggle too, since
        // dateIsSabbath checks it.)
        if (\App\Support\Sabbath::dateIsSabbath($date)) {
            $this->error("{$date} is a sabbath \u{2014} there is no daily verse to set.");
            return self::FAILURE;
        }

        $today = DailyVersePicker::normaliseDate();
        $isPastOrToday = $date <= $today;

        if ($isPastOrToday && ! $this->option('force')) {
            $this->error("{$date} is today or already past.");
            $this->line('Its board may already exist under the current verse. Re-run with --force if you mean it.');
            return self::FAILURE;
        }

        // ---- The reference --------------------------------------------------
        $ref = $this->parseReference($this->argument('reference'));
        if (! $ref) {
            $this->error('Could not read that reference. Try luke.2.11 or "luke 2:11".');
            return self::FAILURE;
        }

        [$slug, $chapter, $verse] = $ref;

        $book = Book::findBySlug($slug);
        if (! $book) {
            $this->error("Unknown book: \"{$slug}\".");
            return self::FAILURE;
        }

        // ---- It has to actually exist, in some English edition --------------
        // Same eligibility the picker uses: identity is the verse, and at
        // least one edition of the language must carry it or the daily page
        // would render an empty scrim.
        $editionIds = Translation::where('language', 'en')->pluck('id');

        $row = Verse::whereIn('translation_id', $editionIds)
            ->where('book_id', $book->id)
            ->where('chapter', $chapter)
            ->where('verse_number', $verse)
            ->first();

        if (! $row) {
            $this->error("{$book->name} {$chapter}:{$verse} isn't in any English edition.");
            return self::FAILURE;
        }

        // ---- The no-repeat rule still applies -------------------------------
        // A verse gets ONE day in a century. Setting one that has already had
        // its turn would put a duplicate in the ledger and quietly break the
        // premise, so it's refused — but named, so you can go look at when.
        $already = DailyVerse::where('book_slug', $book->slug)
            ->where('chapter', $chapter)
            ->where('verse', $verse)
            ->where('date', '!=', $date)
            ->first();

        if ($already) {
            $this->error("{$book->name} {$chapter}:{$verse} was already the daily on {$already->date->toDateString()}.");
            $this->line('Every verse gets one day. Pick another.');
            return self::FAILURE;
        }

        // ---- Write ----------------------------------------------------------
        $existing = DailyVerse::where('date', $date)->first();

        DailyVerse::updateOrCreate(
            ['date' => $date],
            [
                'book_slug' => $book->slug,
                'chapter'   => $chapter,
                'verse'     => $verse,
                'source'    => 'curated',
                'note'      => $this->option('note') ?: null,
            ]
        );

        $chars = mb_strlen($row->text);

        if ($existing) {
            $this->line("Displaced: {$existing->label()} ({$existing->source}) — back into the pool.");
        }
        $this->info("{$date}  →  {$book->name} {$chapter}:{$verse}  ({$chars} chars)");

        if ($chars < 40) {
            $this->warn('That is a very short verse — it will wrap many times in 20 seconds.');
        } elseif ($chars > 400) {
            $this->warn('That is a long verse — most players will not finish one pass.');
        }

        return self::SUCCESS;
    }

    /**
     * "luke.2.11" | "luke 2:11" | "1-john.4.8" → [slug, chapter, verse].
     * Slugs may hold digits and hyphens (1-john, five-psalms-of-david), so
     * the space/colon form is split on its LAST space to keep the book whole.
     */
    private function parseReference(string $raw): ?array
    {
        $raw = strtolower(trim($raw));

        // Dotted form: the slug's own hyphens are safe, dots are separators.
        if (preg_match('/^([a-z0-9-]+)\.(\d+)\.(\d+)$/', $raw, $m)) {
            return [$m[1], (int) $m[2], (int) $m[3]];
        }

        // Spaced form: "1 john 4:8", "luke 2:11".
        if (preg_match('/^(.+)\s+(\d+):(\d+)$/', $raw, $m)) {
            return [str_replace(' ', '-', trim($m[1])), (int) $m[2], (int) $m[3]];
        }

        return null;
    }
}
