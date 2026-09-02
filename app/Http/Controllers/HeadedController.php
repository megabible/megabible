<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Support\CanonOrder;
use App\Support\CrossRef;
use App\Support\HeadingTsv;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * HEADed — a local-only GUI for editing the shared-heading TSV that the
 * headings:import command ingests. Shares App\Support\HeadingTsv (read /
 * validate / serialize), App\Support\CanonOrder (config/canon.php), and
 * App\Support\CrossRef (parse / rebuild ref text).
 *
 * Write ops (edit / create / delete / reorder) each apply ONE change to a
 * fresh read of the file, guarded by an mtime check, a target-content check,
 * forbidden-character rejection, and the importer's validation. Every write
 * backs up (last 10), writes atomically, and logs. New rows are placed within
 * their book's block, not by global canon order.
 */
class HeadedController extends Controller
{
    private function guard(): void
    {
        abort_unless(app()->environment('local'), 404);
    }

    public function index()
    {
        $this->guard();

        return view('headed.index', [
            'defaultSet'  => config('headed.default_set', 'en-standard'),
            'defaultPath' => config('headed.default_path', 'headings/en-standard.tsv'),
        ]);
    }

    // ---- READ ------------------------------------------------------------

    public function load(Request $request): JsonResponse
    {
        $this->guard();

        $rawPath = trim((string) $request->query('path', ''));
        $set     = trim((string) $request->query('set', '')) ?: config('headed.default_set', 'en-standard');

        if ($rawPath === '') {
            return response()->json(['ok' => false, 'error' => 'No file path given.'], 422);
        }
        $path = HeadingTsv::resolvePath($rawPath);
        if (! $path) {
            return response()->json(['ok' => false, 'error' => "File not found: {$rawPath}"], 404);
        }

        try {
            return response()->json($this->analyze($path, $set));
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- WRITE -----------------------------------------------------------

    public function write(Request $request): JsonResponse
    {
        $this->guard();

        $rawPath = trim((string) $request->input('path', ''));
        $set     = trim((string) $request->input('set', '')) ?: config('headed.default_set', 'en-standard');
        $mtime   = (int) $request->input('mtime', 0);
        $op      = $request->input('op', []);

        $path = HeadingTsv::resolvePath($rawPath);
        if (! $path) {
            return response()->json(['ok' => false, 'error' => "File not found: {$rawPath}"], 404);
        }

        clearstatcache(true, $path);
        if ($mtime !== filemtime($path)) {
            return response()->json(['ok' => false, 'stale' => true,
                'error' => 'File changed on disk since you loaded it.']);
        }

        try {
            $tsv = HeadingTsv::read($path);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $booksByOsis = Book::all()->keyBy('osis_id');
        $canonPos    = $this->canonPositions();

        $result = $this->applyOp($tsv, $set, is_array($op) ? $op : [], $canonPos, $booksByOsis);
        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        $this->backup($path);
        $content = HeadingTsv::serialize($tsv);
        if (! $this->atomicWrite($path, $content)) {
            return response()->json(['ok' => false, 'error' => 'Write failed (could not save file).'], 500);
        }
        $this->logOp($path, $set, $result['log']);

        clearstatcache(true, $path);
        $payload = $this->analyze($path, $set);
        $payload['activate'] = $result['activate'];
        return response()->json($payload);
    }

    // ---- Op application (mutates $tsv['lines']) --------------------------

    /**
     * @return array{ok:bool, error?:string, activate?:?array, log?:string}
     */
    private function applyOp(array &$tsv, string $set, array $op, array $canonPos, Collection $booksByOsis): array
    {
        $action = $op['action'] ?? '';
        if (! in_array($action, ['edit', 'create', 'delete', 'reorder'], true)) {
            return ['ok' => false, 'error' => 'Unknown operation.'];
        }

        $targetIdx  = null;
        $targetLine = null;
        if (in_array($action, ['edit', 'delete', 'reorder'], true)) {
            $line = (int) ($op['line'] ?? 0);
            foreach ($tsv['lines'] as $i => $ln) {
                if ($ln['type'] === 'data' && $ln['n'] === $line) {
                    $targetIdx  = $i;
                    $targetLine = $ln;
                    break;
                }
            }
            if ($targetIdx === null) {
                return ['ok' => false, 'error' => "Target row (line {$line}) not found."];
            }
            $mismatch = $this->expectMismatch($tsv, $targetLine, $op['expect'] ?? []);
            if ($mismatch) {
                return ['ok' => false, 'error' => "Row changed since you loaded it ({$mismatch}). Reload."];
            }
        }

        if ($action === 'delete') {
            $before = $this->rowLabel($tsv, $targetLine);
            array_splice($tsv['lines'], $targetIdx, 1);
            $osis = HeadingTsv::field($tsv, $targetLine, 'book_osis');
            $ch   = (int) HeadingTsv::field($tsv, $targetLine, 'chapter');
            $bv   = (int) HeadingTsv::field($tsv, $targetLine, 'before_verse');
            $stillThere = $this->anchorHasRows($tsv, $set, $osis, $ch, $bv);
            return [
                'ok'       => true,
                'log'      => "DELETE {$before}",
                'activate' => $stillThere ? ['osis' => $osis, 'chapter' => $ch, 'before' => $bv] : null,
            ];
        }

        if ($action === 'reorder') {
            return $this->applyReorder($tsv, $targetIdx, $targetLine, $canonPos);
        }

        // edit / create share the new-row build + validation.
        $row = $this->normalizeRow($op['row'] ?? [], $set);

        $bad = $this->validateRow($row, $booksByOsis);
        if ($bad) {
            return ['ok' => false, 'error' => $bad];
        }

        $ignoreLine = $action === 'edit' ? ($op['line'] ?? null) : null;
        $conflict   = $this->dedupeConflict($tsv, $set, $row, $booksByOsis, $ignoreLine);
        if ($conflict) {
            return ['ok' => false, 'error' => "A heading already exists at {$conflict}."];
        }

        $preserve = null;
        if ($action === 'edit') {
            $preserve = $targetLine['fields'];
            $before   = $this->rowLabel($tsv, $targetLine);
            array_splice($tsv['lines'], $targetIdx, 1);
        }

        $raw = HeadingTsv::buildDataLine($tsv, [
            'set_key'      => $set,
            'book_osis'    => $row['osis'],
            'chapter'      => $row['chapter'],
            'before_verse' => $row['before'],
            'kind'         => $row['kind'],
            'level'        => $row['level'],
            'text'         => $row['text'],
            'source_key'   => $row['source'],
        ], $preserve);

        // Place within the row's own book block — NOT by global canon order.
        $withinKey = [$row['chapter'], $row['before'], HeadingTsv::kindRank($row['kind']), $row['level']];
        $insertAt  = HeadingTsv::bookBlockInsertIndex($tsv, $tsv['lines'], $set, $row['osis'], $withinKey);

        array_splice($tsv['lines'], $insertAt, 0, [[
            'n' => 0, 'type' => 'data', 'raw' => $raw, 'fields' => explode("\t", $raw),
        ]]);

        $after = "{$row['osis']} {$row['chapter']}:{$row['before']} {$row['kind']}/{$row['level']}  \"{$row['text']}\""
               . ($row['source'] !== '' ? "  [{$row['source']}]" : '');

        $logLine = $action === 'edit'
            ? "EDIT\n         from: {$before}\n           to: {$after}"
            : "CREATE {$after}";

        return [
            'ok'       => true,
            'log'      => $logLine,
            'activate' => ['osis' => $row['osis'], 'chapter' => $row['chapter'], 'before' => $row['before']],
        ];
    }

    /**
     * Canonize Order: re-sort the target segments inside ONE r/mr's text into
     * canon order and rewrite that one field. Segment text rides through
     * verbatim — only the order changes. Unplaceable segments sort last, keeping
     * their relative order.
     */
    private function applyReorder(array &$tsv, int $targetIdx, array $targetLine, array $canonPos): array
    {
        $kind = HeadingTsv::field($tsv, $targetLine, 'kind');
        if ($kind !== 'r' && $kind !== 'mr') {
            return ['ok' => false, 'error' => 'Only cross-reference rows can be reordered.'];
        }

        $text    = HeadingTsv::field($tsv, $targetLine, 'text');
        $targets = CrossRef::parseTargets($text);
        if (count($targets) < 2) {
            return ['ok' => false, 'error' => 'Nothing to reorder.'];
        }

        $nameToPos = $this->nameToPos($canonPos);

        $decorated = [];
        foreach ($targets as $i => $t) {
            $norm = $t['name'] !== null ? ReferenceResolver::key($t['name']) : '';
            $decorated[] = [
                'pos'     => $nameToPos[$norm] ?? PHP_INT_MAX,
                'chapter' => $t['chapter'] ?? PHP_INT_MAX,
                'i'       => $i,
                'segment' => $t['segment'],
            ];
        }
        usort($decorated, fn ($a, $b) => [$a['pos'], $a['chapter'], $a['i']] <=> [$b['pos'], $b['chapter'], $b['i']]);

        $newText = CrossRef::rebuild(array_map(fn ($d) => $d['segment'], $decorated));
        if ($newText === $text) {
            return ['ok' => false, 'error' => 'Already in canon order.'];
        }

        $raw = HeadingTsv::buildDataLine($tsv, ['text' => $newText], $targetLine['fields']);
        $tsv['lines'][$targetIdx]['raw']    = $raw;
        $tsv['lines'][$targetIdx]['fields'] = explode("\t", $raw);

        $osis = HeadingTsv::field($tsv, $targetLine, 'book_osis');
        $ch   = (int) HeadingTsv::field($tsv, $targetLine, 'chapter');
        $bv   = (int) HeadingTsv::field($tsv, $targetLine, 'before_verse');

        return [
            'ok'       => true,
            'log'      => "REORDER {$osis} {$ch}:{$bv} {$kind}\n         from: {$text}\n           to: {$newText}",
            'activate' => ['osis' => $osis, 'chapter' => $ch, 'before' => $bv],
        ];
    }

    private function normalizeRow(array $r, string $set): array
    {
        return [
            'osis'    => trim((string) ($r['osis'] ?? '')),
            'chapter' => (int) ($r['chapter'] ?? 0),
            'before'  => (int) ($r['before'] ?? 0),
            'kind'    => trim((string) ($r['kind'] ?? '')),
            'level'   => max(1, (int) ($r['level'] ?? 1)),
            'text'    => trim((string) ($r['text'] ?? '')),
            'source'  => trim((string) ($r['source'] ?? '')),
        ];
    }

    private function validateRow(array $row, Collection $booksByOsis): ?string
    {
        if (! $booksByOsis->has($row['osis'])) {
            return "Unknown book OSIS '{$row['osis']}'.";
        }
        if (! in_array($row['kind'], HeadingTsv::KNOWN_KINDS, true)) {
            return "Unknown kind '{$row['kind']}'.";
        }
        if ($row['chapter'] < 1 || $row['before'] < 1) {
            return 'Chapter and verse must be 1 or greater.';
        }
        if ($row['text'] === '') {
            return 'Heading text cannot be empty.';
        }
        foreach (['text' => $row['text'], 'source' => $row['source'], 'osis' => $row['osis']] as $name => $val) {
            if (preg_match('/[\t\r\n]/', $val)) {
                return "The {$name} field contains a tab or line break, which isn't allowed.";
            }
        }
        return null;
    }

    private function dedupeConflict(array $tsv, string $set, array $row, Collection $booksByOsis, ?int $ignoreLine): ?string
    {
        $book = $booksByOsis->get($row['osis']);
        $key  = HeadingTsv::dedupeKey($book->id, $row['chapter'], $row['before'], $row['kind'], $row['level']);

        foreach ($tsv['lines'] as $ln) {
            if ($ln['type'] !== 'data' || $ln['n'] === $ignoreLine) {
                continue;
            }
            if (HeadingTsv::field($tsv, $ln, 'set_key') !== $set) {
                continue;
            }
            $osis = HeadingTsv::field($tsv, $ln, 'book_osis');
            $b    = $booksByOsis->get($osis);
            if (! $b) {
                continue;
            }
            $existing = HeadingTsv::dedupeKey(
                $b->id,
                (int) HeadingTsv::field($tsv, $ln, 'chapter'),
                (int) HeadingTsv::field($tsv, $ln, 'before_verse'),
                HeadingTsv::field($tsv, $ln, 'kind'),
                (int) HeadingTsv::field($tsv, $ln, 'level'),
            );
            if ($existing === $key) {
                return "{$row['osis']} {$row['chapter']}:{$row['before']} {$row['kind']}/{$row['level']} (line {$ln['n']})";
            }
        }
        return null;
    }

    private function expectMismatch(array $tsv, array $line, array $expect): ?string
    {
        $checks = [
            'osis'    => HeadingTsv::field($tsv, $line, 'book_osis'),
            'chapter' => HeadingTsv::field($tsv, $line, 'chapter'),
            'before'  => HeadingTsv::field($tsv, $line, 'before_verse'),
            'kind'    => HeadingTsv::field($tsv, $line, 'kind'),
            'level'   => HeadingTsv::field($tsv, $line, 'level'),
            'text'    => HeadingTsv::field($tsv, $line, 'text'),
        ];
        foreach ($checks as $k => $actual) {
            if (isset($expect[$k]) && (string) $expect[$k] !== (string) $actual) {
                return "{$k} differs";
            }
        }
        return null;
    }

    private function anchorHasRows(array $tsv, string $set, string $osis, int $ch, int $bv): bool
    {
        foreach ($tsv['lines'] as $ln) {
            if ($ln['type'] !== 'data') {
                continue;
            }
            if (HeadingTsv::field($tsv, $ln, 'set_key') === $set
                && HeadingTsv::field($tsv, $ln, 'book_osis') === $osis
                && (int) HeadingTsv::field($tsv, $ln, 'chapter') === $ch
                && (int) HeadingTsv::field($tsv, $ln, 'before_verse') === $bv) {
                return true;
            }
        }
        return false;
    }

    private function rowLabel(array $tsv, array $line): string
    {
        $osis = HeadingTsv::field($tsv, $line, 'book_osis');
        $ch   = HeadingTsv::field($tsv, $line, 'chapter');
        $bv   = HeadingTsv::field($tsv, $line, 'before_verse');
        $k    = HeadingTsv::field($tsv, $line, 'kind');
        $lv   = HeadingTsv::field($tsv, $line, 'level');
        $tx   = HeadingTsv::field($tsv, $line, 'text');
        return "{$osis} {$ch}:{$bv} {$k}/{$lv}  \"{$tx}\"";
    }

    // ---- Backup / write / log -------------------------------------------

    private function backup(string $path): void
    {
        $dir = storage_path('app/' . trim(config('headed.backup_dir', 'headed/backups'), '/'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $base  = basename($path);
        $stamp = now()->format('Ymd-His');
        @copy($path, "{$dir}/{$base}.{$stamp}.tsv");

        $keep  = (int) config('headed.backup_keep', 10);
        $files = glob("{$dir}/{$base}.*.tsv") ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    private function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path . '.tmp.' . uniqid();
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (@rename($tmp, $path)) {
            return true;
        }
        if (@unlink($path) && @rename($tmp, $path)) {
            return true;
        }
        @unlink($tmp);
        return false;
    }

    private function logOp(string $path, string $set, string $line): void
    {
        $dir = storage_path('app/' . trim(config('headed.log_dir', 'headed/logs'), '/'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file  = "{$dir}/" . now()->format('Y-m-d') . '.txt';
        $stamp = now()->format('Y-m-d H:i:s');
        $base  = basename($path);
        @file_put_contents($file, "[{$stamp}] [{$set}] [{$base}] {$line}\n", FILE_APPEND);
    }

    // ---- Shared analysis -------------------------------------------------

    private function canonPositions(): array
    {
        $slugOrder = CanonOrder::slugOrder();
        $canonPos  = [];
        $tail      = count($slugOrder);
        foreach (Book::orderBy('book_order')->get(['osis_id', 'slug']) as $b) {
            $canonPos[$b->osis_id] = $slugOrder[$b->slug] ?? $tail++;
        }
        return $canonPos;
    }

    /** Book NAME (normalized, as written in ref text) => canon position. */
    private function nameToPos(array $canonPos): array
    {
        $slugToOsis = [];
        foreach (Book::all(['osis_id', 'slug']) as $b) {
            $slugToOsis[$b->slug] = $b->osis_id;
        }
        $nameToPos = [];
        foreach ($this->bookLookup() as $normName => $slug) {
            $osis = $slugToOsis[$slug] ?? null;
            if ($osis !== null && isset($canonPos[$osis])) {
                $nameToPos[$normName] = $canonPos[$osis];
            }
        }
        return $nameToPos;
    }

    /**
     * The full payload the front end renders. @throws RuntimeException
     */
    private function analyze(string $path, string $set): array
    {
        $tsv         = HeadingTsv::read($path);
        $booksByOsis = Book::all()->keyBy('osis_id');

        $slugOrder  = CanonOrder::slugOrder();
        $canonPos   = [];
        $bookName   = [];
        $slugToOsis = [];
        $tail       = count($slugOrder);
        foreach (Book::orderBy('book_order')->get(['osis_id', 'slug', 'name']) as $b) {
            $canonPos[$b->osis_id] = $slugOrder[$b->slug] ?? $tail++;
            $bookName[$b->osis_id] = $b->name;
            $slugToOsis[$b->slug]  = $b->osis_id;
        }

        // Ref-text book name (normalized) => OSIS. Same lookup site search uses.
        $nameToOsis = [];
        foreach ($this->bookLookup() as $normName => $slug) {
            $osis = $slugToOsis[$slug] ?? null;
            if ($osis !== null) {
                $nameToOsis[$normName] = $osis;
            }
        }

        $v = HeadingTsv::validate($tsv, $set, null, null, $booksByOsis);

        $acceptedLines = array_fill_keys(array_map(fn ($r) => $r['line'], $v['rows']), true);
        $dupeLines     = array_fill_keys(array_map(fn ($d) => $d['line'], $v['dupes']), true);

        // ---- Pass 1: index every r/mr's targets, chapter ranges expanded,
        //      so the mirror check can test reciprocity (book + chapter). ----
        $refIndex = [];   // "osis|chapter" => set of "targetOsis|targetChapter"
        $refRows  = [];   // line => parsed context for pass 2
        foreach ($tsv['lines'] as $ln) {
            if ($ln['type'] !== 'data' || HeadingTsv::field($tsv, $ln, 'set_key') !== $set) {
                continue;
            }
            $kind = HeadingTsv::field($tsv, $ln, 'kind');
            if ($kind !== 'r' && $kind !== 'mr') {
                continue;
            }

            $fromOsis = HeadingTsv::field($tsv, $ln, 'book_osis');
            $fromCh   = (int) HeadingTsv::field($tsv, $ln, 'chapter');
            $fromKey  = "{$fromOsis}|{$fromCh}";

            $parsed = [];
            foreach (CrossRef::parseTargets(HeadingTsv::field($tsv, $ln, 'text')) as $t) {
                $norm  = $t['name'] !== null ? ReferenceResolver::key($t['name']) : '';
                $tOsis = $nameToOsis[$norm] ?? null;

                $covered = [];
                if ($tOsis !== null && $t['chapter'] !== null) {
                    $lo = min($t['chapter'], $t['chapter_end']);
                    $hi = max($t['chapter'], $t['chapter_end']);
                    if ($hi - $lo > 200) { $hi = $lo + 200; }   // guard pathological ranges
                    for ($c = $lo; $c <= $hi; $c++) {
                        $refIndex[$fromKey]["{$tOsis}|{$c}"] = true;
                        $covered[] = $c;
                    }
                }

                $parsed[] = [
                    'segment' => $t['segment'],
                    'name'    => $t['name'],
                    'osis'    => $tOsis,
                    'covered' => $covered,
                    'known'   => $tOsis !== null,
                ];
            }
            $refRows[$ln['n']] = ['fromKey' => $fromKey, 'targets' => $parsed];
        }

        // ---- Pass 2: canon-order check + mirror check. ----
        $xrefIssues    = [];
        $mirrorIssues  = [];
        $mirrorMissing = [];
        foreach ($tsv['lines'] as $ln) {
            if ($ln['type'] !== 'data' || HeadingTsv::field($tsv, $ln, 'set_key') !== $set) {
                continue;
            }
            $kind = HeadingTsv::field($tsv, $ln, 'kind');
            if ($kind !== 'r' && $kind !== 'mr') {
                continue;
            }
            $ctx = $refRows[$ln['n']] ?? null;
            if ($ctx === null) {
                continue;
            }

            $osis    = HeadingTsv::field($tsv, $ln, 'book_osis');
            $chapter = (int) HeadingTsv::field($tsv, $ln, 'chapter');
            $before  = (int) HeadingTsv::field($tsv, $ln, 'before_verse');
            $text    = HeadingTsv::field($tsv, $ln, 'text');

            // -- canon order --
            $violation = null; $prevPos = null; $prevName = null;
            foreach ($ctx['targets'] as $t) {
                if (! $t['known'] || ! isset($canonPos[$t['osis']])) {
                    continue;
                }
                $pos = $canonPos[$t['osis']];
                if ($prevPos !== null && $pos < $prevPos) {
                    $violation = "“{$prevName}” is listed before “{$t['segment']}”, but “{$t['segment']}” comes first in canon order.";
                    break;
                }
                $prevPos = $pos; $prevName = $t['segment'];
            }
            $unresolved = [];
            foreach ($ctx['targets'] as $t) {
                if (! $t['known']) {
                    $unresolved[] = $t['segment'];
                }
            }
            if ($violation !== null || $unresolved !== []) {
                $xrefIssues[] = [
                    'line'    => $ln['n'], 'osis' => $osis, 'book' => $bookName[$osis] ?? $osis,
                    'chapter' => $chapter, 'before' => $before, 'text' => $text,
                    'reason'  => $violation !== null ? 'order' : 'unresolved',
                    'detail'  => $violation ?? ('Unrecognized book: ' . implode(', ', $unresolved)),
                    'reorderable' => $violation !== null && count($ctx['targets']) > 1,
                ];
            }

            // -- mirror (book + chapter only; verses ignored). --
            $backKey = $ctx['fromKey'];
            $missing = [];
            foreach ($ctx['targets'] as $t) {
                if ($t['osis'] === null || $t['covered'] === []) {
                    continue;   // unrecognized book handled by the order check
                }
                $found = false;
                foreach ($t['covered'] as $c) {
                    if (isset($refIndex["{$t['osis']}|{$c}"][$backKey])) { $found = true; break; }
                }
                if (! $found) {
                    $missing[] = $t['segment'];
                }
            }
            if ($missing !== []) {
                $joined = implode('; ', $missing);
                $mirrorMissing[$ln['n']] = $joined;
                $mirrorIssues[] = [
                    'line'    => $ln['n'], 'osis' => $osis, 'book' => $bookName[$osis] ?? $osis,
                    'chapter' => $chapter, 'before' => $before, 'text' => $text, 'missing' => $joined,
                ];
            }
        }

        // ---- Typed line model for the viewer. ----
        $lines = [];
        foreach ($tsv['lines'] as $ln) {
            if ($ln['type'] !== 'data') {
                $lines[] = ['n' => $ln['n'], 'type' => $ln['type'], 'raw' => $ln['raw']];
                continue;
            }
            $rowSet  = HeadingTsv::field($tsv, $ln, 'set_key');
            $osis    = HeadingTsv::field($tsv, $ln, 'book_osis');
            $chapter = (int) HeadingTsv::field($tsv, $ln, 'chapter');
            $before  = (int) HeadingTsv::field($tsv, $ln, 'before_verse');
            $kind    = HeadingTsv::field($tsv, $ln, 'kind');
            $level   = (int) HeadingTsv::field($tsv, $ln, 'level');
            $text    = HeadingTsv::field($tsv, $ln, 'text');
            $source  = HeadingTsv::field($tsv, $ln, 'source_key');

            $otherSet = ($rowSet !== $set);
            $known    = $booksByOsis->has($osis);
            $accepted = isset($acceptedLines[$ln['n']]);
            $dupe     = isset($dupeLines[$ln['n']]);
            $gkey     = (! $otherSet && $known && $chapter >= 1 && $before >= 1) ? "{$osis}|{$chapter}|{$before}" : null;

            $lines[] = [
                'n' => $ln['n'], 'type' => 'data', 'raw' => $ln['raw'],
                'osis' => $osis, 'book' => $bookName[$osis] ?? $osis,
                'chapter' => $chapter, 'before' => $before, 'kind' => $kind, 'level' => $level,
                'text' => $text, 'source' => $source, 'pos' => $canonPos[$osis] ?? null, 'gkey' => $gkey,
                'other' => $otherSet, 'accepted' => $accepted, 'dupe' => $dupe,
                'invalid' => (! $otherSet && ! $accepted && ! $dupe),
                'mirror_missing' => isset($mirrorMissing[$ln['n']]),
            ];
        }

        $countByOsis = [];
        foreach ($v['rows'] as $r) {
            $countByOsis[$r['osis']] = ($countByOsis[$r['osis']] ?? 0) + 1;
        }
        $books = [];
        foreach ($countByOsis as $osis => $count) {
            $books[] = ['osis' => $osis, 'name' => $bookName[$osis] ?? $osis, 'count' => $count, 'pos' => $canonPos[$osis] ?? PHP_INT_MAX];
        }
        usort($books, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return [
            'ok'     => true,
            'path'   => $path,
            'set'    => $set,
            'mtime'  => filemtime($path),
            'counts' => [
                'rows'         => count($v['rows']),
                'skipped_set'  => $v['skipped_set'],
                'skipped_book' => $v['skipped_book'],
                'skipped_kind' => $v['skipped_kind'],
                'skipped_bad'  => $v['skipped_bad'],
            ],
            'books'          => array_map(fn ($b) => ['osis' => $b['osis'], 'name' => $b['name'], 'count' => $b['count']], $books),
            'dupes'          => $v['dupes'],
            'warnings'       => $v['warnings'],
            'xref_order'     => $xrefIssues,
            'mirror_issues'  => $mirrorIssues,          // array, for the report card
            'mirror_missing' => $mirrorMissing,         // line => "seg; seg", for viewer/detail
            'lines'          => $lines,
            'canon_pos'      => $canonPos,
            'book_names'     => $bookName,
            'source_keys'    => array_keys(config('heading_sources', [])),
        ];
    }

    // ---- Reference resolution (jump box + xref links) -------------------

    public function resolve(Request $request): JsonResponse
    {
        $this->guard();

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['ok' => false]);
        }

        $q = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $q);

        $resolver = new ReferenceResolver($this->bookLookup(), config('canon.chapter_remaps', []));

        $parsed = $resolver->parse($q);
        if (! $parsed) {
            $head = trim(explode('-', $q, 2)[0]);
            if ($head !== '' && $head !== $q) {
                $parsed = $resolver->parse($head);
            }
        }
        if (! $parsed) {
            return response()->json(['ok' => false]);
        }

        $book = Book::findBySlug($parsed['book']);
        if (! $book) {
            return response()->json(['ok' => false]);
        }

        $verse = null;
        if (($parsed['type'] ?? '') === 'passage' && preg_match('/\d+/', $parsed['verses'] ?? '', $m)) {
            $verse = (int) $m[0];
        }

        return response()->json([
            'ok'      => true,
            'osis'    => $book->osis_id,
            'name'    => $book->name,
            'chapter' => $parsed['chapter'] ?? null,
            'verse'   => $verse,
        ]);
    }

    private function bookLookup(): array
    {
        $lookup = [];
        foreach (Book::all(['slug', 'name', 'short_name']) as $b) {
            foreach ([$b->name, $b->short_name, $b->slug, str_replace('-', ' ', $b->slug)] as $variant) {
                if ($variant) {
                    $lookup[ReferenceResolver::key($variant)] = $b->slug;
                }
            }
        }
        foreach (config('book_aliases', []) as $alias => $slug) {
            $lookup[ReferenceResolver::key($alias)] = $slug;
        }
        return $lookup;
    }
}