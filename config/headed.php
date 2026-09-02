<?php

/**
 * HEADed — the local-only Heading TSV editor.
 *
 * Canon ORDER is NOT here: HEADed reads it from the Book table's `book_order`,
 * the same single source of truth the importer and reader use. This file only
 * holds tool defaults and the storage locations Phase 3 will write backups and
 * logs to.
 */
return [

    // Pre-filled on the load screen and used as the active set for a session.
    // HEADed edits exactly one set per session (see the project notes).
    'default_set'  => 'en-standard',

    // Pre-filled path on the load screen. Relative paths resolve under
    // storage/app (see HeadingTsv::resolvePath), so this points at
    // storage/app/headings/en-standard.tsv by default.
    'default_path' => 'headings/en-standard.tsv',

    // Where Phase 3 will keep rolling backups (last 10) and the audit log.
    // Both live under storage/app so they're outside the web root.
    'backup_dir'   => 'headed/backups',
    'log_dir'      => 'headed/logs',
    'backup_keep'  => 10,

];