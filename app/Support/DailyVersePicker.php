<?php

namespace App\Support;

use App\Models\DailyVerse;
use App\Support\Sabbath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DailyVersePicker — the century-long walk through the corpus.
 *
 * ONE VERSE A DAY, NO REPEATS, EVER. Every verse that has had its day lives
 * in daily_verses; the pool is everything else. At roughly 43k verses in the
 * Protestant 66 — more with the deuterocanon, the pseudepigrapha and the
 * Fathers — the walk runs past the year 2140. This class is written to be
 * boring and reproducible for that entire span.
 *
 * DETERMINISTIC BY DATE, NOT RANDOM. Given the same pool, a date always
 * resolves to the same verse: the date is hashed to an index into the pool,
 * ordered canonically. That buys three things worth more than novelty —
 *   · the scheduled command and the on-the-fly fallback AGREE, so a missed
 *     cron run and a live page request produce the same verse;
 *   · two concurrent first-requests on a fresh day can't disagree, so the
 *     unique index on `date` never has to arbitrate a real conflict;
 *   · a pick is reproducible in Tinker when you want to know why.
 *
 * PRIORITY TIERS. The good stuff first: verses whose text sits in the
 * comfortable band for a 20-second scrim. When that tier is finally
 * exhausted — decades out — the walk falls through to everything else, and
 * the genealogies get their day in the sun. They will be a trial and that is
 * the point.
 *
 * ELIGIBILITY is "at least one edition in this language carries it, and that
 * edition's text is in the tier's band". Identity is the verse, never the
 * edition (scrimboards are per-language, translation-agnostic), so the pool
 * is a DISTINCT set of book/chapter/verse triples.
 */
class DailyVersePicker
{
    /**
     * Resolve the verse for a date, persisting it if nobody had chosen one.
     *
     * THE FALLBACK PATH. Called by the daily page on every request; on a day
     * the scheduler already filled, this is one indexed read and nothing
     * more. On a day it didn't, this picks deterministically and writes the
     * row, so the page never 500s because a cron died. A run of `fallback`
     * rows in the ledger is the symptom to look for.
     */
    public static function forDate(string|CarbonInterface|null $date = null, string $lang = 'en'): DailyVerse
    {
        $day = self::normaliseDate($date);

        $existing = DailyVerse::where('date', $day)->first();
        if ($existing) {
            return $existing;
        }

        // NO VERSE IS EVER CREATED FOR A SABBATH. This is the guard that
        // matters most in the whole observance: a verse written to the
        // ledger is consumed from the 117-year pool forever, and there is
        // no undo. Every caller is supposed to branch before reaching here
        // (the daily page, the builder, the pick command) — this line is
        // for the caller that forgets, including a future you in Tinker.
        if (Sabbath::dateIsSabbath($day)) {
            throw new \RuntimeException(
                'No daily verse on the sabbath — none is chosen and none may be created.'
            );
        }

        $pick = self::choose($day, $lang);

        // firstOrCreate, not create: two requests racing on a fresh morning
        // both compute the SAME verse (deterministic), and the unique index
        // settles the tie without either of them erroring.
        return DailyVerse::firstOrCreate(
            ['date' => $day],
            [
                'book_slug' => $pick['book_slug'],
                'chapter'   => $pick['chapter'],
                'verse'     => $pick['verse'],
                'source'    => 'fallback',
            ]
        );
    }

    /**
     * Choose the verse a date SHOULD get, without writing anything.
     * Used by scrim:daily-pick to fill the calendar ahead, and by forDate's
     * fallback. Pure: same date + same pool → same answer.
     *
     * @return array{book_slug: string, chapter: int, verse: int, tier: int}
     * @throws \RuntimeException when the corpus is exhausted (≈ the 2140s)
     */
    public static function choose(string|CarbonInterface|null $date = null, string $lang = 'en'): array
    {
        $day = self::normaliseDate($date);

        foreach (self::tiers() as $i => $tier) {
            $count = self::poolCount($lang, $tier['min'], $tier['max']);
            if ($count < 1) {
                continue;                       // tier spent; try the next
            }

            // The date picks the index. 12 hex digits of sha1 is ~48 bits,
            // far more spread than any pool we'll ever have, and modulo bias
            // at that scale is unmeasurable.
            $index = (int) (hexdec(substr(sha1('mb-daily|' . $day), 0, 12)) % $count);

            $row = self::poolQuery($lang, $tier['min'], $tier['max'])
                ->orderBy('books.slug')          // canonical, stable ordering
                ->orderBy('verses.chapter')
                ->orderBy('verses.verse_number')
                ->skip($index)
                ->take(1)
                ->first();

            if ($row) {
                return [
                    'book_slug' => $row->book_slug,
                    'chapter'   => (int) $row->chapter,
                    'verse'     => (int) $row->verse,
                    'tier'      => $i,
                ];
            }
        }

        throw new \RuntimeException(
            'The corpus is exhausted — every verse has had its day. ' .
            'Congratulations; this was not expected before the 2140s.'
        );
    }

