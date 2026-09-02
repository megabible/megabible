<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\OriginalToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Imports original-language word tokens from STEPBible TAHOT (Hebrew OT)
 * or TAGNT (Greek NT) files into `original_tokens`.
 *
 * Source: github.com/STEPBible/STEPBible-Data, folder
 * "Translators Amalgamated OT+NT" (CC BY 4.0).
 *
 * ── VERIFIED FORMAT FACTS (checked against the real files) ──────────────
 * • Data rows are recognised by their reference in column 0:
 *       Gen.1.1#01=L            (TAHOT)
 *       Mat.1.1#01=NKO          (TAGNT)
 *       Act.13.39(13.38)#01=NKO (alt versification, ROUND brackets)
 *       Act.2.11[2.10]#01=NKO   (alt versification, SQUARE brackets)
 *       Rom.16.25{14.24}#01=NKO (alt versification, CURLY brackets)
 *   STEPBible uses ALL THREE bracket styles for the alternate reference,
 *   and the style carries meaning about *why* the numbering differs
 *   (chapter/verse split, word belongs to the neighbouring verse, whole
 *   passage relocated in some editions). For our purposes they are
 *   equivalent: the ref BEFORE the bracket is always the English/KJV
 *   number, which is what we key on. All three must be tolerated —
 *   accepting only "(...)" silently drops ~300 tokens across 40 verses in
 *   TAGNT alone, including all of Romans 16:25-27.
 *   Everything else — the prose preamble AND the column header that TAHOT
 *   repeats before every chapter — fails the pattern and is skipped.
 * • Primary refs use ENGLISH (KJV-style) versification, so the verse join
 *   to KJV/WEB works natively. Alt-ref rows are counted as info.
 * • The "=X" suffix is the text type: L Leningrad; Q(K)/Q(k) Qere; X words
 *   supplied from other witnesses (e.g. Gen 4:8 "let us go to the field");
 *   R restored verses; TAGNT letters mark editions (N=NA, K=KJV/TR, O=
 *   other; lower case = minor variant). Note the suffix can itself contain
 *   round brackets ("=N(k)O"), which is why the alt-ref group must sit
 *   BEFORE the "#" and never scan past it. ALL types import — K rows are
 *   the KJV-only TR words (Mat 6:13 doxology); dropping any type loses text.
 * • POSITION = ORDER OF APPEARANCE per verse, not the word number. Word
 *   numbers restart mid-verse where Hebrew versification splits an English
 *   verse (Num 26:1 = Heb 25:19 + 26:1), and inserted words use 4-digit
 *   numbers (Gen 4:8 #0501 between #05 and #06). File order is curated
 *   reading order in every verified case, so a per-verse counter is both
 *   simpler and more correct than any word-number arithmetic.
 * • Verses appear in one contiguous block, in canonical order — verified;
 *   no verse key is ever revisited later in the file. That is what makes
 *   the "reset counter when the verse key changes" approach safe.
 *
 * ── COLUMN LAYOUTS (verified from each file's own header) ───────────────
 * TAHOT: Ref | Hebrew | Transliteration | Translation | dStrongs | Grammar | …
 *   - Hebrew marks morphemes with "/" (בְּ/רֵאשִׁית) and escapes
 *     punctuation with "\" (maqqef "\־", sof pasuq "\׃") → both cleaned,
 *     marks kept.
 *   - Transliteration mirrors the slashes ("be./re.Shit") → slashes
 *     stripped, syllable dots kept (STEPBible style as-is).
 *   - Translation mirrors them too ("in/ beginning") → slash becomes a
 *     space, whitespace collapsed.
 *   - Language: grammar codes start H(ebrew) or A(ramaic) — Daniel and
 *     Ezra's Aramaic tags itself for free.
 * TAGNT: Ref | Greek (translit) | English translation | dStrongs=Grammar |
 *        Dict=Gloss | editions | Meaning var | Spelling var | SPANISH | …
 *   - Column 1 combines surface and transliteration: "Βίβλος (Biblos)".
 *   - Column 3 combines Strong's and morphology: "G0976=N-NSF"; crasis
 *     words compound them: "G1473=P-1NS + G2532=CONJ" → split per segment
 *     and rejoin as "G1473+G2532" / "P-1NS+CONJ".
 *   - Column 8 is a per-word SPANISH gloss with 100% coverage → gloss_es.
 *
 * ── DESIGN NOTES ─────────────────────────────────────────────────────────
 * • fgets + explode, NOT fgetcsv (unquoted TSV; fgetcsv's leading-quote
 *   behaviour is a known line-eater — don't invite the bug).
 * • Idempotent per chapter: first sight of a (book, chapter) in the file
 *   deletes its existing tokens, then fresh rows stream in. Re-imports
 *   just work; untouched chapters are never affected.
 * • UNPARSED-ROW GUARD: any line that clearly *looks* like a data row
 *   (book.chapter.verse …#nn) but fails the strict pattern is counted and
 *   sampled in the summary. Silent skipping is how the bracket bug above
 *   went unnoticed; now a malformed ref is loud.
 * • The comparison-format guard refuses the edition-alignment datasets
 *   (OSHB/UHB/UXLC columns) which carry no transliteration or gloss.
 */
class ImportInterlinear extends Command
{
    protected $signature = 'import:interlinear
                            {file : Path to a STEPBible data file (relative to storage/app/)}
                            {--source= : Which dataset this file is: tahot or tagnt}
                            {--dry-run : Parse the first 15 tokens and print them, writing nothing}';

    protected $description = 'Import STEPBible TAHOT/TAGNT original-language word tokens';

    /**
     * Matches a data row's reference in column 0 (see class docblock).
     *
     *   ^([1-4]?[A-Za-z]{2,3})   book code            Gen, Mat, 1Co, 2Th …
     *   \.(\d+)\.(\d+)           chapter, verse       .16.25
     *   (?:[(\[{] … [)\]}])?     OPTIONAL alt ref in round, square OR curly
     *                            brackets — the fix for the dropped tokens
     *   \s*#(\d+)                word number          #01, #0501
     *   (?:=(\S+))?              text/edition type    =L, =NKO, =N(k)O
     *
     * Capture groups stay numbered exactly as before:
     *   1 book · 2 chapter · 3 verse · 4 alt ref · 5 word no. · 6 type
     */
    private const REF_PATTERN =
        '/^([1-4]?[A-Za-z]{2,3})\.(\d+)\.(\d+)\s*(?:[(\[{]([^)\]}]*)[)\]}])?\s*#(\d+)(?:=(\S+))?/u';

    /**
     * Deliberately sloppy "this was obviously meant to be a data row"
     * detector, used only to catch refs the strict pattern rejects.
     */
    private const LOOSE_REF_PATTERN = '/^[1-4]?[A-Za-z]{2,3}\.\d+\.\d+\S*#\d+/u';

    /** Splits TAGNT column 1: "Βίβλος (Biblos)" → surface, translit. */
    private const GREEK_TRANSLIT_PATTERN = '/^(.*?)\s*\(([^)]*)\)\s*$/u';

    public function handle(): int
    {
        $source = strtolower((string) $this->option('source'));
        if (! in_array($source, ['tahot', 'tagnt'], true)) {
            $this->error('Pass --source=tahot or --source=tagnt.');
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $absolutePath = Storage::path($this->argument('file'));
        if (! is_file($absolutePath)) {
            $this->error("Not a readable file: {$absolutePath}");
            return self::FAILURE;
        }

        $bookMap     = config('interlinear.stepbible_books');   // STEPBible code → OSIS
        $booksByOsis = Book::all()->keyBy('osis_id');

        $handle = fopen($absolutePath, 'r');

        $batch        = [];
        $batchSize    = 1000;
        $imported     = 0;
        $altVersRows  = 0;       // rows carrying an alternate versification ref
        $altStyles    = [];      // bracket style → count, e.g. '(' => 22
        $typeCounts   = [];      // "=X" suffix → count (L, Q(K), X, K, NKO, ...)
        $unknownCodes = [];      // STEPBible book codes with no mapping
        $unparsed     = 0;       // looked like a data row, failed REF_PATTERN
        $unparsedEx   = [];      // up to 10 samples of the above
        $clearedChaps = [];      // [book_id][chapter] => true, wiped on first sight
        $currentVerse = '';      // "osis.ch.v" currently being read
        $pos          = 0;       // appearance counter within the current verse
        $headerOk     = false;   // saw and validated a header line
        $dryRows      = [];

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Reading {$source} file: " . basename($absolutePath));

        while (($line = fgets($handle)) !== false) {
            $row = explode("\t", rtrim($line, "\r\n"));
            $ref = $row[0] ?? '';

            if (! preg_match(self::REF_PATTERN, $ref, $m)) {
                // Did it at least LOOK like a data row? If so, that is a bug,
                // not a preamble line — record it so the summary shouts.
                if (preg_match(self::LOOSE_REF_PATTERN, $ref)) {
                    $unparsed++;
                    if (count($unparsedEx) < 10) {
                        $unparsedEx[] = $ref;
                    }
                    continue;
                }

                // Not a data row. Header lines get a one-time sanity check;
                // the comparison-format guard refuses the wrong dataset.
                if (! $headerOk && count($row) > 3) {
                    $cells = array_map(fn ($c) => strtolower(trim($c)), $row);
                    if ($cells[0] === 'ref' && in_array('idx', $cells, true)) {
                        $this->error('This is a STEPBible edition-COMPARISON dataset '
                            . '(Ref/Idx/OSHB columns) — it has no transliteration or '
                            . 'gloss, so it cannot feed the interlinear card.');
                        $this->line('Use the classic files from "Translators Amalgamated '
                            . 'OT+NT" (e.g. "TAHOT Gen-Deu - … - STEPBible.org CC BY.txt").');
                        fclose($handle);
                        return self::FAILURE;
                    }
                    $expect = $source === 'tahot' ? 'hebrew' : 'greek';
                    if (($cells[1] ?? '') === $expect) {
                        $headerOk = true;   // right file for this --source
                    }
                }
                continue;
            }

            [, $code, $chapter, $verse, $altRef] = $m;
            $type = $m[6] ?? '';

            $osis = $bookMap[$code] ?? null;
            if ($osis === null) {
                $unknownCodes[$code] = ($unknownCodes[$code] ?? 0) + 1;
                continue;
            }
            $book = $booksByOsis->get($osis);
            if (! $book) {
                $this->warn("OSIS '{$osis}' (from '{$code}') not in books table — skipping.");
                unset($bookMap[$code]);   // warn once, then treat as unknown
                continue;
            }

            $chapter = (int) $chapter;
            $verse   = (int) $verse;

            if ($altRef !== '') {
                $altVersRows++;
                // Which bracket style was it? Cheap to recover from the raw ref.
                if (preg_match('/[(\[{]/', $ref, $b)) {
                    $altStyles[$b[0]] = ($altStyles[$b[0]] ?? 0) + 1;
                }
            }
            $typeCounts[$type ?: '(none)'] = ($typeCounts[$type ?: '(none)'] ?? 0) + 1;

            // Position = appearance order within the verse.
            $verseKey = "{$osis}.{$chapter}.{$verse}";
            if ($verseKey !== $currentVerse) {
                $currentVerse = $verseKey;
                $pos = 0;
            }
            $pos++;

            // ── per-source field assembly ──────────────────────────────
            if ($source === 'tahot') {
                $surface  = $this->cleanSurface($row[1] ?? '');
                $translit = $this->cleanTranslit($row[2] ?? '');
                $gloss    = $this->cleanGloss($row[3] ?? '');
                $strongs  = trim($row[4] ?? '') ?: null;
                $morph    = trim($row[5] ?? '') ?: null;
                $glossEs  = null;
                $lang     = str_starts_with((string) $morph, 'A') ? 'arc' : 'hbo';
            } else {
                if (preg_match(self::GREEK_TRANSLIT_PATTERN, $row[1] ?? '', $g)) {
                    $surface  = trim($g[1]);
                    $translit = trim($g[2]);
                } else {
                    $surface  = trim($row[1] ?? '');
                    $translit = '';
                }
                $gloss = $this->cleanGloss($row[2] ?? '');
                [$strongs, $morph] = $this->splitStrongsMorph($row[3] ?? '');
                $glossEs = trim($row[8] ?? '') ?: null;
                $lang    = 'grc';
            }

            if ($surface === '') {
                $pos--;     // don't leave a gap for a row we didn't keep
                continue;
            }

            $token = [
                'book_id'    => $book->id,
                'chapter'    => $chapter,
                'verse'      => $verse,
                'position'   => $pos,
                'lang'       => $lang,
                'surface'    => $surface,
                'translit'   => $translit ?: null,
                'gloss'      => $gloss ?: null,
                'gloss_es'   => $glossEs,
                'strongs'    => $strongs,
                'morph'      => $morph,
                'source_key' => $source,
            ];

            if ($dryRun) {
                $dryRows[] = [$verseKey . ' @' . $pos . ($type ? " ={$type}" : ''),
                              $lang, $surface, $translit, $gloss, $glossEs, $strongs, $morph];
                if (count($dryRows) >= 15) {
                    break;
                }
                continue;
            }

            // First sight of this (book, chapter) → clear its old tokens.
            if (! isset($clearedChaps[$book->id][$chapter])) {
                OriginalToken::where('book_id', $book->id)
                    ->where('chapter', $chapter)
                    ->delete();
                $clearedChaps[$book->id][$chapter] = true;
            }

            $batch[] = $token;
            if (count($batch) >= $batchSize) {
                DB::table('original_tokens')->insert($batch);
                $imported += count($batch);
                if ($imported % 20000 === 0) {
                    $this->line("Imported {$imported} tokens...");
                }
                $batch = [];
            }
        }

        if (! $dryRun && ! empty($batch)) {
            DB::table('original_tokens')->insert($batch);
            $imported += count($batch);
        }

        fclose($handle);

        if (! $headerOk) {
            $this->warn('Note: no matching header line was seen — double-check that '
                . "--source={$source} is the right dataset for this file.");
        }

        if ($dryRun) {
            $this->table(
                ['ref @pos', 'lang', 'surface', 'translit', 'gloss', 'es', 'strongs', 'morph'],
                $dryRows
            );
            $this->info('Dry run only — nothing was written. If surface shows clean '
                . 'Hebrew/Greek script (no slashes or backslashes) and the other '
                . 'columns look right, run again without --dry-run.');
            return self::SUCCESS;
        }

        $this->info("Done. Imported {$imported} tokens.");

        arsort($typeCounts);
        $this->line('Text types: ' . collect($typeCounts)
            ->map(fn ($n, $t) => "{$t}×{$n}")->take(10)->implode(', '));

        if ($altVersRows > 0) {
            $styles = collect($altStyles)->map(fn ($n, $b) => "{$b}×{$n}")->implode(' ');
            $this->line("{$altVersRows} rows carried an alternate versification ref "
                . "({$styles}) — informational; the primary ref already matches "
                . 'English numbering, so they import against the right verse.');
        }
        foreach ($unknownCodes as $code => $count) {
            $this->line("Unmapped STEPBible book code '{$code}': {$count} rows skipped.");
        }

        // Loud, because silence here is what hid the bracket bug.
        if ($unparsed > 0) {
            $this->newLine();
            $this->error("{$unparsed} rows looked like data but their reference could not "
                . 'be parsed — THESE TOKENS WERE NOT IMPORTED.');
            $this->line('Sample refs: ' . implode('  ', $unparsedEx));
            $this->line('Widen REF_PATTERN to cover them, then re-run this import.');
        }

        return self::SUCCESS;
    }

    /** בְּ/רֵאשִׁ֖ית → בְּרֵאשִׁ֖ית ; עַל\־ → עַל־ ; חֵֽרֶם\׃ → חֵֽרֶם׃ */
    private function cleanSurface(string $raw): string
    {
        return trim(str_replace(['/', '\\'], '', $raw));
    }

    /** be./re.Shit → be.re.Shit (STEPBible dots kept, morpheme slashes removed). */
    private function cleanTranslit(string $raw): string
    {
        return trim(str_replace('/', '', $raw));
    }

    /** "in/ beginning" → "in beginning" (slash → space, whitespace collapsed). */
    private function cleanGloss(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('/', ' ', $raw)));
    }

    /**
     * TAGNT "dStrongs = Grammar" column:
     *   "G0976=N-NSF"                     → ["G0976", "N-NSF"]
     *   "G1473=P-1NS + G2532=CONJ"        → ["G1473+G2532", "P-1NS+CONJ"]
     *   "H1732|G1138«G1138=N-GSM-P"       → ["H1732|G1138«G1138", "N-GSM-P"]
     *
     * @return array{0: ?string, 1: ?string} [strongs, morph]
     */
    private function splitStrongsMorph(string $raw): array
    {
        $strongs = [];
        $morphs  = [];
        foreach (explode(' + ', $raw) as $segment) {
            [$s, $m] = array_pad(explode('=', $segment, 2), 2, null);
            if (($s = trim((string) $s)) !== '') $strongs[] = $s;
            if (($m = trim((string) $m)) !== '') $morphs[]  = $m;
        }
        return [
            $strongs ? implode('+', $strongs) : null,
            $morphs  ? implode('+', $morphs)  : null,
        ];
    }
}
