<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;
use Illuminate\Http\Request;

/**
 * The Terminal easter egg (/extras/terminal).
 *
 * index() hands the view the canon — every book's name, slug, chapter
 * count, and aliases — so the pseudo-CLI can resolve "gen", "1 clement",
 * or "shepherd of hermas" without a round trip.
 *
 * verse() is the tiny JSON endpoint behind print:. Contract (always
 * HTTP 200; the client branches on `ok`):
 *
 *   ok:    { ok: true, ref: "Acts 9:2", tr: "KJV",
 *            verses: [ { v: 2, text: "..." } ] }
 *   error: { ok: false, message: "..." }   ← prints verbatim, dim,
 *                                            in the console. The error
 *                                            wordings live right here —
 *                                            edit them like any other
 *                                            terminal copy.
 */
class TerminalController extends Controller
{
    public function index()
    {
        // chapter_count is a plain column on books (no chapters relation
        // exists — verses hang directly off Book), so no withCount here.
        $terminalBooks = Book::orderBy('book_order')
            ->get()
            ->map(function (Book $b) {
                // Aliases the terminal accepts besides name and slug:
                // the OSIS id ("gen", "1clem") and the short name.
                // Add more per-book aliases here any time (all lowercase).
                $aliases = array_values(array_unique(array_filter([
                    strtolower((string) $b->osis_id),
                    strtolower((string) $b->short_name),
                ])));

                return [
                    'name' => $b->name,
                    'slug' => $b->slug,
                    'ch'   => (int) $b->chapter_count,
                    'al'   => $aliases,
                ];
            })
            ->values();

        return view('extras.terminal', [
            'terminalBooks' => $terminalBooks,
        ]);
    }

    /**
     * GET /extras/terminal/verse?book=acts&c=9&v=2        (reader's translation)
     * GET /extras/terminal/verse?book=acts&c=9&v=2-4&t=kjv (explicit override)
     */
    public function verse(Request $request)
    {
        // Must match the client's PRINT.max in terminal.blade.php.
        $maxVerses = 10;

        $bookSlug = strtolower((string) $request->query('book', ''));
        $chapter  = (int) $request->query('c', 0);
        $vRaw     = (string) $request->query('v', '');
        $tSlug    = strtolower((string) $request->query('t', ''));

        // Every failure is an ok:false with a console-ready message.
        $fail = fn (string $message) => response()->json(['ok' => false, 'message' => $message]);

        // ---- the verse part: "2" or "2-4" ----------------------------
        if (!preg_match('/^(\d{1,3})(?:-(\d{1,3}))?$/', $vRaw, $m)) {
            return $fail('malformed verse reference.');
        }
        $v1 = (int) $m[1];
        $v2 = isset($m[2]) ? (int) $m[2] : $v1;
        if ($v2 < $v1) {
            [$v1, $v2] = [$v2, $v1];
        }
        if (($v2 - $v1 + 1) > $maxVerses) {
            return $fail("the system prints at most {$maxVerses} verses at a time.");
        }

        // ---- the book + chapter --------------------------------------
        $book = Book::findBySlug($bookSlug);
        if (!$book) {
            return $fail('no such book.');
        }
        if ($chapter < 1 || $chapter > $book->chapter_count) {
            return $fail('out of range: ' . strtoupper($book->name) . " has {$book->chapter_count} chapters.");
        }

        // ---- the translation ------------------------------------------
        // Explicit ?t= override wins. Otherwise the reader's remembered
        // translation — the same reader_translation cookie that the
        // RememberTranslation middleware drops on every reader page
        // (decrypted for us automatically by the web middleware group).
        // Fallback: the first global translation by sort order, i.e.
        // whatever the site itself treats as the house default.
        if ($tSlug !== '') {
            $translation = Translation::findBySlug($tSlug);
            if (!$translation) {
                return $fail("unknown translation: {$tSlug}.");
            }
        } else {
            $translation = null;
            if ($remembered = $request->cookie('reader_translation')) {
                $translation = Translation::findBySlug((string) $remembered);
            }
            $translation ??= Translation::where('is_global', true)
                ->orderBy('sort_order')
                ->first();
            if (!$translation) {
                return $fail('no translations installed.');
            }
        }

        $abbr = strtoupper($translation->abbreviation);

        // ---- the display reference ------------------------------------
        // refParts() applies the reader-label overrides (the Five Psalms
        // of David read "Psalm 151".."Psalm 155"), so the terminal shows
        // exactly what the reader would.
        [$refName, $refChapter] = $book->refParts($chapter);
        $ref = $refName . ' ' . $refChapter . ':' . $v1 . ($v2 > $v1 ? '-' . $v2 : '');

        // ---- the verses ------------------------------------------------
        $verses = Verse::where('translation_id', $translation->id)
            ->where('book_id', $book->id)
            ->where('chapter', $chapter)
            ->whereBetween('verse_number', [$v1, $v2])
            ->orderBy('verse_number')
            ->get(['verse_number', 'text']);

        if ($verses->isEmpty()) {
            // Distinguish "this edition doesn't carry the book at all"
            // (KJV has no Shepherd of Hermas) from "the reference walks
            // off the end of the chapter".
            $carriesBook = Verse::where('translation_id', $translation->id)
                ->where('book_id', $book->id)
                ->exists();

            return $fail($carriesBook
                ? "no verses at {$ref} in {$abbr}."
                : "{$abbr} does not contain " . strtoupper($book->name) . '. its canon is narrower. try print:' . strtolower($book->slug) . ' with another translation.');
        }

        return response()->json([
            'ok'     => true,
            'ref'    => $ref,
            'tr'     => $abbr,
            'verses' => $verses->map(fn (Verse $v) => [
                'v'    => $v->verse_number,
                'text' => $v->text,
            ])->values(),
        ]);
    }
}
