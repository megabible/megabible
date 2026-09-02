<?php

namespace App\Support;

/**
 * Turns a search-box string into a Bible-reference intent, or null if the
 * string isn't a reference (in which case the caller falls back to full-text
 * search).
 *
 * It is deliberately dumb about the database: it only knows which book NAMES
 * exist (a normalized lookup map passed in). Whether a chapter or verse really
 * exists in a translation is the controller's job — it has the DB. That keeps
 * this class pure and easy to test.
 *
 * Returns one of:
 *   ['type' => 'book',    'book' => 'john']
 *   ['type' => 'chapter', 'book' => 'john', 'chapter' => 1]
 *   ['type' => 'passage', 'book' => 'john', 'chapter' => 1, 'verses' => '9-15,20']
 *   null
 *
 * Verse lists are CLAMPED and MERGED, never enumerated — see verseRanges().
 * A 'verses' string that comes out of here is guaranteed to describe at most
 * MAX_SEGMENTS ranges, none of which extends past MAX_VERSE.
 */
class ReferenceResolver
{
    /**
     * Hard ceiling on any verse number we will accept from user input.
     *
     * The longest chapter in anything the site carries is Psalm 119 at 176
     * verses, so 999 is absurdly generous headroom — its only job is to stop
     * "john 3:1-999999999" from describing a hundred-million-verse span. Any
     * endpoint above this is silently pulled down to it.
     */
    public const MAX_VERSE = 999;

    /**
     * Most comma-separated segments we will read out of one verse list.
     * Extras are dropped. SearchController already trims queries to 200
     * characters, which caps segments at roughly this number anyway; the
     * constant is here so the guard travels with the parser rather than
     * depending on a caller that might one day not trim (an API, a job).
     */
    public const MAX_SEGMENTS = 50;

    /**
     * @param array<string,string> $lookup   normalized book name => slug
     * @param array<int,array>     $remaps   chapter-window redirects; see remap()
     */
    public function __construct(
        private array $lookup,
        private array $remaps = [],
    ) {}

    public function parse(string $query): ?array
    {
        // Normalize, then "loosen" so "john1" and "1john" split correctly.
        $q = self::loosen(self::key($query));
        if ($q === '') {
            return null;
        }

        // Whole-string match FIRST, so "Psalm 151" (a one-chapter book) wins over
        // "Psalm" + chapter 151, and "1 John" resolves to the book, not "1" + ch.
        if (isset($this->lookup[$q])) {
            return ['type' => 'book', 'book' => $this->lookup[$q]];
        }

        // book (as little as possible) + optional [ chapter [ : verses ] ].
        // The required space before the chapter keeps "1 john" together.
        $ok = preg_match(
            '/^(?<book>.+?)(?:\s+(?<chapter>\d+)(?:\s*:\s*(?<verses>[\d\s,\-]+))?)?$/u',
            $q,
            $m
        );
        if (! $ok) {
            return null;
        }

        $slug = $this->lookup[self::key($m['book'])] ?? null;
        if ($slug === null) {
            return null;   // unrecognized book → not a reference
        }

        $chapter = isset($m['chapter']) && $m['chapter'] !== '' ? (int) $m['chapter'] : null;
        if ($chapter === null) {
            return ['type' => 'book', 'book' => $slug];
        }

        $verses = isset($m['verses']) ? self::normalizeVerses($m['verses']) : '';
        if ($verses === '') {
            return $this->remap(['type' => 'chapter', 'book' => $slug, 'chapter' => $chapter]);
        }

        return $this->remap(['type' => 'passage', 'book' => $slug, 'chapter' => $chapter, 'verses' => $verses]);
    }

    /**
     * Parse a string that may hold SEVERAL references at once and return every
     * one it can resolve, in the order typed. Handles any mix of separators —
     * "John 3:16, Mark 2:23", "John 3:16; Mark 2:23", "John 3:16. Mark 2:23",
     * and plain "John 3:16 Mark 2:23" all yield the same two references.
     *
     * A verse list inside one reference ("John 3:16,17") is preserved; only the
     * commas that sit between references are treated as separators.
     *
     * @return array<int, array>  each element is a parse() result
     */
    public function parseMany(string $query): array
    {
        $refs = [];
        foreach ($this->splitReferences($query) as $chunk) {
            if ($parsed = $this->parse($chunk)) {
                $refs[] = $parsed;
            }
        }
        return $refs;
    }

