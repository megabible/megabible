<?php

/*
|--------------------------------------------------------------------------
| Interlinear (original languages) configuration
|--------------------------------------------------------------------------
| Follows the same pattern as config/heading_sources.php: data attribution
| and display metadata live in config, never hard-coded in views or JS.
*/

return [

    /*
    | Display metadata per language code stored in original_tokens.lang.
    | `rtl` drives the dir="rtl" attribute on the card's original row.
    | Adding a future language (Ge'ez, Latin) is one line here + an import.
    */
    'languages' => [
        'hbo' => ['name' => 'Hebrew',  'rtl' => true],
        'arc' => ['name' => 'Aramaic', 'rtl' => true],
        'grc' => ['name' => 'Greek',   'rtl' => false],
    ],

    /*
    | Attribution per original_tokens.source_key, surfaced in the card's
    | credit line and (eventually) the chapter colophon. CC BY 4.0 requires
    | the credit + link; keep both intact.
    */
    'sources' => [

        'tahot' => [
            'short'    => 'TAHOT',
            'name'     => 'Translators Amalgamated Hebrew OT',
            'provider' => 'STEP Bible',
            'url'      => 'https://github.com/STEPBible/STEPBible-Data',
            'license'  => 'CC BY 4.0',
        ],

        'tagnt' => [
            'short'    => 'TAGNT',
            'name'     => 'Translators Amalgamated Greek NT',
            'provider' => 'STEP Bible',
            'url'      => 'https://github.com/STEPBible/STEPBible-Data',
            'license'  => 'CC BY 4.0',
        ],

        // When TAGOT lands, it's one more entry here — no migration, no
        // code change, just data:
        // 'tagot' => [
        //     'short'    => 'TAGOT',
        //     'name'     => 'Translators Amalgamated Greek OT (LXX)',
        //     'provider' => 'STEP Bible',
        //     'url'      => 'https://github.com/STEPBible/STEPBible-Data',
        //     'license'  => 'CC BY 4.0',
        // ],

        // And a non-STEP source slots in exactly the same way — this is
        // why provider must be data, not hardcoded in the view:
        // 'some_lxx' => [
        //     'short'    => 'Rahlfs LXX',
        //     'name'     => 'Rahlfs–Hanhart Septuagint',
        //     'provider' => 'Some Provider',
        //     'url'      => 'https://example.org/...',
        //     'license'  => 'CC BY-SA 4.0',
        // ],
    ],

    /*
    | STEPBible's 3-letter book codes → the OSIS ids seeded in BookSeeder.
    | STEPBible refs look like "Gen.1.1#01" / "Phm.1.1#01"; this map turns
    | their code into ours. Codes not listed here are skipped with a warning
    | (deliberate: TAHOT's apparatus rows for LXX additions use codes we
    | don't import yet).
    */
    'stepbible_books' => [
        // ---------- First Testament ----------
        'Gen' => 'Gen',   'Exo' => 'Exod',  'Lev' => 'Lev',   'Num' => 'Num',
        'Deu' => 'Deut',  'Jos' => 'Josh',  'Jdg' => 'Judg',  'Rut' => 'Ruth',
        '1Sa' => '1Sam',  '2Sa' => '2Sam',  '1Ki' => '1Kgs',  '2Ki' => '2Kgs',
        '1Ch' => '1Chr',  '2Ch' => '2Chr',  'Ezr' => 'Ezra',  'Neh' => 'Neh',
        'Est' => 'Esth',  'Job' => 'Job',   'Psa' => 'Ps',    'Pro' => 'Prov',
        'Ecc' => 'Eccl',  'Sng' => 'Song',  'Isa' => 'Isa',   'Jer' => 'Jer',
        'Lam' => 'Lam',   'Ezk' => 'Ezek',  'Dan' => 'Dan',   'Hos' => 'Hos',
        'Jol' => 'Joel',  'Amo' => 'Amos',  'Oba' => 'Obad',  'Jon' => 'Jonah',
        'Mic' => 'Mic',   'Nam' => 'Nah',   'Hab' => 'Hab',   'Zep' => 'Zeph',
        'Hag' => 'Hag',   'Zec' => 'Zech',  'Mal' => 'Mal',

        // ---------- Second Testament ----------
        'Mat' => 'Matt',  'Mrk' => 'Mark',  'Luk' => 'Luke',  'Jhn' => 'John',
        'Act' => 'Acts',  'Rom' => 'Rom',   '1Co' => '1Cor',  '2Co' => '2Cor',
        'Gal' => 'Gal',   'Eph' => 'Eph',   'Php' => 'Phil',  'Col' => 'Col',
        '1Th' => '1Thess','2Th' => '2Thess','1Ti' => '1Tim',  '2Ti' => '2Tim',
        'Tit' => 'Titus', 'Phm' => 'Phlm',  'Heb' => 'Heb',   'Jas' => 'Jas',
        '1Pe' => '1Pet',  '2Pe' => '2Pet',  '1Jn' => '1John', '2Jn' => '2John',
        '3Jn' => '3John', 'Jud' => 'Jude',  'Rev' => 'Rev',
    ],

];
