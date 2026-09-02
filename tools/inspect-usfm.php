<?php
/**
 * inspect-usfm.php
 *
 * A read-only "what's in this file" tool for eBible.org USFM books.
 * It does NOT touch your database. It reads one .usfm book file and prints:
 *
 *   1. The header (book id, title markers).
 *   2. A MARKER INVENTORY — every distinct USFM marker in the file, grouped
 *      by what it does (titles, headings, paragraphs, poetry, lists, notes,
 *      inline styling), with a count of each. Anything we don't recognise is
 *      pushed into an "OTHER — review!" bucket so surprises can't hide.
 *   3. A SKELETON — the linear structure of the first chapter (or N), one line
 *      per marker, with verse text previewed and footnotes/cross-refs collapsed
 *      to [fn] / [xref] so you can SEE how paragraphs, headings, poetry, and
 *      verses interleave.
 *   4. Sanity counts (chapters, verses).
 *
 * This is the "structure printout" step — the same idea as the printout in
 * convert-bible-json.php, but for USFM. Run it on a book, eyeball the output,
 * and that tells us exactly what the real importer + schema need to handle.
 *
 * Usage:
 *   php inspect-usfm.php path/to/MRK.usfm
 *   php inspect-usfm.php path/to/TOB.usfm --skeleton=2     (first 2 chapters)
 *   php inspect-usfm.php path/to/PSA.usfm --skeleton=all   (whole book)
 */

// ---------------------------------------------------------------------------
// 0. Args
// ---------------------------------------------------------------------------
$args       = array_slice($argv, 1);
$skelOpt    = '1';
$positional = [];
foreach ($args as $a) {
    if (preg_match('/^--skeleton=(.+)$/', $a, $m)) {
        $skelOpt = $m[1];
    } else {
        $positional[] = $a;
    }
}
$inputPath = $positional[0] ?? null;

if (!$inputPath) {
    fwrite(STDERR, "Usage: php inspect-usfm.php BOOK.usfm [--skeleton=N|all]\n");
    exit(1);
}
if (!is_file($inputPath)) {
    fwrite(STDERR, "File not found: {$inputPath}\n");
    exit(1);
}

$skeletonChapters = ($skelOpt === 'all') ? PHP_INT_MAX : max(0, (int) $skelOpt);

// ---------------------------------------------------------------------------
// 1. Marker classification.
//    Keys are the marker with any trailing digit stripped (q1 -> q), so we
//    classify the family once. Buckets are printed in this order.
// ---------------------------------------------------------------------------
$CLASS = [
    'Header / book' => ['id','usfm','ide','h','toc','toca','rem','sts'],
    'Titles'        => ['mt','mte','ms','mr'],
    'Introductions' => ['imt','is','ip','ipi','im','imi','ipq','imq','ipr','iq','ib','ili','iot','io','ior','iex','imte','ie'],
    'Section headings' => ['s','sr','r','sp','sd','rq','qa'],
    'Chapter / verse'  => ['c','cp','cl','ca','cd','v','vp','va'],
    'Paragraphs (prose)' => ['p','m','po','pr','cls','pmo','pm','pmc','pmr','pi','mi','nb','pc','ph'],
    'Poetry'        => ['q','qr','qc','qm','qd','b'],
    'Lists'         => ['lh','li','lf','lim','litl','lik','liv'],
    'Other block'   => ['d'],
];
// Inline (character-level) markers — these live INSIDE a verse, not on their
// own line. Listed so the inventory can label them, and so the skeleton knows
// to flatten them out of the preview.
$INLINE = ['add','bk','dc','k','nd','ord','pn','png','qs','qt','sig','sls','tl','wj',
           'em','bd','bdit','it','no','sc','sup','w','wg','wh','wa','rb','pro','fig',
           'ndx','va','vp','ca','cp','jmp','xt'];
$NOTES  = ['f','fe','x']; // open/close note containers (footnote, endnote, xref)

// Build lookup: family => bucket label
$familyBucket = [];
foreach ($CLASS as $label => $families) {
    foreach ($families as $fam) $familyBucket[$fam] = $label;
}

/** Strip a trailing number from a marker: 'q1' => 'q', 's2' => 's', 'p' => 'p'. */
function family(string $marker): string {
    return rtrim($marker, '0123456789');
}

// ---------------------------------------------------------------------------
// 2. Preview helper — turn raw USFM content into readable plain text.
//    Collapses notes to tokens, drops inline styling markers (keeping their
//    text), and truncates.
// ---------------------------------------------------------------------------
function preview(string $s, int $max = 72): string {
    // Collapse whole footnotes / cross-refs to a token.
    $s = preg_replace('/\\\\f\b.*?\\\\f\*/u', '[fn]', $s);
    $s = preg_replace('/\\\\fe\b.*?\\\\fe\*/u', '[fn]', $s);
    $s = preg_replace('/\\\\x\b.*?\\\\x\*/u', '[xref]', $s);
    // Drop closing inline markers (\add*) and opening ones (\add ), keep text.
    $s = preg_replace('/\\\\\+?[a-z]+\d*\*/u', '', $s);   // closers
    $s = preg_replace('/\\\\\+?[a-z]+\d*\s?/u', '', $s);  // openers
    // USFM whitespace tokens.
    $s = str_replace(['~', '//'], [' ', ' '], $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max - 1) . '…';
    }
    return $s;
}

// ---------------------------------------------------------------------------
// 3. Walk the file.
// ---------------------------------------------------------------------------
$content = file_get_contents($inputPath);
$lines   = preg_split('/\r\n|\r|\n/', $content);

