<?php
/**
 * convert-extrabiblical-json.php
 *
 * Sibling to your convert-bible-json.php, but for the NINE extra-biblical works
 * that live outside the 66-book canon and therefore can't be matched by the
 * 1-66 numeric id your Bible converter relies on. These are matched BY NAME and
 * mapped to the OSIS ids you already seed in BookSeeder.php:
 *
 *     1En  Jub  Did  Barn  1Clem  2Clem  Herm  ApPet  ActPaul
 *
 * Output is the same import TSV your import:translation command consumes:
 *
 *     book_osis<TAB>chapter<TAB>verse<TAB>text
 *
 * It handles the scrollmapper relational shape (a flat `books` list of
 * {id,name} + a flat `verses` list of {book_id,chapter,verse,text}) AND the
 * older nested shape, exactly like your Bible converter. Before converting it
 * PRINTS the detected structure so you can sanity-check the field names; if a
 * source uses slightly different keys, send me that printout and I'll widen the
 * pick() lists.
 *
 * Usage:
 *     php convert-extrabiblical-json.php INPUT.json OUTPUT.tsv [--only=OSIS] [--strip-tags]
 *
 * Examples:
 *     # Everything this file recognises, into one TSV:
 *     php convert-extrabiblical-json.php deuterocanon.json storage/app/private/imports/extrabiblical.tsv
 *
 *     # Just 1 Enoch + Jubilees (the Charles edition), into its own TSV:
 *     php convert-extrabiblical-json.php deuterocanon.json storage/app/private/imports/charles.tsv --only=1En
 *     php convert-extrabiblical-json.php deuterocanon.json storage/app/private/imports/charles.tsv --only=Jub
 *     # (run twice into the SAME file? No — see note at bottom. Prefer one
 *     #  --only per file, or omit --only and split later.)
 */

// ---------------------------------------------------------------------------
// 1. OSIS => the name spellings a source might use for this work.
//    All comparisons are done after normalizeName() lowercases/trims, so list
//    the variants in any case. Add more freely if a source surprises you.
// ---------------------------------------------------------------------------
$WORKS = [
    '1En' => [
        '1 enoch', 'enoch', 'book of enoch', '1enoch', 'i enoch',
        'ethiopic enoch', 'first enoch',
    ],
    'Jub' => [
        'jubilees', 'book of jubilees', 'the little genesis', 'leptogenesis',
    ],
    'Did' => [
        'didache', 'the didache', 'teaching of the twelve apostles',
        'teaching of the twelve', 'lord\'s teaching through the twelve apostles',
    ],
    'Barn' => [
        'epistle of barnabas', 'barnabas', 'letter of barnabas',
        'the epistle of barnabas',
    ],
    '1Clem' => [
        '1 clement', 'first clement', 'i clement', '1clement',
        'first epistle of clement', 'clement to the corinthians',
        '1 clement to the corinthians', 'epistle of clement',
    ],
    '2Clem' => [
        '2 clement', 'second clement', 'ii clement', '2clement',
        'second epistle of clement', 'an ancient christian homily',
    ],
    'Herm' => [
        'shepherd of hermas', 'the shepherd of hermas', 'hermas',
        'pastor of hermas', 'the pastor of hermas', 'shepherd',
    ],
    'ApPet' => [
        'apocalypse of peter', 'the apocalypse of peter', 'revelation of peter',
        'apocalypse of saint peter',
    ],
    'ActPaul' => [
        'acts of paul', 'the acts of paul', 'acts of paul and thecla',
        'acts of paul & thecla', 'martyrdom of paul',
    ],
];

// Flatten to: normalized name => OSIS
$NAME_TO_OSIS = [];
foreach ($WORKS as $osis => $names) {
    foreach ($names as $n) {
        $NAME_TO_OSIS[$n] = $osis;
    }
}
$VALID_OSIS = array_keys($WORKS);

/**
 * Normalize a work name for matching: lowercase, trim, collapse whitespace,
 * Roman numerals -> arabic, drop a leading "the".
 */
