<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/*
|==========================================================================
| WHY 91 BOOKS? — w91 r2
|--------------------------------------------------------------------------
| Controller for /extras/why-91-books: a scrolling vertical timeline that
| "collects" the canon's eleven subgroups into the 57 + 34 = 91 totals.
|
| r2 changes:
|   - One-sided layout: ALL whisker bars go to the RIGHT of the spine
|     (always visible, mobile included), all group cards to the LEFT.
|     Lane packing now runs across all eleven groups together.
|   - Books in each group's popover resolve to live reader links (the
|     never-404 rule: a book links only where verses exist; otherwise it
|     renders as plain text). The slug→translation map is cached as
|     plain scalars — URLs are built at render time, never cached.
|   - Six new spine events (Josiah, Ezra, Qumran, Sinaiticus, Rome,
|     Alexandrinus); the scale now runs to 450 AD for Alexandrinus.
|
| All geometry (vertical px positions, whisker lanes, card pre-packing)
| is computed HERE; the blade is a dumb painter, per house convention.
| The counts are derived from the book arrays below, so the tally can
| never drift from the data: add or remove a book line and every count,
| whisker, dot, and popover follows.
|
| EDIT ZONES (the only places that should ever need touching):
|   1. SCALE    — px-per-year per era, paddings, tick spacing
|   2. BOOKS    — the eleven groups and their 91 books with date ranges
|                 (derived from the book-hub JSON `intro` dating fields;
|                 tune freely, this page is approximate on purpose)
|   3. WITNESSES — the canon testimonies and milestones on the spine
|
| ROUTE (top level in routes/web.php — NOT inside the extras/pericope
| group, whose URL+name prefixes would silently double):
|
|   use App\Http\Controllers\WhyBooksController;
|   Route::get('/extras/why-91-books', [WhyBooksController::class, 'index'])
|       ->name('extras.why91');
|==========================================================================
*/

class WhyBooksController extends Controller
{
    /* ===================================================================
       EDIT ZONE 1 — SCALE
       The timeline runs 1000 BC to 450 AD. Each era row is
       [from_year, to_year, px_per_year]: later eras get more room so the
       Second Testament's 150-year burst isn't a smear. Negative years
       are BC; there is no year zero (0 is displayed as "1 AD").
       =================================================================== */
    private const ERAS = [
        [-1000, -500, 1.5],   // 150 px per century — the deep past
        [ -500, -100, 2.2],   // 220 px per century
        [ -100,  450, 3.4],   // 340 px per century — the crowded era
    ];

    private const TL_END     = 450;   // the spine's last year
    private const PAD_TOP    = 48;    // px above the 1000 BC tick
    private const PAD_BOTTOM = 72;    // px below the last year
    private const TICK_STEP  = 100;   // a tick every N years
    private const TICK_END   = 400;   // last labelled tick
    private const MAJOR_STEP = 200;   // ticks on this multiple keep their
                                      // labels on mobile; others hide

    // Card pre-packing (the no-JS fallback layout; the page script
    // re-packs with real rendered heights on load and resize).
    private const CARD_EST_H = 104;   // estimated card height, px
    private const CARD_GAP   = 12;    // min vertical gap between cards
    private const CHIP_EST_H = 44;    // estimated spine-chip height
    private const CHIP_GAP   = 8;

    // Years of breathing room before two groups count as overlapping
    // when packing whisker lanes (keeps back-to-back bars off one lane).
    private const LANE_GAP_YEARS = 15;

