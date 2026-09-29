<?php

return [

    // ──────────────────────────────────────────────────────────────────────
    // ANIMATION PROGRAM KNOBS
    //
    // Referenced by the mb:chapter-stats command (script translation) and
    // by ChapterStat::runtimeRange() at render time (the WPM pair) — so
    // retuning the WPM numbers reshapes every planning page on the next
    // request, with no rebuild.
    // ──────────────────────────────────────────────────────────────────────

    // The default "script" edition for planning-guide stats and summaries,
    // by abbreviation. Where this edition doesn't carry a chapter (KJV has
    // no 1 Enoch, no Psalm 151…), mb:chapter-stats falls back down the
    // site's standard chain: global editions first, then sort_order — the
    // same rule the homepage uses to link books.
    'script_translation' => 'KJV',

    // Narration pace bounds, words per minute. Scripture narration runs
    // slower than commercial audiobooks (~150–160): measured Bible
    // narrations sit roughly here. SLOW gives the high runtime bound,
    // FAST the low one; the guide shows the range ("≈ 4–5 min") rather
    // than false precision.
    'narration_wpm_slow' => 120,
    'narration_wpm_fast' => 145,

];
