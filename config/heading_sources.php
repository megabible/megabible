<?php

/**
 * Heading source attribution.
 *
 * One entry per heading source. The key is either:
 *   - a shared set_key   (e.g. 'en-standard')  → credits shared_headings rows, or
 *   - a per-heading source_key (e.g. 'charles-1913') → credits `headings` rows
 *     that were stamped with that key at import time.
 *
 * BibleController looks these up to build the "N headings from … · Source: …"
 * colophon under each chapter. A missing key just falls back to showing the key
 * itself as the name, so nothing breaks if you forget to add one.
 */
return [

    // Curated section headings extracted from the Berean Standard Bible USFM.
    // This is the attribution for the 'en-standard' shared set (KJV, WEB).
    'en-standard' => [
        'name'       => 'Berean Standard Bible',
        'source_url' => 'https://ebible.org/engbsb/',
        'license'    => 'Public Domain',
        'notes'      => 'Section headings extracted from BSB USFM; verse text unchanged',
    ],

    // R. H. Charles, The Apocrypha and Pseudepigrapha of the Old Testament
    // (Oxford, 1913). For hand-entered headings in 1 Enoch, Jubilees, etc.
    'charles' => [
        'name'       => 'R. H. Charles',
        'source_url' => 'https://archive.org/',
        'license'    => 'Public Domain',
        'notes'      => 'Headings transcribed from Charles’ 1913 edition',
    ],

    // Kirsopp Lake
    'lake' => [
        'name'       => 'Kirsopp Lake',
        'source_url' => 'https://archive.org/details/TheApostolicFathersV1',
        'license'    => 'Public Domain',
        'notes'      => 'Headings extracted from PDF and plain text',
    ],

    // Original MEGABIBLE.net editorial headings (CC BY-SA 4.0 per the spec).
    // Use this source_key when YOU wrote the heading rather than lifting it.
    'megabible' => [
        'name'       => 'MEGABIBLE.net',
        'source_url' => 'https://megabible.net/sources',
        'license'    => 'Public Domain',
        'notes'      => 'Original section headings created by MEGABIBLE.net for the Public Domain',
    ],

];
