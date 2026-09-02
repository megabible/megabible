<?php
/**
 * convert-bible-json.php  (v2)
 *
 * Converts a scrollmapper/bible_databases JSON file into the MegaBible
 * import TSV format:
 *
 *     book_osis<TAB>chapter<TAB>verse<TAB>text
 *
 * scrollmapper's formats/json/ files mirror their database schema: a flat
 * "books" list (id + name) and a SEPARATE flat "verses" list
 * (book_id, chapter, verse, text). This version handles that shape, and
 * falls back to the older nested shape if it sees one.
 *
 * Before converting, it PRINTS the file's structure (top-level keys, the
 * first book object, the first verse object) so you can see exactly what
 * you're working with. If anything looks off, send me that printout.
 *
 * Usage:
 *     php convert-bible-json.php INPUT.json OUTPUT.tsv [--strip-tags]
 *
 * Example:
 *     php convert-bible-json.php KJV.json storage/app/private/imports/kjv-full.tsv
 */

// ---------------------------------------------------------------------------
// 1. Canonical 66-book table: book id/number (1-66) => [OSIS id, full name].
//    Book id is canonical Protestant order, matching your BookSeeder.
// ---------------------------------------------------------------------------
$CANON = [
    1  => ['Gen', 'Genesis'],          2  => ['Exod', 'Exodus'],
    3  => ['Lev', 'Leviticus'],        4  => ['Num', 'Numbers'],
    5  => ['Deut', 'Deuteronomy'],     6  => ['Josh', 'Joshua'],
    7  => ['Judg', 'Judges'],          8  => ['Ruth', 'Ruth'],
    9  => ['1Sam', '1 Samuel'],        10 => ['2Sam', '2 Samuel'],
    11 => ['1Kgs', '1 Kings'],         12 => ['2Kgs', '2 Kings'],
    13 => ['1Chr', '1 Chronicles'],    14 => ['2Chr', '2 Chronicles'],
    15 => ['Ezra', 'Ezra'],            16 => ['Neh', 'Nehemiah'],
    17 => ['Esth', 'Esther'],          18 => ['Job', 'Job'],
    19 => ['Ps', 'Psalms'],            20 => ['Prov', 'Proverbs'],
    21 => ['Eccl', 'Ecclesiastes'],    22 => ['Song', 'Song of Solomon'],
    23 => ['Isa', 'Isaiah'],           24 => ['Jer', 'Jeremiah'],
    25 => ['Lam', 'Lamentations'],     26 => ['Ezek', 'Ezekiel'],
    27 => ['Dan', 'Daniel'],           28 => ['Hos', 'Hosea'],
    29 => ['Joel', 'Joel'],            30 => ['Amos', 'Amos'],
    31 => ['Obad', 'Obadiah'],         32 => ['Jonah', 'Jonah'],
    33 => ['Mic', 'Micah'],            34 => ['Nah', 'Nahum'],
    35 => ['Hab', 'Habakkuk'],         36 => ['Zeph', 'Zephaniah'],
    37 => ['Hag', 'Haggai'],           38 => ['Zech', 'Zechariah'],
    39 => ['Mal', 'Malachi'],          40 => ['Matt', 'Matthew'],
    41 => ['Mark', 'Mark'],            42 => ['Luke', 'Luke'],
    43 => ['John', 'John'],            44 => ['Acts', 'Acts'],
    45 => ['Rom', 'Romans'],           46 => ['1Cor', '1 Corinthians'],
    47 => ['2Cor', '2 Corinthians'],   48 => ['Gal', 'Galatians'],
    49 => ['Eph', 'Ephesians'],        50 => ['Phil', 'Philippians'],
    51 => ['Col', 'Colossians'],       52 => ['1Thess', '1 Thessalonians'],
    53 => ['2Thess', '2 Thessalonians'],54=> ['1Tim', '1 Timothy'],
    55 => ['2Tim', '2 Timothy'],       56 => ['Titus', 'Titus'],
    57 => ['Phlm', 'Philemon'],        58 => ['Heb', 'Hebrews'],
    59 => ['Jas', 'James'],            60 => ['1Pet', '1 Peter'],
    61 => ['2Pet', '2 Peter'],         62 => ['1John', '1 John'],
    63 => ['2John', '2 John'],         64 => ['3John', '3 John'],
    65 => ['Jude', 'Jude'],            66 => ['Rev', 'Revelation'],
];

