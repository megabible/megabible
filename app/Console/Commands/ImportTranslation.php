<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Heading;
use App\Models\Translation;
use App\Models\Verse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Imports a translation from a TSV (columns: book_osis, chapter, verse, text).
 *
 * The `text` column may carry lightweight, hand-editable formatting markup.
 * It is parsed here into the same structures ChapterLayout already renders:
 * `verses.format` (a JSON block list), `verses.starts_paragraph`, and rows in
 * the `headings` table. A verse with NO markup imports exactly as before
 * (format = null, starts_paragraph = false), so existing TSVs are unaffected.
 *
 * ── MARKUP (all optional, all inline — the TSV stays four columns) ───────────
 *
 *   Paragraph break — put a pilcrow at the start of the verse that begins a
 *   new paragraph:
 *       ¶ Now when Paul was come to Philippi …
 *
 *   Headings before a verse — placed at the very start of the verse they sit
 *   above; stripped out of the verse text and stored in the headings table.
 *   They may stack, and may be followed by ¶ and/or poetry markers:
 *       [[h: The Trial Before the Governor]]   → section heading (kind s, lvl 1)
 *       [[h2: A sub-heading]]                   → section heading, level 2
 *       [[title: A Psalm of David]]             → descriptive/psalm title (kind d)
 *       [[ref: (cf. Matthew 5)]]                → parallel reference (kind r)
 *
 *   Heading attribution — every heading can be credited. Two ways:
 *       --heading-source=charles-1913   (option)  sets the default for the file
 *       [[h: The Watchers | src: charles-1913]]   (inline) overrides one heading
 *   The key resolves to config/heading_sources.php for the chapter colophon.
 *   Omit both and the heading's source_key is NULL (no separate credit).
 *
 *   Poetry / prose lines — any "/xx" marker makes the verse "structured" and
 *   splits it into blocks. Lead text before the first marker becomes prose.
 *       /q1 /q2 /q3 /q4   poetry, indent levels 1–4
 *       /qc /qr           centred / right-aligned poetry line
 *       /b                stanza break (blank line between groups of lines)
 *       /p /m /pi /pc /pr  prose paragraph styles (e.g. prose after poetry)
 *
 *   Example (a beatitude rendered as poetry, opened by a new paragraph):
 *       ¶ And he lifted up his voice and said: /q1 Blessed are the pure in heart,
 *       /q2 for they shall see God. /q1 Blessed are they that keep the flesh chaste,
 *       /q2 for they shall become the temple of God.
 *
 * ── WHY WE DON'T USE fgetcsv() ──────────────────────────────────────────────
 *
 * TSV is not CSV. In a TSV, a double quote is just a character — there is no
 * quoting or escaping convention. fgetcsv(), however, ALWAYS applies CSV rules
 * even with a tab delimiter: if a field begins with its enclosure character
 * (default `"`), it reads across line breaks until it finds the closing quote.
 * Translations that open speech with `"Tell Jeremiah to go …` and close it many
 * verses later would silently collapse dozens of verses into one field.
 * fgetcsv()'s escape character (`\`) causes a similar class of corruption.
 * So we read lines and explode() on the tab. A tab is a tab; everything else
 * is literal text.
 *
 * Re-importing a file re-applies its formatting: verse format/starts_paragraph
 * are overwritten, and headings for every chapter the file touches are rebuilt
 * from the file (so editing or deleting a marker and re-importing just works).
 * Other translations and untouched chapters are never affected.
 */
class ImportTranslation extends Command
{
    protected $signature = 'import:translation
                            {abbreviation : The translation abbreviation, e.g. KJV}
                            {file : Path to TSV file (relative to storage/app/)}
                            {--name= : Full translation name}
                            {--year= : Year published}
                            {--license=Public Domain : License}
                            {--source= : Source URL}
                            {--heading-source= : Default attribution key for headings in this file (see config/heading_sources.php)}
                            {--fresh : Delete existing verses + headings for this translation before importing}';

    protected $description = 'Import a translation from a TSV file (columns: book_osis, chapter, verse, text)';

    /** Heading token → [kind, level]. */
    private const HEADING_MAP = [
        'h'     => ['s', 1],
        'h2'    => ['s', 2],
        'h3'    => ['s', 3],
        'title' => ['d', 1],
        'ref'   => ['r', 1],
    ];

    /** Recognised block markers (poetry + prose + stanza break). */
    private const BLOCK_MARKERS = 'q[1-4]|qc|qr|qd|b|p|m|pi|pc|pr';

