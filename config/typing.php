<?php

/**
 * Bible-Typing settings.
 *
 * Everything tunable about the game lives here so you never have to dig through
 * the controller or selector to change a number.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Typing Vigil
    |--------------------------------------------------------------------------
    | Where /extras/vigil lands when opened bare. Phase 2 replaces
    | this redirect with the progress-overview home screen; until then, the
    | vigil opens on this translation + book, chapter 1.
    */
    'vigil' => [
        'default_translation'  => 'web',
        'default_book'         => 'genesis',
        'session_gap_minutes'  => 25,   // vigil verses typed within this gap
                                        // collapse into one "Acts" range row
    ],

    /*
    |--------------------------------------------------------------------------
    | Length tiers (RANKED — legacy prototype, retired in a later phase)
    |--------------------------------------------------------------------------
    | Target WORD counts per tier. The selector walks consecutive verses until
    | it crosses the target, so the real length lands a little above these.
    | `endurance` is null = "a whole random chapter, however long it is."
    */
    'tiers' => [
        'sprint'    => 25,
        'standard'  => 50,
        'endurance' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Difficulty → translation (legacy prototype)
    |--------------------------------------------------------------------------
    */
    'difficulty_translation' => [
        'normal' => 'WEB',   // modern spelling + punctuation
        'hard'   => 'KJV',   // archaic spelling, heavy ; and :
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation lock (legacy prototype)
    |--------------------------------------------------------------------------
    */
    'lock_generation' => env('TYPING_LOCK_GENERATION', false),

    /*
    |--------------------------------------------------------------------------
    | Challenge engine (phase 3)
    |--------------------------------------------------------------------------
    | triad_refs        : how many verses a triad holds (the name says three).
    | triad_max_chars   : total-length cap at token issue, so nobody is
    |                     challenged to the three longest verses in Esther.
    | scrimmage_duration: the one clock every scrimmage runs, in seconds.
    |                     Changing it spawns fresh boards (it's part of the
    |                     canonical challenge identity) — old ones stay intact.
    | time_tolerance_ms : how far a scrimmage's claimed duration may drift
    |                     from the clock (client timer jitter allowance).
    */
    'challenge' => [
        'triad_refs'         => 3,
        'triad_max_chars'    => 600,
        'scrimmage_duration' => 20,
        'time_tolerance_ms'  => 1500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-cheat
    |--------------------------------------------------------------------------
    | max_gross_wpm  : scores claiming more than this are rejected outright.
    | token_grace_ms : slack added to the server's wall-clock when checking that
    |                  the claimed typing time isn't shorter than reality allows.
    | token_ttl_ms   : a start token older than this is stale (round abandoned).
    */
    'max_gross_wpm'  => 250,
    'token_grace_ms' => 2000,
    // A start token older than this is stale. Kept short: a scrim round is
    // 20 seconds, so a long window only widens the replay surface. The
    // scrim page re-mints silently on expiry, so users never see it.
    'token_ttl_ms'   => 5 * 60 * 1000,  // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Leaderboard
    |--------------------------------------------------------------------------
    | board_size : how many rows survive the nightly trim — the champions.
    |              Also the "made_board" threshold in the score response.
    | board_cap  : the intra-day ceiling. A scrimboard accepts new names until
    |              it holds this many rows; after that, a submission must beat
    |              the board's last-place score to earn a seat. The nightly
    |              scrim:trim then cuts everything back to board_size. Raise
    |              this when scrimboards get their bigger home.
    */
    'board_size' => 10,
    'board_cap'  => 100,

    /*
    |--------------------------------------------------------------------------
    | Nightly board trim (scrim:trim — see routes/console.php)
    |--------------------------------------------------------------------------
    | at       : local wall-clock time the trim runs.
    | timezone : IANA zone, NOT a fixed offset — America/Denver follows
    |            Mountain DST automatically, so "midnight" stays midnight
    |            through the spring/fall shifts.
    */
    'board_trim' => [
        'at'       => '00:06',
        'timezone' => 'America/New_York',
    ],

    /*
    |--------------------------------------------------------------------------
    | Leaderboard default names
    |--------------------------------------------------------------------------
    | Names are four dial characters (A–Z, 0–9). One of these is picked at
    | random to pre-set the dials after a scrimmage, so Enter alone claims
    | under a Bible name. EVERY entry must be exactly four characters,
    | uppercase letters/digits only — anything else is filtered out
    | client-side and would never survive server validation anyway.
    */
    'default_names' => [
        'ADAM', 'ABEL', 'SETH', 'NOAH', 'BOAZ', 'RUTH', 'OBED', 'EZRA',
        'AMOS', 'JOEL', 'LEAH', 'SAUL', 'ESAU', 'ANNA', 'MARY', 'JOHN',
        'MARK', 'LUKE', 'PAUL', 'EDEN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Name censor list  (render-time only — the DB is never rewritten)
    |--------------------------------------------------------------------------
    | A flat list of four-character names (uppercase A–Z / 0–9 — the only
    | alphabet the dials can produce, so every entry here is exactly 4 chars).
    |
    | Mechanics: entry is NEVER blocked — the raw name is stored as typed.
    | The board and score endpoints test each name against this list at serve
    | time and ship a `censored` flag; the client renders a listed name
    | blurred and struck through, and nothing else. Because the test happens
    | at serve time, editing this list retroactively censors (or un-censors)
    | names already sitting on boards — no DB change, no data redeploy, just
    | a config edit (and `php artisan config:clear` if config is cached).
    |
    | Extend freely as creative spellings appear in the wild. Leet variants
    | included where they read unmistakably (0↔O, 1↔I/L, 3↔E, 4↔A, 5↔S).
    */
    'censor' => [
        // The obvious four
        'FUCK', 'FVCK', 'FCUK', 'PHUK', 'FUKK', 'F4CK',
        'SHIT', 'SH1T', '5HIT', 'SHYT',
        'CUNT', 'KUNT', 'CVNT', 'C0NT',
        'DICK', 'D1CK', 'DIKK',
        'COCK', 'C0CK', 'KOCK',

        // Crude anatomy & acts
        'TWAT', 'TW4T',
        'SLUT', '5LUT', 'SL0T',
        'PISS', 'P1SS',
        'TITS', 'T1TS',
        'ARSE', 'ANAL', 'ANUS',
        'JIZZ', 'J1ZZ', 'CUMS',
        'PORN', 'P0RN', 'SEXX',
        'RAPE', 'R4PE',
        'PEDO', 'P3DO',

        // Slurs & hate codes — no exceptions, no cleverness credit
        'KIKE', 'K1KE',
        'COON', 'C00N',
        'GOOK', 'G00K',
        'SPIC', 'SP1C',
        'PAKI', 'P4KI',
        'CHNK',
        'NIGG', 'N1GG', 'NGGR',
        'FAGS', 'FAGG', 'F4GS',
        'DYKE', 'DYK3',
        'NAZI', 'N4ZI',
        'HEIL', 'H31L',
        'KKKK', '1488',
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily verse challenge
    |--------------------------------------------------------------------------
    | min_chars / max_chars : the comfortable band for a 20-second scrim —
    |                         long enough to be a real round, short enough to
    |                         wrap more than once. This is tier 0 of the
    |                         picker; the corpus runs through it for roughly a
    |                         century before anything outside it comes up.
    |                         Widening it later just enlarges the tier — no
    |                         migration, and used verses stay used.
    | prefetch_days         : how far ahead scrim:daily-pick keeps the calendar
    |                         filled. Also how much queue you can see when
    |                         deciding which days to curate by hand.
    | archive_at            : when yesterday's daily boards are frozen. MUST
    |                         be later than token_ttl_ms past midnight, or
    |                         rounds begun before midnight lose their seats.
    | pick_at               : when the calendar tops itself back up.
    */
    'daily' => [
        'min_chars'     => 60,
        'max_chars'     => 250,
        'prefetch_days' => 14,
        'archive_at'    => '00:06',
        'pick_at'       => '00:10',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrimboard hub
    |--------------------------------------------------------------------------
    | trending_count : verses on the hub's most-played list.
    | board_count    : boards shown on the hub (top by total plays).
    | board_rows     : rows previewed per hub board (click through for all).
    | board_show     : rows a SCRIM PAGE renders before the "view full
    |                  board" link takes over. The full-board page itself
    |                  shows everything up to board_cap.
    | cache_minutes  : hub page cache, per period filter. All of the hub is
    |                  aggregate queries over scrim_plays; nobody needs it
    |                  fresher than this.
    */
    'hub' => [
        'trending_count' => 10,
        'board_count'    => 6,
        'board_rows'     => 5,
        'board_show'     => 20,
        'cache_minutes'  => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | The sabbath
    |--------------------------------------------------------------------------
    | Saturday 00:00 → Sunday 00:00 on the site clock: boards veiled, scores
    | not kept, no daily verse. `enabled` turns the observance off wholesale
    | — for development, and so launch day never depends on the weekday. The
    | trim SCHEDULE stays Saturday regardless (routes/console.php); this
    | flag governs only the gates and guards.
    */
    'sabbath' => [
        'enabled' => true,
    ],
];
