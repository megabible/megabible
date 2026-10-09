<?php

/*
|--------------------------------------------------------------------------
| PERICOPE SHORT-LINK KNOBS                               short-link r1
|--------------------------------------------------------------------------
| The word pools live in config/pericope_words.php; these are the
| operational dials.
*/

return [

    /*
    | How many shared revisions to keep per code. Re-sharing past the cap
    | deletes the oldest rows. The bare code always serves the newest
    | revision; a ?v= pin whose row was pruned ALSO serves the newest
    | (a link never 404s once minted — the eternal-QR rule). Raise or
    | lower freely; pruning only ever happens at the moment of a
    | re-share, so changing this touches nothing retroactively.
    */
    'max_versions' => 10,

    /*
    | Hard byte ceiling on a submitted blob. The monster example board
    | was ~1.8 KB; 32 KB is a board an order of magnitude beyond anything
    | the grid can comfortably hold, while keeping "use MEGABIBLE as a
    | free pastebin" unattractive. Well under the TEXT column's 64 KB.
    */
    'max_blob_bytes' => 32768,

    /*
    | Mint attempts before giving up on a free code. At 5.1 million
    | combinations this loop realistically never passes attempt 1;
    | the ceiling exists so a hypothetical full namespace fails loudly
    | instead of spinning.
    */
    'mint_attempts' => 25,

];
