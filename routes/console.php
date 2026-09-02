<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| THE WEEKLY CLOCKWORK
|--------------------------------------------------------------------------
| Everything below runs on EASTERN time (America/New_York — the IANA zone,
| so DST is handled for free; the knobs live in config/typing.php). That is
| the SITE clock: the sabbath, the trim, the daily rollover, the archive,
| and the analytics day bucket all agree on when a day — and a week — ends.
|
| The week's shape:
|
|   SATURDAY 00:06   scrim:trim — THE SABBATH CUT (weekly, not nightly).
|                    Every scrimboard cut to its top rows; every standing
|                    row crowned for the week. Six past midnight so tokens
|                    minted Friday 23:59 (valid for typing.token_ttl_ms)
|                    can file their last honest scores first — raise the
|                    TTL, raise this. Boards then rest VEILED all Saturday
|                    (a read-path veil, not a data state — see
|                    App\Support\Sabbath) and are revealed at Sunday 00:00
|                    by nothing more than the veil lifting. No restore job
|                    exists, on purpose.
|
|   NIGHTLY 00:06    scrim:daily-archive. Yesterday's daily boards frozen
|                    into daily_snapshot_entries, ranks and all; live rows
|                    deleted. Friday's daily freezes Saturday morning as
|                    ever; Sunday morning it finds no Saturday ledger row
|                    and does nothing — self-skipping beats rescheduling.
|                    (Saturday it shares the minute with the trim: they run
|                    sequentially in registration order and touch disjoint
|                    challenge_modes, so the pairing is safe.)
|
|   NIGHTLY 00:10    scrim:daily-pick. Tops the calendar up two weeks; the
|                    command itself skips Saturdays (no daily on the
|                    sabbath) and never touches a day that has a verse, so
|                    curated days stay safe forever.
|
| Prod (Forge): the scheduler needs its one cron —
|   * * * * * php /path/artisan schedule:run >> /dev/null 2>&1
| Local testing: `php artisan schedule:work`, `php artisan schedule:list`
| to see the computed times, or run any command by hand with --dry-run.
*/

$siteTz = config('typing.board_trim.timezone', 'America/New_York');

// weeklyOn: 6 = Saturday (0 = Sunday). The sabbath cut.
Schedule::command('scrim:trim')
    ->weeklyOn(6, config('typing.board_trim.at', '00:06'))
    ->timezone($siteTz)
    ->onOneServer();

Schedule::command('scrim:daily-archive')
    ->dailyAt(config('typing.daily.archive_at', '00:06'))
    ->timezone($siteTz)
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('scrim:daily-pick --days=' . config('typing.daily.prefetch_days', 14))
    ->dailyAt(config('typing.daily.pick_at', '00:10'))
    ->timezone($siteTz)
    ->onOneServer()
    ->withoutOverlapping();
