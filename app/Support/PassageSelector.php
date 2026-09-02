<?php

namespace App\Support;

use App\Models\Book;
use App\Models\Translation;
use App\Models\TypingPassage;
use App\Models\Verse;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The "what do we make the player type?" brain.
 *
 * Two jobs:
 *   • freePlay()  — exact, user-chosen text (book→chapter→verse range). Transient,
 *                   never stored, never ranked. For practising a memory verse.
 *   • ranked()    — a length-controlled chunk for the leaderboard. Stored + reused
 *                   so every pull is on record and great ones can be curated.
 *
 * Verse text comes from the `verses.text` column, which the importer already
 * cleaned (tags stripped, whitespace collapsed to single spaces). So a chunk is
 * just consecutive verse texts joined with a single space — exactly what shows
 * in the reader, which is what we want the player typing.
 */
class PassageSelector
{
    /**
     * FREE PLAY — exact selection. Returns a transient payload (no DB row).
     *
     * @return array{text:string,reference:string,word_count:int,char_count:int}
     */
    public function freePlay(Translation $t, Book $b, int $chapter, int $verseStart, ?int $verseEnd): array
    {
        $verseEnd = $verseEnd ?: $verseStart;
        if ($verseEnd < $verseStart) {
            [$verseStart, $verseEnd] = [$verseEnd, $verseStart];
        }

        $verses = Verse::where('translation_id', $t->id)
            ->where('book_id', $b->id)
            ->where('chapter', $chapter)
            ->whereBetween('verse_number', [$verseStart, $verseEnd])
            ->orderBy('verse_number')
            ->get();

        if ($verses->isEmpty()) {
            throw new RuntimeException('That passage is not available in this translation.');
        }

        $text = $this->joinText($verses);

        return [
            'text'       => $text,
            'reference'  => $this->labelFor($b, $verses),
            'word_count' => $this->countWords($text),
            'char_count' => mb_strlen($text),
        ];
    }

    /**
     * RANKED — returns a stored, reusable TypingPassage.
     *
     * @param  string     $tier        sprint | standard | endurance
     * @param  string     $difficulty  normal | hard   (chooses the translation)
     * @param  Book|null  $book        set = "roulette" (lock to one book); null = whole Bible
     */
    public function ranked(string $tier, string $difficulty, ?Book $book = null): TypingPassage
    {
        $tiers = config('typing.tiers');
        if (! array_key_exists($tier, $tiers)) {
            throw new RuntimeException("Unknown tier: {$tier}");
        }

        $t = $this->translationForDifficulty($difficulty);

        // Curation: if generation is locked and we have blessed pulls that match,
        // serve one of those instead of building something new.
        if (config('typing.lock_generation')) {
            $curated = $this->curatedPool($t, $tier, $book)->inRandomOrder()->first();
            if ($curated) {
                $curated->increment('times_served');
                return $curated;
            }
            // else: empty pool → fall through and generate, so the game never
            // leaves the player staring at nothing.
        }

        // Build a fresh chunk.
        $built = $tier === 'endurance'
            ? $this->buildChapter($t, $book)
            : $this->buildChunk($t, $book, (int) $tiers[$tier]);

        return $this->store($t, $built, $tier);
    }

    /* ===================================================================== */
    /*  Difficulty / translation resolution                                  */
    /* ===================================================================== */

    public function translationForDifficulty(string $difficulty): Translation
    {
        $map = config('typing.difficulty_translation');
        $abbr = $map[$difficulty] ?? null;

        if (! $abbr) {
            throw new RuntimeException("Unknown difficulty: {$difficulty}");
        }

        $t = Translation::findBySlug($abbr);
        if (! $t) {
            throw new RuntimeException(
                "The {$abbr} translation isn't loaded yet, so this difficulty is unavailable."
            );
        }

        return $t;
    }

    /* ===================================================================== */
    /*  Chunk builders                                                       */
    /* ===================================================================== */

    /**
     * Walk consecutive verses from a random start until we cross the word target.
     * Stays inside ONE book (never splices the end of one book onto the next).
     *
     * @return array{verses:Collection,text:string,reference:string,word_count:int}
     */
    private function buildChunk(Translation $t, ?Book $book, int $targetWords): array
    {
        $start = $this->randomStartVerse($t, $book);
        $bookId = $start->book_id;

        // Pull a small forward window by reading order (sort_key) within the same
        // book. 12 verses is far more than ~50 words ever needs, but cheap.
        $window = Verse::where('translation_id', $t->id)
            ->where('book_id', $bookId)
            ->where('sort_key', '>=', $start->sort_key)
            ->orderBy('sort_key')
            ->limit(12)
            ->get();

        $picked = collect();
        $words = 0;
        foreach ($window as $v) {
            $picked->push($v);
            $words += $this->countWords($v->text);
            if ($words >= $targetWords) {
                break;
            }
        }

        // If a single opening verse already blew past the target, that's fine —
        // we keep just it rather than padding. (Sprint on a long verse = one verse.)
        $text = $this->joinText($picked);
        $b = $book ?: Book::find($bookId);

        return [
            'verses'     => $picked,
            'text'       => $text,
            'reference'  => $this->labelFor($b, $picked),
            'word_count' => $this->countWords($text),
        ];
    }

