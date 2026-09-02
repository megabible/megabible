<?php
/**
 * convert-apocrypha-json.php  (v1)
 *
 * Sibling to convert-bible-json.php, purpose-built for the scrollmapper
 * deuterocanonical / extra-biblical JSON files (the "2024 branch" shape).
 *
 * Why a separate script instead of extending convert-bible-json.php?
 * The Bible converter resolves a book to its OSIS id by looking the book up
 * in the canonical 66-book table (by numeric id or by name). These apocryphal
 * books are NOT in that table, so that converter would skip every row. Rather
 * than bolt special cases onto a working, tested script and risk regressions
 * in the canonical-Bible path, this one does the one job it needs to do.
 *
 * Each scrollmapper apocrypha file is a SINGLE book in this shape:
 *
 *     { "books": [ { "name": "...", "chapters": [
 *         { "chapter": 1, "name": "...", "verses": [
 *             { "verse": 1, "chapter": 1, "name": "...", "text": "..." }, ...
 *         ] }, ...
 *     ] } ] }
 *
 * Because the file is one known book, you tell the script which OSIS id to
 * stamp on every row with --osis=. (The file's own "name" is ignored for
 * resolution — you decide the target.)
 *
 * Output is the SAME TSV your import:translation command already eats:
 *
 *     book_osis<TAB>chapter<TAB>verse<TAB>text
 *
 * ---------------------------------------------------------------------------
 * Usage:
 *     php convert-apocrypha-json.php INPUT.json OUTPUT.tsv --osis=OSIS [options]
 *
 * Options:
 *     --osis=XXX           (required) OSIS id to stamp on every verse, e.g. 1En
 *     --offset=N           Add N to every source chapter number (default 0).
 *                          Used to MERGE multi-file books (see Hermas below)
 *                          and to lift a stray "chapter 0" out of the way.
 *     --append             Append to OUTPUT.tsv instead of overwriting, and do
 *                          NOT re-write the header row. Used for merges.
 *     --strip-anf-headers  Drop the "Chapter I." / "—Title." artifact verses
 *                          that the Ante-Nicene-Fathers exports carry, then
 *                          renumber the surviving verses in each chapter from 1.
 *                          Leave OFF for clean texts (Enoch, Jubilees, Barnabas,
 *                          Hermas, Apocalypse of Peter).
 *
 * ---------------------------------------------------------------------------
 * Merging the three Hermas files into the single book "Herm":
 *
 *   Visions      (1-hermas, chapters 1..4)        -> chapters 1..4
 *   Commandments (2-hermas, chapters 0..12)       -> chapters 5..17
 *   Similitudes  (3-hermas, chapters 1..9)        -> chapters 18..26
 *
 *     php convert-apocrypha-json.php 1-hermas.json herm.tsv --osis=Herm --offset=0
 *     php convert-apocrypha-json.php 2-hermas.json herm.tsv --osis=Herm --offset=5  --append
 *     php convert-apocrypha-json.php 3-hermas.json herm.tsv --osis=Herm --offset=17 --append
 *
 *   (2-hermas starts at chapter 0, so offset 5 maps 0->5 .. 12->17. The next
 *    file picks up at 18.)
 */

// ---------------------------------------------------------------------------
// 1. Parse CLI arguments.
// ---------------------------------------------------------------------------
$args        = array_slice($argv, 1);
$flags       = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional  = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$inputPath   = $positional[0] ?? null;
$outputPath  = $positional[1] ?? null;

$osis        = null;
$offset      = 0;
$append      = false;
$stripHeaders = false;

foreach ($flags as $flag) {
    if (str_starts_with($flag, '--osis=')) {
        $osis = substr($flag, 7);
    } elseif (str_starts_with($flag, '--offset=')) {
        $offset = (int) substr($flag, 9);
    } elseif ($flag === '--append') {
        $append = true;
    } elseif ($flag === '--strip-anf-headers') {
        $stripHeaders = true;
    } else {
        fwrite(STDERR, "Unknown option: {$flag}\n");
        exit(1);
    }
}

