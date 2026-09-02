<?php

namespace App\Support;

use App\Models\Book;
use App\Models\Translation;
use App\Models\Verse;

/**
 * Challenge — a URL-defined typing challenge, resolved and canonicalised.
 *
 * THE URL CONTRACT (query params; the future UI builds and shares these):
 *
 *   SCRIMMAGE     ?mode=scrimmage&t=kjv&b=romans&c=8&v=1
 *     One verse, one fixed clock (config typing.challenge.scrimmage_duration,
 *     20s). The verse text is the wrap unit — finish it and it repeats until
 *     the clock runs out. The duration is NOT a URL param, but it stays in the
 *     canonical identity (…|d20) so a future retune spawns fresh boards
 *     instead of corrupting old ones.
 *
 *   VERSE TRIAD   ?mode=triad&t=kjv&p=romans.8.1_john.3.16_psalms.23.1
 *     Exactly three verses in the CHALLENGER'S CHOSEN ORDER (order is part of
 *     the challenge identity — arranging them is the point), typed
 *     consecutively, joined by single spaces. Total length is capped
 *     (config typing.challenge.triad_max_chars) so nobody gets challenged to
 *     the three longest verses in Esther.
 *
 * CANONICAL IDENTITY: fromParams() normalises everything (lowercased slugs,
 * integer chapter/verse, validated duration, the translation's language from
 * translations.language), then canonical() renders one
 * unambiguous string and key() hashes it. `?v=1&c=8` and `?c=8&v=1` are the
 * same challenge; a leaderboard hangs off the key with no pre-registration.
 *
 * SERVER RESOLVES THE TEXT, ALWAYS. The client never supplies text — params
 * in, verse rows out, 422 (RuntimeException, caught by the controller) when
 * anything doesn't exist. A `score=` param in a shared URL is display-only
 * bragging and never touches this class.
 */
class Challenge
{
    public const MODES = ['scrimmage', 'triad', 'daily'];

    public string      $mode;
    public Translation $translation;
    public string      $txSlug;

    /**
     * ISO 639-1 language of the translation ('en', 'es'), read from
     * translations.language. Part of SCRIMMAGE identity: boards are shared
     * across every edition of one LANGUAGE, and each language gets its own
     * board (the /scrimboard-en URL suffix). Triads don't use it — their
     * identity already carries the exact edition.
     */
    public string $lang = 'en';

    /** @var array<int, array{book: Book, chapter: int, verse: int, text: string}> */
    public array $refs = [];

    /** Scrimmage/daily tier in seconds; null for triad. */
    public ?int $duration = null;

    /**
     * DAILY ONLY: the day this challenge belongs to (Y-m-d, site clock).
     * It is part of the identity, which is the whole point of the mode — a
     * new date is a new key, so every day opens a virgin board that no
     * previous champion defends, and yesterday's is frozen rather than
     * trimmed. Null for every other mode.
     */
    public ?string $date = null;

    /** The full typing target (triad: verse texts joined by single spaces). */
    public string $text = '';
    public int    $charCount = 0;

    /** e.g. "Romans 8:1" or "Romans 8:1 + John 3:16 + Psalms 23:1" */
    public string $referenceLabel = '';

    private function __construct()
    {
    }

    /* ===================================================================== */
    /*  Construction                                                         */
    /* ===================================================================== */

    /**
     * Build from raw query params. Throws RuntimeException with a
     * user-facing message on any invalid input — the controller maps
     * those to 422s, mirroring PassageSelector's convention.
     */
    public static function fromParams(array $params): self
    {
        $c = new self();

        $mode = strtolower(trim((string) ($params['mode'] ?? '')));
        if (! in_array($mode, self::MODES, true)) {
            throw new \RuntimeException('Unknown challenge mode.');
        }
        $c->mode = $mode;

        $t = Translation::findBySlug((string) ($params['t'] ?? ''));
        if (! $t) {
            throw new \RuntimeException('Unknown translation.');
        }
        $c->translation = $t;
        $c->txSlug = strtolower($t->abbreviation);
        // Defensive fallback: a blank/missing column value still yields a
        // valid key rather than an empty identity segment.
        $c->lang = strtolower(trim((string) ($t->language ?? ''))) ?: 'en';

        if ($mode === 'scrimmage') {
            $c->buildScrimmage($params);
        } elseif ($mode === 'daily') {
            $c->buildDaily($params);
        } else {
            $c->buildTriad($params);
        }

        return $c;
    }

    private function buildScrimmage(array $params): void
    {
        // Every scrimmage runs the same clock; a `d` param, if present on an
        // old shared URL, is simply ignored.
        $this->duration = (int) config('typing.challenge.scrimmage_duration');

        $ref = $this->resolveRef(
            (string) ($params['b'] ?? ''),
            (int) ($params['c'] ?? 0),
            (int) ($params['v'] ?? 0)
        );
        $this->refs = [$ref];

        // The single verse IS the target; the client wraps it until time runs
        // out, so charCount here is the length of one pass (the wrap unit).
        $this->text = $ref['text'];
        $this->charCount = mb_strlen($this->text);
        $this->referenceLabel = mb_substr(self::labelFor($ref), 0, 120);
    }