    /* ===================================================================
       EDIT ZONE 2 — THE 91 BOOKS, IN THEIR ELEVEN GROUPS
       Each book line: [name, start_year, end_year, sort_year, 'date text', 'slug']
         start/end  the approximate composition window (negative = BC)
         sort_year  the single "representative" year (the hub's
                    dating_sort); group cards sit at their books' median
         date text  shown in the group's book-list popover
         slug       the book's reader slug — the popover links through it
                    wherever verses exist; a slug with no verses (or a
                    typo) just renders unlinked, never a 404
       'color' keys resolve through config('canon.section_colors') — the
       same palette map as the QuickNav, About tiles, and homepage.
       =================================================================== */
    private const GROUPS = [
        'torah' => [
            'side'  => 'ft',
            'label' => 'Torah',
            'full'  => 'The Torah',
            'books' => [
                ['Genesis', -950, -400, -600, 'c. 10th-5th century BC', 'genesis'],
                ['Exodus', -950, -400, -600, 'c. 10th-5th century BC', 'exodus'],
                ['Leviticus', -600, -450, -500, 'c. 6th-5th century BC', 'leviticus'],
                ['Numbers', -950, -400, -600, 'c. 10th-5th century BC', 'numbers'],
                ['Deuteronomy', -700, -400, -500, 'c. 9th-6th century BC', 'deuteronomy'],
            ],
        ],
        'neviim' => [
            'side'  => 'ft',
            'label' => "Nevi'im",
            'full'  => "The Nevi'im (Prophets)",
            'books' => [
                ['Joshua', -640, -540, -600, 'c. 9th-6th century BC', 'joshua'],
                ['Judges', -640, -540, -600, 'c. 9th-6th century BC', 'judges'],
                ['1 Samuel', -630, -540, -600, 'c. 9th-6th century BC', '1-samuel'],
                ['2 Samuel', -630, -540, -600, 'c. 9th-6th century BC', '2-samuel'],
                ['1 Kings', -560, -540, -560, 'c. 8th-6th century BC', '1-kings'],
                ['2 Kings', -560, -540, -550, 'c. 7th-6th century BC', '2-kings'],
                ['Isaiah', -740, -500, -600, 'c. 8th-5th century BC', 'isaiah'],
                ['Jeremiah', -620, -540, -580, 'c. 6th century BC', 'jeremiah'],
                ['Ezekiel', -593, -550, -570, 'c. 6th century BC', 'ezekiel'],
                ['Hosea', -750, -722, -740, 'c. 750-722 BC', 'hosea'],
                ['Joel', -450, -350, -400, 'c. 5th-4th century BC', 'joel'],
                ['Amos', -760, -740, -750, 'c. 760-750 BC', 'amos'],
                ['Obadiah', -586, -550, -570, 'c. 6th century BC', 'obadiah'],
                ['Jonah', -450, -350, -400, 'c. 5th-4th century BC', 'jonah'],
                ['Micah', -740, -690, -720, 'c. 8th century BC', 'micah'],
                ['Nahum', -660, -612, -630, 'c. 7th century BC', 'nahum'],
                ['Habakkuk', -610, -590, -600, 'c. late 7th century BC', 'habakkuk'],
                ['Zephaniah', -640, -609, -625, 'c. 7th century BC', 'zephaniah'],
                ['Haggai', -520, -520, -520, '520 BC', 'haggai'],
                ['Zechariah', -520, -400, -500, 'c. 5th-2nd century BC', 'zechariah'],
                ['Malachi', -475, -430, -450, 'c. 460-430 BC', 'malachi'],
            ],
        ],
        'ketuvim' => [
            'side'  => 'ft',
            'label' => 'Ketuvim',
            'full'  => 'The Ketuvim (Writings)',
            'books' => [
                ['Psalms', -1000, -200, -400, 'c. 10th-2nd century BC', 'psalms'],
                ['Proverbs', -700, -350, -450, 'c. 7th-4th century BC', 'proverbs'],
                ['Job', -600, -400, -500, 'c. 6th-4th century BC', 'job'],
                ['Song of Solomon', -500, -250, -350, 'c. 5th-3rd century BC', 'song-of-solomon'],
                ['Ruth', -500, -400, -450, 'c. 6th-4th century BC', 'ruth'],
                ['Lamentations', -586, -550, -570, 'c. 6th century BC', 'lamentations'],
                ['Ecclesiastes', -300, -200, -250, 'c. 3rd century BC', 'ecclesiastes'],
                ['Esther', -400, -300, -350, 'c. 4th-3rd century BC', 'esther'],
                ['Daniel', -300, -164, -165, 'c. 167-164 BC', 'daniel'],
                ['Ezra', -400, -350, -380, 'c. 4th century BC', 'ezra'],
                ['Nehemiah', -400, -350, -380, 'c. 4th century BC', 'nehemiah'],
                ['1 Chronicles', -400, -350, -375, 'c. 4th century BC', '1-chronicles'],
                ['2 Chronicles', -400, -350, -375, 'c. 4th century BC', '2-chronicles'],
            ],
        ],
        'ft_deuterocanon' => [
            'side'  => 'ft',
            'label' => 'Deuterocanon',
            'full'  => 'The Deuterocanon',
            'books' => [
                ['Tobit', -250, -175, -200, 'c. 3rd-2nd century BC', 'tobit'],
                ['Judith', -150, -100, -125, 'c. 2nd century BC', 'judith'],
                ['Greek Esther', -150, -50, -100, 'c. 2nd-1st century BC', 'greek-esther'],
                ['Greek Daniel', -150, -100, -100, 'c. 2nd-1st century BC', 'greek-daniel'],
                ['Sirach', -200, -175, -190, 'c. 196-175 BC', 'sirach'],
                ['Baruch', -200, -100, -150, 'c. 2nd-1st century BC', 'baruch'],
                ['Wisdom of Solomon', -100, 0, -30, 'c. 1st century BC', 'wisdom-of-solomon'],
                ['1 Maccabees', -125, -100, -110, 'c. 100 BC', '1-maccabees'],
                ['2 Maccabees', -140, -110, -124, 'Late 2nd century BC', '2-maccabees'],
            ],
        ],
        'ft_apocrypha' => [
            'side'  => 'ft',
            'label' => 'Apocrypha',
            'full'  => 'First Testament Apocrypha',
            'books' => [
                ['1 Esdras', -200, -100, -150, 'c. 2nd-1st century BC', '1-esdras'],
                ['2 Esdras', 90, 270, 100, 'c. 100 AD (core)', '2-esdras'],
                ['Prayer of Manasseh', -200, -1, -100, 'c. 2nd-1st century BC', 'prayer-of-manasseh'],
                ['Psalms 151-155', -250, -50, -150, 'c. 3rd-1st century BC', 'five-psalms-of-david'],
                ['3 Maccabees', -100, -1, -50, 'c. 1st century BC', '3-maccabees'],
                ['4 Maccabees', 1, 100, 50, 'c. 1st century AD', '4-maccabees'],
                ['1 Enoch', -300, 50, -200, 'c. 3rd century BC - 1st century AD', '1-enoch'],
                ['Jubilees', -170, -140, -155, 'c. 160-150 BC', 'jubilees'],
                ['2 Baruch', 70, 135, 100, 'c. 70-135 AD', '2-baruch'],
            ],
        ],
        'pauline_epistles' => [
            'side'  => 'st',
            'label' => 'Pauline Epistles',
            'full'  => 'The Pauline Epistles',
            'books' => [
                ['Romans', 56, 57, 57, 'c. 56-57 AD', 'romans'],
                ['1 Corinthians', 54, 55, 54, 'c. 54-55 AD', '1-corinthians'],
                ['2 Corinthians', 55, 56, 56, 'c. 55-56 AD', '2-corinthians'],
                ['Galatians', 54, 55, 54, 'c. 54-55 AD', 'galatians'],
                ['Ephesians', 80, 90, 85, 'c. 80-90 AD', 'ephesians'],
                ['Philippians', 54, 62, 61, 'c. 60-62 AD', 'philippians'],
                ['Colossians', 60, 80, 70, 'c. 60 or 70-80 AD', 'colossians'],
                ['1 Thessalonians', 50, 51, 50, 'c. 50-51 AD', '1-thessalonians'],
                ['2 Thessalonians', 50, 100, 75, 'c. 50-51 or 80-100 AD', '2-thessalonians'],
                ['Philemon', 54, 62, 61, 'c. 60-62 AD', 'philemon'],
            ],
        ],
        'gospels_acts' => [
            'side'  => 'st',
            'label' => 'Gospels & Acts',
            'full'  => 'Synoptic Gospels & Acts',
            'books' => [
                ['Matthew', 70, 90, 85, 'c. 80-90 AD', 'matthew'],
                ['Mark', 65, 75, 68, 'c. 65-75 AD', 'mark'],
                ['Luke', 80, 90, 88, 'c. 80-90 AD', 'luke'],
                ['Acts', 80, 95, 85, 'c. 80-90 AD', 'acts'],
            ],
        ],
        'johannine' => [
            'side'  => 'st',
            'label' => 'Johannine Works',
            'full'  => 'The Johannine Works',
            'books' => [
                ['John', 90, 110, 95, 'c. 90-110 AD', 'john'],
                ['1 John', 95, 110, 100, 'c. 95-110 AD', '1-john'],
                ['2 John', 95, 110, 100, 'c. 95-110 AD', '2-john'],
                ['3 John', 95, 110, 100, 'c. 95-110 AD', '3-john'],
                ['Apocalypse of John', 90, 96, 95, 'c. 90-96 AD', 'apocalypse-of-john'],
            ],
        ],
        'pastoral_epistles' => [
            'side'  => 'st',
            'label' => 'Pastoral Epistles',
            'full'  => 'The Pastoral Epistles',
            'books' => [
                ['1 Timothy', 90, 110, 100, 'c. 90-110 AD', '1-timothy'],
                ['2 Timothy', 90, 110, 100, 'c. 90-110 AD', '2-timothy'],
                ['Titus', 90, 110, 100, 'c. 90-110 AD', 'titus'],
            ],
        ],
        'catholic_epistles' => [
            'side'  => 'st',
            'label' => 'Catholic Epistles',
            'full'  => 'The Catholic Epistles',
            'books' => [
                ['Hebrews', 60, 95, 85, 'c. 60-95 AD', 'hebrews'],
                ['James', 70, 100, 85, 'c. 70-100 AD', 'james'],
                ['1 Peter', 75, 95, 85, 'c. 75-95 AD', '1-peter'],
                ['2 Peter', 100, 130, 115, 'c. 100-130 AD', '2-peter'],
                ['Jude', 70, 100, 85, 'c. 70-100 AD', 'jude'],
            ],
        ],
        'st_apocrypha' => [
            'side'  => 'st',
            'label' => 'Apocrypha',
            'full'  => 'Second Testament Apocrypha',
            'books' => [
                ['1 Clement', 95, 96, 96, 'c. 95-96 AD', '1-clement'],
                ['2 Clement', 140, 160, 150, 'c. 140-160 AD', '2-clement'],
                ['Didache', 50, 120, 105, 'c. 90-120 AD', 'didache'],
                ['Epistle of Barnabas', 130, 132, 130, 'c. 130-132 AD', 'epistle-of-barnabas'],
                ['Shepherd of Hermas', 90, 155, 120, 'c. 90-150 AD', 'shepherd-of-hermas'],
                ['Apocalypse of Peter', 130, 150, 135, 'c. 130-150 AD', 'apocalypse-of-peter'],
                ['Acts of Paul', 160, 190, 175, 'c. 160-190 AD', 'acts-of-paul'],
            ],
        ],
    ];

