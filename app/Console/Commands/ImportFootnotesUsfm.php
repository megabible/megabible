<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Footnote;
use App\Models\Translation;
use App\Support\UsfmBookMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Extract \f…\f* footnotes from USFM files into the `footnotes` table.
 *
 * This importer reads ONLY footnotes. It never writes to `verses` or
 * `headings`, so it is safe to run against a live translation — the hosted
 * verse text is untouched no matter what the input file contains. That is the
 * whole point of its existence as a separate command: footnotes can come from
 * a file imported months after the verse text (the WEB case), and the two
 * never need to be re-imported together.
 *
 * What it captures per note:
 *   chapter / verse   the verse the note is attached to (from \c and \v)
 *   sequence          order within the verse (Gen 1:20 KJV carries four)
 *   anchor_text       the last few words of verse text immediately before the
 *                     \f marker — the word(s) the note glosses. Shown as the
 *                     bold lead-in in the note popover.
 *   text              the cleaned \ft content. Inline markers stripped;
 *                     curly quotes and Hebrew (\+wh …\+wh*) preserved.
 *
 * Validation (VERIFY over guesses):
 *   - Each note's \fr self-reference (e.g. "1:4") is checked against the
 *     verse it is actually attached to. Mismatches are WARNED but imported —
 *     the attachment position in the file is authoritative.
 *   - Each note's verse is checked against the `verses` table for this
 *     translation. Notes on verses the translation doesn't have are WARNED
 *     and SKIPPED (nothing to render them on), and counted in the summary.
 *   - Notes found outside any verse (e.g. on a \d psalm title) are counted
 *     and reported, never guessed onto a verse.
 *
 * Import is per-book replace inside a transaction, so re-running on the same
 * file "just works", exactly like import:usfm and headings:import.
 *
 * Examples:
 *   php artisan footnotes:import-usfm WEB storage/usfm/web --source-key=web-notes --dry-run
 *   php artisan footnotes:import-usfm WEB storage/usfm/web --source-key=web-notes
 *   php artisan footnotes:import-usfm WEB path/02-GENeng-web.usfm --source-key=web-notes --books=Gen
 *   php artisan footnotes:import-usfm WEB --flush
 *   php artisan footnotes:import-usfm WEB --flush --books=Gen
 */
class ImportFootnotesUsfm extends Command
{
    protected $signature = 'footnotes:import-usfm
                            {abbreviation : Translation abbreviation, e.g. WEB}
                            {path? : A .usfm file OR a directory of .usfm files. Optional when --flush.}
                            {--source-key= : Attribution key (config/footnote_sources.php) stamped on every imported note}
                            {--books= : Comma-separated OSIS ids to scope to (e.g. Gen,Exod)}
                            {--flush : Delete this translation\'s footnotes (in scope) and import nothing}
                            {--dry-run : Parse, validate, and report — write nothing}';

    protected $description = 'Extract \f footnotes from USFM into the footnotes table (verse text untouched).';

    /** Line-initial markers whose content is VERSE TEXT once a verse is open
     *  (prose paragraphs + poetry lines). Footnotes on these lines belong to
     *  the currently open verse. Everything else (headings, \d titles, intro
     *  matter) closes nothing but contributes nothing either. */
    private const VERSE_TEXT_MARKERS = [
        'p','m','po','pr','cls','pmo','pm','pmc','pmr','pi','pi1','pi2','pi3',
        'mi','nb','pc','ph','ph1','ph2','ph3','lit',
        'li','li1','li2','li3','li4','lim','lim1','lim2','lh','lf',
        'q','q1','q2','q3','q4','qr','qc','qm','qm1','qm2','qm3','qd',
    ];

    /** How many words of preceding verse text to capture as the anchor. */
    private const ANCHOR_WORDS = 4;

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
        $bookScope    = null;   // list of OSIS ids
        $scopeBookIds = null;   // list of book ids
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

        // ---- FLUSH path: clear the scope, import nothing. --------------------
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
            $this->error('A USFM path is required unless you pass --flush.');
            return self::FAILURE;
        }

