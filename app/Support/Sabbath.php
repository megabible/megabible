<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Sabbath — the weekly rest, defined once.
 *
 * From Saturday 00:00 to Sunday 00:00 ON THE SITE CLOCK
 * (typing.board_trim.timezone — one clock for the whole world, so the
 * sabbath falls at the same moment for a player in El Paso and a player in
 * Tokyo, whatever their local Saturday is doing):
 *
 *   · scrimmages may be PLAYED, but no score is kept and no name is set
 *     (the /score gate refuses tokens MINTED during the sabbath — a round
 *     begun Friday 23:59 still files, exactly like the daily's grace);
 *   · scrimboards are veiled — present, uncut, merely unseen until Sunday
 *     (the "restore" is nothing but the veil lifting; no job runs);
 *   · there is no daily verse, and none is ever created for a Saturday
 *     (guarded in the picker, the pick command, and the set command —
 *     defense in depth, because a burned pool verse can't be unburned);
 *   · the weekly trim falls at the sabbath's opening minutes, and its
 *     survivor stamps carry champion crowns through the whole week.
 *
 * `typing.sabbath.enabled` turns the observance off wholesale — for
 * development, and so launch day never depends on what weekday it is. The
 * one thing NOT tied to the flag is lastCutAt(): the trim schedule is
 * Saturday regardless, so champion freshness always measures from the most
 * recent Saturday midnight.
 *
 * Every method is static and stateless; Carbon::setTestNow() steers all of
 * them at once, which is how the whole system is tested on a Tuesday.
 */
class Sabbath
{
    /** The observance switch. Gates and guards honour it; the trim schedule doesn't. */
    public static function enabled(): bool
    {
        return (bool) config('typing.sabbath.enabled', true);
    }

    /** Is it the sabbath right now, on the site clock? */
    public static function isSabbath(): bool
    {
        return self::enabled() && Carbon::now(self::tz())->isSaturday();
    }

    /**
     * Does this Y-m-d date fall on the sabbath? For date-shaped questions —
     * the daily picker, the pick command, the set command, the daily guard —
     * where "now" is beside the point.
     */
    public static function dateIsSabbath(string $ymd): bool
    {
        return self::enabled() && Carbon::parse($ymd, self::tz())->isSaturday();
    }

    /**
     * Was a token minted during the sabbath? THE score gate. Keying on the
     * MINT moment rather than the submission moment is what grants the
     * grace window in both directions: a Friday-23:59 round files into
     * Friday's week a few minutes after midnight, and a Saturday-23:59
     * round stays scoreless even if submitted Sunday 00:01 — the round
     * belongs to the day it began.
     */
    public static function mintedOnSabbath(int $issuedMs): bool
    {
        if (! self::enabled() || $issuedMs <= 0) {
            return false;
        }

        return Carbon::createFromTimestampMs($issuedMs, self::tz())->isSaturday();
    }

    /**
     * The most recent Saturday 00:00 on the site clock — the last cut. A
     * survivor stamp fresher than this instant is THIS week's crown; the
     * board endpoint measures champion freshness against it. During a
     * Saturday this is today's midnight (the cut that just fell).
     */
    public static function lastCutAt(): CarbonInterface
    {
        $d = Carbon::now(self::tz())->startOfDay();

        if (! $d->isSaturday()) {
            $d = $d->previous(CarbonInterface::SATURDAY);   // 00:00 of that Saturday
        }

        return $d;
    }

    private static function tz(): string
    {
        return config('typing.board_trim.timezone', 'America/New_York');
    }
}