    /**
     * DAILY — one verse, the same verse for everyone, for one day only.
     *
     * Mechanically identical to a scrimmage (same clock, same wrap unit, same
     * scoring); the ONLY difference is that the date joins the identity. The
     * verse is not chosen by the client — the caller reads it from the
     * daily_verses ledger and passes it in — but it is re-validated here like
     * any other reference, because a token is minted from whatever this
     * returns.
     */
    private function buildDaily(array $params): void
    {
        $this->duration = (int) config('typing.challenge.scrimmage_duration');

        $date = trim((string) ($params['date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \RuntimeException('A daily challenge needs a date (YYYY-MM-DD).');
        }
        $this->date = $date;

        $ref = $this->resolveRef(
            (string) ($params['b'] ?? ''),
            (int) ($params['c'] ?? 0),
            (int) ($params['v'] ?? 0)
        );
        $this->refs = [$ref];

        $this->text = $ref['text'];
        $this->charCount = mb_strlen($this->text);
        $this->referenceLabel = mb_substr(self::labelFor($ref), 0, 120);
    }

    private function buildTriad(array $params): void
    {
        $need = (int) config('typing.challenge.triad_refs');

        $p = trim((string) ($params['p'] ?? ''));
        if ($p === '') {
            throw new \RuntimeException('Missing verse list (p=book.chapter.verse_…).');
        }

        $parts = explode('_', $p);
        if (count($parts) !== $need) {
            throw new \RuntimeException("A triad needs exactly {$need} verses.");
        }

        foreach ($parts as $part) {
            if (! preg_match('/^([a-z0-9-]+)\.(\d+)\.(\d+)$/', strtolower(trim($part)), $m)) {
                throw new \RuntimeException("Malformed verse reference: \"{$part}\". Use book.chapter.verse.");
            }
            $this->refs[] = $this->resolveRef($m[1], (int) $m[2], (int) $m[3]);
        }

        // Consecutive typing target: verse texts joined by single spaces —
        // the same join the vigil uses between fragments.
        $this->text = implode(' ', array_column($this->refs, 'text'));
        $this->charCount = mb_strlen($this->text);

        $cap = (int) config('typing.challenge.triad_max_chars');
        if ($this->charCount > $cap) {
            throw new \RuntimeException(
                "That triad is {$this->charCount} characters — the limit is {$cap}. Pick shorter verses."
            );
        }

        $this->referenceLabel = mb_substr(
            implode(' + ', array_map([self::class, 'labelFor'], $this->refs)),
            0, 120
        );
    }

    /** Resolve one (book slug, chapter, verse) in this translation or throw. */
    private function resolveRef(string $bookSlug, int $chapter, int $verse): array
    {
        $bookSlug = strtolower(trim($bookSlug));
        if ($bookSlug === '' || $chapter < 1 || $verse < 1) {
            throw new \RuntimeException('Each reference needs a book, chapter, and verse.');
        }

        $book = Book::findBySlug($bookSlug);
        if (! $book) {
            throw new \RuntimeException("Unknown book: \"{$bookSlug}\".");
        }

        $row = Verse::where('translation_id', $this->translation->id)
            ->where('book_id', $book->id)
            ->where('chapter', $chapter)
            ->where('verse_number', $verse)
            ->first();

        if (! $row) {
            throw new \RuntimeException(
                "{$book->name} {$chapter}:{$verse} doesn't exist in {$this->translation->abbreviation}."
            );
        }

        return ['book' => $book, 'chapter' => $chapter, 'verse' => $verse, 'text' => $row->text];
    }

    private static function labelFor(array $ref): string
    {
        return $ref['book']->name . ' ' . $ref['chapter'] . ':' . $ref['verse'];
    }

    /* ===================================================================== */
    /*  Identity                                                             */
    /* ===================================================================== */

    /**
     * The canonical string — one unambiguous rendering of this challenge.
     *   scrimmage|en|romans.8.1|d20
     *   triad|kjv|romans.8.1|john.3.16|psalms.23.1
     *
     * SCRIMMAGE identity is PER-LANGUAGE, translation-agnostic within it:
     * every ENGLISH edition of a verse is one challenge with one SCRIMBOARD
     * (/scrimboard-en); Spanish editions, when imported, spawn their own
     * (…|es|… → /scrimboard-es). The edition typed lives on each score row
     * (translation_id → the board's TR column) and inside its per-text
     * difficulty modifier, so harder editions still earn more. Note the key
     * is computable from (lang, book, chapter, verse) ALONE — no translation
     * needed — which is what lets the full-board pages resolve without one.
     * TRIADS keep the exact edition in their identity instead (three texts,
     * one edition, arranged on purpose — the edition is part of the
     * arrangement), which already implies the language.
     *
     * Adding lang was a ONE-TIME KEY RESET (pre-launch sandbox; the
     * add_language_to_translations migration truncated typing_scores).
     * A future `daily` mode slots its date + lang here the same way.
     *
     * Triad ref ORDER IS PRESERVED: the challenger arranged them on purpose,
     * so a re-ordering is a different challenge.
     */
    public function canonical(): string
    {
        if ($this->mode === 'scrimmage') {
            $r = $this->refs[0];
            return self::scrimmageCanonical(
                $this->lang, $r['book']->slug, $r['chapter'], $r['verse'], $this->duration
            );
        }

        if ($this->mode === 'daily') {
            $r = $this->refs[0];
            return self::dailyCanonical(
                $this->date, $this->lang, $r['book']->slug, $r['chapter'], $r['verse'], $this->duration
            );
        }

        // TRIAD — the edition is part of the arrangement, and the refs keep
        // the order the challenger chose.
        $parts = [$this->mode, $this->txSlug];
        foreach ($this->refs as $r) {
            $parts[] = $r['book']->slug . '.' . $r['chapter'] . '.' . $r['verse'];
        }
        if ($this->duration !== null) {
            $parts[] = 'd' . $this->duration;
        }

        return implode('|', $parts);
    }

    /* --------------------------------------------------------------------- */
    /*  Static key builders — identity WITHOUT resolving text                */
    /* --------------------------------------------------------------------- */
    /*
       A scrimmage or daily key is computable from (lang, book, chapter,
       verse [, date]) alone: no translation, no verse lookup, no database.
       That is exactly what the board pages, the archive, and the analytics
       hub need — /extras/scrimmage/psalms/138/2/scrimboard-en carries no
       edition and must never have to invent one just to find its rows.

       These are THE definition of the canonical shape; canonical() above
       delegates to them so a live challenge and a bare key lookup can never
       drift apart. Change the shape here and every board resets — which is
       free pre-launch and never afterwards.
    */

    /** scrimmage|en|psalms.138.2|d20 */
    public static function scrimmageCanonical(
        string $lang, string $bookSlug, int $chapter, int $verse, ?int $duration = null
    ): string {
        $duration ??= (int) config('typing.challenge.scrimmage_duration');

        return implode('|', [
            'scrimmage',
            strtolower($lang),
            strtolower($bookSlug) . '.' . $chapter . '.' . $verse,
            'd' . $duration,
        ]);
    }

    /** daily|2026-08-01|en|psalms.138.2|d20 */
    public static function dailyCanonical(
        string $date, string $lang, string $bookSlug, int $chapter, int $verse, ?int $duration = null
    ): string {
        $duration ??= (int) config('typing.challenge.scrimmage_duration');

        return implode('|', [
            'daily',
            $date,
            strtolower($lang),
            strtolower($bookSlug) . '.' . $chapter . '.' . $verse,
            'd' . $duration,
        ]);
    }

    /** The scrimboard key for a verse in a language — no lookup required. */
    public static function scrimmageKey(string $lang, string $bookSlug, int $chapter, int $verse): string
    {
        return sha1(self::scrimmageCanonical($lang, $bookSlug, $chapter, $verse));
    }

    /** The daily board key for one date — what the archive freezes. */
    public static function dailyKey(string $date, string $lang, string $bookSlug, int $chapter, int $verse): string
    {
        return sha1(self::dailyCanonical($date, $lang, $bookSlug, $chapter, $verse));
    }

    /** The challenge key — what leaderboard rows hang off. */
    public function key(): string
    {
        return sha1($this->canonical());
    }

    /**
     * The normalised share params, ready to be rebuilt into a URL or stored
     * verbatim in typing_scores.params_json for reconstruction.
     */
    public function shareParams(): array
    {
        $out = ['mode' => $this->mode, 't' => $this->txSlug];
        if ($this->mode === 'daily') {
            $r = $this->refs[0];
            $out['date'] = $this->date;
            $out['b'] = $r['book']->slug;
            $out['c'] = $r['chapter'];
            $out['v'] = $r['verse'];
        } elseif ($this->mode === 'scrimmage') {
            $r = $this->refs[0];
            $out['b'] = $r['book']->slug;
            $out['c'] = $r['chapter'];
            $out['v'] = $r['verse'];
            // no `d`: the clock is fixed, so it isn't part of the share URL
        } else {
            $out['p'] = implode('_', array_map(
                fn ($r) => $r['book']->slug . '.' . $r['chapter'] . '.' . $r['verse'],
                $this->refs
            ));
        }
        return $out;
    }
}
