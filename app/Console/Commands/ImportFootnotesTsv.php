<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Footnote;
use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import footnotes from a standalone TSV into the `footnotes` table.
 *
 * The sibling of footnotes:import-usfm, for the case where the notes do NOT
 * live inside the verse-text source file. The WEB's notes ship inside its USFM,
 * so their verse alignment is guaranteed by construction. Everything else —
 * Lake's Apostolic Fathers, Scrivener's Cambridge Paragraph marginalia, and any
 * note you write yourself — has to be stated, verse by verse, in a file of its
 * own. This command reads that file.
 *
 * Like its sibling, it writes ONLY to `footnotes`. It never touches `verses` or
 * `headings`, so it is safe to run against a translation whose text is already
 * live, and safe to re-run: import is a per-book replace inside a transaction.
 *
 * FILE FORMAT — tab-separated, one note per line, header row required:
 *
 *   book_osis  chapter  verse  sequence  kind  source_key  anchor_text  text
 *
 * Only book_osis, chapter, verse and text are required. Columns may appear in
 * any order — the header row names them — and the four optional ones may be
 * omitted entirely:
 *
 *   sequence     order within the verse. Omit it and notes are numbered in the
 *                order they appear in the file, which is almost always what you
 *                want; supply it only to force an order the file does not have.
 *   kind         defaults to 'note'.
 *   source_key   attribution into config/footnote_sources.php. The --source-key
 *                option sets it for the whole file; a column value wins over it.
 *   anchor_text  the words the note glosses, shown as the popover's lead-in.
 *
 * Blank lines and lines beginning with # are skipped, so a file can carry
 * commentary and can park a note you are not ready to import by commenting it
 * out rather than deleting it.
 *
 * VALIDATION — the reason to prefer this over hand-written SQL:
 *   - Every book_osis must exist. An unknown one aborts before anything is
 *     written, rather than importing three books and failing on the fourth.
 *   - Every note's verse is checked against `verses` for THIS translation.
 *     Notes on verses the translation does not have are reported and skipped:
 *     there is nothing to render them on, and a note silently attached to a
 *     verse number that does not exist is invisible until a reader finds it.
 *   - Duplicate (book, chapter, verse, sequence) slots are caught in the file,
 *     before the unique index catches them in the database, so the error names
 *     the line to fix instead of the constraint that failed.
 *
 * A file listing no notes for a book does NOT clear that book: only books
 * actually present in the file are replaced. Use --flush to clear.
 *
 * Examples:
 *   php artisan footnotes:import-tsv LAKE storage/app/lake/lake-footnotes.tsv --source-key=lake --dry-run
 *   php artisan footnotes:import-tsv LAKE storage/app/lake/lake-footnotes.tsv --source-key=lake
 *   php artisan footnotes:import-tsv LAKE storage/app/lake/lake-footnotes.tsv --books=Did
 *   php artisan footnotes:import-tsv LAKE --flush --books=Herm
 */
