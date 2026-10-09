<?php

namespace App\Support;

/**
 * PERICOPE SHORT-LINK CODE MINTER · short-link r1
 *
 * AdjectiveAdjectiveNoun from config/pericope_words.php, gfycat-style:
 * SweetHoneyedEmber, TranquilEverlastingPeacock. Pure string generation —
 * uniqueness against the pericope_shares table is the CALLER's job
 * (PericopeShareController's retry loop), because only the caller can
 * close the check-then-insert race with the unique index.
 *
 * random_int(), not mt_rand(): codes are capability-adjacent (an
 * unguessable code is part of the privacy story), so they come from the
 * CSPRNG like the secrets do.
 */
class PericopeCode
{
    public static function mint(): string
    {
        $adjectives = (array) config('pericope_words.adjectives');
        $nouns      = (array) config('pericope_words.nouns');

        $first = $adjectives[random_int(0, count($adjectives) - 1)];

        // Two DISTINCT adjectives — "SacredSacredLamb" reads like a bug.
        do {
            $second = $adjectives[random_int(0, count($adjectives) - 1)];
        } while ($second === $first);

        $noun = $nouns[random_int(0, count($nouns) - 1)];

        return $first . $second . $noun;
    }
}