function normalizeName(string $name): string
{
    $n = strtolower(trim($name));
    $n = preg_replace('/\s+/', ' ', $n);
    $n = preg_replace('/^the\s+/', '', $n);
    $n = preg_replace('/^iii\s+/', '3 ', $n);
    $n = preg_replace('/^ii\s+/',  '2 ', $n);
    $n = preg_replace('/^i\s+/',   '1 ', $n);
    return trim($n);
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

/** Resolve any source name to one of our OSIS ids, or null if not one of the nine. */
function resolveOsis(?string $name, array $nameToOsis): ?string
{
    if ($name === null) return null;
    $norm = normalizeName($name);
    if (isset($nameToOsis[$norm])) return $nameToOsis[$norm];

    // Loose contains-match fallback: e.g. "Epistle of Barnabas (Roberts)".
    foreach ($nameToOsis as $candidate => $osis) {
        if (str_contains($norm, $candidate)) return $osis;
    }
    return null;
}

// ---------------------------------------------------------------------------
// 2. CLI arguments.
// ---------------------------------------------------------------------------
$args      = array_slice($argv, 1);
$stripTags = in_array('--strip-tags', $args, true);

$only = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = substr($a, strlen('--only='));
    }
}

$positional = array_values(array_filter(
    $args,
    fn ($a) => $a !== '--strip-tags' && !str_starts_with($a, '--only=')
));
$inputPath  = $positional[0] ?? null;
$outputPath = $positional[1] ?? null;

