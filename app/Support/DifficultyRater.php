<?php

namespace App\Support;

/**
 * DifficultyRater — turns a typing target into a difficulty modifier.
 *
 *   net_wpm     = max(0, gross_wpm − (errors × ERROR_CHARS / 5) / minutes)
 *   final_score = net_wpm × (accuracy/100)² × modifier
 *
 * The modifier is a pure, deterministic function of the text, so the same
 * verse always rates the same and anyone can verify a board entry. Three
 * ingredients, all justified by what actually slows typists down:
 *
 *   1. COMPLEX PUNCTUATION — semicolons, colons, dashes, brackets. Measured
 *      per 100 characters so a long verse isn't punished twice (length has
 *      its own term).
 *   2. ARCHAIC LANGUAGE — the KJV tax. A lexicon of archaic function words
 *      plus the -eth/-est verb suffixes (with a stoplist so "best" and
 *      "harvest" don't count), as a fraction of all words.
 *   3. LENGTH — a mild term centred at 80 characters (a typical verse):
 *      longer targets demand sustained accuracy, shorter ones forgive.
 *
 * VERSIONING IS THE CONTRACT: every stored score records the VERSION that
 * produced its modifier. Change ANY weight, set, or clamp below → bump
 * VERSION → old rows stay internally consistent and boards can be re-scored
 * or segregated by version. Never retune silently.
 */
class DifficultyRater
{
    /** Bump on ANY change to the formula, weights, or word sets below.
     *  v2: scrimmage wrap + perfect bonuses joined the final-score formula.
     *  v3: an uncorrected error costs ERROR_CHARS characters, not a whole
     *      word — the 80%-accuracy zero cliff became a 50% one. */
    public const VERSION = 3;

    /* ---- v1 weights & clamps (the knobs) ------------------------------ */
    private const PUNCT_PER100_WEIGHT = 0.035;  // per punctuation mark per 100 chars
    private const PUNCT_CAP           = 0.25;
    private const ARCHAIC_WEIGHT      = 0.60;   // × (archaic words / all words)
    private const ARCHAIC_CAP         = 0.30;
    private const LENGTH_BASELINE     = 80;     // chars; a typical verse
    private const LENGTH_DIVISOR      = 1000;   // +0.10 at baseline+100 chars
    private const LENGTH_MIN          = -0.05;
    private const LENGTH_MAX          = 0.15;
    private const CLAMP_MIN           = 0.85;
    private const CLAMP_MAX           = 1.70;

    /** The complex-punctuation class: ; : em/en dash ( ) [ ] */
    private const PUNCT_RE = '/[;:\x{2014}\x{2013}()\[\]]/u';

    /** Archaic function words & verb forms (lowercased match). */
    private const ARCHAIC_WORDS = [
        'thee', 'thou', 'thy', 'thine', 'ye', 'hath', 'doth', 'dost',
        'shalt', 'wilt', 'unto', 'thereof', 'therein', 'thereto', 'wherefore',
        'whosoever', 'whatsoever', 'verily', 'begat', 'saith', 'spake',
        'cometh', 'goeth', 'maketh', 'yea', 'nay', 'howbeit', 'peradventure',
    ];

    /** Common modern words ending -eth/-est that must NOT count as archaic. */
    private const SUFFIX_STOPLIST = [
        'best', 'rest', 'test', 'west', 'least', 'chest', 'guest', 'harvest',
        'honest', 'earnest', 'priest', 'interest', 'nest', 'crest', 'forest',
        'tempest', 'request', 'conquest', 'breast', 'feast', 'east', 'beast',
    ];

