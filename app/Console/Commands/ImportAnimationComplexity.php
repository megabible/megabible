<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\ChapterComplexity;
use Illuminate\Console\Command;

/**
 * Import (upsert) hand-rated animation-complexity scores from a TSV.
 *
 *   php artisan mb:animation-complexity animations/complexity.tsv
 *   php artisan mb:animation-complexity animations/complexity.tsv --dry
 *
 * The path is absolute, or relative to storage/app — the same resolution
 * HEADed uses. The TSV is the editing surface: revise ratings in the file
 * whenever, re-run the import, done. Rows are upserted on (book, chapter);
 * chapters absent from the file are LEFT ALONE (this is an import, not a
 * sync), so a partial file — say, just the books rated this week — is fine.
 *
 * FORMAT — three tab-separated columns, CRLF or LF, one chapter per line:
 *
 *   book<TAB>chapter<TAB>rating
 *
 *   genesis	1	4
 *   revelation	12	10
 *   Gen	2	3          ← OSIS ids work too
 *   # comment lines and blank lines are skipped
 *
 * `book` is a slug (canon.php spelling) or an OSIS id. `chapter` is the
 * REAL 1-based DB chapter — for the Five Psalms of David write 1–5, not
 * 151–155 (reader_labels renumbering is display-only, as everywhere).
 * `rating` is an integer 1–10. A first line whose rating column isn't
 * numeric is treated as a header and skipped.
 *
 * Read with fgets + explode, NEVER fgetcsv — house rule (the 2 Baruch
 * incident): fgetcsv applies CSV quoting to TSVs and a double-quoted
 * field swallows the lines after it.
 */
class ImportAnimationComplexity extends Command
{
    protected $signature = 'mb:animation-complexity
                            {path : TSV file — absolute, or relative to storage/app}
                            {--dry : Parse and report without writing}';

    protected $description = 'Import hand-rated 1–10 chapter complexity scores from a TSV';

    public function handle(): int
    {
        // ---- Resolve the file (absolute, or relative to storage/app) ----
        $raw  = (string) $this->argument('path');
        $path = str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw)
            ? $raw
            : storage_path('app/' . ltrim($raw, '/'));

        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("Failed to open: {$path}");
            return self::FAILURE;
        }

        // Books resolve by slug first, then OSIS. Cached — a full-canon
        // file mentions each book dozens of times.
        $bookCache = [];
        $resolve = function (string $key) use (&$bookCache): ?Book {
            if (! array_key_exists($key, $bookCache)) {
                $bookCache[$key] = Book::findBySlug($key) ?? Book::findByOsis($key);
            }
            return $bookCache[$key];
        };

        $created = 0;
        $updated = 0;
        $errors  = [];   // "line N: reason"
        $n       = 0;

        while (($line = fgets($fh)) !== false) {
            $n++;
            $line = rtrim($line, "\r\n");

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = explode("\t", $line, 3);
            if (count($cols) < 3) {
                $errors[] = "line {$n}: expected 3 tab-separated columns";
                continue;
            }

            [$bookKey, $chapterRaw, $ratingRaw] = array_map('trim', $cols);

            // A non-numeric rating on the FIRST data line is a header row.
            if (! ctype_digit($ratingRaw)) {
                if ($created + $updated + count($errors) === 0) {
                    continue;   // header, skip silently
                }
                $errors[] = "line {$n}: rating '{$ratingRaw}' is not a number";
                continue;
            }

            $rating = (int) $ratingRaw;
            if ($rating < 1 || $rating > 10) {
                $errors[] = "line {$n}: rating {$rating} outside 1–10";
                continue;
            }

            if (! ctype_digit($chapterRaw) || (int) $chapterRaw < 1) {
                $errors[] = "line {$n}: chapter '{$chapterRaw}' is not a positive number";
                continue;
            }
            $chapter = (int) $chapterRaw;

            $book = $resolve($bookKey);
            if (! $book) {
                $errors[] = "line {$n}: unknown book '{$bookKey}' (not a slug or OSIS id)";
                continue;
            }

            // Soft sanity check only — chapter_count may lag reality, so a
            // mismatch warns rather than rejects.
            if ($book->chapter_count && $chapter > $book->chapter_count) {
                $this->warn("line {$n}: {$book->slug} chapter {$chapter} exceeds chapter_count ({$book->chapter_count}) — imported anyway. Remember: real DB numbers, not reader_labels renumbering.");
            }

            if ($dry) {
                $created++;   // counted as "would write"
                continue;
            }

            $row = ChapterComplexity::updateOrCreate(
                ['book_id' => $book->id, 'chapter' => $chapter],
                ['rating' => $rating]
            );
            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        fclose($fh);

        // ---- Report ------------------------------------------------------
        if ($dry) {
            $this->info("DRY RUN: {$created} row(s) would be written.");
        } else {
            $this->info("Complexity import: {$created} created, {$updated} updated.");
        }

        if ($errors !== []) {
            $this->warn(count($errors) . ' line(s) skipped:');
            foreach ($errors as $e) {
                $this->line("  {$e}");
            }
            // Valid lines (if any) were already written — the failure code
            // just makes the skips impossible to miss.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