    /**
     * ENDURANCE — a whole random chapter (optionally locked to one book).
     *
     * @return array{verses:Collection,text:string,reference:string,word_count:int}
     */
    private function buildChapter(Translation $t, ?Book $book): array
    {
        // Pick a random (book, chapter) that actually exists for this translation.
        $pair = Verse::query()
            ->where('translation_id', $t->id)
            ->when($book, fn ($q) => $q->where('book_id', $book->id))
            ->select('book_id', 'chapter')
            ->distinct()
            ->inRandomOrder()
            ->first();

        if (! $pair) {
            throw new RuntimeException('No chapters are available for this selection.');
        }

        $verses = Verse::where('translation_id', $t->id)
            ->where('book_id', $pair->book_id)
            ->where('chapter', $pair->chapter)
            ->orderBy('verse_number')
            ->get();

        $text = $this->joinText($verses);
        $b = $book ?: Book::find($pair->book_id);

        return [
            'verses'     => $verses,
            'text'       => $text,
            'reference'  => $this->labelFor($b, $verses),
            'word_count' => $this->countWords($text),
        ];
    }

    /** A random verse to start a chunk from, constrained to translation [+ book]. */
    private function randomStartVerse(Translation $t, ?Book $book): Verse
    {
        $verse = Verse::where('translation_id', $t->id)
            ->when($book, fn ($q) => $q->where('book_id', $book->id))
            ->inRandomOrder()
            ->first();

        if (! $verse) {
            throw new RuntimeException('No verses are available for this selection.');
        }

        return $verse;
    }

    /* ===================================================================== */
    /*  Storage + dedupe                                                     */
    /* ===================================================================== */

    /**
     * Persist a built chunk, collapsing identical pulls onto one row (and bumping
     * times_served) via the text hash.
     */
    private function store(Translation $t, array $built, string $mode): TypingPassage
    {
        /** @var Collection $verses */
        $verses = $built['verses'];
        $first  = $verses->first();
        $last   = $verses->last();
        $hash   = sha1($this->normalise($built['text']));

        $existing = TypingPassage::where('text_hash', $hash)->first();
        if ($existing) {
            $existing->increment('times_served');
            return $existing;
        }

        return TypingPassage::create([
            'translation_id'  => $t->id,
            'book_id'         => $first->book_id,
            'chapter_start'   => $first->chapter,
            'verse_start'     => $first->verse_number,
            'chapter_end'     => $last->chapter,
            'verse_end'       => $last->verse_number,
            'reference_label' => $built['reference'],
            'text'            => $built['text'],
            'text_hash'       => $hash,
            'word_count'      => $built['word_count'],
            'char_count'      => mb_strlen($built['text']),
            'mode'            => $mode,
            'times_served'    => 1,
            'is_curated'      => false,
        ]);
    }

    /** Curated pool matching a ranked request (used when generation is locked). */
    private function curatedPool(Translation $t, string $mode, ?Book $book)
    {
        return TypingPassage::query()
            ->where('translation_id', $t->id)
            ->where('mode', $mode)
            ->where('is_curated', true)
            ->when($book, fn ($q) => $q->where('book_id', $book->id));
    }

    /* ===================================================================== */
    /*  Small helpers                                                        */
    /* ===================================================================== */

    /** Join verse texts the way the reader shows them: single spaces between. */
    private function joinText(Collection $verses): string
    {
        return trim($verses->pluck('text')->implode(' '));
    }

    /** Build "Romans 8:28–30", "Psalms 23", or cross-chapter "Romans 8:28–9:2". */
    private function labelFor(Book $b, Collection $verses): string
    {
        $first = $verses->first();
        $last  = $verses->last();

        $start = "{$first->chapter}:{$first->verse_number}";

        if ($first->chapter === $last->chapter) {
            $end = $first->verse_number === $last->verse_number
                ? null
                : (string) $last->verse_number;                 // same chapter
        } else {
            $end = "{$last->chapter}:{$last->verse_number}";     // crosses chapters
        }

        $ref = $end ? "{$start}\u{2013}{$end}" : $start;          // en dash
        return "{$b->name} {$ref}";
    }

    private function countWords(string $text): int
    {
        $text = trim($text);
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    /** Normalise for hashing only (so trivial spacing diffs dedupe together). */
    private function normalise(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text));
    }
}
