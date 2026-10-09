<?php

namespace App\Console\Commands;

use App\Models\PericopeShare;
use Illuminate\Console\Command;

/**
 * THE ADMIN KILL SWITCH · short-link r1
 *
 *   php artisan pericope:unmint SweetHoneyedEmber
 *
 * The answer to "minted forever with no way to take it down": the owner's
 * secret can delete a code, and so can this command — abuse reports,
 * takedown requests, or your own judgment. Deleting the share row
 * cascades its versions; the code 404s immediately and (being random)
 * will realistically never be re-minted. Lookup is case-insensitive via
 * the column collation, so a code pasted from a chat in any casing works.
 */
class PericopeUnmint extends Command
{
    protected $signature = 'pericope:unmint
                            {code : The short-link code to delete}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a pericope short-link code and all its versions';

    public function handle(): int
    {
        $share = PericopeShare::where('code', $this->argument('code'))->first();

        if (! $share) {
            $this->error('No share found for that code.');

            return self::FAILURE;
        }

        $versions = $share->versions()->count();
        $this->line("Code:     {$share->code}");
        $this->line("Minted:   {$share->created_at}");
        $this->line("Versions: {$versions}");

        if (! $this->option('force') && ! $this->confirm('Delete this short link permanently?')) {
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        $share->delete();
        $this->info("Unminted {$share->code} — the URL now 404s.");

        return self::SUCCESS;
    }
}