    /* ===================================================================
       EDIT ZONE 3 — THE WITNESSES & MILESTONES
       Events on the spine: ancient readers who testify to which books
       counted, and the moments the collection visibly moved. Each line:
       [key, year, name, 'year display', 'one-line testimony'] — the
       testimony appears in the popover when the chip is tapped. Keep the
       list in year order. Chips sit on the spine at their year; crowded
       ones get nudged down by the page script (their dot stays put).

       NOTE on the codices: 1-2 Clement ride in Codex ALEXANDRINUS
       (c. 425) — Vaticanus' Second Testament is lost after Hebrews and
       never carried them. Swap the name below if you'd rather credit a
       different witness.
       =================================================================== */
    private const WITNESSES = [
        ['josiah', -622, "Josiah's Reformers", '622 BC',
         "During temple repairs under King Josiah, the high priest Hilkiah finds 'the Book of the Law' (2 Kings 22) widely identified with the core of Deuteronomy."],
        ['ezra-reads', -444, 'Ezra Reads the Law', 'c. 444 BC',
         "Ezra reads 'the Book of the Law of Moses' aloud to the whole assembly in Jerusalem (Nehemiah 8)."],
        ['septuagint', -260, 'The LXX Begins', 'c. 280-250 BC',
         "Work begins on the Septuagint project (LXX) by translating the first five books of Moses from Hebrew into Greek for the Greek-speaking Jews of the Diaspora. The process of translating the entire First Testament would last until the first century AD."],
        ['sirach-prologue', -132, 'Prologue to Sirach', 'c. 132 BC',
         "Ben Sira's grandson, translating Sirach into Greek, describes \"the Law, the Prophets, and the other books of our ancestors\" which is the earliest surviving witness to the three-part Hebrew canon."],
        ['qumran', -100, 'Dead Sea Scrolls', 'c. 250 BC-70 AD',
         'The library hidden in the caves at Qumran preserved 1 Enoch, Jubilees, Psalm 151, 154, 155, Sirach, Tobit, and many other apocryphal works side by side with the books of the Hebrew canon.'],
        ['philo', 40, 'Philo of Alexandria', 'c. 40 AD',
         "The great Jewish philosopher of Alexandria treats the Greek Torah as scripture of the highest order, quoting it thousands of times in his commentaries. Nevii'm quotations are reserved for a few prophets like Jeremiah, while Ketuvim quotations seem limited to the Psalms."],
        ['josephus', 95, 'Josephus', 'c. 95 AD',
         'In Against Apion, the Jewish historian counts "only twenty-two books, justly accredited" though he relies on the Greek Deuterocanonical book 1 Esdras when he recounts the story of the Three Guardsmen in Antiquities of the Jews Book 11.'],
        ['fourth-ezra', 100, '4 Ezra', 'c. 100 AD',
         'The apocalypse preserved inside 2 Esdras has Ezra restore twenty-four books for everyone to read, with seventy more reserved for the wise.'],
        ['marcion', 130, "Marcion's Canon", 'c. 130-140 AD',
         'Marcion of Sinope produced the first Christan canon of the Second Testament, which includes an edited copy of Luke, Galatians, 1 Cor, 2 Cor, Romans, 1 Thess, 2 Thess, Colossians, Philippians, Philemon, and Laodiceans. He rejected the Hebrew Bible entirely.'],
        ['melito', 170, 'Melito of Sardis', 'c. 170 AD',
         'Melito traveled east to establish "the exact number of the ancient books" thereby creating the earliest surviving Christian list of the First Testament, which excluded the Deuterocanononical books.'],
        ['muratorian', 185, 'Muratorian Canon', 'c. 170-200 AD',
         'This ancient Latin list of Second Testament books includes the Apocalypse of Peter and the Shepherd of Hermas but omits Hebrews, 1 Peter, 2 Peter, and James.'],
        ['clement-alex', 200, 'Clement of Alexandria', 'c. 200 AD',
         'Clement quotes (as divine scripture) freely from many apocryphal works: 1 Enoch, Sirach, Wisdom, Baruch, the Epistle of Barnabas, 1 Clement, and the Apocalypse of Peter among them.'],
        ['origen', 250, 'Origen', 'c. 250 AD',
         "The church's great textual scholar counts twenty-two books for the First Testament and defends the Church's continued use of the Deuterocanonical books. Like his teacher Clement of Alexandria, he quotes from 1 Clement, the Didache, and the Shepherd of Hermas as scripture."],
        ['eusebius', 325, 'Eusebius of Caesarea', 'c. 325 AD',
         'Every Christian book is categorized by the great historian. Canonical books like James, Jude, 2 John, and 3 John were categorized as disputed, while books like the Acts of Paul, the Apocalypse of Peter, the Didache, and the Epistle of Barnabas were listed as disputed yet reconized by  many churches.'],
        ['sinaiticus', 350, 'Codex Sinaiticus', 'c. 350 AD',
         'The oldest complete Second Testament includes the Shepherd of Hermas and the Epistle of Barnabas right after the Apocalypse of John.'],
        ['athanasius', 367, 'Athanasius of Alexandria', '367 AD',
         'His 39th Festal Letter names the twenty-seven books of the Second Testament exactly as most Bibles have them today.'],
        ['rome-382', 382, 'Council of Rome', '382 AD',
         'This council under Pope Damasus I is traditionally credited with the Decretum Gelasianum, whose canon list explicitly includes the Deuterocanonical books.'],
        ['alexandrinus', 425, 'Codex Alexandrinus', 'c. 425 AD',
         'This ancient Greek Bible includes Tobit, Judith, Sirach, Baruch, Psalm 151, the Prayer of Manasseh, 1-4 Maccabees, and it closes its Second Testament with 1 Clement and 2 Clement.'],
    ];

