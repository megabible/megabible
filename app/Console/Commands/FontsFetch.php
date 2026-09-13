<?php

namespace App\Console\Commands;

use App\Support\Fonts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * FONTS:FETCH  ·  populate public/fonts/pool from the manifest (fonts r1)
 * ---------------------------------------------------------------------------
 * Downloads each family's latin woff2 from Google Fonts' css2 API and its
 * license text from the google/fonts repo, into public/{config fonts.dir}.
 * Runs at BUILD time on the dev machine — the live site never talks to
 * fonts.googleapis.com (the no-third-party policy); the fetched files are
 * committed and served self-hosted.
 *
 *   php artisan fonts:fetch            every family in the manifest
 *   php artisan fonts:fetch jim        just one
 *   php artisan fonts:fetch --check    report what's on disk, fetch nothing
 *
 * HOW THE WOFF2 IS FOUND: css2 returns different formats by User-Agent, so
 * we ask as a modern browser and get one @font-face block per SUBSET, each
 * tagged with a comment. We take the SUBSET block's url(...woff2). When
 * the Spanish site needs more than `latin`, add 'latin-ext' to SUBSETS and
 * refetch — the command then downloads one file per subset with the subset
 * suffixed to the name, and font-faces.blade needs the matching
 * unicode-range treatment (leave that until it's real).
 *
 * Failures are per-family and loud, never fatal to the loop: a typo'd
 * manifest URL should not stop the other faces from landing.
 */
class FontsFetch extends Command
{
    protected $signature = 'fonts:fetch
        {family?* : Manifest key(s) to fetch; none means every family}
        {--check : Report the pool on disk without fetching anything}';

    protected $description = 'Download the font pool (woff2 + licenses) from the fonts.php manifest';

    private const SUBSETS = ['latin'];

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function handle(): int
    {
        $dir      = public_path(config('fonts.dir', 'fonts/pool'));
        $families = Fonts::families();

        $only = $this->argument('family');
        if (is_array($only) && $only !== []) {
            $unknown = array_diff($only, array_keys($families));
            foreach ($unknown as $key) {
                $this->warn("No '{$key}' in config/fonts.php — skipped.");
            }
            $families = array_intersect_key($families, array_flip($only));
        }

        if ($families === []) {
            $this->error('Nothing to do: no matching families in the manifest.');
            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->check($dir, $families);
        }

        File::ensureDirectoryExists($dir);
        $failed = 0;

        foreach ($families as $key => $f) {
            $this->line("<info>{$key}</info>  ({$f['label']})");
            if (! $this->fetchFace($dir, $key, $f)) {
                $failed++;
            }
            $this->fetchLicense($dir, $key, $f);
        }

        $this->newLine();
        $this->line($failed === 0
            ? 'Pool complete. Commit public/' . config('fonts.dir') . ' so the site ships it.'
            : "{$failed} face(s) failed — see above; rerun with just those keys after fixing the manifest.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function fetchFace(string $dir, string $key, array $f): bool
    {
        $cssUrl = 'https://fonts.googleapis.com/css2?family=' . $f['google'] . '&display=swap';

        $css = Http::withHeaders(['User-Agent' => self::UA])->get($cssUrl);
        if (! $css->ok()) {
            $this->error("  css2 request failed ({$css->status()}) — check the 'google' value.");
            return false;
        }

        foreach (self::SUBSETS as $subset) {
            $url = $this->woffUrlForSubset($css->body(), $subset);
            if ($url === null) {
                $this->error("  no '{$subset}' subset block in the css2 response.");
                return false;
            }

            $woff = Http::withHeaders(['User-Agent' => self::UA])->get($url);
            if (! $woff->ok()) {
                $this->error("  woff2 download failed ({$woff->status()}).");
                return false;
            }

            // One subset: the manifest filename as-is. More than one:
            // suffix the extras so latin keeps the canonical name.
            $file = $subset === self::SUBSETS[0]
                ? $f['file']
                : preg_replace('/\.woff2$/', "-{$subset}.woff2", $f['file']);

            File::put($dir . '/' . $file, $woff->body());
            $this->line('  ' . $file . '  ' . number_format(strlen($woff->body())) . ' bytes');
        }

        return true;
    }

    /**
     * css2 tags each @font-face block with a subset comment above it:
     *   /* latin *\/ @font-face { ... url(...woff2) ... }
     * Take the url from the block that follows our subset's tag.
     */
    private function woffUrlForSubset(string $css, string $subset): ?string
    {
        $ok = preg_match(
            '~/\* ' . preg_quote($subset, '~') . ' \*/\s*@font-face\s*\{[^}]*?url\((https://[^)]+\.woff2)\)~s',
            $css,
            $m
        );
        return $ok ? $m[1] : null;
    }

    private function fetchLicense(string $dir, string $key, array $f): void
    {
        $url = $f['license_url'] ?? null;
        if (! $url) {
            $this->warn('  no license_url in the manifest — add one; the OFL requires shipping its text.');
            return;
        }
        $res = Http::get($url);
        if (! $res->ok()) {
            $this->warn("  license download failed ({$res->status()}) — {$url}");
            return;
        }
        File::put($dir . "/{$key}-LICENSE.txt", $res->body());
        $this->line("  {$key}-LICENSE.txt  ({$f['license']})");
    }

    private function check(string $dir, array $families): int
    {
        $missing = 0;
        foreach ($families as $key => $f) {
            $file = $dir . '/' . $f['file'];
            $lic  = $dir . "/{$key}-LICENSE.txt";
            $okF  = File::exists($file);
            $okL  = File::exists($lic);
            if (! $okF) { $missing++; }
            $this->line(sprintf(
                '%s %-10s %s%s',
                $okF ? '<info>ok</info>  ' : '<error>miss</error>',
                $key,
                $okF ? number_format(File::size($file)) . ' bytes' : $f['file'] . ' not on disk',
                $okL ? '' : '  <comment>(license missing)</comment>'
            ));
        }
        $this->newLine();
        $this->line($missing === 0 ? 'Pool intact.' : "{$missing} face(s) missing — run fonts:fetch.");
        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