    public function handle(): int
    {
        $abbreviation = strtoupper($this->argument('abbreviation'));
        $relativePath = $this->argument('file');
        $absolutePath = Storage::path($relativePath);

        if (! is_file($absolutePath)) {
            $this->error("Not a readable file: {$absolutePath}");
            $this->line('(Pass the full path including the .tsv extension, and make sure it is a file, not a folder.)');
            return self::FAILURE;
        }

        $translation = Translation::firstOrCreate(
            ['abbreviation' => $abbreviation],
            [
                'name'           => $this->option('name') ?? $abbreviation,
                'year_published' => $this->option('year'),
                'license'        => $this->option('license'),
                'source_url'     => $this->option('source'),
            ]
        );

        // Default attribution stamped on every heading in this file that doesn't
        // carry its own inline "| src: …" override. Blank option → NULL.
        $defaultHeadingSource = $this->option('heading-source') ?: null;

        if ($this->option('fresh')) {
            $this->warn("Deleting existing verses + headings for {$abbreviation}...");
            Verse::where('translation_id', $translation->id)->delete();
            Heading::where('translation_id', $translation->id)->delete();
        }

        $booksByOsis = Book::all()->keyBy('osis_id');

        $handle = fopen($absolutePath, 'r');

        // Read the header as a plain line, not as CSV. Strip a UTF-8 BOM if the
        // file was saved from Excel or Notepad, otherwise the first column name
        // comes back as "\u{FEFF}book_osis" and the header check fails.
        $headerLine = fgets($handle);
        $headerLine = ltrim((string) $headerLine, "\u{FEFF}");
        $header     = explode("\t", rtrim($headerLine, "\r\n"));

        $expectedHeader = ['book_osis', 'chapter', 'verse', 'text'];

        if ($header !== $expectedHeader) {
            $this->error('Bad header. Expected: ' . implode("\t", $expectedHeader));
            $this->error('Got: ' . implode("\t", $header ?: []));
            fclose($handle);
            return self::FAILURE;
        }

        $this->info("Importing into translation: {$translation->abbreviation}");
        if ($defaultHeadingSource) {
            $this->line("Default heading source: {$defaultHeadingSource}");
        }

        $batch       = [];
        $batchSize   = 1000;
        $imported    = 0;
        $skipped     = 0;
        $now         = now();
        $headingRows = [];          // headings parsed out of this file
        $touched     = [];          // [book_id][chapter] => true, chapters this file covers
        $lineNo      = 1;           // header was line 1; data starts at line 2

        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $line = rtrim($line, "\r\n");

            if (trim($line) === '') {
                continue;                                   // blank line, not an error
            }

            // Limit 4: any tab beyond the third stays inside the text column
            // rather than shifting the row. We warn about it just below.
            $row = explode("\t", $line, 4);

            if (count($row) < 4) {
                $this->warn("Line {$lineNo}: expected 4 tab-separated columns, got " . count($row) . ' — skipping.');
                $skipped++;
                continue;
            }

            [$osis, $chapter, $verseNum, $rawText] = $row;

            if (str_contains($rawText, "\t")) {
                $this->warn("Line {$lineNo} ({$osis} {$chapter}:{$verseNum}): stray tab inside the text column — collapsing to a space.");
                $rawText = str_replace("\t", ' ', $rawText);
            }

            $book = $booksByOsis->get($osis);

            if (! $book) {
                $this->warn("Line {$lineNo}: unknown book OSIS '{$osis}' — skipping.");
                $skipped++;
                continue;
            }

            $chapter  = (int) $chapter;
            $verseNum = (int) $verseNum;
            $sortKey  = ($book->book_order * 1_000_000) + ($chapter * 1_000) + $verseNum;

            $parsed = $this->parseVerseMarkup((string) $rawText);

            $batch[] = [
                'translation_id'  => $translation->id,
                'book_id'         => $book->id,
                'chapter'         => $chapter,
                'verse_number'    => $verseNum,
                'text'            => $parsed['text'],
                'format'          => $parsed['format'] !== null
                                        ? json_encode($parsed['format'], JSON_UNESCAPED_UNICODE)
                                        : null,
                'starts_paragraph' => $parsed['starts_paragraph'],
                'osis_ref'        => "{$osis}.{$chapter}.{$verseNum}",
                'sort_key'        => $sortKey,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            foreach ($parsed['headings'] as $h) {
                $headingRows[] = [
                    'translation_id' => $translation->id,
                    'book_id'        => $book->id,
                    'chapter'        => $chapter,
                    'before_verse'   => $verseNum,
                    'kind'           => $h['kind'],
                    'level'          => $h['level'],
                    'text'           => $h['text'],
                    'source_key'     => $h['source'] ?? $defaultHeadingSource,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            $touched[$book->id][$chapter] = true;

            if (count($batch) >= $batchSize) {
                $this->flushVerses($batch);
                $imported += count($batch);
                $this->line("Imported {$imported} verses...");
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->flushVerses($batch);
            $imported += count($batch);
        }

        fclose($handle);

        // Rebuild headings for exactly the chapters this file covered.
        $this->syncHeadings($translation->id, $touched, $headingRows);

        // Update chapter_count for affected books.
        $this->info('Updating chapter counts...');
        DB::statement('
            UPDATE books b
            SET chapter_count = (SELECT MAX(chapter) FROM verses v WHERE v.book_id = b.id)
            WHERE b.id IN (SELECT DISTINCT book_id FROM verses WHERE translation_id = ?)
        ', [$translation->id]);

        $this->info("Done. Imported {$imported} verses, skipped {$skipped}, "
            . count($headingRows) . ' headings.');
        return self::SUCCESS;
    }

    /** Upsert a batch of verses, including the formatting columns. */
    private function flushVerses(array $batch): void
    {
        DB::table('verses')->upsert(
            $batch,
            ['translation_id', 'book_id', 'chapter', 'verse_number'],
            ['text', 'format', 'starts_paragraph', 'osis_ref', 'sort_key', 'updated_at']
        );
    }

    /**
     * Replace headings for the (translation, book, chapter) tuples this file
     * touched, then insert the freshly-parsed ones. Scoping the delete to the
     * touched chapters means a partial re-import never wipes other chapters,
     * and the per-translation scope never touches another translation.
     */
    private function syncHeadings(int $translationId, array $touched, array $headingRows): void
    {
        foreach ($touched as $bookId => $chapters) {
            Heading::where('translation_id', $translationId)
                ->where('book_id', $bookId)
                ->whereIn('chapter', array_keys($chapters))
                ->delete();
        }

        foreach (array_chunk($headingRows, 500) as $chunk) {
            DB::table('headings')->insert($chunk);
        }
    }

    /**
     * Parse our per-verse markup.
     *
     * @return array{text:string, format:?array, starts_paragraph:bool, headings:array<array{kind:string,level:int,text:string,source:?string}>}
     */
    private function parseVerseMarkup(string $raw): array
    {
        $text     = trim($raw);
        $headings = [];

        // 1. Leading heading tokens (may stack). Each may carry an optional
        //    "| src: key" attribution override just before the closing ]].
        while (preg_match('/^\[\[(h2|h3|h|title|ref):\s*(.*?)(?:\s*\|\s*src:\s*([A-Za-z0-9._-]+))?\s*\]\]\s*/u', $text, $m)) {
            [$kind, $level] = self::HEADING_MAP[$m[1]];
            $headings[] = [
                'kind'   => $kind,
                'level'  => $level,
                'text'   => trim($m[2]),
                'source' => (isset($m[3]) && $m[3] !== '') ? $m[3] : null,
            ];
            $text = substr($text, strlen($m[0]));
        }

        // 2. Leading paragraph marker.
        $startsParagraph = false;
        if (preg_match('/^¶\s*/u', $text)) {
            $startsParagraph = true;
            $text = preg_replace('/^¶\s*/u', '', $text);
        }

        // 3. Poetry / prose blocks. No markers → simple prose (format stays null).
        $markerRe = '~/(?:' . self::BLOCK_MARKERS . ')\b~u';
        if (! preg_match($markerRe, $text)) {
            return [
                'text'             => trim($text),
                'format'           => null,
                'starts_paragraph' => $startsParagraph,
                'headings'         => $headings,
            ];
        }

        // Split at each marker (zero-width lookahead keeps the marker on the chunk).
        $chunks = preg_split('~(?=/(?:' . self::BLOCK_MARKERS . ')\b)~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $blocks = [];

        foreach ($chunks as $chunk) {
            if (preg_match('~^/(' . self::BLOCK_MARKERS . ')\b\s*(.*)$~us', $chunk, $mm)) {
                $style = $mm[1];
                $body  = trim($mm[2]);
                if ($style === 'b') {
                    $blocks[] = ['s' => 'b'];                       // stanza break, no text
                    if ($body !== '') $blocks[] = ['s' => 'p', 't' => $body];
                } else {
                    $blocks[] = ['s' => $style, 't' => $body];
                }
            } else {
                $body = trim($chunk);                               // lead text before first marker
                if ($body !== '') $blocks[] = ['s' => 'p', 't' => $body];
            }
        }

        // Plain text (markers stripped) for search, permalinks and parallel view.
        $plain = trim(implode(' ', array_map(fn ($b) => $b['t'] ?? '', $blocks)));

        return [
            'text'             => $plain,
            'format'           => $blocks,
            'starts_paragraph' => $startsParagraph,
            'headings'         => $headings,
        ];
    }
}