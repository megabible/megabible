<?php
/**
 * extract-bsb-headings.php
 *
 * Walks a folder of Berean Standard Bible USFM files and extracts every
 * SECTION heading into the MegaBible shared-headings TSV format:
 *
 *     set_key <TAB> book_osis <TAB> chapter <TAB> before_verse <TAB> kind <TAB> level <TAB> text
 *
 * What it extracts (USFM marker -> kind/level stored in the DB):
 *     \s  \s1..\s4   -> s   (section heading, level 1..4)
 *     \ms \ms1..\ms3 -> ms  (major section heading)
 *     \mr            -> mr  (major section reference range)
 *     \r             -> r   (parallel-passage reference)
 *     \sr            -> sr  (section reference range)
 *     \sp            -> sp  (speaker label, e.g. Song of Songs)
 *
 * What it deliberately SKIPS:
 *     \d   descriptive titles (Psalm superscriptions) — these are kept
 *          per-translation in your existing `headings` table, untouched.
 *     \sd  semantic dividers, \cl/\cp/\cd chapter labels, everything else.
 *
 * Anchoring: a heading attaches to the verse it PRECEDES (before_verse).
 * Major-section headings that sit just above a \c (e.g. "BOOK ONE" above
 * Psalm 1) attach to verse 1 of the chapter that follows.
 *
 * No database, no Laravel — pure PHP. Run it, eyeball the TSV, retune by
 * hand, then feed it to `php artisan headings:import`.
 *
 * Usage:
 *   php extract-bsb-headings.php INPUT_DIR OUTPUT.tsv [--set=en-standard] [--books=MAT,MRK,LUK]
 *
 * Example (whole Bible):
 *   php extract-bsb-headings.php ~/bsb-usfm storage/app/private/headings/en-standard.tsv
 *
 * Example (just the Gospels + Acts, to review the New Testament first):
 *   php extract-bsb-headings.php ~/bsb-usfm nt-headings.tsv --books=MAT,MRK,LUK,JHN,ACT
 */

// ---------------------------------------------------------------------------
// 1. Paratext/USFM 3-letter book id  ->  [OSIS id, canonical order 1..66].
//    OSIS ids match your BookSeeder; order drives the output sort.
// ---------------------------------------------------------------------------
$USFM_TO_OSIS = [
    'GEN' => ['Gen', 1],   'EXO' => ['Exod', 2],  'LEV' => ['Lev', 3],
    'NUM' => ['Num', 4],   'DEU' => ['Deut', 5],  'JOS' => ['Josh', 6],
    'JDG' => ['Judg', 7],  'RUT' => ['Ruth', 8],  '1SA' => ['1Sam', 9],
    '2SA' => ['2Sam', 10], '1KI' => ['1Kgs', 11], '2KI' => ['2Kgs', 12],
    '1CH' => ['1Chr', 13], '2CH' => ['2Chr', 14], 'EZR' => ['Ezra', 15],
    'NEH' => ['Neh', 16],  'EST' => ['Esth', 17], 'JOB' => ['Job', 18],
    'PSA' => ['Ps', 19],   'PRO' => ['Prov', 20], 'ECC' => ['Eccl', 21],
    'SNG' => ['Song', 22], 'ISA' => ['Isa', 23],  'JER' => ['Jer', 24],
    'LAM' => ['Lam', 25],  'EZK' => ['Ezek', 26], 'DAN' => ['Dan', 27],
    'HOS' => ['Hos', 28],  'JOL' => ['Joel', 29], 'AMO' => ['Amos', 30],
    'OBA' => ['Obad', 31], 'JON' => ['Jonah', 32],'MIC' => ['Mic', 33],
    'NAM' => ['Nah', 34],  'HAB' => ['Hab', 35],  'ZEP' => ['Zeph', 36],
    'HAG' => ['Hag', 37],  'ZEC' => ['Zech', 38], 'MAL' => ['Mal', 39],
    'MAT' => ['Matt', 40], 'MRK' => ['Mark', 41], 'LUK' => ['Luke', 42],
    'JHN' => ['John', 43], 'ACT' => ['Acts', 44], 'ROM' => ['Rom', 45],
    '1CO' => ['1Cor', 46], '2CO' => ['2Cor', 47], 'GAL' => ['Gal', 48],
    'EPH' => ['Eph', 49],  'PHP' => ['Phil', 50], 'COL' => ['Col', 51],
    '1TH' => ['1Thess', 52],'2TH' => ['2Thess', 53],'1TI' => ['1Tim', 54],
    '2TI' => ['2Tim', 55], 'TIT' => ['Titus', 56],'PHM' => ['Phlm', 57],
    'HEB' => ['Heb', 58],  'JAS' => ['Jas', 59],  '1PE' => ['1Pet', 60],
    '2PE' => ['2Pet', 61], '1JN' => ['1John', 62],'2JN' => ['2John', 63],
    '3JN' => ['3John', 64],'JUD' => ['Jude', 65], 'REV' => ['Rev', 66],
];