    /* =================================================================== */

    public function index()
    {
        $colors = config('canon.section_colors', []);

        $height = $this->yPx(self::TL_END) + self::PAD_BOTTOM;
        $spine  = ['top' => self::PAD_TOP, 'bottom' => self::PAD_BOTTOM];

        // ---- Ticks: one per century, majors keep labels on mobile.
        $ticks = [];
        for ($y = -1000; $y <= self::TICK_END; $y += self::TICK_STEP) {
            $ticks[] = [
                'top'   => round($this->yPx($y), 1),
                'label' => $this->yearLabel($y),
                'major' => ($y % self::MAJOR_STEP) === 0,
            ];
        }

        // ---- Groups: ranges, counts, geometry.
        $sideCounts = ['ft' => 0, 'st' => 0];
        $groups = [];

        foreach (self::GROUPS as $key => $g) {
            $starts = array_column($g['books'], 1);
            $ends   = array_column($g['books'], 2);
            $sorts  = array_column($g['books'], 3);
            sort($sorts);
            $median = $sorts[intdiv(count($sorts), 2)];

            $start = min($starts);
            $end   = max($ends);

            $groups[$key] = [
                'key'      => $key,
                'side'     => $g['side'],
                'label'    => $g['label'],
                'full'     => $g['full'],
                'color'    => $colors[$key] ?? 'clay',
                'count'    => count($g['books']),
                'start'    => $start,
                'end'      => $end,
                'range'    => $this->rangeDisplay($start, $end),
                'top'      => round($this->yPx($start), 1),
                'height'   => round($this->yPx($end) - $this->yPx($start), 1),
                'card_tgt' => round($this->yPx($median), 1),
                'lane'     => 0,
            ];
            $sideCounts[$g['side']] += count($g['books']);
        }

        // Whisker lanes: greedy packing across ALL groups (r2 — the bars
        // share one cluster right of the spine), lane 0 nearest the spine.
        $byStart = $groups;
        uasort($byStart, fn ($a, $b) => $a['start'] <=> $b['start']);
        $laneEnds = [];
        foreach ($byStart as $gKey => $g) {
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] + self::LANE_GAP_YEARS > $g['start']) {
                $lane++;
            }
            $laneEnds[$lane] = $g['end'];
            $groups[$gKey]['lane'] = $lane;
        }
        $lanes = count($laneEnds);

        // Card pre-pack: ONE left-hand column (r2), ordered by target,
        // pushed down on collision. (The page script re-packs with real
        // heights; this is the no-JS fallback and the first paint.)
        uasort($groups, fn ($a, $b) => $a['card_tgt'] <=> $b['card_tgt']);
        $cursor = -INF;
        foreach ($groups as $gKey => $g) {
            $top = max($g['card_tgt'], $cursor);
            $groups[$gKey]['card_top'] = round($top, 1);
            $cursor = $top + self::CARD_EST_H + self::CARD_GAP;
        }

        // ---- Witnesses: dot at the true year; chip pre-packed below it.
        $witnesses = [];
        $cursor = -INF;
        foreach (self::WITNESSES as [$key, $year, $name, $yearDisplay, $blurb]) {
            $dot  = round($this->yPx($year), 1);
            $chip = max($dot + 8, $cursor);
            $witnesses[] = [
                'key'      => $key,
                'name'     => $name,
                'year'     => $yearDisplay,
                'dot_top'  => $dot,
                'chip_top' => round($chip, 1),
                'chip_tgt' => round($dot + 8, 1),
            ];
            $cursor = $chip + self::CHIP_EST_H + self::CHIP_GAP;
        }

        // ---- Book links (r2): slug → translation slug, so the popover
        //      lists link into the reader. Cached as plain scalars only
        //      (house rule: never cache route() URLs); URLs are built here
        //      at render time.
        $linkTx = $this->bookLinkTranslations();

        // ---- Popover payload: ONE pre-computed variable for ONE json
        //      directive in the blade (house rule). Books: [name, dates, url|null].
        $pop = [];
        foreach ($groups as $g) {
            $books = [];
            foreach (self::GROUPS[$g['key']]['books'] as $b) {
                $slug = $b[5];
                $url  = isset($linkTx[$slug])
                    ? route('bible.book', ['translation' => $linkTx[$slug], 'book' => $slug])
                    : null;
                $books[] = [$b[0], $b[4], $url];
            }
            $pop[$g['key']] = [
                't'     => $g['full'],
                's'     => $g['count'] . ' books · ' . $g['range'],
                'books' => $books,
            ];
        }
        foreach (self::WITNESSES as [$key, $year, $name, $yearDisplay, $blurb]) {
            $pop['auth-' . $key] = ['t' => $name, 's' => $yearDisplay, 'b' => $blurb];
        }

        $total = $sideCounts['ft'] + $sideCounts['st'];   // 91, derived

        return view('extras.why-91-books', [
            'w91' => [
                'height'    => round($height),
                'spine'     => $spine,
                'ticks'     => $ticks,
                'lanes'     => $lanes,
                'groups'    => $groups,
                'sides'     => [
                    'ft' => ['label' => 'First Testament',  'count' => $sideCounts['ft']],
                    'st' => ['label' => 'Second Testament', 'count' => $sideCounts['st']],
                ],
                'witnesses' => $witnesses,
                'total'     => $total,
            ],
            'w91Pop' => $pop,
        ]);
    }

    /* ---- helpers ---------------------------------------------------- */

    // slug → translation slug for every book that actually has verses.
    // Order of preference: a per-book override from config canon
    // 'book_default_translations' (e.g. the Five Psalms of David point
    // at Wright, the only edition carrying all five), then global
    // editions (reader defaults) by sort order, then any edition that
    // carries the book (Lake for the Apostolic Fathers). Plain scalars
    // only in the cache; a DB hiccup degrades to unlinked names, never
    // an error page. Bump the cache key when the rules change.
    private function bookLinkTranslations(): array
    {
        $slugs = [];
        foreach (self::GROUPS as $g) {
            foreach ($g['books'] as $b) {
                $slugs[] = $b[5];
            }
        }

        try {
            return cache()->remember('w91.link_tx.v2', 21600, function () use ($slugs) {
                $books = \App\Models\Book::whereIn('slug', $slugs)->get(['id', 'slug']);
                if ($books->isEmpty()) {
                    return [];
                }

                $rows = DB::table('verses')
                    ->select('book_id', 'translation_id')
                    ->distinct()
                    ->whereIn('book_id', $books->pluck('id'))
                    ->get();
                $has = [];
                foreach ($rows as $r) {
                    $has[$r->book_id][$r->translation_id] = true;
                }

                // Globals first (reader defaults, by sort order), then the rest.
                $editions = \App\Models\Translation::orderByDesc('is_global')
                    ->orderBy('sort_order')
                    ->get(['id', 'abbreviation']);

                // Per-book default overrides (config canon), slug → edition
                // slug. An override only wins when that edition actually has
                // the book's verses; otherwise the normal order applies.
                $overrides = config('canon.book_default_translations', []);
                $byAbbr = [];
                foreach ($editions as $t) {
                    $byAbbr[strtolower($t->abbreviation)] = $t;
                }

                $out = [];
                foreach ($books as $book) {
                    $want = $overrides[$book->slug] ?? null;
                    if ($want && isset($byAbbr[$want]) && isset($has[$book->id][$byAbbr[$want]->id])) {
                        $out[$book->slug] = $want;
                        continue;
                    }
                    foreach ($editions as $t) {
                        if (isset($has[$book->id][$t->id])) {
                            $out[$book->slug] = strtolower($t->abbreviation);
                            break;
                        }
                    }
                }
                return $out;
            });
        } catch (\Throwable $e) {
            return [];   // unlinked names beat a broken page
        }
    }

    // Year → vertical px, through the piecewise era scale.
    private function yPx(float $year): float
    {
        $px = self::PAD_TOP;
        foreach (self::ERAS as [$from, $to, $ppy]) {
            if ($year <= $from) {
                break;
            }
            $px += (min($year, $to) - $from) * $ppy;
        }
        return $px;
    }

    private function yearLabel(int $year): string
    {
        if ($year < 0) {
            return abs($year) . ' BC';
        }
        return ($year === 0 ? 1 : $year) . ' AD';
    }

    // "c. 950-400 BC" / "c. 300 BC-270 AD" / "c. 50-100 AD"
    private function rangeDisplay(int $s, int $e): string
    {
        if ($e <= 0) {
            return 'c. ' . abs($s) . '–' . abs(min($e, -1)) . ' BC';
        }
        if ($s > 0) {
            return 'c. ' . $s . '–' . $e . ' AD';
        }
        return 'c. ' . abs($s) . ' BC–' . $e . ' AD';
    }
}
