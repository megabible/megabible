<?php
/**
 * build-acts-of-paul.php  (v1)
 *
 * Builds acts-of-paul.tsv (book_osis<TAB>chapter<TAB>verse<TAB>text) for the
 * single composite "Acts of Paul" book, OSIS=ActPaul, from the M.R. James
 * (1924) translation.
 *
 * WHY A FETCH-AND-SEGMENT SCRIPT (not a hand-typed TSV)?
 * Retyping a primary text by hand risks silently corrupting it, which your
 * spec forbids (§11). Pulling the bytes straight from the source keeps the
 * text faithful. You run this locally (you have PHP + network); it does the
 * fetching and the structural segmentation.
 *
 * SOURCE: M.R. James, The Apocryphal New Testament (Oxford, 1924), as hosted
 * (public domain) at earlychristianwritings.com. Pass --file= to segment a
 * local saved copy instead of fetching.
 *
 * STRUCTURE (James's own divisions):
 *   Introduction  -> written to acts-of-paul-intro.txt (NOT versified; use it
 *                    to seed the book intro)
 *   Section  I    -> chapter 1   The Opening Episode (at Antioch)
 *   Section  II   -> chapter 2   The Acts of Paul and Thecla
 *   Section  III  -> chapter 3   At Myra (Hermocrates)
 *   Section  IV   -> chapter 4   At Sidon
 *   Section  V    -> chapter 5   At Tyre (very fragmentary)
 *   Section  VI   -> chapter 6   The Mines (Frontina)
 *   Section  VII  -> chapter 7   At Philippi & the Letters to/from Corinth (3 Cor)
 *   Section  VIII -> chapter 8   At Ephesus (Paul and the Lion)
 *   Section  IX   -> chapter 9   Fragments: Scenes of Farewell
 *   Section  X    -> chapter 10  The Martyrdom of Paul
 *
 * VERSE NUMBERING: each paragraph in a section becomes one verse, renumbered
 * from 1. This deliberately flattens James's own numbering (Thecla's 1-43, the
 * three Corinthian sub-letters that each restart at 1, etc.) into one clean
 * per-chapter sequence, and silently absorbs the source's OCR number glitches.
 * Where James printed a leading verse number on a paragraph, it is stripped so
 * it doesn't show up inside the text. Later, your `headings` table can label
 * the sub-parts (e.g. "The Corinthians to Paul", "Paul's Reply") in chapter 7.
 *
 * Usage:
 *   php build-acts-of-paul.php OUTPUT.tsv [--file=local.html] [--url=...]
 * Example:
 *   php build-acts-of-paul.php storage/app/private/imports/acts-of-paul.tsv
 */

// ---------------------------------------------------------------------------
// 1. Arguments.
// ---------------------------------------------------------------------------
$args       = array_slice($argv, 1);
$flags      = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$outputPath = $positional[0] ?? null;
$localFile  = null;
$url        = 'http://www.earlychristianwritings.com/text/actspaul.html';

foreach ($flags as $flag) {
    if (str_starts_with($flag, '--file=')) $localFile = substr($flag, 7);
    elseif (str_starts_with($flag, '--url=')) $url = substr($flag, 6);
}

if (!$outputPath) {
    fwrite(STDERR, "Usage: php build-acts-of-paul.php OUTPUT.tsv [--file=local.html] [--url=...]\n");
    exit(1);
}

$OSIS = 'ActPaul';
$CHAPTER_TITLES = [
    1  => 'The Opening Episode (at Antioch)',
    2  => 'The Acts of Paul and Thecla',
    3  => 'At Myra',
    4  => 'At Sidon',
    5  => 'At Tyre',
    6  => 'The Mines',
    7  => 'At Philippi & the Letters with Corinth',
    8  => 'At Ephesus',
    9  => 'Fragments: Scenes of Farewell',
    10 => 'The Martyrdom of Paul',
];