    /**
     * Break a multi-reference string into individual reference substrings.
     *
     * The scan walks left to right and CONSUMES each reference whole — book
     * name, then its chapter[:verses] tail — before looking for the next book.
     * This is what stops a numbered book from stealing a digit that's already
     * spoken for: in "mark 6:3 john 6:3", Mark's tail eats the ":3", so the
     * next scan starts at "john" and can never see "3 john". (The previous
     * version sliced between book-name POSITIONS, so the epistle "3 john" in
     * the alternation matched Mark's verse number — mangling both references.)
     *
     * The tail's verse list is strict — digits, ranges, comma-joined — with no
     * bare space-separated digits, so "luke 6:3 3 john 4" still yields Luke 6:3
     * followed by 3 John 4: Luke's tail stops at the space, and the free "3"
     * is there for the epistle to claim.
     *
     * Junk guard: if anything OTHER than whitespace sits before, between, or
     * after the consumed references, the whole string is declared "not a
     * reference list" ([]), and the caller falls through to full-text search.
     * Without this, a prose query that merely CONTAINS a book name — "love
     * mark", "did john write revelation" — would teleport the reader to a book
     * hub instead of searching. References fire only when the entire query is
     * references.
     *
     * @return array<int, string>
     */
    private function splitReferences(string $query): array
    {
        $q = self::loosen(self::key($query));
        if ($q === '') {
            return [];
        }

        // Commas: keep the ones between two digits (verse lists like "16,17");
        // treat every other comma as a separator. Strip spaces around commas
        // first so the digit test is a simple fixed-width lookaround.
        $q = preg_replace('/\s*,\s*/u', ',', $q);
        $q = preg_replace('/(?<!\d),|,(?!\d)/u', ' ', $q);

        // Semicolons and periods always separate references.
        $q = preg_replace('/[;.]+/u', ' ', $q);
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        if ($q === '') {
            return [];
        }

        // Build one big alternation of every known book name, longest first so
        // "song of solomon" beats "song" and "1 john" beats "john" when two
        // names could match at the same spot.
        $names = array_keys($this->lookup);
        if ($names === []) {
            return [];
        }
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names));

        // A book name on whole-word boundaries…
        $bookRe = '/(?<!\w)(?:' . $alt . ')(?!\w)/u';

        // …and the chapter[:verses] tail that may follow it. \G anchors the
        // match at exactly the offset we pass, so the tail must sit flush
        // against the book name (whitespace allowed). Verses: number or range,
        // then any number of comma-joined numbers/ranges.
        $tailRe = '/\G\s+\d+(?:\s*:\s*\d+(?:\s*-\s*\d+)?(?:\s*,\s*\d+(?:\s*-\s*\d+)?)*)?/u';

        $chunks = [];
        $pos    = 0;       // scan cursor (byte offset — consistent with substr/strlen)
        $junk   = false;   // anything non-space found outside a reference?

        while (preg_match($bookRe, $q, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];

            // Words between the previous reference and this book name → junk.
            if (trim(substr($q, $pos, $start - $pos)) !== '') {
                $junk = true;
            }

            $cursor = $start + strlen($m[0][0]);
            if (preg_match($tailRe, $q, $t, 0, $cursor)) {
                $cursor += strlen($t[0]);
            }

            $chunks[] = substr($q, $start, $cursor - $start);
            $pos = $cursor;
        }

        // Trailing words after the last reference → junk. Also covers the
        // "no book matched at all" case, where $pos is still 0.
        if (trim(substr($q, $pos)) !== '') {
            $junk = true;
        }

        return $junk ? [] : $chunks;
    }

    /**
     * Redirect chapter/passage hits that fall inside a configured window onto
     * another book, shifting the chapter number. This is what lets "Psalm 152"
     * — which parses as psalms:152 — resolve to the Five Psalms of David at
     * its real chapter 2. Book-type hits (no chapter) pass through untouched;
     * out-of-window chapters ("Psalm 90") pass through untouched. Verse lists
     * ride along unchanged, since only the chapter shifts.
     *
     * Each remap entry: ['book'=>fromSlug, 'from'=>int, 'to'=>int,
     *                     'target'=>toSlug, 'offset'=>int].
     */
    private function remap(array $ref): array
    {
        foreach ($this->remaps as $r) {
            if ($ref['book'] === ($r['book'] ?? null)
                && $ref['chapter'] >= ($r['from'] ?? PHP_INT_MAX)
                && $ref['chapter'] <= ($r['to'] ?? PHP_INT_MIN)
            ) {
                $ref['book']    = $r['target'] ?? $ref['book'];
                $ref['chapter'] = $ref['chapter'] + ($r['offset'] ?? 0);
                return $ref;
            }
        }
        return $ref;
    }

    /** Lowercase + collapse whitespace. Used to BUILD the lookup and to MATCH. */
    public static function key(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    }

    /** Space out letter/digit boundaries: "john1" → "john 1", "1john" → "1 john". */
    private static function loosen(string $s): string
    {
        $s = preg_replace('/([a-z])(\d)/u', '$1 $2', $s);
        $s = preg_replace('/(\d)([a-z])/u', '$1 $2', $s);
        return $s;
    }

    /**
     * Parse a verse list into clamped, sorted, merged [start, end] pairs.
     *
     *   "20, 9-15"  →  [[9, 15], [20]]        (as [9,15] and [20,20])
     *   "9-15,14-20"→  [[9, 20]]              (overlapping, merged)
     *   "1-3,4"     →  [[1, 4]]               (adjacent, merged)
     *   "1-999999999" → [[1, 999]]            (clamped — see MAX_VERSE)
     *
     * THIS IS THE ONE PLACE VERSE INPUT IS SANITISED, and it works on the
     * RANGES rather than the verses inside them. The previous implementation
     * marked every verse in a range into a keyed array, which meant the cost
     * of parsing scaled with the SIZE OF THE SPAN, not the length of the
     * input: "psalms 119:1-999999999" spun a hundred-million-iteration loop
     * on an unauthenticated GET before the database was ever touched. Nothing
     * in here may ever loop over a span again.
     *
     * Public because SearchController needs the same intervals to build SQL —
     * one parser, one grammar, two consumers.
     *
     * @return array<int, array{0:int, 1:int}>  disjoint, ascending, non-adjacent
     */
    public static function verseRanges(string $raw): array
    {
        $parts  = preg_split('/\s*,\s*/u', trim($raw));
        $parts  = array_slice($parts, 0, self::MAX_SEGMENTS);
        $ranges = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $start = self::clampVerse($m[1]);
                $end   = self::clampVerse($m[2]);
                if ($start > $end) {
                    [$start, $end] = [$end, $start];   // "15-9" reads as "9-15"
                }
            } elseif (preg_match('/^\d+$/', $part)) {
                $start = $end = self::clampVerse($part);
            } else {
                continue;   // not a number or a range — ignore it
            }

            $ranges[] = [$start, $end];
        }

        if ($ranges === []) {
            return [];
        }

        // PHP compares the [start, end] arrays element-by-element, so this
        // sorts by start and then by end — the same trick the search results
        // use for [order, chapter, verse].
        usort($ranges, fn (array $a, array $b) => $a <=> $b);

        // Merge overlapping AND adjacent ranges, so "1-3,4" collapses to
        // "1-4" exactly as the old enumerate-then-regroup version did.
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * One digit string → a verse number no larger than MAX_VERSE.
     *
     * The length test comes first on purpose: a 200-digit string has no
     * meaningful integer value, and we would rather never cast it than rely
     * on how PHP saturates an out-of-range numeric string.
     */
    private static function clampVerse(string $digits): int
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;   // "0", "000" — names no verse, but harmless downstream
        }

        if (strlen($digits) > strlen((string) self::MAX_VERSE)) {
            return self::MAX_VERSE;
        }

        return min((int) $digits, self::MAX_VERSE);
    }

    /** "20, 9-15" or "9-15,20" → canonical "9-15,20" (sorted, merged, deduped). */
    private static function normalizeVerses(string $raw): string
    {
        $out = [];

        foreach (self::verseRanges($raw) as [$start, $end]) {
            $out[] = $start === $end ? "$start" : "$start-$end";
        }

        return implode(',', $out);
    }
}