    /**
     * Rate a typing target. Deterministic; clamped to [0.85, 1.70]; 3 d.p.
     */
    public static function rate(string $text): float
    {
        $chars = mb_strlen($text);
        if ($chars === 0) {
            return 1.0;
        }

        $modifier = 1.0;

        // ---- 1. Complex punctuation density -----------------------------
        $punct   = preg_match_all(self::PUNCT_RE, $text);
        $per100  = ($punct / $chars) * 100;
        $modifier += min(self::PUNCT_CAP, $per100 * self::PUNCT_PER100_WEIGHT);

        // ---- 2. Archaic language ratio ----------------------------------
        preg_match_all('/[\p{L}\']+/u', mb_strtolower($text), $m);
        $words = $m[0];
        $wordCount = count($words);
        if ($wordCount > 0) {
            $archaic = 0;
            $lexicon = array_flip(self::ARCHAIC_WORDS);
            $stop    = array_flip(self::SUFFIX_STOPLIST);
            foreach ($words as $w) {
                if (isset($lexicon[$w])) {
                    $archaic++;
                    continue;
                }
                // -eth / -est verb suffix on a word long enough to be a verb
                // form, minus the modern-word stoplist.
                if (mb_strlen($w) > 4
                    && ! isset($stop[$w])
                    && preg_match('/(eth|est)$/u', $w)) {
                    $archaic++;
                }
            }
            $modifier += min(self::ARCHAIC_CAP, ($archaic / $wordCount) * self::ARCHAIC_WEIGHT);
        }

        // ---- 3. Length ---------------------------------------------------
        $lengthTerm = ($chars - self::LENGTH_BASELINE) / self::LENGTH_DIVISOR;
        $modifier  += max(self::LENGTH_MIN, min(self::LENGTH_MAX, $lengthTerm));

        // ---- Clamp & round ----------------------------------------------
        return round(max(self::CLAMP_MIN, min(self::CLAMP_MAX, $modifier)), 3);
    }

    /* ---- v2 scrimmage bonuses (the knobs) ------------------------------ */
    private const WRAP_STEP    = 0.03;   // wrap k adds k × this (escalating)
    private const WRAP_CAP     = 1.50;
    private const PERFECT_STEP = 0.06;   // × wraps, only while error-free
    private const PERFECT_CAP  = 1.50;

    /**
     * Wrap bonus: each completed pass of the verse is worth MORE than the
     * last — wrap k contributes k × WRAP_STEP, so n wraps give
     * 1 + STEP × n(n+1)/2, capped. Verifiable server-side: wraps is just
     * ⌊chars_typed / verse_chars⌋, no client claim to trust.
     */
    public static function wrapMultiplier(int $wraps): float
    {
        if ($wraps < 1) {
            return 1.0;
        }
        return round(min(self::WRAP_CAP, 1 + self::WRAP_STEP * $wraps * ($wraps + 1) / 2), 3);
    }

    /**
     * Perfect bonus: a clean round (zero errors) with at least one full wrap
     * stacks a further multiplier per wrap. Four perfect wraps of "Jesus
     * wept" is the whole point.
     */
    public static function perfectMultiplier(int $wraps, int $errorCount): float
    {
        if ($errorCount > 0 || $wraps < 1) {
            return 1.0;
        }
        return round(min(self::PERFECT_CAP, 1 + self::PERFECT_STEP * $wraps), 3);
    }

    /* ---- v3 speed math (the knob) --------------------------------------- */
    /**
     * What one uncorrected error costs, in characters. A "word" is 5
     * characters by the standard WPM convention, so 5 here means each error
     * erases a whole word — which floored any round below 80% accuracy at
     * exactly zero. 2 makes the penalty a gradient: the zero cliff moves to
     * 50% accuracy, and slop is punished mainly by the squared accuracy
     * factor in finalScore() rather than by annihilation.
     */
    private const ERROR_CHARS = 2;

    /** Raw typing rate, penalty-free: the standard (keystrokes / 5) / minutes. */
    public static function grossWpm(int $keystrokes, int $durationMs): float
    {
        if ($durationMs <= 0) {
            return 0.0;
        }
        return ($keystrokes / 5) / ($durationMs / 60000);
    }

    /**
     * Speed after the error penalty, floored at zero. Every caller uses this
     * — the controller for both game modes, and the blade mirrors it for its
     * live estimate. Change ERROR_CHARS, change the mirror, bump VERSION.
     */
    public static function netWpm(int $keystrokes, int $errors, int $durationMs): float
    {
        if ($durationMs <= 0) {
            return 0.0;
        }
        $minutes = $durationMs / 60000;
        $penalty = (($errors * self::ERROR_CHARS) / 5) / $minutes;

        return max(0, self::grossWpm($keystrokes, $durationMs) - $penalty);
    }

    /**
     * The full score, in one place so nothing else re-implements it:
     * net_wpm × (accuracy/100)² × modifier. Squared accuracy punishes slop
     * harder than a linear factor — 95% accuracy is ×0.9025 — which kills
     * the spray-and-pray strategy. Scrimmage bonuses multiply on top (see
     * TypingController::scoreChallenge). The client mirrors ALL of this for
     * its live estimate — change anything here, change it there, and bump
     * VERSION.
     */
    public static function finalScore(float $netWpm, float $accuracy, float $modifier): float
    {
        return round($netWpm * (($accuracy / 100) ** 2) * $modifier, 2);
    }
}
