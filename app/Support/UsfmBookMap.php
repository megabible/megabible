<?php

namespace App\Support;

/**
 * The Paratext (USFM) 3-letter book code => seeded Book osis_id map, plus the
 * peripheral non-book codes, extracted so every USFM-reading command shares
 * one source of truth.
 *
 * Used by footnotes:import-usfm today. ImportUsfm carries its own private
 * copy of the same map; swapping it to use this class is a safe one-line
 * refactor for a future housekeeping session (deliberately NOT done now, to
 * avoid touching a working importer mid-feature).
 */
class UsfmBookMap
{
    public const USFM_TO_OSIS = [
        'GEN'=>'Gen','EXO'=>'Exod','LEV'=>'Lev','NUM'=>'Num','DEU'=>'Deut','JOS'=>'Josh',
        'JDG'=>'Judg','RUT'=>'Ruth','1SA'=>'1Sam','2SA'=>'2Sam','1KI'=>'1Kgs','2KI'=>'2Kgs',
        '1CH'=>'1Chr','2CH'=>'2Chr','EZR'=>'Ezra','NEH'=>'Neh','EST'=>'Esth','JOB'=>'Job',
        'PSA'=>'Ps','PRO'=>'Prov','ECC'=>'Eccl','SNG'=>'Song','ISA'=>'Isa','JER'=>'Jer',
        'LAM'=>'Lam','EZK'=>'Ezek','DAN'=>'Dan','HOS'=>'Hos','JOL'=>'Joel','AMO'=>'Amos',
        'OBA'=>'Obad','JON'=>'Jonah','MIC'=>'Mic','NAM'=>'Nah','HAB'=>'Hab','ZEP'=>'Zeph',
        'HAG'=>'Hag','ZEC'=>'Zech','MAL'=>'Mal',
        'MAT'=>'Matt','MRK'=>'Mark','LUK'=>'Luke','JHN'=>'John','ACT'=>'Acts','ROM'=>'Rom',
        '1CO'=>'1Cor','2CO'=>'2Cor','GAL'=>'Gal','EPH'=>'Eph','PHP'=>'Phil','COL'=>'Col',
        '1TH'=>'1Thess','2TH'=>'2Thess','1TI'=>'1Tim','2TI'=>'2Tim','TIT'=>'Titus','PHM'=>'Phlm',
        'HEB'=>'Heb','JAS'=>'Jas','1PE'=>'1Pet','2PE'=>'2Pet','1JN'=>'1John','2JN'=>'2John',
        '3JN'=>'3John','JUD'=>'Jude','REV'=>'Rev',
        // Deuterocanon / Apocrypha:
        'TOB'=>'Tob','JDT'=>'Jdt','WIS'=>'Wis','SIR'=>'Sir','BAR'=>'Bar','ESG'=>'EsthGr','DAG'=>'DanGr',
        '1MA'=>'1Macc','2MA'=>'2Macc','1ES'=>'1Esd','2ES'=>'2Esd','MAN'=>'PrMan',
        'S3Y'=>'PrAzar','SUS'=>'Sus','BEL'=>'Bel',
        // WEB-only extras:
        'PS2'=>'Ps151','3MA'=>'3Macc','4MA'=>'4Macc',
    ];

    /** Peripheral USFM "books" that are not scripture. */
    public const NON_BOOKS = ['FRT','INT','BAK','OTH','CNC','GLO','TDX','NDX','PREF','PUB'];
}
