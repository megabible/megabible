<?php

namespace App\Console\Commands;

use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make one translation take over another's identity.
 *
 *   php artisan translation:supersede KJVCPB KJV --name="King James Version" ...
 *
 * "KJVCPB supersedes KJV" — the first (surviving) translation is re-badged with
 * the second's abbreviation, and the second (now redundant) translation is
 * deleted. Its verses and headings go with it automatically via the
 * ON DELETE CASCADE foreign keys, so there's no slow row-by-row cleanup.
 *
 * Wrapped in a transaction: if anything fails, nothing changes. Safe to keep
 * around for the next time you replace a translation with a better edition.
 */
class SupersedeTranslation extends Command
{
    protected $signature = 'translation:supersede
                            {new : Abbreviation of the translation that will SURVIVE (e.g. KJVCPB)}
                            {identity : Abbreviation it should TAKE OVER (e.g. KJV)}
                            {--name= : New display name for the surviving translation}
                            {--year= : year_published to set}
                            {--url= : source_url to set}';

    protected $description = 'Re-badge one translation with another\'s abbreviation and delete the superseded one (verses + headings cascade).';

    public function handle(): int
    {
        $newAbbr      = strtoupper($this->argument('new'));
        $identityAbbr = strtoupper($this->argument('identity'));

        $new = Translation::where('abbreviation', $newAbbr)->first();
        if (! $new) {
            $this->error("Surviving translation '{$newAbbr}' not found. Nothing to do.");
            return self::FAILURE;
        }

        $old = Translation::where('abbreviation', $identityAbbr)->first();

        if ($old && $old->id === $new->id) {
            $this->info("'{$newAbbr}' already holds the '{$identityAbbr}' identity. Nothing to do.");
            return self::SUCCESS;
        }

        // Show the plan before touching anything.
        $this->warn('About to:');
        if ($old) {
            $oldVerses   = DB::table('verses')->where('translation_id', $old->id)->count();
            $oldHeadings = DB::table('headings')->where('translation_id', $old->id)->count();
            $this->line("  • DELETE translation '{$old->abbreviation}' (id {$old->id}) — {$oldVerses} verses, {$oldHeadings} headings");
        }
        $rename = "  • RE-BADGE '{$new->abbreviation}' (id {$new->id}) → '{$identityAbbr}'";
        if ($this->option('name')) $rename .= " / \"{$this->option('name')}\"";
        $this->line($rename);

        if (! $this->confirm('Proceed?', true)) {
            $this->info('Aborted — no changes made.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($old, $new, $identityAbbr) {
            // Delete the old identity FIRST so the unique abbreviation is free.
            // Its verses + headings are removed by the cascade.
            if ($old) {
                $old->delete();
            }

            $new->abbreviation = $identityAbbr;
            if ($this->option('name')) $new->name           = $this->option('name');
            if ($this->option('year')) $new->year_published = (int) $this->option('year');
            if ($this->option('url'))  $new->source_url     = $this->option('url');
            $new->save();
        });

        $slug = strtolower($identityAbbr);
        $this->info("Done. '{$identityAbbr}' now serves the formatted text. Check /bible/{$slug}/psalms/1 to confirm.");
        return self::SUCCESS;
    }
}