if (!$inputPath || !$outputPath) {
    fwrite(STDERR, "Usage: php convert-extrabiblical-json.php INPUT.json OUTPUT.tsv [--only=OSIS] [--strip-tags]\n");
    fwrite(STDERR, "Valid --only values: " . implode(', ', $VALID_OSIS) . "\n");
    exit(1);
}
if ($only !== null && !in_array($only, $VALID_OSIS, true)) {
    fwrite(STDERR, "--only must be one of: " . implode(', ', $VALID_OSIS) . "\n");
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
// 4. Locate the books list and the verses list (relational shape), tolerating
//    wrapper shapes — same logic as your Bible converter.
// ---------------------------------------------------------------------------
$booksList  = null;
$versesList = null;

if (is_array($data)) {
    if (isset($data['books']) && is_array($data['books'])) {
        $b = $data['books'];
        $booksList = (isset($b['ot']) || isset($b['nt']))
            ? array_merge($b['ot'] ?? [], $b['nt'] ?? [])
            : array_values($b);
    }
    foreach (['verses', 'data', 'rows'] as $k) {
        if (isset($data[$k]) && is_array($data[$k]) && isset($data[$k][0]) && is_array($data[$k][0])) {
            $versesList = $data[$k];
            break;
        }
    }
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
if ($only) echo "Filtering to --only={$only}\n";
echo "---------------------------\n\n";

// ---------------------------------------------------------------------------
// 6. Build a book-id => OSIS map from the books list (relational shape only).
//    Unlike the Bible converter, ids are NOT canonical 1-66 here, so we resolve
//    purely by NAME and remember which id that name had.
// ---------------------------------------------------------------------------
$idToOsis = [];
if ($booksList) {
    foreach ($booksList as $book) {
        $bid  = pick($book, ['id', 'number', 'book_id', 'b']);
        $name = pick($book, ['name', 'n', 'book_name', 'title']);
        $osis = resolveOsis(is_string($name) ? $name : null, $NAME_TO_OSIS);
        if ($osis !== null && $bid !== null) {
            $idToOsis[(string) $bid] = $osis;
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

$verseCount  = 0;
$skipped     = 0;
$perWork     = [];   // osis => count
$sample      = [];

$writeRow = function (string $osis, int $c, int $v, string $text)
    use ($out, $stripTags, $only, &$verseCount, &$perWork, &$sample) {

    if ($only !== null && $osis !== $only) return false;

    if ($stripTags) {
        $text = preg_replace('/\{[HG]\d+\}/', '', $text);
        $text = preg_replace('/<[^>]*>/', '', $text);
    }
    $text = trim(preg_replace('/[\t\r\n]+/', ' ', $text));
    if ($c <= 0 || $v <= 0 || $text === '') return false;

    fwrite($out, "{$osis}\t{$c}\t{$v}\t{$text}\n");
    $verseCount++;
    $perWork[$osis] = ($perWork[$osis] ?? 0) + 1;
    if (count($sample) < 4) $sample[] = "{$osis} {$c}:{$v}  " . mb_substr($text, 0, 60);
    return true;
};

if ($versesList) {
    // --- Relational shape: flat verse rows referencing books by id or name ---
    foreach ($versesList as $v) {
        $bid   = pick($v, ['book_id', 'book', 'b', 'book_number']);
        $bname = pick($v, ['book_name', 'name', 'title']);

        $osis = null;
        if ($bid !== null && isset($idToOsis[(string) $bid])) {
            $osis = $idToOsis[(string) $bid];
        } else {
            $osis = resolveOsis(is_string($bname) ? $bname : null, $NAME_TO_OSIS);
        }
        if ($osis === null) { $skipped++; continue; }

        $c = (int) pick($v, ['chapter', 'c', 'chapter_number']);
        $n = (int) pick($v, ['verse', 'v', 'verse_number', 'number']);
        $t = (string) (pick($v, ['text', 't', 'verse_text']) ?? '');
        if (!$writeRow($osis, $c, $n, $t)) $skipped++;
    }
} elseif ($booksList) {
    // --- Nested fallback: verses live inside each book's chapters -------------
    foreach ($booksList as $book) {
        $name = pick($book, ['name', 'n', 'book_name', 'title']);
        $osis = resolveOsis(is_string($name) ? $name : null, $NAME_TO_OSIS);
        if ($osis === null) { $skipped++; continue; }

        foreach (($book['chapters'] ?? []) as $ci => $chapter) {
            $cNum   = is_array($chapter) ? (int) (pick($chapter, ['number', 'chapter', 'c']) ?? ($ci + 1)) : ($ci + 1);
            $verses = is_array($chapter) ? ($chapter['verses'] ?? (isset($chapter[0]) ? $chapter : [])) : [];
            foreach ($verses as $vi => $verse) {
                if (is_array($verse)) {
                    $vNum = (int) (pick($verse, ['number', 'verse', 'v']) ?? ($vi + 1));
                    $t    = (string) (pick($verse, ['text', 't']) ?? '');
                } else {
                    $vNum = $vi + 1;
                    $t    = (string) $verse;
                }
                if (!$writeRow($osis, $cNum, $vNum, $t)) $skipped++;
            }
        }
    }
} else {
    fwrite(STDERR, "Couldn't find a verses list or a nested books list. "
        . "Send me the 'structure detected' block above and I'll adjust the pick() lists.\n");
    fclose($out);
    exit(1);
}

fclose($out);

// ---------------------------------------------------------------------------
// 8. Report.
// ---------------------------------------------------------------------------
echo "Done.\n";
echo "  Verses written: {$verseCount}\n";
echo "  Rows skipped  : {$skipped}  (rows for works not in this file's nine, or empty rows)\n";
echo "  Output        : {$outputPath}\n";
if ($perWork) {
    echo "  Per-work counts:\n";
    foreach ($perWork as $osis => $cnt) echo "    {$osis}: {$cnt}\n";
}
echo "\nSample (sanity-check this is clean verse text):\n";
foreach ($sample as $line) echo "  {$line}\n";
if ($verseCount === 0) {
    echo "\nZero verses written. Either this file doesn't contain any of the nine works,\n"
       . "or the names don't match my list. Paste me the 'structure detected' block and a\n"
       . "couple of book names from the source, and I'll widen the matcher.\n";
}
