<?php

namespace Database\Seeders;

use App\Models\Book;
use Illuminate\Database\Seeder;

class BookSeeder extends Seeder
{
    public function run(): void
    {
        // section => [ [osis, slug, name, short, testament, book_order], ... ]
        // Position within each section array sets section_order (display order).
        $catalog = [

            // ---------- FIRST TESTAMENT ----------
            'torah' => [
                ['Gen',  'genesis',     'Genesis',     'Gen',  'OT', 1],
                ['Exod', 'exodus',      'Exodus',      'Exod', 'OT', 2],
                ['Lev',  'leviticus',   'Leviticus',   'Lev',  'OT', 3],
                ['Num',  'numbers',     'Numbers',     'Num',  'OT', 4],
                ['Deut', 'deuteronomy', 'Deuteronomy', 'Deut', 'OT', 5],
            ],

            // Authentic Tanakh ordering: Former + Latter Prophets + the Twelve.
            // (Ruth, Lamentations, Daniel sit in Ketuvim below, not here.)
            'neviim' => [
                ['Josh',  'joshua',   'Joshua',   'Josh',  'OT', 6],
                ['Judg',  'judges',   'Judges',   'Judg',  'OT', 7],
                ['1Sam',  '1-samuel', '1 Samuel', '1 Sam', 'OT', 9],
                ['2Sam',  '2-samuel', '2 Samuel', '2 Sam', 'OT', 10],
                ['1Kgs',  '1-kings',  '1 Kings',  '1 Kgs', 'OT', 11],
                ['2Kgs',  '2-kings',  '2 Kings',  '2 Kgs', 'OT', 12],
                ['Isa',   'isaiah',   'Isaiah',   'Isa',   'OT', 23],
                ['Jer',   'jeremiah', 'Jeremiah', 'Jer',   'OT', 24],
                ['Ezek',  'ezekiel',  'Ezekiel',  'Ezek',  'OT', 26],
                ['Hos',   'hosea',    'Hosea',    'Hos',   'OT', 28],
                ['Joel',  'joel',     'Joel',     'Joel',  'OT', 29],
                ['Amos',  'amos',     'Amos',     'Amos',  'OT', 30],
                ['Obad',  'obadiah',  'Obadiah',  'Obad',  'OT', 31],
                ['Jonah', 'jonah',    'Jonah',    'Jonah', 'OT', 32],
                ['Mic',   'micah',    'Micah',    'Mic',   'OT', 33],
                ['Nah',   'nahum',    'Nahum',    'Nah',   'OT', 34],
                ['Hab',   'habakkuk', 'Habakkuk', 'Hab',   'OT', 35],
                ['Zeph',  'zephaniah','Zephaniah','Zeph',  'OT', 36],
                ['Hag',   'haggai',   'Haggai',   'Hag',   'OT', 37],
                ['Zech',  'zechariah','Zechariah','Zech',  'OT', 38],
                ['Mal',   'malachi',  'Malachi',  'Mal',   'OT', 39],
            ],

            'ketuvim' => [
                ['Ps',    'psalms',          'Psalms',          'Ps',   'OT', 19],
                ['Prov',  'proverbs',        'Proverbs',        'Prov', 'OT', 20],
                ['Job',   'job',             'Job',             'Job',  'OT', 18],
                ['Song',  'song-of-solomon', 'Song of Solomon', 'Song', 'OT', 22],
                ['Ruth',  'ruth',            'Ruth',            'Ruth', 'OT', 8],
                ['Lam',   'lamentations',    'Lamentations',    'Lam',  'OT', 25],
                ['Eccl',  'ecclesiastes',    'Ecclesiastes',    'Eccl', 'OT', 21],
                ['Esth',  'esther',          'Esther',          'Esth', 'OT', 17],
                ['Dan',   'daniel',          'Daniel',          'Dan',  'OT', 27],
                ['Ezra',  'ezra',            'Ezra',            'Ezra', 'OT', 15],
                ['Neh',   'nehemiah',        'Nehemiah',        'Neh',  'OT', 16],
                ['1Chr',  '1-chronicles',    '1 Chronicles',    '1 Chr','OT', 13],
                ['2Chr',  '2-chronicles',    '2 Chronicles',    '2 Chr','OT', 14],
            ],

            // Deuterocanon — the books accepted in the Catholic/Orthodox canon.
            // Placeholder rows (no verses yet) render as "in progress" on the homepage.
            'ft_deuterocanon' => [
                ['Tob',    'tobit',              'Tobit',              'Tob',     'AP', 110],
                ['Jdt',    'judith',             'Judith',             'Jdt',     'AP', 111],
                ['EsthGr', 'greek-esther',       'Greek Esther',       'Esth Gr', 'AP', 112],
                ['Wis',    'wisdom-of-solomon',  'Wisdom of Solomon',  'Wis',     'AP', 113],
                ['Sir',    'sirach',             'Sirach',             'Sir',     'AP', 114],
                ['Bar',    'baruch',             'Baruch',             'Bar',     'AP', 115],
                ['DanGr',  'greek-daniel',       'Greek Daniel',       'Dan Gr',  'AP', 117],
                ['1Macc',  '1-maccabees',        '1 Maccabees',        '1 Macc',  'AP', 120],
                ['2Macc',  '2-maccabees',        '2 Maccabees',        '2 Macc',  'AP', 121],
            ],

            // Apocrypha — the wider tradition beyond the deuterocanon
            // (additional canonical-adjacent works + pseudepigrapha).
            'ft_apocrypha' => [
                ['PrMan',  'prayer-of-manasseh',    'Prayer of Manasseh',   'Pr Man',     'AP', 122],
                ['Ps151',  'five-psalms-of-david',  'Five Psalms of David', 'Ps Dvd',     'AP', 123],
                ['3Macc',  '3-maccabees',           '3 Maccabees',          '3 Macc',     'AP', 124],
                ['4Macc',  '4-maccabees',           '4 Maccabees',          '4 Macc',     'AP', 125],
                ['1Esd',   '1-esdras',              '1 Esdras',             '1 Esd',      'AP', 126],
                ['2Esd',   '2-esdras',              '2 Esdras',             '2 Esd',      'AP', 127],
                ['1En',    '1-enoch',               '1 Enoch',              '1 En',       'PS', 128],
                ['Jub',    'jubilees',              'Jubilees',             'Jub',        'PS', 129],
                ['2Bar',   '2-baruch',              '2 Baruch',             '2 Bar',      'PS', 130],
            ],

            // ---------- SECOND TESTAMENT ----------
            'gospels' => [
                ['Matt', 'matthew', 'Matthew', 'Matt', 'NT', 40],
                ['Mark', 'mark',    'Mark',    'Mark', 'NT', 41],
                ['Luke', 'luke',    'Luke',    'Luke', 'NT', 42],
                ['John', 'john',    'John',    'John', 'NT', 43],
            ],

            'acts' => [
                ['Acts', 'acts', 'Acts', 'Acts', 'NT', 44],
            ],

            'pauline' => [
                ['Rom',    'romans',           'Romans',          'Rom',     'NT', 45],
                ['1Cor',   '1-corinthians',    '1 Corinthians',   '1 Cor',   'NT', 46],
                ['2Cor',   '2-corinthians',    '2 Corinthians',   '2 Cor',   'NT', 47],
                ['Gal',    'galatians',        'Galatians',       'Gal',     'NT', 48],
                ['Eph',    'ephesians',        'Ephesians',       'Eph',     'NT', 49],
                ['Phil',   'philippians',      'Philippians',     'Phil',    'NT', 50],
                ['Col',    'colossians',       'Colossians',      'Col',     'NT', 51],
                ['1Thess', '1-thessalonians',  '1 Thessalonians', '1 Thess', 'NT', 52],
                ['2Thess', '2-thessalonians',  '2 Thessalonians', '2 Thess', 'NT', 53],
                ['1Tim',   '1-timothy',        '1 Timothy',       '1 Tim',   'NT', 54],
                ['2Tim',   '2-timothy',        '2 Timothy',       '2 Tim',   'NT', 55],
                ['Titus',  'titus',            'Titus',           'Titus',   'NT', 56],
                ['Phlm',   'philemon',         'Philemon',        'Phlm',    'NT', 57],
            ],

            // Hebrews is anonymous; grouped here with the General Epistles.
            'general' => [
                ['Heb',   'hebrews', 'Hebrews', 'Heb',    'NT', 58],
                ['Jas',   'james',   'James',   'James',  'NT', 59],
                ['1Pet',  '1-peter', '1 Peter', '1 Pet',  'NT', 60],
                ['2Pet',  '2-peter', '2 Peter', '2 Pet',  'NT', 61],
                ['1John', '1-john',  '1 John',  '1 John', 'NT', 62],
                ['2John', '2-john',  '2 John',  '2 John', 'NT', 63],
                ['3John', '3-john',  '3 John',  '3 John', 'NT', 64],
                ['Jude',  'jude',    'Jude',    'Jude',   'NT', 65],
            ],

            'apocalypse' => [
                ['Rev', 'apocalypse-of-john', 'Apocalypse of John', 'ApJohn', 'NT', 66],
            ],

            'st_apocrypha' => [
                ['Did',     'didache',             'Didache',             'Did',     'AP', 210],
                ['Barn',    'epistle-of-barnabas', 'Epistle of Barnabas', 'Barn',    'AP', 211],
                ['1Clem',   '1-clement',           '1 Clement',           '1 Clem',  'AP', 212],
                ['2Clem',   '2-clement',           '2 Clement',           '2 Clem',  'AP', 213],
                ['Herm',    'shepherd-of-hermas',  'Shepherd of Hermas',  'Herm',    'AP', 214],
                ['ApPet',   'apocalypse-of-peter', 'Apocalypse of Peter', 'ApPet',   'AP', 215],
                ['ActPaul', 'acts-of-paul',        'Acts of Paul',        'ActPaul', 'AP', 216],
            ],
        ];

        foreach ($catalog as $section => $books) {
            $sectionOrder = 1;
            foreach ($books as [$osis, $slug, $name, $short, $testament, $order]) {
                Book::updateOrCreate(
                    ['osis_id' => $osis],
                    [
                        'slug'          => $slug,
                        'name'          => $name,
                        'short_name'    => $short,
                        'testament'     => $testament,
                        'canon_section' => 'protestant',
                        'book_order'    => $order,
                        'section'       => $section,
                        'section_order' => $sectionOrder++,
                    ]
                );
            }
        }

        // ──────────────────────────────────────────────────────────────────
        // Retired books — removed from the catalogue above.
        //
        // updateOrCreate never deletes, so without this step re-running the
        // seeder would leave these rows orphaned in the DB (gone from the
        // homepage, but still present and still URL-resolvable). Listing them
        // explicitly keeps `db:seed` idempotent toward the desired state.
        //
        // The verses FK is cascadeOnDelete, so any verses would go too — these
        // were verse-less placeholders, so nothing of value is lost.
        // ──────────────────────────────────────────────────────────────────
        $retired = [
            'testaments-twelve-patriarchs',
            'letter-of-jeremiah',
            'prayer-of-azariah',
            'susanna',
            'bel-and-the-dragon',
        ];

        Book::whereIn('slug', $retired)->delete();
    }
}