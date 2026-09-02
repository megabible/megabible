<?php

// Extra abbreviations users type that aren't already a book's slug, name, or
// seeded short_name. Format:  'what they type' => 'book-slug'.
// Matching is case-insensitive and space-insensitive ("1 jn" also matches "1jn").

return [
    // Pentateuch
    'gn' => 'genesis',
    'ex' => 'exodus', 'exo' => 'exodus',
    'lv' => 'leviticus',
    'nm' => 'numbers', 'nb' => 'numbers',
    'dt' => 'deuteronomy',

    // History
    'jos' => 'joshua',
    'jdg' => 'judges', 'jgs' => 'judges',

    // Wisdom & poetry
    'ps' => 'psalms', 'psa' => 'psalms', 'psalm' => 'psalms',
    'prv' => 'proverbs', 'prov' => 'proverbs', 'pr' => 'proverbs',
    'qoh' => 'ecclesiastes',
    'sos' => 'song-of-solomon', 'song of songs' => 'song-of-solomon',
    'canticles' => 'song-of-solomon', 'cant' => 'song-of-solomon',

    // Prophets
    'isa' => 'isaiah',
    'jer' => 'jeremiah',
    'eze' => 'ezekiel', 'ezk' => 'ezekiel',
    'dan' => 'daniel', 'dn' => 'daniel',

    // Gospels
    'mt' => 'matthew',
    'mk' => 'mark', 'mrk' => 'mark',
    'lk' => 'luke', 'luk' => 'luke',
    'jn' => 'john', 'jhn' => 'john',

    // Paul
    'rom' => 'romans', 'rm' => 'romans',
    '1 co' => '1-corinthians', '2 co' => '2-corinthians',
    'gal' => 'galatians',
    'eph' => 'ephesians',
    'php' => 'philippians', 'phil' => 'philippians',
    'col' => 'colossians',
    '1 th' => '1-thessalonians', '2 th' => '2-thessalonians',
    '1 tim' => '1-timothy', '2 tim' => '2-timothy',
    'tit' => 'titus',
    'phlm' => 'philemon', 'phm' => 'philemon',

    // General
    'heb' => 'hebrews',
    'jas' => 'james', 'jm' => 'james',
    '1 pt' => '1-peter', '2 pt' => '2-peter',
    '1 jn' => '1-john', '2 jn' => '2-john', '3 jn' => '3-john',
    'rev' => 'apocalypse-of-john', 'rv' => 'apocalypse-of-john', 'apoc' => 'apocalypse-of-john',
    'the apocalypse'  => 'apocalypse-of-john', 'revelation'  => 'apocalypse-of-john',
    'apocalypse'      => 'apocalypse-of-john',

    // Deuterocanon / Apocrypha
    'sir' => 'sirach', 'ecclus' => 'sirach', 'ecclesiasticus' => 'sirach',
    'wis' => 'wisdom-of-solomon', 'wisd' => 'wisdom-of-solomon',
    '1 mac' => '1-maccabees', '2 mac' => '2-maccabees',
    '3 mac' => '3-maccabees', '4 mac' => '4-maccabees',
    'enoch' => '1-enoch', '1 en' => '1-enoch',
    'jub' => 'jubilees',

    // Five Psalms of David — the collection typed as a whole range. Chapter
    // forms like "psalm 152" are handled by canon.chapter_remaps, not here.
    // Both dash forms: hyphen (typed) and en dash (pasted from the homepage).
    'psalms 151-155' => 'five-psalms-of-david', 'psalms 151–155' => 'five-psalms-of-david',
    'ps 151-155' => 'five-psalms-of-david', 'ps 151–155' => 'five-psalms-of-david',
];