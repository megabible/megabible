<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\SharedHeading;
use App\Support\HeadingTsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import curated section headings from a TSV into `shared_headings`.
 *
 * All reading and validation lives in App\Support\HeadingTsv, which HEADed
 * (the local heading editor) shares — so the file the editor says is clean
 * is the file this command accepts. This class only decides what to do with
 * the result: report, flush, or replace-in-scope.
 *
 * TSV columns (tab-separated, matched by name from the header row). Required:
 *     set_key  book_osis  chapter  before_verse  kind  level  text
 * Optional:
 *     source_key   attribution key (see config/heading_sources.php). When
 *                  absent or blank on a row, --source-key is used; when that's
 *                  absent too, the row's source_key is NULL and the colophon
 *                  falls back to crediting the set itself.
 *
 * The file is the source of truth. Importing REPLACES every heading in the
 * scope it touches (a set + a set of books), so editing/deleting rows and
 * re-importing "just works". See --books / --flush / --dry-run below.
 *
 * Examples:
 *   php artisan headings:import headings/en-standard.tsv --books=1Macc --source-key=megabible
 *   php artisan headings:import headings/en-standard.tsv --books=Matt,Mark
 *   php artisan headings:import headings/en-standard.tsv                 # whole file
 *   php artisan headings:import x --set=en-standard --books=1Macc --flush
 *   php artisan headings:import x --set=en-standard --flush              # nuke the set
 */
class ImportHeadings extends Command
{
    protected $signature = 'headings:import
                            {file? : TSV path (relative to storage/app, or absolute). Optional when --flush.}
                            {--set=en-standard : The heading set_key to import/clear}
                            {--source-key= : Default attribution key for rows without their own source_key}
                            {--books= : Comma-separated OSIS ids to scope to (e.g. Matt,Mark)}
                            {--flush : Delete the scope and import nothing}
                            {--dry-run : Report only; make no changes}';

    protected $description = 'Import shared section headings from a TSV into the shared_headings table.';

    public function handle(): int
    {
        $set           = $this->option('set');
        $flush         = (bool) $this->option('flush');
        $dryRun        = (bool) $this->option('dry-run');
        $defaultSource = $this->option('source-key') ?: null;

        $bookScope = null;
        if ($raw = $this->option('books')) {
            $bookScope = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        $booksByOsis = Book::all()->keyBy('osis_id');

        $scopeBookIds = null;
        if ($bookScope !== null) {
            $scopeBookIds = [];
            foreach ($bookScope as $osis) {
                $book = $booksByOsis->get($osis);
                if (! $book) {
                    $this->error("Unknown book OSIS in --books: '{$osis}'. Aborting.");
                    return self::FAILURE;
                }
                $scopeBookIds[] = $book->id;
            }
        }

        // ---- FLUSH path: clear the scope, import nothing. --------------------
        if ($flush) {
            $q = SharedHeading::where('set_key', $set);
            if ($scopeBookIds !== null) {
                $q->whereIn('book_id', $scopeBookIds);
            }
            $count = (clone $q)->count();
            $where = $scopeBookIds !== null ? implode(',', $bookScope) : 'ALL books';

            if ($dryRun) {
                $this->warn("[dry-run] Would delete {$count} headings from set '{$set}' ({$where}).");
                return self::SUCCESS;
            }
            $q->delete();
            $this->info("Flushed {$count} headings from set '{$set}' ({$where}).");
            return self::SUCCESS;
        }

        // ---- IMPORT path: a file is required. --------------------------------
        $file = $this->argument('file');
        if (! $file) {
            $this->error('A TSV file is required unless you pass --flush.');
            return self::FAILURE;
        }

        $path = HeadingTsv::resolvePath($file);
        if (! $path) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        // ---- Read + validate via the shared parser. --------------------------
        try {
            $tsv = HeadingTsv::read($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $v = HeadingTsv::validate($tsv, $set, $bookScope, $defaultSource, $booksByOsis);

        foreach ($v['warnings'] as $w) {
            $this->warn($w);
        }

        $rows           = $v['rows'];
        $touchedBookIds = $scopeBookIds ?? $v['touched_book_ids'];

        $this->line('');
        $this->info("Set            : {$set}");
        $this->info('Books in scope : ' . ($bookScope ? implode(', ', $bookScope) : count($touchedBookIds) . ' (from file)'));
        $this->info('Rows to insert : ' . count($rows));
        if ($defaultSource)     $this->line("  (default source-key: {$defaultSource})");
        if ($v['skipped_set'])  $this->line("  (skipped {$v['skipped_set']} rows belonging to other sets)");
        if ($v['skipped_book']) $this->line("  (skipped {$v['skipped_book']} rows with unknown books)");
        if ($v['skipped_kind']) $this->line("  (skipped {$v['skipped_kind']} rows with unknown kinds)");
        if ($v['skipped_bad'])  $this->line("  (skipped {$v['skipped_bad']} rows with empty text or bad numbers)");
        if ($v['dupes']) {
            $this->line('  (collapsed ' . count($v['dupes']) . ' duplicate rows)');
            foreach ($v['dupes'] as $d) {
                $this->line("    line {$d['line']} duplicates line {$d['first_line']}  [{$d['key']}]");
            }
        }

        if (empty($touchedBookIds)) {
            $this->warn('Nothing in scope. No changes made.');
            return self::SUCCESS;
        }

        $toClear = SharedHeading::where('set_key', $set)
            ->whereIn('book_id', $touchedBookIds)->count();

        if ($dryRun) {
            $this->warn("[dry-run] Would delete {$toClear} existing headings in scope, then insert " . count($rows) . '.');
            return self::SUCCESS;
        }

        $now    = now();
        $insert = array_map(
            fn (array $r) => $r['data'] + ['created_at' => $now, 'updated_at' => $now],
            $rows
        );

        DB::transaction(function () use ($set, $touchedBookIds, $insert) {
            SharedHeading::where('set_key', $set)
                ->whereIn('book_id', $touchedBookIds)
                ->delete();

            foreach (array_chunk($insert, 1000) as $chunk) {
                DB::table('shared_headings')->insert($chunk);
            }
        });

        $this->info("Done. Replaced {$toClear} headings with " . count($insert) . " in set '{$set}'.");
        return self::SUCCESS;
    }
}