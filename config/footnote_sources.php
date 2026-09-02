<?php

/**
 * Footnote source attribution.
 *
 * Same schema and role as config/heading_sources.php, but for footnotes: one
 * entry per footnote source, keyed by the `source_key` stamped onto footnotes
 * rows at import time. BibleController looks these up to build the
 * "N footnotes from … · {license} · Source: {host}" colophon line under each
 * chapter. A missing key falls back to showing the key itself as the name, so
 * nothing breaks if you forget to add one.
 */
return [

    // Translators' footnotes shipped inside the World English Bible USFM
    // (\f + \fr … \ft … \f*). Imported by footnotes:import-usfm from the same
    // eBible.org files the WEB verse text came from, so verse alignment is
    // guaranteed by construction.
    'web' => [
        'name'       => 'World English Bible',
        'source_url' => 'https://ebible.org/eng-web/',
        'license'    => 'Public Domain',
        'notes'      => 'Translators\' footnotes extracted from the WEB USFM',
    ],

    // F. H. A. Scrivener's marginal notes from The Cambridge Paragraph Bible
    // (Cambridge, 1873) — the edition the hosted KJVCPB text comes from, so
    // anchors match the hosted verses exactly. Not present in the eBible.org
    // digitization; transcribed by hand from the public domain scans at
    // archive.org/details/cambridgeparagra00scri via the TSV importer.
    // (Future phase — key registered now so the schema is settled.)
    'scrivener-1873' => [
        'name'       => 'F. H. A. Scrivener',
        'source_url' => 'https://archive.org/details/cambridgeparagra00scri',
        'license'    => 'Public Domain',
        'notes'      => 'Marginal notes transcribed from the Cambridge Paragraph Bible (1873)',
    ],

    // Kirsopp Lake's translator footnotes from The Apostolic Fathers, Loeb
    // Classical Library (Heinemann / Putnam, 1912-13) — the edition the hosted
    // LAKE text comes from, so the notes and the verses are the same witness.
    // Extracted from the Internet Archive scans and imported via
    // footnotes:import-tsv. Matches the 'lake' key in heading_sources.php.
    //
    // These are Lake's ENGLISH notes only, printed at the foot of the
    // translation page. The Loeb also carries a Greek textual apparatus on the
    // facing page; that is a different body of work with its own conventions
    // and sigla, and if it is ever imported it should get its own key rather
    // than being folded in here.
    'lake' => [
        'name'       => 'Kirsopp Lake',
        'source_url' => 'https://archive.org/details/TheApostolicFathersV1',
        'license'    => 'Public Domain',
        'notes'      => 'Translator\'s footnotes transcribed from the Loeb edition (1912-13)',
    ],

    // Original MEGABIBLE.net editorial notes — use this source_key when YOU
    // wrote the note rather than transcribing it from a printed source.
    'megabible' => [
        'name'       => 'MEGABIBLE.net',
        'source_url' => 'https://megabible.net/sources',
        'license'    => 'Public Domain',
        'notes'      => 'Original editorial footnotes created by MEGABIBLE.net for the Public Domain',
    ],

];
