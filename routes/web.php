<?php

use App\Http\Controllers\ActsController;
use App\Http\Controllers\BibleController;
use App\Http\Controllers\PericopeController;
use App\Http\Controllers\TypingController;
use App\Http\Controllers\HeadedController;
use App\Http\Controllers\SearchController;
use App\Http\Middleware\RememberTranslation;
use Illuminate\Support\Facades\Route;

// Home — the global Bible table of contents (temporary landing page)
Route::get('/', [BibleController::class, 'index'])->name('home');

// Site-level static pages
Route::view('/about', 'pages.about')->name('about');
Route::view('/support', 'pages.support')->name('support');
Route::view('/privacy', 'pages.privacy')->name('privacy');

// Search — resolver (reference / shortcut) or full-text fallback
Route::get('/search', [SearchController::class, 'handle'])->name('search');

// Bible routes
Route::prefix('bible')->name('bible.')
    ->middleware(RememberTranslation::class)
    ->group(function () {
        // Interlinear tokens for selected verses (Synthesis card backs).
        // JSON only; the ?v= param uses the same "3,8" / "1-3" syntax as
        // the reader's Focus selection. Registered alongside the verse
        // permalink — no clash, because {verse} is constrained to digits.
        Route::get('/{translation}/{book}/{chapter}/interlinear', [BibleController::class, 'interlinear'])
            ->where('chapter', '[0-9]+')
            ->name('interlinear');

        // Verse across every translation (JSON) — feeds the Pericope card
        // switcher. MUST sit above the /{translation} redirect below, or
        // "verse-translations" is read as a translation slug and bounced to
        // the index. Relative path + name: the group adds the `/bible` prefix
        // and the `bible.` name, giving /bible/verse-translations and
        // bible.verse-translations.
        Route::get('/verse-translations', [BibleController::class, 'verseTranslations'])
            ->name('verse-translations');

        // Translation home isn't built yet — send bare /bible/{translation}
        // back to the canon index rather than 404ing on a trimmed URL.
        Route::redirect('/{translation}', '/');   

        // Book home — list of chapters
        Route::get('/{translation}/{book}', [BibleController::class, 'showBook'])
            ->name('book');

        // Chapter view — the main reading screen
        Route::get('/{translation}/{book}/{chapter}', [BibleController::class, 'showChapter'])
            ->where('chapter', '[0-9]+')
            ->name('chapter');

        // Single-verse permalink (redirects to chapter view with anchor)
        Route::get('/{translation}/{book}/{chapter}/{verse}', [BibleController::class, 'showVerse'])
            ->where(['chapter' => '[0-9]+', 'verse' => '[0-9]+'])
            ->name('verse');
    });

// Parallel reading — two translations of one chapter, side by side.
// e.g. /parallel/kjv,web/john/3   ({translations} is a comma-separated slug list)
Route::get('/parallel/{translations}/{book}/{chapter}', [BibleController::class, 'showParallel'])
    ->where('translations', '[A-Za-z0-9,]+')
    ->where('chapter', '[0-9]+')
    ->name('parallel');

// Terminal theme easter egg
Route::view('/extras/terminal', 'extras.terminal')->name('terminal.index');

// Acts of the User — the global record: the deeds feed (vigil verses,
// chapter/book completions, scrimmages) plus export/import/clear-all.
Route::get('/extras/acts-of-the-user', [ActsController::class, 'show'])
    ->name('extras.acts');

// Pericope — Milanote-for-verses. All data lives in the visitor's browser
// (localStorage → window.MBPericope); these are thin client-rendered shells.
//
// Route ORDER matters here for when the later steps land: the literal
// reserved slugs `shared` and `verses` (Phase 4) MUST be registered BEFORE
// the parameterised `{slug}` board route (step 1d), or they'd be swallowed by
// it — the same house habit the scrimmage group follows. Those slugs are
// already reserved in pericope-store.js so no board can ever take them.
Route::prefix('extras/pericope')->name('extras.pericope')->group(function () {
    // The hub — /extras/pericope
    Route::get('/', [PericopeController::class, 'hub'])->name('');

    // The share-link landing page (share plan S2). Fragment-borne board data
    // never reaches the server; this just ships the import shell.
    Route::get('/shared', [PericopeController::class, 'shared'])->name('.shared');
    // Phase 4:  Route::get('/verses',  [PericopeController::class, 'verses'])->name('.verses');

    // One board — /extras/pericope/{slug}. Client-resolved; unknown slugs render
    // a "not found" state rather than a server 404 (the server has no board data).
    Route::get('/{slug}', [PericopeController::class, 'board'])
        ->where('slug', '[a-z0-9-]+')
        ->name('.board');
});