// ---------------------------------------------------------------------------
// 2. Get the HTML.
// ---------------------------------------------------------------------------
if ($localFile) {
    echo "Reading local file {$localFile} ...\n";
    $html = file_get_contents($localFile);
} else {
    echo "Fetching {$url} ...\n";
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: Mozilla/5.0 (MegaBible importer)\r\n",
        'timeout' => 30,
    ]]);
    $html = file_get_contents($url, false, $ctx);
}
if ($html === false || $html === '') {
    fwrite(STDERR, "Could not load the source HTML.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. HTML -> paragraphs.
//    Turn block-level tags into paragraph breaks, strip the rest, decode
//    entities, then split on blank lines.
// ---------------------------------------------------------------------------
$html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
// Block boundaries become a sentinel.
$html = preg_replace('/<\/(p|div|h[1-6]|br|li)\s*>/i', "\n\n", $html);
$html = preg_replace('/<br\s*\/?>/i', "\n\n", $html);
$text = strip_tags($html);
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
// Normalise odd whitespace but keep paragraph breaks.
$text = str_replace(["\r\n", "\r"], "\n", $text);

// Trim to the content region: from "Introduction" to the site footer.
$startPos = stripos($text, 'Introduction');
if ($startPos !== false) $text = substr($text, $startPos);
foreach (['Go to the', 'Chronological List', 'Early Christian Writings is copyright'] as $stop) {
    $p = stripos($text, $stop);
    if ($p !== false) { $text = substr($text, 0, $p); break; }
}

$paras = preg_split('/\n\s*\n/', $text);
$paras = array_values(array_filter(array_map(fn($p) => trim(preg_replace('/[ \t]+/', ' ', $p)), $paras),
                                   fn($p) => $p !== ''));

// ---------------------------------------------------------------------------
// 4. Walk paragraphs. A paragraph that is EXACTLY the next expected Roman
//    numeral (I..X, in order) starts the next chapter. Everything before the
//    first "I" is the Introduction.
// ---------------------------------------------------------------------------
// A paragraph that is EXACTLY a bare Roman numeral I..X is a section header;
// map it straight to that chapter number. (The sub-letters inside chapters 7
// and 10 are written "I.", "II." with a trailing period and text, so they
// never match this bare-numeral test.) Mapping by value (not by "next in
// sequence") means one mis-read header can't cascade into the rest.
$ROMAN2INT = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6,'VII'=>7,'VIII'=>8,'IX'=>9,'X'=>10];

$chapter    = 0;           // 0 == still in the introduction
$verseNum   = 0;
$introLines = [];
$rows       = [];

foreach ($paras as $p) {
    if (isset($ROMAN2INT[$p])) {     // bare numeral -> that chapter
        $chapter  = $ROMAN2INT[$p];
        $verseNum = 0;
        continue;
    }
    if ($chapter === 0) {            // still in the intro
        $introLines[] = $p;
        continue;
    }
    // Strip a leading reference marker so it doesn't show inside the verse:
    //   "p.9. "  (Coptic page)   "I. " (sub-letter)   "12 " / "1.7 " (verse no.)
    $clean = preg_replace('/^(?:p\.\d+\.?\s*)?(?:[IVX]+\.\s+)?(?:\d+(?:\.\d+)?\s+)?/', '', $p);
    $clean = trim($clean);
    if ($clean === '') continue;
    $rows[] = [$OSIS, $chapter, ++$verseNum, $clean];
}

// ---------------------------------------------------------------------------
// 5. Write outputs.
// ---------------------------------------------------------------------------
$out = fopen($outputPath, 'w');
fwrite($out, "book_osis\tchapter\tverse\ttext\n");
foreach ($rows as [$osis, $c, $v, $t]) {
    fwrite($out, "{$osis}\t{$c}\t{$v}\t{$t}\n");
}
fclose($out);

$introPath = preg_replace('/\.tsv$/', '', $outputPath) . '-intro.txt';
file_put_contents($introPath, implode("\n\n", $introLines));

// ---------------------------------------------------------------------------
// 6. Report — eyeball this. You should see 10 chapters with sane counts.
// ---------------------------------------------------------------------------
$perChapter = [];
foreach ($rows as [$o, $c, $v, $t]) $perChapter[$c] = max($perChapter[$c] ?? 0, $v);
ksort($perChapter);

echo "Done.\n";
echo "  Output : {$outputPath}\n";
echo "  Intro  : {$introPath}\n";
echo "  Total verses: " . count($rows) . "\n\n";
echo "Per-chapter verse counts:\n";
foreach ($perChapter as $c => $n) {
    $title = $CHAPTER_TITLES[$c] ?? '';
    echo "  ch{$c}  {$n} verses   {$title}\n";
}
if (count($perChapter) !== 10) {
    echo "\nWARNING: expected 10 chapters, got " . count($perChapter)
       . ". The Roman-numeral section detection may need adjusting — paste me the output.\n";
}
