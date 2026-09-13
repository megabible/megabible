<?php

return [

    // ──────────────────────────────────────────────────────────────────────
    // THE FONT POOL  ·  one source of truth for display faces (fonts r1)
    //
    // Every face the pericope feed (and, in time, the presenter and the
    // animations) can paint with lives here. Three consumers read this file:
    //
    //   bible/partials/font-faces.blade.php  emits the @font-face rules
    //   App\Support\Fonts::clientPool()      ships the pool to the client
    //   fonts:fetch (Artisan)                downloads the woff2 + license
    //
    // ADDING A FACE is therefore one entry here + one `php artisan
    // fonts:fetch <key>` — it then appears in the scroll settings panel and
    // the per-post rotation with no other edits.
    //
    // Files are SELF-HOSTED (no fonts.googleapis.com at runtime — the
    // no-third-party policy) at public/{dir}/<file>, with each family's
    // license shipped alongside as <key>-LICENSE.txt: the OFL requires
    // distributing its license with the font, and doing it for Apache too
    // costs nothing. `latin` subset only for now; when the Spanish site
    // needs accented capitals beyond latin, revisit with `latin-ext`
    // (fonts:fetch's SUBSET constant).
    // ──────────────────────────────────────────────────────────────────────

    // Web path (under public/) where fetched files and licenses live.
    'dir' => 'fonts/pool',

    // Keys are PERMANENT once shipped: the scroll settings pref (mb.scroll)
    // stores them, and pericope-present.js uses the same four for its own
    // table — keep them aligned so the presenter can adopt this pool later
    // without orphaning anyone's saved choice.
    //
    //   label       what settings panels print
    //   family      the CSS font-family name (unquoted here; consumers
    //               quote it when the name needs it)
    //   role        'body' — comfortable at any length · 'display' — short
    //               passages only
    //   max_weight  the char-budget ceiling for the per-post rotation
    //               (null = unlimited). The scroll feed skips a face for
    //               posts heavier than this; a face PINNED in settings
    //               ignores it (the pin is the person's own call).
    //   google      the css2 API family parameter (spaces as '+')
    //   license / license_url   what it is, and where fonts:fetch gets the
    //               text it ships next to the file
    'families' => [

        'tinos' => [
            'label'       => 'Tinos',
            'family'      => 'Tinos',
            'role'        => 'body',
            'max_weight'  => null,
            'file'        => 'tinos.woff2',
            'google'      => 'Tinos',
            'license'     => 'Apache-2.0',
            'license_url' => 'https://raw.githubusercontent.com/google/fonts/main/apache/tinos/LICENSE.txt',
        ],

        'calsans' => [
            'label'       => 'Cal Sans',
            'family'      => 'Cal Sans',
            'role'        => 'body',
            'max_weight'  => null,
            'file'        => 'cal-sans.woff2',
            'google'      => 'Cal+Sans',
            'license'     => 'OFL-1.1',
            'license_url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/calsans/OFL.txt',
        ],

        'jim' => [
            'label'       => 'Jim Nightshade',
            'family'      => 'Jim Nightshade',
            'role'        => 'display',
            'max_weight'  => 200,
            'file'        => 'jim-nightshade.woff2',
            'google'      => 'Jim+Nightshade',
            'license'     => 'OFL-1.1',
            'license_url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/jimnightshade/OFL.txt',
        ],

        'rocker' => [
            'label'       => 'New Rocker',
            'family'      => 'New Rocker',
            'role'        => 'display',
            'max_weight'  => 160,
            'file'        => 'new-rocker.woff2',
            'google'      => 'New+Rocker',
            'license'     => 'OFL-1.1',
            'license_url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/newrocker/OFL.txt',
        ],

    ],

    // Named subsets of the pool, for pages that don't want everything.
    // The pericope pages currently take the whole pool; the set exists so
    // a future page (or the presenter) can carve its own without a second
    // manifest.
    'sets' => [
        'pericope' => ['tinos', 'calsans', 'jim', 'rocker'],
    ],

];