// Typing Scrimmage — the builder and the scrims themselves.
// The verse route IS the challenge: /extras/scrimmage/kjv/romans/8/1
// The literal builder route is registered first so it can never be
// swallowed by the parameterised one.
Route::prefix('extras/scrimmage')->name('typing.scrimmage')->group(function () {
    Route::get('/', [TypingController::class, 'scrimmage'])->name('');

    // THE SCRIMBOARD HUB — trending verses and the hottest boards, from the
    // anonymous play counters. Literal segment, registered before the
    // parameterised routes per house habit (its one segment could never
    // match them anyway).
    Route::get('/scrimboards', [TypingController::class, 'scrimboards'])
        ->name('.boards');

    // ONE FULL BOARD — the whole intra-day field for one verse in one
    // LANGUAGE. No translation in the URL: a scrimboard is shared by every
    // edition of a language, and its key is computable from (lang, b, c, v)
    // alone. `-es` is reserved; until Spanish imports land it renders a
    // polite placeholder. The four-segment verse route below can't swallow
    // this shape (its {v} is digits-only), but order still declares intent.
    Route::get('/{b}/{c}/{v}/scrimboard-{lang}', [TypingController::class, 'scrimboardFull'])
        ->where(['c' => '[0-9]+', 'v' => '[0-9]+', 'lang' => 'en|es'])
        ->name('.board');

    // THE DAILY ARCHIVE — every day that has happened, and each frozen
    // board. Registered BEFORE /daily so neither literal can shadow the
    // other, and before the parameterised routes as ever. The {date}
    // pattern is pinned to YYYY-MM-DD so a typo 404s here rather than
    // wandering into a verse route.
    Route::get('/daily/archive', [TypingController::class, 'dailyArchive'])
        ->name('.daily.archive');

    Route::get('/daily/archive/{date}', [TypingController::class, 'dailyArchiveDay'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('.daily.day');

    // TODAY'S DAILY — one verse, one shot, sealed until midnight.
    Route::get('/daily', [TypingController::class, 'daily'])->name('.daily');

    Route::get('/{t}/{b}/{c}/{v}', [TypingController::class, 'scrimmageVerse'])
        ->where(['c' => '[0-9]+', 'v' => '[0-9]+'])
        ->name('.verse');
});

/* ---- TYPING VIGIL ----------------------------------------------------
   A reader you type. Real URLs per chapter (back/forward, shareable,
   Cloudflare-cacheable), mirroring the reader's own URL shape.

   Route NAMES stay under the `typing.` namespace (typing.vigil,
   typing.vigil.book, typing.vigil.home) even though the URL no longer
   lives under /extras/bible-typing — every blade and controller builds
   these links by name, so the names are the stable contract.

   The bare /vigil literal must sit first. The book-level route redirects
   to chapter 1 — it exists because the QuickNav's Screen-2 title link
   points at the "book hub", and in vigil mode that hub is simply
   "start the book". */
Route::prefix('extras/vigil')->name('typing.')->group(function () {

    Route::get('/', [TypingController::class, 'vigilHome'])
        ->name('vigil.home');

    Route::get('/{translation}/{book}', [TypingController::class, 'vigilBook'])
        ->name('vigil.book');

    Route::get('/{translation}/{book}/{chapter}', [TypingController::class, 'vigil'])
        ->where('chapter', '[0-9]+')
        ->name('vigil');
});

// Bible-Typing endpoints — challenge engine, vigil pages, score submission.
// (The old /extras/bible-typing landing page is retired; the prefix stays
// because every typing endpoint lives under it.)
Route::prefix('extras/bible-typing')->name('typing.')->group(function () {

    // Challenge engine (phase 3): resolve a URL-defined challenge + its board.
    // Both take the same query params (see App\Support\Challenge for the
    // contract); throttled since each resolve costs verse lookups.
    Route::get('/challenge', [TypingController::class, 'challenge'])
        ->middleware('throttle:30,1')
        ->name('challenge');
    Route::get('/challenge/board', [TypingController::class, 'challengeBoard'])
        ->middleware('throttle:30,1')
        ->name('challenge.board');

    Route::get('/outline', [TypingController::class, 'outline'])->name('outline');
    Route::get('/passage', [TypingController::class, 'passage'])->name('passage');
    Route::get('/leaderboard', [TypingController::class, 'leaderboard'])->name('leaderboard');

    // Layered rate limit, cache-backed (costs nothing): a burst ceiling per
    // minute AND a per-IP daily ceiling, so one actor can't flood boards
    // with dial names all day. The `score-submit` limiter is defined in
    // AppServiceProvider::boot() — the route 500s if that definition is
    // missing, so add it alongside this change.
    Route::post('/score', [TypingController::class, 'score'])
        ->middleware('throttle:score-submit')
        ->name('score');

    // Round-completion beacon: the anonymous play counter (scrim_plays).
    // Fired once per finished round, whatever it scored and whether or not
    // a name is ever claimed — /score is a CLAIM, not a round, and counting
    // there both inflated (retries after a held name) and undercounted
    // (zero-score rounds never submit at all). Idempotent per token, so a
    // double-fire is harmless. Throttled like any write.
    Route::post('/played', [TypingController::class, 'played'])
        ->middleware('throttle:30,1')
        ->name('played');    
});

// HEADed — the local-only heading TSV editor. Not registered in production.
if (app()->environment('local')) {
    Route::prefix('headed')->name('headed.')->group(function () {
        Route::get('/',        [HeadedController::class, 'index'])->name('index');
        Route::get('/load',    [HeadedController::class, 'load'])->name('load');
        Route::get('/resolve', [HeadedController::class, 'resolve'])->name('resolve');
        Route::post('/write',  [HeadedController::class, 'write'])->name('write');
    });
}