// Sanity counts done globally so a \v packed after \q1 on one line still counts.
$chapCount  = preg_match_all('/(^|\s)\\\\c\s+\d+/u', $content);
$verseCount = preg_match_all('/(^|\s)\\\\v\s+\d/u', $content);

$counts      = [];   // marker => count (full marker, e.g. 'q1')
$inlineSeen  = [];   // inline marker => count
$noteSeen    = [];   // note marker => count
$unknown     = [];   // unrecognised line-initial markers
$header      = [];   // header marker => text (first occurrence)
$skeleton    = [];   // lines for the structural skeleton
$chapter     = 0;

foreach ($lines as $raw) {
    $line = rtrim($raw);
    if ($line === '') continue;

    // Tally any INLINE and NOTE markers anywhere on the line (for the inventory).
    if (preg_match_all('/\\\\\+?([a-z]+)\d*\*?/u', $line, $mm)) {
        foreach ($mm[1] as $mk) {
            if (in_array($mk, $GLOBALS['NOTES'], true)) {
                $noteSeen[$mk] = ($noteSeen[$mk] ?? 0) + 1;
            }
        }
    }

    // Line-initial structural marker?
    if (!preg_match('/^\\\\([a-z]+\d*)\*?\s?(.*)$/u', $line, $m)) {
        // Continuation text (rare in eBible output) — attach to skeleton softly.
        if ($chapter !== 0 && $chapter <= $GLOBALS['skeletonChapters']) {
            $skeleton[] = ['marker' => '   ↳', 'text' => preview($line)];
        }
        continue;
    }

    [$full, $marker, $rest] = [$m[0], $m[1], $m[2]];
    $fam = family($marker);

    $counts[$marker] = ($counts[$marker] ?? 0) + 1;

    // Header capture.
    if (in_array($fam, ['id','usfm','ide','h','toc','mt','ms','mr'], true) && !isset($header[$marker])) {
        $header[$marker] = $rest;
    }

    // Chapter bookkeeping (drives skeleton gating; counts are computed globally).
    if ($fam === 'c') {
        $chapter = (int) $rest;
    } elseif ($fam === 'v') {
        if (preg_match('/^(\d+[a-zA-Z]?(?:[-,]\d+[a-zA-Z]?)?)\s*(.*)$/u', $rest, $vm)) {
            $rest = "({$vm[1]}) " . $vm[2];
        }
    }

    // Track inline / unknown for the inventory.
    if (in_array($fam, $GLOBALS['INLINE'], true)) {
        $inlineSeen[$marker] = ($inlineSeen[$marker] ?? 0) + 1;
    } elseif (!isset($GLOBALS['familyBucket'][$fam]) && !in_array($fam, $GLOBALS['NOTES'], true)) {
        $unknown[$marker] = ($unknown[$marker] ?? 0) + 1;
    }

    // Skeleton (first N chapters). We show structural lines; header lines too.
    $showInSkeleton = ($chapter === 0) || ($chapter <= $GLOBALS['skeletonChapters']);
    if ($showInSkeleton) {
        $skeleton[] = ['marker' => '\\' . $marker, 'text' => preview($rest)];
    }
}

// ---------------------------------------------------------------------------
// 4. Report.
// ---------------------------------------------------------------------------
$bar = str_repeat('─', 64);
echo "\n{$bar}\n  USFM INSPECTION  —  " . basename($inputPath) . "\n{$bar}\n";

echo "\n# HEADER\n";
foreach (['id','usfm','h','toc1','toc2','toc3','mt1','mt2','mt','ms1'] as $k) {
    if (isset($header[$k])) printf("  \\%-6s %s\n", $k, preview($header[$k], 80));
}
if (!$header) echo "  (no header markers found — unusual for eBible USFM)\n";

echo "\n# MARKER INVENTORY  (grouped; count in parentheses)\n";
$printedFamilies = [];
foreach ($GLOBALS['CLASS'] as $label => $families) {
    $hits = [];
    foreach ($counts as $marker => $n) {
        if (in_array(family($marker), $families, true)) {
            $hits[] = "\\{$marker} ({$n})";
            $printedFamilies[family($marker)] = true;
        }
    }
    if ($hits) {
        echo "  " . str_pad($label, 20) . implode('  ', $hits) . "\n";
    }
}
if ($inlineSeen) {
    $hits = [];
    foreach ($inlineSeen as $marker => $n) $hits[] = "\\{$marker} ({$n})";
    echo "  " . str_pad('Inline styling', 20) . implode('  ', $hits) . "\n";
}
if ($noteSeen) {
    $hits = [];
    foreach ($noteSeen as $marker => $n) $hits[] = "\\{$marker} ({$n})";
    echo "  " . str_pad('Notes / refs', 20) . implode('  ', $hits) . "\n";
}
if ($unknown) {
    $hits = [];
    foreach ($unknown as $marker => $n) $hits[] = "\\{$marker} ({$n})";
    echo "  " . str_pad('OTHER — review!', 20) . implode('  ', $hits) . "\n";
}

echo "\n# SKELETON";
echo ($skeletonChapters === PHP_INT_MAX) ? "  (whole book)\n" : "  (first {$skeletonChapters} chapter(s))\n";
foreach ($skeleton as $row) {
    printf("  %-8s %s\n", $row['marker'], $row['text']);
}

echo "\n# SANITY\n";
echo "  Chapters: {$chapCount}\n";
echo "  Verses:   {$verseCount}\n";
if ($unknown) {
    echo "\n  ⚠  Unrecognised markers above — paste this printout back and I'll\n";
    echo "     fold them into the importer + schema before we build anything.\n";
}
echo "\n";
