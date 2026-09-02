<?php

return [

    // ──────────────────────────────────────────────────────────────────────
    // HOMEPAGE DISPLAY STRUCTURE
    //
    // This file is the single source of truth for how books are *grouped and
    // ordered on the homepage*. It is purely cosmetic — it does NOT touch the
    // database. Books are referenced by their `slug` (the same slug seeded in
    // BookSeeder.php); the controller resolves each slug to a Book model.
    //
    // A section lists its books in one of two ways:
    //   'books'     => [ ...slugs... ]                         → one flat grid
    //   'subgroups' => [ ['label' => '…', 'books' => [...]], ] → labelled grids
    // Use whichever fits the section.
    // ──────────────────────────────────────────────────────────────────────

    // Top-level buckets, in display order.
    'testaments' => [
        'first' => [
            'label' => 'First Testament',
            'blurb' => [
                'The Hebrew Scriptures. These are the first documents of the revelation of YHWH to mankind, the earliest of which were written 3,000 years ago.',
                'These books are arranged in the Hebrew Tanakh format: Torah, Nevi’im, and Ketuvim. Also included are the wider Deuterocanonical works and lesser known Apocryphal texts.',
            ],
            'sections' => ['torah', 'neviim', 'ketuvim', 'ft_deuterocanon', 'ft_apocrypha'],
        ],
        'second' => [
            'label' => 'Second Testament',
            'blurb' => [
                'The Christian Scriptures. Not a replacement but a continuation, the writers of the Second Testament entirely relied on the framework and vocabulary of the First.',
                'Written and compiled 2,000 years ago in the ancient Roman Empire, the recognized Second Testament documents include the first Pauline Epistles, the Synoptic Gospels and Acts of the Apostles, the Johannine Writings, the Pastoral Epistles, the Catholic Epistles, and an expanded Apocryphal tradition of scriptures which were considered canon in centuries past.',
            ],
            'sections' => ['pauline_epistles', 'gospels_acts', 'johannine', 'pastoral_epistles', 'catholic_epistles', 'st_apocrypha'],
        ],
    ],

    // Section metadata + book layout. `subtitle` is the small italic gloss;
    // null hides it.
    'sections' => [

        // ---------- FIRST TESTAMENT ----------
        'torah' => [
            'label'    => 'Torah',
            'subtitle' => 'The Law',
            'books'    => ['genesis', 'exodus', 'leviticus', 'numbers', 'deuteronomy'],
        ],

        'neviim' => [
            'label'    => 'Nevi’im',
            'subtitle' => 'The Prophets',
            'subgroups' => [
                ['label' => 'Early Prophets', 'books' => [
                    'joshua', 'judges', '1-samuel', '2-samuel', '1-kings', '2-kings',
                ]],
                ['label' => 'Major Prophets', 'books' => [
                    'isaiah', 'jeremiah', 'ezekiel',
                ]],
                ['label' => 'The Twelve', 'books' => [
                    'hosea', 'joel', 'amos', 'obadiah', 'jonah', 'micah',
                    'nahum', 'habakkuk', 'zephaniah', 'haggai', 'zechariah', 'malachi',
                ]],
            ],
        ],

        'ketuvim' => [
            'label'    => 'Ketuvim',
            'subtitle' => 'The Writings',
            'subgroups' => [
                ['label' => 'Sifrei Emet (Documents of Truth)', 'books' => [
                    'psalms', 'proverbs', 'job',
                ]],
                ['label' => 'Hamesh Megillot (Five Scrolls)', 'books' => [
                    'song-of-solomon', 'ruth', 'lamentations', 'ecclesiastes', 'esther',
                ]],
                ['label' => 'Other Writings', 'books' => [
                    'daniel', 'ezra', 'nehemiah', '1-chronicles', '2-chronicles',
                ]],
            ],
        ],

        'ft_deuterocanon' => [
            'label'    => 'Deuterocanon',
            'subtitle' => 'The Wider Tradition',
            'books'    => [
                'tobit', 'judith', 'greek-esther', 'greek-daniel',
                'sirach','baruch', 'wisdom-of-solomon', 
                '1-maccabees', '2-maccabees',
            ],
        ],

        'ft_apocrypha' => [
            'label'    => 'Apocrypha',
            'subtitle' => 'Authoritative Jewish and Christian Texts',
            'books' => [
                '1-esdras', '2-esdras', 'prayer-of-manasseh',
                'five-psalms-of-david', '3-maccabees', '4-maccabees',
                '1-enoch', 'jubilees', '2-baruch',
            ],
        ],

        // ---------- SECOND TESTAMENT ----------
		'pauline_epistles' => [
            'label'    => 'Pauline Epistles',
            'subtitle' => 'Corpus Paulinum',
            'books'    => [
                    'galatians', '1-thessalonians', '2-thessalonians', 'romans',
                    '1-corinthians', '2-corinthians', 'philippians', 'ephesians', 'colossians',
                    'philemon',
                ],
        ],
		
        'gospels_acts' => [
            'label'    => 'The Synoptic Gospels and Acts',
            // 'subtitle' => 'The Life of Jesus and Acts of the Apostles',
            // MARK FIRST
            'books'    => ['mark', 'matthew', 'luke', 'acts'],
        ],

        'johannine' => [
            'label'    => 'Johannine Works',
            'subtitle' => 'Corpus Johanneum',
            'books'    => ['john','1-john', '2-john', '3-john','apocalypse-of-john'],
        ],        
		
		'pastoral_epistles' => [
            'label'    => 'Pastoral Epistles',
            'subtitle' => 'Corpus Pastorale',
            'books'    => [
                    '1-timothy', '2-timothy', 'titus',
                ],
        ],
		
		'catholic_epistles' => [
            'label'    => 'Catholic Epistles',
            'subtitle' => 'Corpus Apostolicum',
            'books'    => [
                    'james', '1-peter', '2-peter', 'jude', 'hebrews',
                ],
        ],

        'st_apocrypha' => [
            'label'    => 'Apocrypha',
            'subtitle' => 'Formerly Canonical Works',
            'books'    => [
                'apocalypse-of-peter','1-clement', '2-clement','didache', 'epistle-of-barnabas', 'shepherd-of-hermas',
                'acts-of-paul',
            ],
        ],

    ],

    // ──────────────────────────────────────────────────────────────────────
    // QUICKNAV BUTTON COLOURS
    //
    // One timeline-palette colour per section, used by the logo "quicknav"
    // popup to tint each book button by its group. Values are the bare palette
    // names defined in app.blade.php as --tl-<name>.
    // ──────────────────────────────────────────────────────────────────────
    'section_colors' => [
        // ---------- FIRST TESTAMENT ----------
        'torah'           	=> 'gold',
        'neviim'          	=> 'terracotta',
        'ketuvim'         	=> 'teal',
        'ft_deuterocanon' 	=> 'olive',
        'ft_apocrypha'    	=> 'moss',
        // ---------- SECOND TESTAMENT ----------
		'pauline_epistles'  => 'navy',
        'gospels_acts'      => 'crimson',
        'johannine'      	=> 'royal',
        'pastoral_epistles' => 'plum',
        'catholic_epistles' => 'plum',
        'st_apocrypha'    	=> 'indigo',
    ],    

    /*
    |--------------------------------------------------------------------------
    | Homepage display-name overrides
    |--------------------------------------------------------------------------
    | Hard overrides, always shown on the index instead of the DB book name.
    | The book hub page and the quicknav keep the DB name — only the
    | homepage tile differs. (home_short_names still handles the narrow-
    | width swap and works on top of these.)
    */
    'home_names' => [
        'five-psalms-of-david' => 'Psalms 151–155',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reader label overrides
    |--------------------------------------------------------------------------
    | Keyed by OSIS id. Books listed here render in the reader (h1, tab/SEO
    | title, copy references, focus-mode label) as name + (chapter + offset)
    | instead of "BookName N". DB chapters, URLs, and routes are untouched.
    |
    |   Five Psalms of David: chapters 1–5 display as Psalm 151–155.
    */
    'reader_labels' => [
        'Ps151' => ['name' => 'Psalm', 'chapter_offset' => 150],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search chapter remaps
    |--------------------------------------------------------------------------
    | Lets the search box resolve a reference that a user thinks of as a
    | chapter of one book but that we store as a chapter of another. A hit
    | whose slug is 'book' and whose chapter falls in [from, to] is
    | redirected to 'target', its chapter shifted by 'offset'. Verse lists
    | ride along unchanged. Read by ReferenceResolver via SearchController.
    |
    |   "Psalm 152" -> five-psalms-of-david chapter 2  (152 + -150).
    | Out-of-window chapters ("Psalm 90") are untouched.
    */
    'chapter_remaps' => [
        [
            'book'   => 'psalms',
            'from'   => 151,
            'to'     => 155,
            'target' => 'five-psalms-of-david',
            'offset' => -150,
        ],
    ],

    // ──────────────────────────────────────────────────────────────────────
    // HOMEPAGE SHORT NAMES (narrowest screens only)
    //
    // Optional per-book label used ONLY at the mobile breakpoint, so long names
    // (e.g. "1 Thessalonians") don't wrap onto a second row and break the even
    // one-line grid. Keyed by book `slug`. Any book NOT listed here just uses
    // its full name at every width. Has zero effect above the mobile breakpoint.
    // This is purely cosmetic.
    // ──────────────────────────────────────────────────────────────────────
    'home_short_names' => [

        // ---------- FIRST TESTAMENT ----------
        'song-of-solomon'    => 'Song of Sol',
        'wisdom-of-solomon'  => 'Wisdom of Sol',
        'prayer-of-manasseh' => 'Prayer of Man',
        'five-psalms-of-david' => 'Ps 151-155',

        // ---------- SECOND TESTAMENT ----------
        '1-thessalonians'     => '1 Thess',
        '2-thessalonians'     => '2 Thess',
        'epistle-of-barnabas' => 'Ep Barnabas',
        'shepherd-of-hermas'  => 'Shep of Herm',
		'apocalypse-of-john' => 'Apoc of John',
        'apocalypse-of-peter' => 'Apoc of Peter',

    ],

];