    /* ===================================================================== */
    /*  The pool                                                             */
    /* ===================================================================== */

    /**
     * Length tiers, best first. A tier is only abandoned when it holds
     * NOTHING unused, so tier 0 runs for something like a century before
     * tier 1 sees a single day.
     *
     * The band is a knob: config('typing.daily.min_chars' / 'max_chars').
     * Widening it later simply enlarges tier 0 — no migration, no reset,
     * and already-used verses stay used.
     */
    private static function tiers(): array
    {
        $min = (int) config('typing.daily.min_chars', 60);
        $max = (int) config('typing.daily.max_chars', 250);

        return [
            // Tier 0 — the comfortable scrim: long enough to be a real
            // round, short enough to wrap more than once.
            ['min' => $min, 'max' => $max],
            // Tier 1 — everything else, once the good stuff is spent.
            // Short doxologies, and the genealogies. Especially those.
            ['min' => 1, 'max' => 100000],
        ];
    }

    /**
     * Unused verses in this language whose text falls in [min, max] chars.
     *
     * DISTINCT on the triple: a verse carried by five English editions is
     * ONE candidate, not five. Without that, well-covered books (the
     * Protestant 66, in every edition) would be five times likelier than the
     * Fathers, and the walk would spend its first decades in familiar
     * country.
     */
    private static function poolQuery(string $lang, int $min, int $max)
    {
        return DB::table('verses')
            ->join('translations', 'translations.id', '=', 'verses.translation_id')
            ->join('books', 'books.id', '=', 'verses.book_id')
            ->where('translations.language', $lang)
            ->whereRaw('CHAR_LENGTH(verses.text) BETWEEN ? AND ?', [$min, $max])
            // Already spoken for — the no-repeat rule, enforced in SQL so a
            // pool count and a pool pick can never disagree.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('daily_verses')
                  ->whereColumn('daily_verses.book_slug', 'books.slug')
                  ->whereColumn('daily_verses.chapter', 'verses.chapter')
                  ->whereColumn('daily_verses.verse', 'verses.verse_number');
            })
            ->distinct()
            ->select([
                'books.slug as book_slug',
                'verses.chapter as chapter',
                'verses.verse_number as verse',
            ]);
    }

    /**
     * How many verses that tier still holds.
     *
     * Counting a DISTINCT set over every verse row is the expensive part of
     * a pick (a few hundred ms on a corpus this size). It runs at most once
     * per pick and the answer only changes when a day is consumed, so it is
     * cached for the rest of the day — and the cache key carries the ledger
     * size, so filling a week of dates invalidates it immediately rather
     * than serving a stale count into a stale offset.
     */
    private static function poolCount(string $lang, int $min, int $max): int
    {
        $used = DailyVerse::count();
        $key  = "mb:daily:pool:{$lang}:{$min}:{$max}:{$used}";

        return (int) cache()->remember($key, now()->addHours(6), function () use ($lang, $min, $max) {
            return DB::table(DB::raw('(' . self::poolQuery($lang, $min, $max)->toSql() . ') as pool'))
                ->mergeBindings(self::poolQuery($lang, $min, $max))
                ->count();
        });
    }

    /** Y-m-d in the SITE clock — the same midnight the trim and archive use. */
    public static function normaliseDate(string|CarbonInterface|null $date = null): string
    {
        $tz = config('typing.board_trim.timezone', 'America/Denver');

        if ($date === null) {
            return Carbon::now($tz)->toDateString();
        }
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return Carbon::parse($date, $tz)->toDateString();
    }
}