// Name => OSIS, with the spelling variants scrollmapper uses
// (Roman numerals, "Revelation of John", etc.) normalized away.
$BY_NAME = [];
foreach ($CANON as $entry) {
    $BY_NAME[normalizeName($entry[1])] = $entry[0];
}

/** Normalize a book name for matching: lowercase, Roman numerals -> arabic, common aliases. */
function normalizeName(string $name): string
{
    $n = strtolower(trim($name));
    $n = preg_replace('/^iii\s+/', '3 ', $n);
    $n = preg_replace('/^ii\s+/',  '2 ', $n);
    $n = preg_replace('/^i\s+/',   '1 ', $n);
    $aliases = [
        'revelation of john' => 'revelation',
        'the revelation'     => 'revelation',
        'song of songs'      => 'song of solomon',
        'canticles'          => 'song of solomon',
        'psalm'              => 'psalms',
    ];
    return $aliases[$n] ?? $n;
}

/** First present, non-empty value among candidate keys. */
function pick(array $arr, array $keys)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
            return $arr[$k];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// 2. CLI arguments.
// ---------------------------------------------------------------------------
$args = array_slice($argv, 1);
$stripTags = in_array('--strip-tags', $args, true);
$positional = array_values(array_filter($args, fn($a) => $a !== '--strip-tags'));
$inputPath  = $positional[0] ?? null;
$outputPath = $positional[1] ?? null;

