<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The one reader/validator/serializer for heading TSV files.
 *
 * Shared so the importer, HEADed's read path, and HEADed's WRITE path can
 * never disagree about the file's shape:
 *   - headings:import (App\Console\Commands\ImportHeadings)
 *   - HEADed (App\Http\Controllers\HeadedController)
 *
 * read()      → every PHYSICAL line, typed, plus the file's EOL and BOM so a
 *               round-trip preserves them byte-for-byte.
 * validate()  → the importer's view: rows that insert, rows skipped and why,
 *               duplicates — all WITH line numbers.
 * serialize() + buildDataLine() + canonInsertIndex()  → the write path.
 */
class HeadingTsv
{
    /** Heading kinds the chapter renderer knows how to draw. */
    public const KNOWN_KINDS = ['s', 'ms', 'mr', 'r', 'sr', 'sp', 'd'];

    /** Columns that must appear in the header. */
    public const REQUIRED_COLUMNS = ['set_key', 'book_osis', 'chapter', 'before_verse', 'kind', 'level', 'text'];

    /** Sort order of kinds that share one (book, chapter, before_verse). */
    public const KIND_RANK = ['ms' => 0, 'mr' => 1, 's' => 2, 'sr' => 3, 'r' => 4, 'sp' => 5, 'd' => 6];

