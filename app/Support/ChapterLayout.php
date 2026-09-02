<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Reassembles a chapter for reading.
 *
 * The DB stores verses atomically (great for search, permalinks, parallel view)
 * plus a `format` block list for anything with internal structure, and headings
 * in their own table. This class walks those back into the flat, ordered list
 * the chapter view renders — merging consecutive prose verses into shared
 * paragraphs, breaking poetry into its own lines, and slotting headings in.
 *
 * Output is an array of elements, each one of:
 *   ['type' => 'heading', 'kind' => 's|d|r|sr|ms|mr|sp', 'level' => int, 'text' => string]
 *   ['type' => 'para',    'style' => 'p|m|pi|pc|pr', 'verses' => [['n'=>int|null,'vn'=>int,'text'=>string], …]]
 *   ['type' => 'poetry',  'style' => 'q1|q2|q3|q4|qc|qr|qd', 'n' => int|null, 'vn' => int, 'text' => string]
 *   ['type' => 'stanza']
 *
 * `n` (a verse number) is non-null only on the fragment that *begins* a verse,
 * which is where the superscript number + #v{n} anchor go.
 *
 * `vn` (the OWNING verse number) is present on every verse-bearing fragment —
 * including continuation poetry lines and "prose after poetry" fragments whose
 * `n` is null. A single verse can be split across several fragments (prose →
 * poetry → prose), so the reader needs to know which verse each fragment
 * belongs to in order to select, highlight, and collect it as one unit. Headings
 * and stanza breaks are not verses and carry no `vn`.
 *
 * FOOTNOTES: when $footnotesByVerse is passed (verse number => array of
 * ['marker' => 'a', …]), a `notes` key is attached to the LAST text fragment
 * of each annotated verse — the verse-end position where the reading flow
 * renders the superscript letter markers. Footnotes are an overlay: nothing
 * about paragraph merging or poetry splitting changes when they're present,
 * and callers that don't pass them (the parallel view, for now) get the exact
 * same output as before.
 */
class ChapterLayout
{
    /** Block styles that are prose paragraphs (everything else in `format` is poetry). */
    private const PROSE = ['p', 'm', 'pi', 'pc', 'pr'];

    /**
     * @param  Collection  $verses            Verse models for one chapter, ordered by verse_number.
     * @param  Collection  $headings          Heading models for the same chapter.
     * @param  array       $footnotesByVerse  Optional: verse number => [['marker'=>string], …].
     */
    public static function build(Collection $verses, Collection $headings, array $footnotesByVerse = []): array
    {
        // Headings grouped by the verse they precede, in document order.
        $byVerse = $headings->groupBy('before_verse');

        $layout = [];
        $para   = null;   // the prose paragraph currently being accumulated

        $flush = function () use (&$layout, &$para) {
            if ($para !== null && ! empty($para['verses'])) {
                $layout[] = $para;
            }
            $para = null;
        };

        foreach ($verses as $verse) {
            $n = $verse->verse_number;

            // Emit any headings that sit before this verse.
            foreach ($byVerse->get($n, collect()) as $h) {
                $flush();
                $layout[] = [
                    'type'  => 'heading',
                    'kind'  => $h->kind,
                    'level' => $h->level,
                    'text'  => $h->text,
                ];
            }

            // Simple prose verse (format === null): merge into the running paragraph.
            if ($verse->format === null) {
                if ($verse->starts_paragraph || $para === null) {
                    $flush();
                    $para = ['type' => 'para', 'style' => 'p', 'verses' => []];
                }
                $para['verses'][] = ['n' => $n, 'vn' => $n, 'text' => $verse->text];

                if (! empty($footnotesByVerse[$n])) {
                    self::attachNotes($layout, $para, $n, $footnotesByVerse[$n]);
                }
                continue;
            }

            // Structured verse: walk its blocks.
            $first = true;
            foreach ($verse->format as $block) {
                $style = $block['s'] ?? 'p';

                if ($style === 'b') {                       // stanza break
                    $flush();
                    $layout[] = ['type' => 'stanza'];
                    $first = false;
                    continue;
                }

                $text = $block['t'] ?? '';

                if (in_array($style, self::PROSE, true)) {
                    if ($first) {
                        // Lead prose: continue the current paragraph unless this
                        // verse explicitly opened a new one.
                        if ($verse->starts_paragraph || $para === null) {
                            $flush();
                            $para = ['type' => 'para', 'style' => $style, 'verses' => []];
                        }
                        $para['verses'][] = ['n' => $n, 'vn' => $n, 'text' => $text];
                    } else {
                        // Prose after poetry within the same verse → its own paragraph.
                        // `n` stays null (no second superscript) but it still belongs
                        // to this verse, so `vn` carries the owning number.
                        $flush();
                        $para = ['type' => 'para', 'style' => $style, 'verses' => [['n' => null, 'vn' => $n, 'text' => $text]]];
                    }
                } else {
                    // Poetry line. First line of the verse shows the number; later
                    // lines don't (`n` null) but still belong to this verse (`vn`).
                    $flush();
                    $layout[] = [
                        'type'  => 'poetry',
                        'style' => $style,
                        'n'     => $first ? $n : null,
                        'vn'    => $n,
                        'text'  => $text,
                    ];
                }

                $first = false;
            }

            if (! empty($footnotesByVerse[$n])) {
                self::attachNotes($layout, $para, $n, $footnotesByVerse[$n]);
            }
        }

        $flush();
        return $layout;
    }

    /**
     * Attach a verse's footnote markers to its LAST text fragment, wherever
     * that fragment currently lives: in the still-open paragraph accumulator,
     * or already flushed into the layout (poetry lines, closed paragraphs).
     * Runs immediately after the owning verse is processed, so the fragment is
     * always near the tail — the backward walk is a few steps at most.
     */
    private static function attachNotes(array &$layout, ?array &$para, int $n, array $notes): void
    {
        // Most common case: the verse's last fragment is in the open paragraph.
        if ($para !== null && ! empty($para['verses'])) {
            $last = array_key_last($para['verses']);
            if (($para['verses'][$last]['vn'] ?? null) === $n) {
                $para['verses'][$last]['notes'] = $notes;
                return;
            }
        }

        // Otherwise walk backwards past stanza breaks to the verse's last
        // flushed fragment (a poetry line, or the tail of a closed paragraph).
        for ($i = count($layout) - 1; $i >= 0; $i--) {
            $el = $layout[$i];

            if (($el['type'] ?? '') === 'stanza') {
                continue;   // a trailing \b — the verse text sits just above it
            }
            if (($el['type'] ?? '') === 'poetry' && ($el['vn'] ?? null) === $n) {
                $layout[$i]['notes'] = $notes;
                return;
            }
            if (($el['type'] ?? '') === 'para') {
                $last = array_key_last($el['verses']);
                if (($el['verses'][$last]['vn'] ?? null) === $n) {
                    $layout[$i]['verses'][$last]['notes'] = $notes;
                }
                return;   // found the previous text either way — stop
            }
            return;       // hit a heading or anything else — verse isn't here
        }
    }
}