class ImportFootnotesTsv extends Command
{
    protected $signature = 'footnotes:import-tsv
                            {abbreviation : Translation abbreviation, e.g. LAKE}
                            {path? : Path to the .tsv file. Optional when --flush.}
                            {--source-key= : Attribution key (config/footnote_sources.php) for rows that do not name their own}
                            {--books= : Comma-separated OSIS ids to scope to (e.g. Did,Barn)}
                            {--flush : Delete this translation\'s footnotes (in scope) and import nothing}
                            {--dry-run : Parse, validate, and report — write nothing}';

    protected $description = 'Import footnotes from a standalone TSV into the footnotes table (verse text untouched).';

    private const REQUIRED = ['book_osis', 'chapter', 'verse', 'text'];
    private const OPTIONAL = ['sequence', 'kind', 'source_key', 'anchor_text'];

    public function handle(): int
    {
        $abbreviation = strtoupper($this->argument('abbreviation'));
        $dryRun       = (bool) $this->option('dry-run');
        $sourceKey    = $this->option('source-key') ?: null;

        $translation = Translation::where('abbreviation', $abbreviation)->first();
        if (! $translation) {
            $this->error("Unknown translation '{$abbreviation}'. Import its verse text first.");
            return self::FAILURE;
        }

        $booksByOsis = Book::all()->keyBy('osis_id');

        // ---- Optional book scope --------------------------------------------
        $bookScope    = null;
        $scopeBookIds = null;
        if ($raw = $this->option('books')) {
            $bookScope    = array_values(array_filter(array_map('trim', explode(',', $raw))));
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

        // ---- FLUSH path -------------------------------------------------------
        if ($this->option('flush')) {
            $q = Footnote::where('translation_id', $translation->id);
            if ($scopeBookIds !== null) {
                $q->whereIn('book_id', $scopeBookIds);
            }
            $count = (clone $q)->count();
            $where = $scopeBookIds !== null ? implode(',', $bookScope) : 'ALL books';

            if ($dryRun) {
                $this->warn("[dry-run] Would delete {$count} footnotes from {$abbreviation} ({$where}).");
                return self::SUCCESS;
            }
            $q->delete();
            $this->info("Flushed {$count} footnotes from {$abbreviation} ({$where}).");
            return self::SUCCESS;
        }

        // ---- IMPORT path ------------------------------------------------------
        $path = $this->argument('path');
        if (! $path) {
            $this->error('A TSV path is required unless you pass --flush.');
            return self::FAILURE;
        }
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if (! $sourceKey) {
            $this->warn('No --source-key given: rows without their own source_key column will');
            $this->warn('import as NULL and the colophon will credit the translation itself.');
        } elseif (! array_key_exists($sourceKey, config('footnote_sources', []))) {
            $this->warn("source-key '{$sourceKey}' is not in config/footnote_sources.php —");
            $this->warn('the colophon will show the raw key until you add an entry.');
        }

        $parsed = $this->parseFile($path, $booksByOsis, $bookScope);
        if ($parsed === null) {
            return self::FAILURE;      // parseFile has already explained why
        }
        [$byBook, $skippedScope] = $parsed;

        if (empty($byBook)) {
            $this->warn('No footnote rows in scope — nothing to do.');
            return self::SUCCESS;
        }

        $totalNotes   = 0;
        $totalOrphans = 0;
        $touchedBooks = 0;

        foreach ($byBook as $osis => $notes) {
            $book = $booksByOsis->get($osis);

            // Which verses this translation actually has in this book. A note
            // whose verse is missing has nothing to render on.
            $existing = [];
            $verseRows = DB::table('verses')
                ->where('translation_id', $translation->id)
                ->where('book_id', $book->id)
                ->get(['chapter', 'verse_number']);
            foreach ($verseRows as $v) {
                $existing["{$v->chapter}.{$v->verse_number}"] = true;
            }

            if (empty($existing)) {
                $this->warn("  {$osis} — {$abbreviation} has no verses in this book; all "
                    . count($notes) . ' note(s) skipped.');
                $totalOrphans += count($notes);
                continue;
            }

            $rows    = [];
            $orphans = 0;
            $now     = now();

            foreach ($notes as $n) {
                if (! isset($existing["{$n['chapter']}.{$n['verse']}"])) {
                    $this->warn("  line {$n['line']}: {$osis} {$n['chapter']}:{$n['verse']} — skipped, verse not in {$abbreviation}: \""
                        . mb_substr($n['text'], 0, 60) . '…"');
                    $orphans++;
                    continue;
                }
                $rows[] = [
                    'translation_id' => $translation->id,
                    'book_id'        => $book->id,
                    'chapter'        => $n['chapter'],
                    'verse_number'   => $n['verse'],
                    'sequence'       => $n['sequence'],
                    'kind'           => $n['kind'],
                    'source_key'     => $n['source_key'] ?: $sourceKey,
                    'anchor_text'    => $n['anchor_text'],
                    'text'           => $n['text'],
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            $totalOrphans += $orphans;

            if ($dryRun) {
                $have = DB::table('footnotes')
                    ->where('translation_id', $translation->id)
                    ->where('book_id', $book->id)->count();
                $this->line(sprintf('  [dry-run] %-6s %-20s %4d notes in (replacing %d existing, %d orphaned)',
                    $osis, $book->name, count($rows), $have, $orphans));
                $totalNotes += count($rows);
                $touchedBooks++;
                continue;
            }

            try {
                DB::transaction(function () use ($translation, $book, $rows) {
                    Footnote::where('translation_id', $translation->id)
                        ->where('book_id', $book->id)
                        ->delete();
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        DB::table('footnotes')->insert($chunk);
                    }
                });
            } catch (\Throwable $e) {
                $this->error("  FAILED {$osis} {$book->name} — left unchanged: " . $e->getMessage());
                continue;
            }

            $totalNotes += count($rows);
            $touchedBooks++;
            $this->line(sprintf('  %-6s %-20s %4d notes', $osis, $book->name, count($rows)));
        }

        $this->line('');
        $verb = $dryRun ? '[dry-run] Would import' : 'Imported';
        $this->info("{$verb} {$totalNotes} footnotes across {$touchedBooks} book(s) into {$abbreviation}"
            . ($sourceKey ? " (source: {$sourceKey})." : '.'));
        if ($totalOrphans)   $this->warn("{$totalOrphans} note(s) skipped: their verse is not in this translation.");
        if ($skippedScope)   $this->line("{$skippedScope} row(s) ignored: outside --books scope.");

        return self::SUCCESS;
    }

    // ── The parser ───────────────────────────────────────────────────────────

    /**
     * Read the TSV into [notesGroupedByOsis, skippedByScope], or null on a
     * fatal problem (already reported).
     *
     * Read with fgets()+explode rather than fgetcsv(): fgetcsv treats a double
     * quote as a field enclosure whatever the delimiter, so a note opening with
     * a quotation mark — which many of these do, since they gloss quoted words —
     * silently swallows the following lines into one field.
     */
    private function parseFile(string $path, $booksByOsis, ?array $bookScope): ?array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->error("Could not open: {$path}");
            return null;
        }

        $cols = null;
        $byBook = [];
        $seen = [];                 // "osis.ch.v.seq" => line, for duplicate detection
        $autoSeq = [];              // "osis.ch.v" => running counter
        $lineNo = 0;
        $skippedScope = 0;
        $errors = [];

        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = rtrim($line, "\r\n");
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $parts = explode("\t", $line);

            if ($cols === null) {
                $cols = [];
                foreach ($parts as $i => $name) {
                    $cols[trim(strtolower($name))] = $i;
                }
                foreach (self::REQUIRED as $need) {
                    if (! array_key_exists($need, $cols)) {
                        $this->error("Header row is missing the '{$need}' column.");
                        $this->line('Expected columns: ' . implode(', ', array_merge(self::REQUIRED, self::OPTIONAL)));
                        fclose($fh);
                        return null;
                    }
                }
                continue;
            }

            $get = function (string $name) use ($parts, $cols): string {
                $i = $cols[$name] ?? null;
                return $i === null ? '' : trim($parts[$i] ?? '');
            };

            $osis = $get('book_osis');
            $book = $booksByOsis->get($osis);
            if (! $book) {
                $errors[] = "line {$lineNo}: unknown book_osis '{$osis}'";
                continue;
            }
            if ($bookScope !== null && ! in_array($osis, $bookScope, true)) {
                $skippedScope++;
                continue;
            }

            $chapter = (int) $get('chapter');
            $verse   = (int) $get('verse');
            $text    = $get('text');

            if ($chapter < 1 || $verse < 1) {
                $errors[] = "line {$lineNo}: chapter/verse must be positive, got {$chapter}:{$verse}";
                continue;
            }
            if ($text === '') {
                $errors[] = "line {$lineNo}: {$osis} {$chapter}:{$verse} has empty text";
                continue;
            }

            $slotKey = "{$osis}.{$chapter}.{$verse}";
            $sequence = $get('sequence') !== '' ? (int) $get('sequence') : null;
            if ($sequence === null) {
                $autoSeq[$slotKey] = ($autoSeq[$slotKey] ?? 0) + 1;
                $sequence = $autoSeq[$slotKey];
            }

            $dupe = "{$slotKey}.{$sequence}";
            if (isset($seen[$dupe])) {
                $errors[] = "line {$lineNo}: {$osis} {$chapter}:{$verse} sequence {$sequence} "
                    . "duplicates line {$seen[$dupe]}";
                continue;
            }
            $seen[$dupe] = $lineNo;

            $byBook[$osis][] = [
                'line'        => $lineNo,
                'chapter'     => $chapter,
                'verse'       => $verse,
                'sequence'    => $sequence,
                'kind'        => $get('kind') ?: 'note',
                'source_key'  => $get('source_key') ?: null,
                'anchor_text' => mb_substr($get('anchor_text'), 0, 255) ?: null,
                'text'        => $text,
            ];
        }
        fclose($fh);

        if ($cols === null) {
            $this->error('File is empty — no header row found.');
            return null;
        }

        if ($errors) {
            $this->error(count($errors) . ' problem(s) in the file. Nothing was imported:');
            foreach (array_slice($errors, 0, 25) as $e) {
                $this->line('  ' . $e);
            }
            if (count($errors) > 25) {
                $this->line('  … and ' . (count($errors) - 25) . ' more');
            }
            return null;
        }

        // Notes render in verse+sequence order; sorting here means the file
        // itself does not have to be sorted for the output to read correctly.
        foreach ($byBook as $osis => &$notes) {
            usort($notes, fn ($a, $b) => [$a['chapter'], $a['verse'], $a['sequence']]
                                     <=> [$b['chapter'], $b['verse'], $b['sequence']]);
        }

        return [$byBook, $skippedScope];
    }
}
