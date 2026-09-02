<?php

// Search-box phrase => destination. Three supported forms:
//   ['route' => 'name', 'params' => [...]]   a named route (params optional)
//   ['url'   => '/some/path']                 a literal path
//   'https://example.com'                     a bare string is treated as a URL

return [
    'terminal-system' => ['route' => 'terminal.index'],

    // 'leaderboard' => ['route' => 'typing.leaderboard'],
    // 'discord'     => 'https://discord.gg/UGNCFD3e',
];