if (!$inputPath || !$outputPath) {
    fwrite(STDERR, "Usage: php convert-bible-json.php INPUT.json OUTPUT.tsv [--strip-tags]\n");
    exit(1);
}
if (!is_file($inputPath)) {
    fwrite(STDERR, "Input file not found: {$inputPath}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Load + decode.
// ---------------------------------------------------------------------------
echo "Reading {$inputPath} ...\n";
$data = json_decode(file_get_contents($inputPath), true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "JSON parse error: " . json_last_error_msg() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. Locate the books list and the verses list, tolerating wrapper shapes.
// ---------------------------------------------------------------------------
$booksList  = null;
$versesList = null;

if (is_array($data)) {
    // books
    if (isset($data['books']) && is_array($data['books'])) {
        $b = $data['books'];
        $booksList = (isset($b['ot']) || isset($b['nt']))
            ? array_merge($b['ot'] ?? [], $b['nt'] ?? [])
            : array_values($b);
    }
    // verses (the scrollmapper relational shape)
    foreach (['verses', 'data', 'rows'] as $k) {
        if (isset($data[$k]) && is_array($data[$k]) && isset($data[$k][0]) && is_array($data[$k][0])) {
            $versesList = $data[$k];
            break;
        }
    }
    // bare top-level array of verse rows
    if ($versesList === null && isset($data[0]) && is_array($data[0]) && pick($data[0], ['text','t','verse_text']) !== null) {
        $versesList = $data;
    }
}

// ---------------------------------------------------------------------------
// 5. STRUCTURE PRINTOUT — look at this before trusting the output.
// ---------------------------------------------------------------------------
echo "\n--- structure detected ---\n";
echo "Top-level keys: " . (is_array($data) ? implode(', ', array_keys($data)) : gettype($data)) . "\n";
if ($booksList)  echo "First book object : " . json_encode($booksList[0]) . "\n";
if ($versesList) echo "First verse object: " . json_encode($versesList[0]) . "\n";
echo "books found: " . ($booksList ? count($booksList) : 0)
   . " | verses found: " . ($versesList ? count($versesList) : 0) . "\n";
echo "---------------------------\n\n";

// ---------------------------------------------------------------------------
// 6. Build a book-id => OSIS map from the books list.
//    Prefer the numeric id (canonical order); fall back to normalized name.
// ---------------------------------------------------------------------------
$idToOsis   = [];   // book id/number => OSIS
$nameToOsis = [];   // normalized name => OSIS (extra fallback for verse rows)
if ($booksList) {
    foreach ($booksList as $book) {
        $bid  = pick($book, ['id', 'number', 'book_id', 'b']);
        $name = pick($book, ['name', 'n', 'book_name']);
        $osis = null;
        if ($bid !== null && isset($CANON[(int) $bid])) {
            $osis = $CANON[(int) $bid][0];
        } elseif ($name !== null && isset($BY_NAME[normalizeName($name)])) {
            $osis = $BY_NAME[normalizeName($name)];
        }
        if ($osis !== null) {
            if ($bid !== null)  $idToOsis[(int) $bid] = $osis;
            if ($name !== null) $nameToOsis[normalizeName($name)] = $osis;
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Write the TSV.
// ---------------------------------------------------------------------------
$out = fopen($outputPath, 'w');
if ($out === false) {
    fwrite(STDERR, "Could not open output file for writing: {$outputPath}\n");
    exit(1);
}
fwrite($out, "book_osis\tchapter\tverse\ttext\n");

$verseCount = 0;
$skipped    = 0;
$sample     = [];

$writeRow = function (string $osis, int $c, int $v, string $text) use ($out, $stripTags, &$verseCount, &$sample) {
    if ($stripTags) {
        $text = preg_replace('/\{[HG]\d+\}/', '', $text);
        $text = preg_replace('/<[^>]*>/', '', $text);
    }
    $text = trim(preg_replace('/[\t\r\n]+/', ' ', $text));
    if ($c <= 0 || $v <= 0 || $text === '') return false;
    fwrite($out, "{$osis}\t{$c}\t{$v}\t{$text}\n");
    $verseCount++;
    if (count($sample) < 3) $sample[] = "{$osis} {$c}:{$v}  " . mb_substr($text, 0, 60);
    return true;
};

if ($versesList) {
    // --- Relational shape: flat verse rows referencing books by id ----------
    foreach ($versesList as $v) {
        $bid     = pick($v, ['book_id', 'book', 'b', 'book_number']);
        $bname   = pick($v, ['book_name', 'name']);
        $osis    = null;
        if ($bid !== null && isset($idToOsis[(int) $bid]))        $osis = $idToOsis[(int) $bid];
        elseif ($bid !== null && isset($CANON[(int) $bid]))       $osis = $CANON[(int) $bid][0];
        elseif ($bname !== null && isset($nameToOsis[normalizeName($bname)])) $osis = $nameToOsis[normalizeName($bname)];

        if ($osis === null) { $skipped++; continue; }

        $c = (int) pick($v, ['chapter', 'c', 'chapter_number']);
        $n = (int) pick($v, ['verse', 'v', 'verse_number', 'number']);
        $t = (string) (pick($v, ['text', 't', 'verse_text']) ?? '');
        if (!$writeRow($osis, $c, $n, $t)) $skipped++;
    }
} elseif ($booksList) {
    // --- Nested fallback: verses live inside each book's chapters -----------
    foreach ($booksList as $book) {
        $bid  = pick($book, ['id', 'number', 'book_id', 'b']);
        $name = pick($book, ['name', 'n', 'book_name']);
        $osis = ($bid !== null && isset($CANON[(int) $bid])) ? $CANON[(int) $bid][0]
              : ($name !== null ? ($BY_NAME[normalizeName($name)] ?? null) : null);
        if ($osis === null) { $skipped++; continue; }

        foreach (($book['chapters'] ?? []) as $ci => $chapter) {
            // chapter may be an object {number, verses:[...]} or a bare array of strings
            $cNum    = is_array($chapter) ? (int) (pick($chapter, ['number', 'chapter', 'c']) ?? ($ci + 1)) : ($ci + 1);
            $verses  = is_array($chapter) ? ($chapter['verses'] ?? (isset($chapter[0]) ? $chapter : [])) : [];
            foreach ($verses as $vi => $verse) {
                if (is_array($verse)) {
                    $vNum = (int) (pick($verse, ['number', 'verse', 'v']) ?? ($vi + 1));
                    $t    = (string) (pick($verse, ['text', 't']) ?? '');
                } else {
                    $vNum = $vi + 1;            // array-of-strings chapter
                    $t    = (string) $verse;
                }
                if (!$writeRow($osis, $cNum, $vNum, $t)) $skipped++;
            }
        }
    }
} else {
    fwrite(STDERR, "Couldn't find a verses list or a nested books list. "
        . "Send me the structure printout above and I'll adjust.\n");
    fclose($out);
    exit(1);
}

fclose($out);

// ---------------------------------------------------------------------------
// 8. Report.
// ---------------------------------------------------------------------------
echo "Done.\n";
echo "  Verses written: {$verseCount}\n";
echo "  Rows skipped  : {$skipped}\n";
echo "  Output        : {$outputPath}\n";
echo "\nSample (sanity-check this is clean verse text):\n";
foreach ($sample as $line) echo "  {$line}\n";
if ($verseCount === 0) {
    echo "\nStill zero. Paste me the 'structure detected' block above — "
       . "the field names are slightly different from what I matched.\n";
} elseif ($verseCount < 30000) {
    echo "\nNote: a full KJV is ~31,100 verses; you got {$verseCount}. "
       . "If that's far short, tell me.\n";
}