        $files = $this->resolveFiles($path);
        if (empty($files)) {
            $this->error("No .usfm/.sfm files found at: {$path}");
            return self::FAILURE;
        }

        if (! $sourceKey) {
            $this->warn('No --source-key given: notes will be imported with source_key NULL');
            $this->warn('and the colophon will fall back to crediting the translation itself.');
        } elseif (! array_key_exists($sourceKey, config('footnote_sources', []))) {
            $this->warn("source-key '{$sourceKey}' is not in config/footnote_sources.php —");
            $this->warn('the colophon will show the raw key until you add an entry.');
        }

        $totalNotes    = 0;
        $totalOrphans  = 0;   // notes on verses this translation doesn't have (skipped)
        $totalRefMism  = 0;   // \fr disagrees with attachment point (imported anyway)
        $totalHomeless = 0;   // notes found outside any \v context (skipped)
        $touchedBooks  = 0;

        foreach ($files as $file) {
            $raw  = file_get_contents($file);
            $code = $this->bookCode($raw);

            if (in_array($code, UsfmBookMap::NON_BOOKS, true)) {
                continue;
            }

            $osis = UsfmBookMap::USFM_TO_OSIS[$code] ?? null;
            $book = $osis ? $booksByOsis->get($osis) : null;

            if (! $book) {
                $this->warn('  skip ' . basename($file) . " — book code '{$code}' not mapped/seeded");
                continue;
            }
            if ($bookScope !== null && ! in_array($osis, $bookScope, true)) {
                continue;
            }

            [$notes, $homeless] = $this->parseBook($raw);
            $totalHomeless += $homeless;

            if (empty($notes) && $homeless === 0) {
                // Nothing to do AND nothing existing gets cleared — a file with
                // no footnotes should not wipe notes imported from elsewhere.
                continue;
            }

            // ---- Validate against this translation's actual verses. ----------
            // "chapter.verse" => true, for every verse this translation has in
            // this book. Notes pointing anywhere else are orphans.
            $existing = [];
            $verseRows = DB::table('verses')
                ->where('translation_id', $translation->id)
                ->where('book_id', $book->id)
                ->get(['chapter', 'verse_number']);
            foreach ($verseRows as $v) {
                $existing["{$v->chapter}.{$v->verse_number}"] = true;
            }

            $rows     = [];
            $orphans  = 0;
            $mismatch = 0;
            $now      = now();

            foreach ($notes as $n) {
                if (! isset($existing["{$n['chapter']}.{$n['verse']}"])) {
                    $this->warn("  {$osis} {$n['chapter']}:{$n['verse']} — note skipped, verse not in {$abbreviation}: \"" . mb_substr($n['text'], 0, 60) . '…"');
                    $orphans++;
                    continue;
                }
                if ($n['fr'] !== null && $n['fr'] !== "{$n['chapter']}.{$n['verse']}") {
                    $this->warn("  {$osis} {$n['chapter']}:{$n['verse']} — \\fr says '{$n['fr_raw']}' (imported at attachment point)");
                    $mismatch++;
                }
                $rows[] = [
                    'translation_id' => $translation->id,
                    'book_id'        => $book->id,
                    'chapter'        => $n['chapter'],
                    'verse_number'   => $n['verse'],
                    'sequence'       => $n['sequence'],
                    'kind'           => 'note',
                    'source_key'     => $sourceKey,
                    'anchor_text'    => $n['anchor'],
                    'text'           => $n['text'],
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            $totalOrphans += $orphans;
            $totalRefMism += $mismatch;

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] %-4s %-16s %4d notes (%d orphaned, %d \\fr mismatches)',
                    $code, $book->name, count($rows), $orphans, $mismatch));
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
                $this->error("  FAILED {$code} {$book->name} — left unchanged: " . $e->getMessage());
                continue;
            }

            $totalNotes += count($rows);
            $touchedBooks++;
            $this->line(sprintf('  %-4s %-16s %4d notes', $code, $book->name, count($rows)));
        }

        $this->line('');
        $verb = $dryRun ? '[dry-run] Would import' : 'Imported';
        $this->info("{$verb} {$totalNotes} footnotes across {$touchedBooks} book(s) into {$abbreviation}"
            . ($sourceKey ? " (source: {$sourceKey})." : '.'));
        if ($totalOrphans)  $this->warn("{$totalOrphans} note(s) skipped: their verse is not in this translation.");
        if ($totalRefMism)  $this->warn("{$totalRefMism} note(s) had a \\fr self-reference that disagreed with their attachment point.");
        if ($totalHomeless) $this->warn("{$totalHomeless} note(s) skipped: found outside any verse (e.g. on a \\d title line).");

        return self::SUCCESS;
    }

    // ── The parser ───────────────────────────────────────────────────────────

    /**
     * Walk one book's USFM and pull out every footnote with its verse context.
     *
     * Returns [notes, homelessCount] where each note is:
     *   ['chapter'=>int, 'verse'=>int, 'sequence'=>int,
     *    'anchor'=>?string, 'text'=>string, 'fr'=>?string, 'fr_raw'=>?string]
     */
    private function parseBook(string $raw): array
    {
        $chapter  = null;
        $verse    = null;
        $buffer   = '';     // raw USFM of the CURRENT verse, accumulated across lines
        $notes    = [];
        $homeless = 0;

        $flush = function () use (&$buffer, &$notes, &$chapter, &$verse) {
            if ($verse === null || $chapter === null || $buffer === '') {
                $buffer = '';
                return;
            }
            $seq = 0;
            // Every \f…\f* in the verse, WITH byte offsets so the anchor can be
            // cut from the text preceding each note.
            if (preg_match_all('/\\\\f\s.*?\\\\f\*/us', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$noteRaw, $offset]) {
                    $seq++;
                    [$fr, $frRaw, $text] = $this->parseNote($noteRaw);
                    if ($text === '') {
                        continue;   // empty \ft — nothing worth storing
                    }
                    $notes[] = [
                        'chapter'  => $chapter,
                        'verse'    => $verse,
                        'sequence' => $seq,
                        'anchor'   => $this->anchorBefore($buffer, $offset),
                        'text'     => $text,
                        'fr'       => $fr,
                        'fr_raw'   => $frRaw,
                    ];
                }
            }
            $buffer = '';
        };

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (! preg_match('/^\\\\([a-z0-9]+)\s?(.*)$/u', $line, $m)) {
                // Bare continuation line (rare) — treat as verse text if open.
                if ($verse !== null && trim($line) !== '') {
                    $buffer .= ' ' . $line;
                }
                continue;
            }
            [, $marker, $rest] = $m;

            if ($marker === 'c') {
                $flush();
                $chapter = (int) trim($rest);
                $verse   = null;
                continue;
            }

            if ($marker === 'v') {
                $flush();
                // "\v 12 text…" — bridged verses ("\v 17-18") anchor to the first number.
                if (preg_match('/^(\d+)(?:[-–]\d+)?\s*(.*)$/us', $rest, $vm)) {
                    $verse  = (int) $vm[1];
                    $buffer = $vm[2];
                } else {
                    $verse  = null;
                    $buffer = '';
                }
                continue;
            }

            if (in_array($marker, self::VERSE_TEXT_MARKERS, true)) {
                // Paragraph/poetry line: its text belongs to the open verse.
                if ($verse !== null && $rest !== '') {
                    $buffer .= ' ' . $rest;
                }
                continue;
            }

            // Any other marker (\s heading, \d title, \b, intro matter…):
            // count any footnotes it carries as homeless, never guess a verse.
            if (str_contains($rest, '\\f ')) {
                $homeless += preg_match_all('/\\\\f\s.*?\\\\f\*/us', $rest);
            }
        }
        $flush();

        return [$notes, $homeless];
    }

    /**
     * Split one raw \f…\f* run into [frNormalized, frRaw, cleanedText].
     * frNormalized is "C.V" regardless of whether the file wrote "1.4" (KJV
     * style) or "1:4" (WEB style); null when no \fr is present.
     */
    private function parseNote(string $noteRaw): array
    {
        // Strip the envelope: "\f + " … "\f*"
        $inner = preg_replace('/^\\\\f\s+\S*\s*/u', '', $noteRaw);
        $inner = preg_replace('/\\\\f\*$/u', '', $inner);

        $fr = $frRaw = null;
        if (preg_match('/\\\\fr\s+([^\\\\]*)/u', $inner, $rm)) {
            $frRaw = trim($rm[1]);
            if (preg_match('/^(\d+)[.:](\d+)/', $frRaw, $cm)) {
                $fr = "{$cm[1]}.{$cm[2]}";
            }
            $inner = str_replace($rm[0], '', $inner);   // drop the \fr … chunk
        }

        // Drop the \ft marker itself; its content is the note.
        $inner = preg_replace('/\\\\ft\s*/u', '', $inner);

        return [$fr, $frRaw, $this->cleanNoteText($inner)];
    }

    /**
     * Clean footnote body text: unwrap \+wh Hebrew (keep the Hebrew), unwrap
     * any \w-style word tags, strip attribute lists and leftover markers,
     * normalise whitespace. Curly quotes pass through untouched.
     */
    private function cleanNoteText(string $s): string
    {
        // \+wh אֱלֹהִים \+wh*  ->  אֱלֹהִים   (same for \wh, \+w, \w)
        $s = preg_replace_callback('/\\\\\+?w[hj]?\s+(.*?)\\\\\+?w[hj]?\*/us', function ($m) {
            return explode('|', $m[1])[0];
        }, $s);

        $s = preg_replace('/\|(?:\s*[a-z0-9\-]+="[^"]*")+/ui', '', $s);
        $s = preg_replace('/\\\\\+?[a-z]+\d*\*/u', '', $s);     // closers
        $s = preg_replace('/\\\\\+?[a-z]+\d*\b\s?/u', '', $s);  // openers
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * The anchor: the last ANCHOR_WORDS words of CLEANED verse text sitting
     * immediately before byte $offset in the raw verse buffer. Null when the
     * note opens the verse (nothing precedes it).
     */
    private function anchorBefore(string $buffer, int $offset): ?string
    {
        $prefix = substr($buffer, 0, $offset);

        // Earlier footnotes in the same verse are not verse text — remove them
        // so a second note doesn't anchor onto the first note's words.
        $prefix = preg_replace('/\\\\f\s.*?\\\\f\*/us', '', $prefix);

        // Same reduction ImportUsfm::clean() applies to verse text.
        $prefix = preg_replace_callback('/\\\\\+?w\s+(.*?)\\\\\+?w\*/us', function ($m) {
            return explode('|', $m[1])[0];
        }, $prefix);
        $prefix = preg_replace('/\|(?:\s*[a-z0-9\-]+="[^"]*")+/ui', '', $prefix);
        $prefix = preg_replace('/\\\\\+?[a-z]+\d*\*/u', '', $prefix);
        $prefix = preg_replace('/\\\\\+?[a-z]+\d*\b\s?/u', '', $prefix);
        $prefix = str_replace(['~', '//', '¶'], ' ', $prefix);
        $prefix = trim(preg_replace('/\s+/u', ' ', $prefix));

        if ($prefix === '') {
            return null;
        }

        $words  = explode(' ', $prefix);
        $anchor = implode(' ', array_slice($words, -self::ANCHOR_WORDS));

        // Trim trailing punctuation for a cleaner popover lead-in.
        $anchor = rtrim($anchor, ".,;:!?");

        return mb_substr($anchor, 0, 255) ?: null;
    }

    // ── File resolution (same conventions as import:usfm) ────────────────────

    private function resolveFiles(string $path): array
    {
        if (is_file($path)) return [$path];
        if (is_dir($path)) {
            $hits = [];
            foreach (scandir($path) as $f) {
                if (preg_match('/\.(usfm|sfm)$/i', $f)) $hits[] = rtrim($path, '/') . '/' . $f;
            }
            sort($hits);
            return $hits;
        }
        return [];
    }

    private function bookCode(string $raw): ?string
    {
        if (preg_match('/^\\\\id\s+(\S+)/m', $raw, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }
}
