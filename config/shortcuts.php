<?php

// Search-box phrase => destination. Three supported forms:
//   ['route' => 'name', 'params' => [...]]   a named route (params optional)
//   ['url'   => '/some/path']                 a literal path
//   'https://example.com'                     a bare string is treated as a URL
//
// Matching (SearchController::matchShortcut → phraseKey): case-insensitive,
// and runs of spaces/hyphens count as one space — so "Bible  Scrim" and
// "bible-scrim" both hit "bible scrim". Punctuation is NOT forgiven:
// "scrim!" falls through to an ordinary text search, by design.

return [
    'terminal-system' => ['route' => 'terminal.index'],

    // scrim-gate r1: the Scrimmage builder's front door from the search box.
    // The reader FAB's scrim button is now locked by default (mb.reader →
    // scrimUnlocked), so these phrases are the discoverable way in. Visiting
    // the builder this way does NOT unlock the FAB button — that comes from
    // a separate sequence, still to be written.
    'scrim'            => ['route' => 'typing.scrimmage'],
    'scrimmage'        => ['route' => 'typing.scrimmage'],
    'bible scrim'      => ['route' => 'typing.scrimmage'],
    'bible scrimmage'  => ['route' => 'typing.scrimmage'],
    'typing scrim'     => ['route' => 'typing.scrimmage'],
    'typing scrimmage' => ['route' => 'typing.scrimmage'],

    // 'leaderboard' => ['route' => 'typing.leaderboard'],
    // 'discord'     => 'https://discord.gg/UGNCFD3e',
];