    // ------------------------------------------------------------------
    // Step 1: read the file into typed lines (+ EOL / BOM for round-trips).
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   path: string, header: string[], col: array<string,int>,
     *   eol: string, bom: bool,
     *   lines: array<int, array{n:int, raw:string, type:string, fields:string[]}>
     * }
     * @throws RuntimeException
     */
    public static function read(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        $lines  = [];
        $header = null;
        $col    = [];
        $n      = 0;
        $eol    = "\r\n";      // sensible default; overwritten from the first line
        $bom    = false;
        $eolSet = false;

        while (($rawLine = fgets($handle)) !== false) {
            $n++;

            if (! $eolSet) {
                $eol = str_ends_with($rawLine, "\r\n") ? "\r\n"
                     : (str_ends_with($rawLine, "\n") ? "\n" : "\r\n");
                $eolSet = true;
            }

            $raw = rtrim($rawLine, "\r\n");

            if ($n === 1 && str_starts_with($raw, "\xEF\xBB\xBF")) {
                $bom = true;
                $raw = substr($raw, 3);
            }

            if ($header === null) {
                if (trim($raw) === '') {
                    $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'blank', 'fields' => []];
                    continue;
                }
                if (str_starts_with(ltrim($raw), '#')) {
                    $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'comment', 'fields' => []];
                    continue;
                }
                $header = array_map('trim', explode("\t", $raw));
                $col    = array_flip($header);

                $missing = array_diff(self::REQUIRED_COLUMNS, $header);
                if (! empty($missing)) {
                    fclose($handle);
                    throw new RuntimeException(
                        'Header is missing required column(s): ' . implode(', ', $missing)
                        . ' — got: ' . implode(', ', $header)
                    );
                }
                $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'header', 'fields' => $header];
                continue;
            }

            if (trim($raw) === '') {
                $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'blank', 'fields' => []];
                continue;
            }
            if (str_starts_with(ltrim($raw), '#')) {
                $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'comment', 'fields' => []];
                continue;
            }

            $lines[] = ['n' => $n, 'raw' => $raw, 'type' => 'data', 'fields' => explode("\t", $raw)];
        }
        fclose($handle);

        if ($header === null) {
            throw new RuntimeException("File has no header row: {$path}");
        }

        return ['path' => $path, 'header' => $header, 'col' => $col, 'eol' => $eol, 'bom' => $bom, 'lines' => $lines];
    }

    /** Pull one named field from a line, trimmed, or '' if absent. */
    public static function field(array $file, array $line, string $name): string
    {
        $i = $file['col'][$name] ?? null;
        return ($i !== null && isset($line['fields'][$i])) ? trim((string) $line['fields'][$i]) : '';
    }

    // ------------------------------------------------------------------
    // Step 2: validate against a set / book scope, exactly as the importer.
    // ------------------------------------------------------------------

    public static function validate(
        array $file,
        string $set,
        ?array $bookScope,
        ?string $defaultSource,
        Collection $booksByOsis,
    ): array {
        $rows     = [];
        $seen     = [];
        $dupes    = [];
        $warnings = [];
        $skippedSet = $skippedBook = $skippedKind = $skippedBad = 0;
        $hasSourceCol = isset($file['col']['source_key']);

        foreach ($file['lines'] as $line) {
            if ($line['type'] !== 'data') {
                continue;
            }
            $n = $line['n'];

            $rowSet = self::field($file, $line, 'set_key');
            if ($rowSet !== $set) { $skippedSet++; continue; }

            $osis = self::field($file, $line, 'book_osis');
            if ($bookScope !== null && ! in_array($osis, $bookScope, true)) {
                continue;
            }

            $book = $booksByOsis->get($osis);
            if (! $book) {
                $warnings[] = "Line {$n}: unknown book OSIS '{$osis}', skipping.";
                $skippedBook++;
                continue;
            }

            $kind = self::field($file, $line, 'kind');
            if (! in_array($kind, self::KNOWN_KINDS, true)) {
                $warnings[] = "Line {$n}: unknown kind '{$kind}', skipping.";
                $skippedKind++;
                continue;
            }

            $chapter     = (int) self::field($file, $line, 'chapter');
            $beforeVerse = (int) self::field($file, $line, 'before_verse');
            $level       = max(1, (int) self::field($file, $line, 'level'));
            $text        = self::field($file, $line, 'text');
            if ($text === '' || $chapter < 1 || $beforeVerse < 1) {
                $warnings[] = "Line {$n}: empty text or bad numbers, skipping.";
                $skippedBad++;
                continue;
            }

            $rowSource  = $hasSourceCol ? self::field($file, $line, 'source_key') : '';
            $sourceKey  = $rowSource !== '' ? $rowSource : $defaultSource;

            $key = self::dedupeKey($book->id, $chapter, $beforeVerse, $kind, $level);
            if (isset($seen[$key])) {
                $dupes[] = ['line' => $n, 'first_line' => $seen[$key], 'key' => "{$osis} {$chapter}:{$beforeVerse} {$kind}/{$level}"];
                continue;
            }
            $seen[$key] = $n;

            $rows[] = [
                'line' => $n,
                'osis' => $osis,
                'data' => [
                    'set_key'      => $set,
                    'source_key'   => $sourceKey,
                    'book_id'      => $book->id,
                    'chapter'      => $chapter,
                    'before_verse' => $beforeVerse,
                    'kind'         => $kind,
                    'level'        => $level,
                    'text'         => $text,
                ],
            ];
        }

        $touched = array_values(array_unique(array_map(fn ($r) => $r['data']['book_id'], $rows)));

        return [
            'rows'             => $rows,
            'skipped_set'      => $skippedSet,
            'skipped_book'     => $skippedBook,
            'skipped_kind'     => $skippedKind,
            'skipped_bad'      => $skippedBad,
            'dupes'            => $dupes,
            'warnings'         => $warnings,
            'touched_book_ids' => $touched,
        ];
    }

    // ------------------------------------------------------------------
    // Write-path helpers.
    // ------------------------------------------------------------------

    /**
     * Build one raw TSV data line, fields in the file's own column order.
     * $values is keyed by canonical column name. $preserve (an original line's
     * fields array) carries through any columns beyond the known set on edit;
     * null (create) leaves unknown columns empty.
     */
    public static function buildDataLine(array $file, array $values, ?array $preserve = null): string
    {
        $width  = count($file['header']);
        $fields = array_fill(0, $width, '');

        if ($preserve) {
            foreach ($preserve as $i => $val) {
                if ($i < $width) {
                    $fields[$i] = (string) $val;
                }
            }
        }
        foreach ($values as $name => $val) {
            $idx = $file['col'][$name] ?? null;
            if ($idx !== null) {
                $fields[$idx] = (string) $val;
            }
        }
        return implode("\t", $fields);
    }

    /**
     * Index in $lines where a new/edited row should sit — placed WITHIN its own
     * book's block, never by global canon order. The file's physical book order
     * is the editor's own (it deliberately differs from canon.php), so we only
     * keep chapters/verses/kinds sorted inside the book. That drops a new cross-
     * reference right under its section heading instead of teleporting it to a
     * canon slot elsewhere in the file.
     *
     * $withinKey is [chapter, before_verse, kindRank, level] — no book position,
     * because we only ever compare rows of the SAME book. Falls back to the end
     * of the file when the book has no rows yet (a brand-new book).
     */
    public static function bookBlockInsertIndex(array $file, array $lines, string $set, string $osis, array $withinKey): int
    {
        $firstInBook  = null;
        $lastInBook   = null;
        $insertBefore = null;

        foreach ($lines as $i => $ln) {
            if (($ln['type'] ?? '') !== 'data') {
                continue;
            }
            if (self::field($file, $ln, 'set_key') !== $set) {
                continue;
            }
            if (self::field($file, $ln, 'book_osis') !== $osis) {
                continue;
            }

            if ($firstInBook === null) {
                $firstInBook = $i;
            }
            $lastInBook = $i;

            $key = [
                (int) self::field($file, $ln, 'chapter'),
                (int) self::field($file, $ln, 'before_verse'),
                self::kindRank(self::field($file, $ln, 'kind')),
                (int) self::field($file, $ln, 'level'),
            ];
            if ($insertBefore === null && $key > $withinKey) {
                $insertBefore = $i;
            }
        }

        if ($firstInBook === null) {
            return count($lines);           // book not in file yet: append
        }
        return $insertBefore ?? ($lastInBook + 1);
    }

    /** Reassemble the whole file from its line list, preserving EOL and BOM. */
    public static function serialize(array $file): string
    {
        $eol  = $file['eol'] ?? "\r\n";
        $body = implode($eol, array_map(fn ($ln) => $ln['raw'], $file['lines'])) . $eol;
        return ($file['bom'] ?? false) ? "\xEF\xBB\xBF" . $body : $body;
    }

    public static function dedupeKey(int $bookId, int $chapter, int $beforeVerse, string $kind, int $level): string
    {
        return "{$bookId}|{$chapter}|{$beforeVerse}|{$kind}|{$level}";
    }

    public static function kindRank(string $kind): int
    {
        return self::KIND_RANK[$kind] ?? 99;
    }

    public static function resolvePath(string $file): ?string
    {
        if (is_file($file)) {
            return $file;
        }
        $underApp = storage_path('app/' . ltrim($file, '/'));
        if (is_file($underApp)) {
            return $underApp;
        }
        try {
            $viaDisk = Storage::path($file);
            if (is_file($viaDisk)) {
                return $viaDisk;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return null;
    }
}