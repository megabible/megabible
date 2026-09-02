<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Heading;
use App\Models\Translation;
use App\Models\Verse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportUsfm extends Command
{
    protected $signature = 'import:usfm
                            {abbreviation : Translation abbreviation, e.g. KJVCPB}
                            {path : A .usfm file OR a directory of .usfm files}
                            {--name= : Full translation name}
                            {--year= : Year published}
                            {--license=Public Domain : License}
                            {--source= : Source URL}
                            {--fresh : Wipe ALL verses+headings for this translation before importing}';

    protected $description = 'Import an eBible.org USFM book (or folder of books): paragraphs, poetry, and headings.';

    // ── Paratext (USFM) 3-letter book code  =>  your seeded Book osis_id ──────
    // 66 protestant books plus the KJV/Deuterocanon books you have seeded.
    // A file whose code isn't here (or whose osis_id isn't in the books table)
    // is skipped with a warning — that's how we'll discover any Apocrypha books
    // still missing from BookSeeder, without the import ever crashing.
    private const USFM_TO_OSIS = [
        'GEN'=>'Gen','EXO'=>'Exod','LEV'=>'Lev','NUM'=>'Num','DEU'=>'Deut','JOS'=>'Josh',
        'JDG'=>'Judg','RUT'=>'Ruth','1SA'=>'1Sam','2SA'=>'2Sam','1KI'=>'1Kgs','2KI'=>'2Kgs',
        '1CH'=>'1Chr','2CH'=>'2Chr','EZR'=>'Ezra','NEH'=>'Neh','EST'=>'Esth','JOB'=>'Job',
        'PSA'=>'Ps','PRO'=>'Prov','ECC'=>'Eccl','SNG'=>'Song','ISA'=>'Isa','JER'=>'Jer',
        'LAM'=>'Lam','EZK'=>'Ezek','DAN'=>'Dan','HOS'=>'Hos','JOL'=>'Joel','AMO'=>'Amos',
        'OBA'=>'Obad','JON'=>'Jonah','MIC'=>'Mic','NAM'=>'Nah','HAB'=>'Hab','ZEP'=>'Zeph',
        'HAG'=>'Hag','ZEC'=>'Zech','MAL'=>'Mal',
        'MAT'=>'Matt','MRK'=>'Mark','LUK'=>'Luke','JHN'=>'John','ACT'=>'Acts','ROM'=>'Rom',
        '1CO'=>'1Cor','2CO'=>'2Cor','GAL'=>'Gal','EPH'=>'Eph','PHP'=>'Phil','COL'=>'Col',
        '1TH'=>'1Thess','2TH'=>'2Thess','1TI'=>'1Tim','2TI'=>'2Tim','TIT'=>'Titus','PHM'=>'Phlm',
        'HEB'=>'Heb','JAS'=>'Jas','1PE'=>'1Pet','2PE'=>'2Pet','1JN'=>'1John','2JN'=>'2John',
        '3JN'=>'3John','JUD'=>'Jude','REV'=>'Rev',
        // Deuterocanon / Apocrypha you already seeded:
        'TOB'=>'Tob','JDT'=>'Jdt','WIS'=>'Wis','SIR'=>'Sir','BAR'=>'Bar','ESG'=>'EsthGr','DAG'=>'DanGr',
        '1MA'=>'1Macc','2MA'=>'2Macc','1ES'=>'1Esd','2ES'=>'2Esd','MAN'=>'PrMan',
        'ESG'=>'EsthGr','S3Y'=>'PrAzar','SUS'=>'Sus','BEL'=>'Bel',
        // WEB-only extras (seed these in BookSeeder before importing WEB):
        'PS2'=>'Ps151','3MA'=>'3Macc','4MA'=>'4Macc',
    ];

    // Peripheral USFM "books" that are not scripture — skipped silently.
    private const NON_BOOKS = ['FRT','INT','BAK','OTH','CNC','GLO','TDX','NDX','PREF','PUB'];

    // Line-initial markers that open a PROSE paragraph (its own <p>).
    private const PARA = ['p','m','po','pr','cls','pmo','pm','pmc','pmr','pi','pi1','pi2','pi3',
                          'mi','nb','pc','ph','ph1','ph2','ph3','lit',
                          'li','li1','li2','li3','li4','lim','lim1','lim2','lh','lf'];

    // Line-initial markers that are a POETRY line.
    private const POETRY = ['q','q1','q2','q3','q4','qr','qc','qm','qm1','qm2','qm3','qd'];

    // Line-initial markers that become rows in the `headings` table.
    private const HEADING = ['s','s1','s2','s3','s4','sr','r','ms','ms1','ms2','ms3','mr','sp','sd'];

    // Header / title / intro markers we deliberately ignore for the reading text
    // (book intros come from your own intro system, not the USFM).
    private const IGNORE = ['id','ide','usfm','h','h1','h2','h3','toc1','toc2','toc3','toca1','toca2',
                            'toca3','mt','mt1','mt2','mt3','mt4','mte','rem','sts','restore','cl','cp',
                            'ca','va','vp','periph','imt','imt1','imt2','is','is1','is2','ip','ipi','im',
                            'imi','ipq','imq','ipr','iq','iq1','iq2','ib','ili','ili1','ili2','iot','io',
                            'io1','io2','ior','iex','imte','ie','iqt','rq','qa','b','c','v','d'];

    public function handle(): int
    {
        $abbreviation = strtoupper($this->argument('abbreviation'));
        $path         = $this->argument('path');

        $files = $this->resolveFiles($path);
        if (empty($files)) {
            $this->error("No .usfm/.sfm files found at: {$path}");
            return self::FAILURE;
        }

        $translation = Translation::firstOrCreate(
            ['abbreviation' => $abbreviation],
            [
                'name'          => $this->option('name') ?? $abbreviation,
                'year_published'=> $this->option('year'),
                'license'       => $this->option('license'),
                'source_url'    => $this->option('source'),
            ]
        );

        if ($this->option('fresh')) {
            $this->warn("--fresh: clearing all verses + headings for {$abbreviation}…");
            Verse::where('translation_id', $translation->id)->delete();
            Heading::where('translation_id', $translation->id)->delete();
        }

        $books   = Book::all()->keyBy('osis_id');
        $unknown = [];      // USFM codes we couldn't map/seed
        $touched = [];      // book ids we imported (for chapter_count refresh)
        $totalV  = 0;
        $totalH  = 0;

        foreach ($files as $file) {
            $raw  = file_get_contents($file);
            $code = $this->bookCode($raw);

            // Peripheral matter (front, intro, glossary, indexes…) isn't scripture.
            // Skip it silently rather than reporting it as an unmapped book.
            if (in_array($code, self::NON_BOOKS, true)) {
                continue;
            }

            $osis = self::USFM_TO_OSIS[$code] ?? null;
            $book = $osis ? $books->get($osis) : null;

            if (! $book) {
                $unknown[] = $code ?: basename($file);
                $this->warn("  skip " . basename($file) . " — book code '{$code}' not mapped/seeded");
                continue;
            }

            [$verses, $headings] = $this->parseBook($raw, $book);

            try {
                // Idempotent per book, and atomic: if anything in here fails, the
                // book rolls back to its previous state and the run continues with
                // the next file instead of aborting everything downstream.
                DB::transaction(function () use ($translation, $book, $verses, $headings) {
                    Verse::where('translation_id', $translation->id)->where('book_id', $book->id)->delete();
                    Heading::where('translation_id', $translation->id)->where('book_id', $book->id)->delete();
                    $this->insertVerses($translation, $book, $verses);
                    $this->insertHeadings($translation, $book, $headings);
                });
            } catch (\Throwable $e) {
                $this->error("  FAILED {$code} {$book->name} — left unchanged: " . $e->getMessage());
                continue;
            }

            $touched[$book->id] = true;
            $totalV += count($verses);
            $totalH += count($headings);
            $this->line(sprintf("  %-4s %-16s %5d verses, %3d headings", $code, $book->name, count($verses), count($headings)));
        }

        $this->refreshChapterCounts($translation, array_keys($touched));

        $this->info("Done. {$totalV} verses, {$totalH} headings across " . count($touched) . " book(s).");
        if ($unknown) {
            $this->warn("Unmapped/unseeded books (add to BookSeeder + the USFM_TO_OSIS map): " . implode(', ', array_unique($unknown)));
        }
        return self::SUCCESS;
    }

    // ── File resolution ──────────────────────────────────────────────────────
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

    /** Pull the 3-letter book code from the \id line: "\id MRK 41-MRK-web.sfm …" => MRK */
    private function bookCode(string $raw): ?string
    {
        if (preg_match('/^\\\\id\s+(\S+)/m', $raw, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    // ── The parser ───────────────────────────────────────────────────────────
    /**
     * @return array{0: array<int,array>, 1: array<int,array>}  [verses, headings]
     */
    private function parseBook(string $raw, Book $book): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        $chapter        = 0;
        $container      = 'p';     // current paragraph/poetry style in force
        $startsPending  = false;   // a \p/\m seen since the last verse?
        $pendingHead    = [];      // headings waiting for their before_verse
        $cur            = null;    // current verse being built
        $order          = [];      // verse keys in first-seen order
        $byKey          = [];      // "chapter:verse" => verse entry
        $headings       = [];

        // Flush the in-progress verse. If we've already seen this chapter:verse
        // (a segmented verse like 12a/12b, or a repeated marker in the source),
        // merge its blocks into the existing entry instead of creating a second
        // row — the unique (translation, book, chapter, verse) key forbids dupes,
        // and a split verse is one verse anyway.
        $flush = function () use (&$cur, &$order, &$byKey) {
            if ($cur === null) return;
            $key = $cur['chapter'] . ':' . $cur['number'];
            if (isset($byKey[$key])) {
                $byKey[$key]['blocks'] = array_merge($byKey[$key]['blocks'], $cur['blocks']);
            } else {
                $byKey[$key] = $cur;
                $order[]     = $key;
            }
            $cur = null;
        };

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') continue;

            if (! preg_match('/^\\\\([a-z]+\d*)\*?\s?(.*)$/u', $line, $m)) {
                // Bare continuation text (rare in eBible output): append to current verse.
                if ($cur !== null) $this->addBlock($cur, $container, $this->clean($line));
                continue;
            }
            [$marker, $rest] = [$m[1], $m[2]];

            if ($marker === 'c') {
                $flush();
                $chapter       = (int) $rest;
                $container     = 'p';
                $startsPending = false;
                continue;
            }

            if ($marker === 'v') {
                $flush();
                if (! preg_match('/^(\d+)[a-zA-Z]?(?:[-,]\d+[a-zA-Z]?)?\s*(.*)$/su', $rest, $vm)) {
                    continue; // malformed verse line
                }
                $cur = [
                    'number'  => (int) $vm[1],
                    'chapter' => $chapter,
                    'starts'  => $startsPending,
                    'blocks'  => [],
                ];
                foreach ($pendingHead as $h) {
                    $headings[] = $h + ['chapter' => $chapter, 'before_verse' => (int) $vm[1]];
                }
                $pendingHead = [];
                $this->addBlock($cur, $container, $this->clean($vm[2]));
                $startsPending = false;
                continue;
            }

            if (in_array($marker, self::PARA, true)) {
                $container     = $this->normalizePara($marker);
                $startsPending = true;
                if (trim($rest) !== '' && $cur !== null) {
                    $this->addBlock($cur, $container, $this->clean($rest), true);
                }
                continue;
            }

            if (in_array($marker, self::POETRY, true)) {
                $container = $this->normalizePoetry($marker);
                if (trim($rest) !== '' && $cur !== null) {
                    $this->addBlock($cur, $container, $this->clean($rest));
                }
                continue;
            }

            if ($marker === 'b') {                       // stanza break
                if ($cur !== null) $cur['blocks'][] = ['s' => 'b'];
                continue;
            }

            if ($marker === 'd') {                        // psalm / descriptive title
                $pendingHead[] = ['kind' => 'd', 'level' => 1, 'text' => $this->clean($rest)];
                continue;
            }

            if (in_array($marker, self::HEADING, true)) {
                [$kind, $level] = $this->classifyHeading($marker);
                $pendingHead[] = ['kind' => $kind, 'level' => $level, 'text' => $this->clean($rest)];
                continue;
            }

            // Everything else (\id, \h, \toc, \mt, intros…) is ignored for reading text.
            // If it's none of the known-ignore markers, it's worth flagging.
            if (! in_array($marker, self::IGNORE, true)) {
                $this->warn("    note: unhandled marker \\{$marker} in {$book->name} {$chapter} — ignored");
            }
        }

        $flush();

        $verses = [];
        foreach ($order as $key) {
            $verses[] = $this->finalizeVerse($byKey[$key]);
        }
        return [$verses, $headings];
    }

    /** Append a text block to a verse, skipping empty prose/poetry text. */
    private function addBlock(array &$verse, string $style, string $text, bool $newParagraph = false): void
    {
        if ($text === '') return;
        $block = ['s' => $style, 't' => $text];
        if ($newParagraph) {
            $block['np'] = true;   // transient flag: an explicit \p break opened this block
        }
        $verse['blocks'][] = $block;
    }

    /**
     * Turn a verse's blocks into the stored row. A verse is "simple" — and so
     * gets format = null — only when it is exactly one default-prose (\p) block.
     * Anything else (poetry, a non-\p paragraph style, multiple blocks, a stanza
     * break) keeps its full block list so the reader can render it faithfully.
     */
    private function finalizeVerse(array $verse): array
    {
        $blocks = $this->collapseBlocks($verse['blocks']);

        $textParts = [];
        foreach ($blocks as $b) {
            if (($b['s'] ?? '') === 'b') continue;
            if (isset($b['t']) && $b['t'] !== '') $textParts[] = $b['t'];
        }
        $text = trim(implode(' ', $textParts));

        $isSimple = count($blocks) === 1
                 && ($blocks[0]['s'] ?? '') === 'p'
                 && isset($blocks[0]['t']);

        return [
            'number'           => $verse['number'],
            'chapter'          => $verse['chapter'],
            'text'             => $text,
            'starts_paragraph' => $verse['starts'],
            'format'           => $isSimple ? null : $blocks,
        ];
    }

    /**
     * Join adjacent blocks that share the same PROSE style (e.g. a verse split
     * across 12a/12b both in \p). Poetry lines are left untouched so each \q
     * line stays its own line.
     */
    private function collapseBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $i         = count($out) - 1;
            $prev      = $i >= 0 ? $out[$i] : null;
            $isNewPara = ! empty($b['np']);   // explicit \p break → keep it separate
            if ($prev !== null
                && ! $isNewPara
                && ($b['s'] ?? '') === ($prev['s'] ?? '')
                && in_array($b['s'] ?? '', ['p', 'm', 'pi', 'pc', 'pr'], true)
                && isset($b['t'], $prev['t'])) {
                $out[$i]['t'] = trim($prev['t'] . ' ' . $b['t']);
            } else {
                $out[] = $b;
            }
        }
        // The 'np' flag is only a parse-time hint; don't persist it in the stored format JSON.
        foreach ($out as &$o) {
            unset($o['np']);
        }
        unset($o);
        return $out;
    }

    private function normalizePara(string $m): string
    {
        // Collapse numbered indents to their family; keep the meaningful ones.
        return match (true) {
            str_starts_with($m, 'pi') => 'pi',
            str_starts_with($m, 'ph') => 'pi',   // hanging indent ≈ indented prose
            str_starts_with($m, 'li') => 'pi',   // list item (genealogies) ≈ indented prose
            $m === 'lh' || $m === 'lf' => 'm',    // list header / footer
            $m === 'nb'               => 'p',     // "no break" continues prose
            $m === 'pc'               => 'pc',
            $m === 'pr'               => 'pr',
            $m === 'm' || $m === 'mi' => 'm',
            default                   => 'p',
        };
    }

    private function normalizePoetry(string $m): string
    {
        return match ($m) {
            'q'              => 'q1',
            'qm','qm1'       => 'q1',
            'qm2'            => 'q2',
            'qm3'            => 'q3',
            'qc'             => 'qc',
            'qr'             => 'qr',
            'qd'             => 'qd',
            default          => in_array($m, ['q1','q2','q3','q4'], true) ? $m : 'q1',
        };
    }

    private function classifyHeading(string $m): array
    {
        return match (true) {
            $m === 'r'                 => ['r', 1],
            $m === 'sr'                => ['sr', 1],
            $m === 'mr'                => ['mr', 1],
            $m === 'sp'                => ['sp', 1],
            $m === 'sd'                => ['s', 1],
            str_starts_with($m, 'ms')  => ['ms', $this->levelOf($m, 1)],
            str_starts_with($m, 's')   => ['s', $this->levelOf($m, 1)],
            default                    => ['s', 1],
        };
    }

    private function levelOf(string $m, int $default): int
    {
        return preg_match('/(\d)$/', $m, $mm) ? (int) $mm[1] : $default;
    }

    /**
     * Reduce a raw USFM run to clean, plain, searchable text:
     *   - drop footnotes (\f…\f*), endnotes (\fe…\fe*), cross-refs (\x…\x*)
     *   - unwrap word tags  \w surface|strong="G…"\w*  -> surface
     *   - strip any leftover |attribute="…" lists
     *   - remove all remaining markers, keeping their inner text
     *     (so \add the\add* -> the, \nd Lord\nd* -> Lord, \wj …\wj* -> …)
     *   - normalise USFM spacing tokens and whitespace
     */
    private function clean(string $s): string
    {
        $s = preg_replace('/\\\\f\b.*?\\\\f\*/us', '', $s);
        $s = preg_replace('/\\\\fe\b.*?\\\\fe\*/us', '', $s);
        $s = preg_replace('/\\\\x\b.*?\\\\x\*/us', '', $s);

        // Unwrap \w … \w* (and nested \+w … \+w*), keeping text before the '|'.
        $s = preg_replace_callback('/\\\\\+?w\s+(.*?)\\\\\+?w\*/us', function ($m) {
            return explode('|', $m[1])[0];
        }, $s);

        // Strip any remaining attribute lists, e.g.  |strong="G3588"  |lemma="x" strong="y"
        $s = preg_replace('/\|(?:\s*[a-z0-9\-]+="[^"]*")+/ui', '', $s);

        // Remove remaining markers (closers then openers), keeping inner text.
        $s = preg_replace('/\\\\\+?[a-z]+\d*\*/u', '', $s);
        $s = preg_replace('/\\\\\+?[a-z]+\d*\b\s?/u', '', $s);

        $s = str_replace(['~', '//'], [' ', ' '], $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    // ── DB writes ────────────────────────────────────────────────────────────
    private function insertVerses(Translation $t, Book $book, array $verses): void
    {
        $now  = now();
        $rows = [];
        foreach ($verses as $v) {
            $rows[] = [
                'translation_id'   => $t->id,
                'book_id'          => $book->id,
                'chapter'          => $v['chapter'] ?? null,
                'verse_number'     => $v['number'],
                'text'             => $v['text'],
                'starts_paragraph' => $v['starts_paragraph'],
                'format'           => $v['format'] === null ? null : json_encode($v['format'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'osis_ref'         => "{$book->osis_id}.{$v['chapter']}.{$v['number']}",
                'sort_key'         => ($book->book_order * 1_000_000) + (($v['chapter'] ?? 0) * 1_000) + $v['number'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('verses')->insert($chunk);
        }
    }

    private function insertHeadings(Translation $t, Book $book, array $headings): void
    {
        if (empty($headings)) return;
        $now  = now();
        $rows = [];
        foreach ($headings as $h) {
            $rows[] = [
                'translation_id' => $t->id,
                'book_id'        => $book->id,
                'chapter'        => $h['chapter'],
                'before_verse'   => $h['before_verse'],
                'kind'           => $h['kind'],
                'level'          => $h['level'],
                'text'           => $h['text'],
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('headings')->insert($chunk);
        }
    }

    private function refreshChapterCounts(Translation $t, array $bookIds): void
    {
        if (empty($bookIds)) return;
        DB::statement("
            UPDATE books b
            SET chapter_count = (SELECT MAX(chapter) FROM verses v WHERE v.book_id = b.id)
            WHERE b.id IN (" . implode(',', array_fill(0, count($bookIds), '?')) . ")
        ", $bookIds);
    }
}