if (!$inputPath || !$outputPath || !$osis) {
    fwrite(STDERR, "Usage: php convert-apocrypha-json.php INPUT.json OUTPUT.tsv --osis=OSIS "
        . "[--offset=N] [--append] [--strip-anf-headers]\n");
    exit(1);
}
if (!is_file($inputPath)) {
    fwrite(STDERR, "Input file not found: {$inputPath}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Load + decode.
// ---------------------------------------------------------------------------
echo "Reading {$inputPath} ...\n";
$data = json_decode(file_get_contents($inputPath), true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "JSON parse error: " . json_last_error_msg() . "\n");
    exit(1);
}

$book = $data['books'][0] ?? null;
if (!is_array($book) || !isset($book['chapters'])) {
    fwrite(STDERR, "Couldn't find books[0].chapters in this file. "
        . "Is this the scrollmapper single-book shape?\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. STRUCTURE PRINTOUT — eyeball this before trusting the output.
// ---------------------------------------------------------------------------
$chapters = $book['chapters'];
$firstCh  = $chapters[0] ?? [];
$firstV   = $firstCh['verses'][0] ?? [];
echo "\n--- structure detected ---\n";
echo "Source book name : " . ($book['name'] ?? '(none)') . "\n";
echo "Target OSIS      : {$osis}\n";
echo "Chapter offset   : {$offset}\n";
echo "Strip ANF headers: " . ($stripHeaders ? 'yes' : 'no') . "\n";
echo "Chapters in file : " . count($chapters)
   . "  (source numbers " . implode(',', array_map(fn($c) => $c['chapter'] ?? '?', $chapters)) . ")\n";
echo "First verse obj  : " . json_encode($firstV) . "\n";
echo "---------------------------\n\n";

// ---------------------------------------------------------------------------
// 4. Helpers.
// ---------------------------------------------------------------------------

/** Is this verse one of the ANF export's header artifacts? */
function isAnfHeaderArtifact(string $text): bool
{
    $t = trim($text);
    // "Chapter I.", "Chapter XII.", "Chapter 3." — short, leading.
    if (preg_match('/^chapter\s+[ivxlcdm0-9]+\.?$/i', $t)) {
        return true;
    }
    // The em-dash title fragment that follows it: "—The Salutation."
    if (str_starts_with($t, "\u{2014}") || str_starts_with($t, '—') || str_starts_with($t, '-')) {
        // Only treat as artifact if it's a short title-ish fragment, not prose.
        return mb_strlen($t) < 120;
    }
    return false;
}

/** Collapse internal whitespace/newlines to single spaces and trim. */
function cleanText(string $text): string
{
    return trim(preg_replace('/[\t\r\n\s]+/u', ' ', $text));
}

// ---------------------------------------------------------------------------
// 5. Open output.
// ---------------------------------------------------------------------------
$out = fopen($outputPath, $append ? 'a' : 'w');
if ($out === false) {
    fwrite(STDERR, "Could not open output file for writing: {$outputPath}\n");
    exit(1);
}
if (!$append) {
    fwrite($out, "book_osis\tchapter\tverse\ttext\n");
}

// ---------------------------------------------------------------------------
// 6. Walk chapters -> verses, write rows.
// ---------------------------------------------------------------------------
$verseCount = 0;
$skipped    = 0;
$dropped    = 0;
$sample     = [];

foreach ($chapters as $ci => $chapter) {
    $srcChapter = (int) ($chapter['chapter'] ?? ($ci)); // some files start at 0
    $chapterNum = $srcChapter + $offset;
    $verses     = $chapter['verses'] ?? [];

    // When stripping headers we renumber survivors from 1 within the chapter,
    // so the reader never sees gaps where artifacts were removed.
    $outVerse = 0;

    foreach ($verses as $vi => $verse) {
        $rawText = (string) ($verse['text'] ?? '');

        if ($stripHeaders && isAnfHeaderArtifact($rawText)) {
            $dropped++;
            continue;
        }

        $text = cleanText($rawText);
        if ($text === '') {
            $skipped++;
            continue;
        }

        // Verse number: renumber when stripping, else trust the source number.
        if ($stripHeaders) {
            $verseNum = ++$outVerse;
        } else {
            $verseNum = (int) ($verse['verse'] ?? ($vi + 1));
        }

        if ($chapterNum <= 0 || $verseNum <= 0) {
            $skipped++;
            continue;
        }

        fwrite($out, "{$osis}\t{$chapterNum}\t{$verseNum}\t{$text}\n");
        $verseCount++;
        if (count($sample) < 3) {
            $sample[] = "{$osis} {$chapterNum}:{$verseNum}  " . mb_substr($text, 0, 60);
        }
    }
}

fclose($out);

// ---------------------------------------------------------------------------
// 7. Report.
// ---------------------------------------------------------------------------
echo "Done.\n";
echo "  Verses written : {$verseCount}\n";
echo "  Header artifacts dropped : {$dropped}\n";
echo "  Empty rows skipped       : {$skipped}\n";
echo "  Output ({$osis}) " . ($append ? 'appended to' : 'written to') . ": {$outputPath}\n";
echo "\nSample (sanity-check this is clean verse text):\n";
foreach ($sample as $line) echo "  {$line}\n";
if ($verseCount === 0) {
    echo "\nZero verses written. Paste me the 'structure detected' block above.\n";
}