// Ordering of kinds when several headings share the same anchor verse.
$KIND_RANK = ['ms' => 0, 'mr' => 1, 's' => 2, 'sr' => 3, 'r' => 4, 'sp' => 5];

// ---------------------------------------------------------------------------
// 2. CLI args.
// ---------------------------------------------------------------------------
$args       = array_slice($argv, 1);
$setKey     = 'en-standard';
$bookFilter = null;                       // null = all books
$positional = [];

foreach ($args as $a) {
    if (str_starts_with($a, '--set=')) {
        $setKey = substr($a, 6);
    } elseif (str_starts_with($a, '--books=')) {
        $bookFilter = array_filter(array_map('trim', explode(',', strtoupper(substr($a, 8)))));
    } else {
        $positional[] = $a;
    }
}

$inputDir   = $positional[0] ?? null;
$outputPath = $positional[1] ?? null;

if (!$inputDir || !$outputPath) {
    fwrite(STDERR, "Usage: php extract-bsb-headings.php INPUT_DIR OUTPUT.tsv [--set=en-standard] [--books=MAT,MRK]\n");
    exit(1);
}
if (!is_dir($inputDir)) {
    fwrite(STDERR, "Input directory not found: {$inputDir}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Inline-markup cleaner: turn a raw USFM heading line into plain text.
//    Drops footnotes/cross-refs entirely, unwraps character styles, keeps the
//    surface form of wordlist entries (\w grace|strong="G5485"\w* -> grace).
// ---------------------------------------------------------------------------
function clean_usfm_text(string $s): string
{
    // 1. Remove footnote / cross-reference spans, content and all.
    $s = preg_replace('/\\\\(f|fe|x)\b.*?\\\\\1\*/su', '', $s);
    // 2. Wordlist: keep the surface word before the optional | attributes.
    $s = preg_replace('/\\\\\+?w\s+([^|\\\\]*?)(?:\|[^\\\\]*)?\\\\\+?w\*/u', '$1', $s);
    // 3. Strip any remaining closing character markers (\nd*, \add*, ...).
    $s = preg_replace('/\\\\\+?[a-z0-9]+\*/iu', '', $s);
    // 4. Strip any remaining opening markers (\nd , \add , \bk , ...).
    $s = preg_replace('/\\\\\+?[a-z0-9]+\s?/iu', '', $s);
    // 5. Tidy whitespace.
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Map a USFM heading marker token (no backslash) to [kind, level], or null. */
function marker_to_kind(string $tok): ?array
{
    return match (true) {
        $tok === 's'  || $tok === 's1' => ['s', 1],
        $tok === 's2'                  => ['s', 2],
        $tok === 's3'                  => ['s', 3],
        $tok === 's4'                  => ['s', 4],
        $tok === 'ms' || $tok === 'ms1'=> ['ms', 1],
        $tok === 'ms2'                 => ['ms', 2],
        $tok === 'ms3'                 => ['ms', 3],
        $tok === 'mr'                  => ['mr', 1],
        $tok === 'r'                   => ['r', 1],
        $tok === 'sr'                  => ['sr', 1],
        $tok === 'sp'                  => ['sp', 1],
        default                        => null,
    };
}

// ---------------------------------------------------------------------------
// 4. Gather USFM files.
// ---------------------------------------------------------------------------
$files = [];
foreach (scandir($inputDir) as $f) {
    if (preg_match('/\.usfm$/i', $f) || preg_match('/\.sfm$/i', $f)) {
        $files[] = $inputDir . DIRECTORY_SEPARATOR . $f;
    }
}
sort($files);
if (empty($files)) {
    fwrite(STDERR, "No .usfm/.sfm files found in {$inputDir}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 5. Parse each file.
// ---------------------------------------------------------------------------
$rows         = [];   // collected heading rows
$booksSeen    = 0;
$booksSkipped = [];   // unknown ids
$headingMarkerRe = '/^\\\\(ms[1-3]?|mr|sr|sp|s[1-4]?|r)(?=\s|$)\s*(.*)$/u';

foreach ($files as $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;

    // Resolve the book id: prefer the \id line, fall back to the filename.
    $usfmId = null;
    foreach ($lines as $ln) {
        if (preg_match('/^\\\\id\s+([A-Z0-9]{3})\b/i', $ln, $m)) {
            $usfmId = strtoupper($m[1]);
            break;
        }
    }
    if ($usfmId === null && preg_match('/([A-Z0-9]{3})/i', basename($path), $m)) {
        $cand = strtoupper($m[1]);
        if (isset($USFM_TO_OSIS[$cand])) $usfmId = $cand;
    }

    if ($usfmId === null || !isset($USFM_TO_OSIS[$usfmId])) {
        $booksSkipped[] = basename($path) . ($usfmId ? " (id {$usfmId})" : ' (no id)');
        continue;
    }
    if ($bookFilter !== null && !in_array($usfmId, $bookFilter, true)) {
        continue;
    }

    [$osis, $order] = $USFM_TO_OSIS[$usfmId];
    $booksSeen++;

    $chapter = 0;
    $buffer  = [];   // headings waiting for their following verse

    foreach ($lines as $ln) {
        // Chapter marker: set current chapter, but KEEP any buffered headings
        // so a major heading sitting above \c attaches to the new chapter.
        if (preg_match('/^\\\\c\s+(\d+)/', $ln, $m)) {
            $chapter = (int) $m[1];
            continue;
        }

        // Verse marker: flush the buffer onto this verse number.
        if (preg_match('/^\\\\v\s+(\d+)/', $ln, $m)) {
            if (!empty($buffer)) {
                $bv = (int) $m[1];
                foreach ($buffer as $h) {
                    $rows[] = [
                        'order'        => $order,
                        'osis'         => $osis,
                        'chapter'      => max(1, $chapter),
                        'before_verse' => $bv,
                        'kind'         => $h['kind'],
                        'level'        => $h['level'],
                        'text'         => $h['text'],
                    ];
                }
                $buffer = [];
            }
            continue;
        }

        // Heading marker?
        if (preg_match($headingMarkerRe, $ln, $m)) {
            $kl = marker_to_kind($m[1]);
            if ($kl === null) continue;
            $text = clean_usfm_text($m[2] ?? '');
            if ($text === '') continue;          // empty heading line, skip
            $buffer[] = ['kind' => $kl[0], 'level' => $kl[1], 'text' => $text];
        }
    }
    // Any buffer left at EOF has no verse to attach to — drop it.
}

// ---------------------------------------------------------------------------
// 6. Sort canonically: book order, chapter, verse, then kind rank.
// ---------------------------------------------------------------------------
usort($rows, function ($a, $b) use ($KIND_RANK) {
    return [$a['order'], $a['chapter'], $a['before_verse'], $KIND_RANK[$a['kind']] ?? 9]
       <=> [$b['order'], $b['chapter'], $b['before_verse'], $KIND_RANK[$b['kind']] ?? 9];
});

// ---------------------------------------------------------------------------
// 7. Write the TSV.
// ---------------------------------------------------------------------------
$out = fopen($outputPath, 'w');
if ($out === false) {
    fwrite(STDERR, "Could not open output for writing: {$outputPath}\n");
    exit(1);
}
fwrite($out, "set_key\tbook_osis\tchapter\tbefore_verse\tkind\tlevel\ttext\n");
foreach ($rows as $r) {
    // Headings are plain text; strip any stray tabs just in case.
    $text = str_replace("\t", ' ', $r['text']);
    fwrite($out, implode("\t", [
        $setKey, $r['osis'], $r['chapter'], $r['before_verse'], $r['kind'], $r['level'], $text,
    ]) . "\n");
}
fclose($out);

// ---------------------------------------------------------------------------
// 8. Report.
// ---------------------------------------------------------------------------
$byKind = [];
foreach ($rows as $r) $byKind[$r['kind']] = ($byKind[$r['kind']] ?? 0) + 1;
ksort($byKind);

echo "Done.\n";
echo "  Set key       : {$setKey}\n";
echo "  Books parsed  : {$booksSeen}\n";
echo "  Headings found: " . count($rows) . "\n";
echo "  By kind       : " . (empty($byKind)
        ? '(none)'
        : implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($byKind), $byKind))) . "\n";
echo "  Output        : {$outputPath}\n";
if (!empty($booksSkipped)) {
    echo "  Skipped files : " . implode('; ', $booksSkipped) . "\n";
}
echo "\nFirst few rows:\n";
foreach (array_slice($rows, 0, 6) as $r) {
    echo "  {$r['osis']} {$r['chapter']}:before-{$r['before_verse']}  [{$r['kind']}{$r['level']}]  {$r['text']}\n";
}
