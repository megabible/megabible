<?php

namespace App\Support;

/**
 * Parses the parenthetical target list inside a cross-reference heading's text,
 * e.g.  "(Psalms 14:1–7; Isaiah 59:1–17)"  into an ordered list of targets:
 *   [ ['name'=>'Psalms', 'chapter'=>14, 'chapter_end'=>14, 'segment'=>'Psalms 14:1–7'],
 *     ['name'=>'Isaiah', 'chapter'=>59, 'chapter_end'=>59, 'segment'=>'Isaiah 59:1–17'] ]
 *
 * Handles four locator shapes after the book name:
 *   "11:4"        section verse            → chapter 11..11
 *   "16:22–30"    verse range in a chapter → chapter 16..16
 *   "27–50"       CHAPTER range            → chapter 27..50
 *   "1:1–2:3"     cross-chapter range      → chapter 1..2
 * Dashes may be hyphen, en dash, em dash, or minus.
 *
 * Deliberately dumb about which books exist — it splits the string and pulls
 * out each segment's book NAME and chapter span. Resolving names to real books
 * (and canon positions) is the caller's job. An unreadable segment yields
 * ['name'=>null].
 */
class CrossRef
{
    /**
     * @return array<int, array{name: ?string, chapter: ?int, chapter_end: ?int, segment: string}>
     */
    public static function parseTargets(string $text): array
    {
        $inner = self::innerText($text);
        if ($inner === '') {
            return [];
        }

        $out = [];
        foreach (explode(';', $inner) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $name = null;
            $start = null;
            $end   = null;

            // Book name = text up to the first "space then digit"; the rest is
            // the locator. Non-greedy so "1 Chronicles 1:4-27" keeps its name.
            if (preg_match('/^(.+?)\s+(\d.*)$/u', $segment, $m)) {
                $name = trim($m[1]);
                [$start, $end] = self::parseLocator($m[2]);
            }

            $out[] = [
                'name'        => $name,
                'chapter'     => $start,
                'chapter_end' => $end ?? $start,
                'segment'     => $segment,
            ];
        }

        return $out;
    }

    /** Strip one outer paren pair and trim. */
    public static function innerText(string $text): string
    {
        $inner = trim($text);
        if (str_starts_with($inner, '(')) {
            $inner = substr($inner, 1);
        }
        if (str_ends_with($inner, ')')) {
            $inner = substr($inner, 0, -1);
        }
        return trim($inner);
    }

    /**
     * A locator like "27-50" or "16:22-30" or "1:1-2:3" → [startChapter, endChapter].
     */
    private static function parseLocator(string $loc): array
    {
        $loc = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', trim($loc));
        if ($loc === '') {
            return [null, null];
        }

        // Has a colon → chapter:verse, possibly spanning chapters ("C:V-C2:V2").
        if (strpos($loc, ':') !== false) {
            if (! preg_match('/^\s*(\d+)\s*:/', $loc, $m)) {
                return [null, null];
            }
            $start = (int) $m[1];
            $end   = $start;
            if (preg_match('/-\s*(\d+)\s*:/', $loc, $m2)) {   // "-C2:" → cross-chapter
                $end = (int) $m2[1];
            }
            return [$start, $end];
        }

        // No colon → single chapter, or a chapter range "C-C2".
        if (preg_match('/^\s*(\d+)(?:\s*-\s*(\d+))?/', $loc, $m)) {
            $start = (int) $m[1];
            $end   = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : $start;
            return [$start, $end];
        }

        return [null, null];
    }

    /**
     * Wrap an ordered list of segment strings back into "(a; b; c)". Segment
     * text (verse ranges, en dashes) rides through untouched — used by Canonize
     * Order, which only changes the segments' order.
     *
     * @param array<int, string> $segments
     */
    public static function rebuild(array $segments): string
    {
        return '(' . implode('; ', array_map('trim', $segments)) . ')';
    }
}