<?php

namespace App\Console\Commands;

use App\Models\ChapterAnimation;
use App\Models\Verse;
use App\Support\DataPath;
use Illuminate\Console\Command;

/**
 * Attach verse-level timing cues to one video, from a hand-authored file.
 *
 *   php artisan mb:video-cues dQw4w9WgXcQ animations/genesis-1-cues.txt
 *   php artisan mb:video-cues dQw4w9WgXcQ animations/genesis-1-cues.txt --dry
 *   php artisan mb:video-cues dQw4w9WgXcQ --clear
 *
 * The file is written while scrubbing the finished render: one line per
 * verse, the verse number then the moment its narration starts —
 *
 *   # Genesis 1 — KJV
 *   1  0:00
 *   2  0:14.5
 *   3  0:27
 *   23 1:02:07.2      ← h:mm:ss works too; so do plain seconds ("87.5")
 *
 * Any run of spaces or tabs separates the two columns; # lines and blank
 * lines are skipped. Verse numbers are the REAL DB numbers of the video's
 * own chapter (reader_labels renumbering never enters here, same as every
 * other import).
 *
 * VALIDATION — because a cue typo shows up as a highlight jumping to the
 * wrong verse mid-video, which is miserable to debug by eye:
 *   · every verse must exist in the video's chapter+edition;
 *   · no verse may appear twice;
 *   · times must strictly increase (the VO follows the text in order);
 *   · verses that DECREASE only warn — order is canonical, but a warning
 *     beats rejecting an intentional oddity.
 * Chapter verses with no cue are reported (the sync simply holds the
 * previous highlight through them), so partial files import fine.
 *
 * STORAGE: the model's `cues` JSON — objects, not pairs:
 * ['k' => 'v', 'v' => 3, 't' => 27.0]. 'k' is the cue kind; only 'v'
 * (verse start) exists today, and richer kinds (pauses, stanzas) will be
 * new kinds in the same list, no migration. Cues may be attached to a
 * DRAFT video — that is the normal order of work: cues first, then flip
 * the status live.
 */
class ImportVideoCues extends Command
{
    protected $signature = 'mb:video-cues
                            {youtube_id : The video to attach cues to}
                            {path? : Cue file — absolute, or relative to storage/app}
                            {--dry : Parse and report without writing}
                            {--clear : Remove the video\'s cues instead of importing}';

    protected $description = 'Attach verse-level timing cues to a chapter animation from a text file';

    public function handle(): int
    {
        $video = ChapterAnimation::where('youtube_id', (string) $this->argument('youtube_id'))->first();
        if (! $video) {
            $this->error('No chapter_animations row with that youtube_id — publish the video row first.');
            return self::FAILURE;
        }

        $where = "{$video->book->slug} {$video->chapter} ({$video->translation->abbreviation})";

        // ---- --clear: remove and stop ------------------------------------
        if ($this->option('clear')) {
            $video->update(['cues' => null]);
            $this->info("Cues cleared for {$where}.");
            return self::SUCCESS;
        }

        // ---- Resolve the file --------------------------------------------
        $raw = (string) $this->argument('path');
        if ($raw === '') {
            $this->error('Give a cue file path, or --clear to remove cues.');
            return self::FAILURE;
        }
        $path = DataPath::resolve($raw) ?? '';

        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");
            return self::FAILURE;
        }

        // The chapter's real verse numbers, for validation — keyed for O(1)
        // lookups, and reused at the end for the coverage report.
        $chapterVerses = Verse::where('translation_id', $video->translation_id)
            ->where('book_id', $video->book_id)
            ->where('chapter', $video->chapter)
            ->pluck('verse_number')
            ->flip();

        if ($chapterVerses->isEmpty()) {
            $this->error("The video's edition has no verses for {$where} — check the row's book/chapter/translation.");
            return self::FAILURE;
        }

        // ---- Parse (fgets + explode-style splitting, never fgetcsv) ------
        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("Failed to open: {$path}");
            return self::FAILURE;
        }

        $cues      = [];
        $errors    = [];
        $seen      = [];     // verse => line number, for duplicate reporting
        $lastTime  = null;
        $lastVerse = null;
        $n         = 0;

        while (($line = fgets($fh)) !== false) {
            $n++;
            $line = trim(rtrim($line, "\r\n"));

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = preg_split('/\s+/', $line, 2);
            if (count($cols) < 2) {
                $errors[] = "line {$n}: expected 'verse time'";
                continue;
            }
            [$verseRaw, $timeRaw] = $cols;

            if (! ctype_digit($verseRaw)) {
                $errors[] = "line {$n}: verse '{$verseRaw}' is not a number";
                continue;
            }
            $verse = (int) $verseRaw;

            if (! isset($chapterVerses[$verse])) {
                $errors[] = "line {$n}: verse {$verse} does not exist in {$where}";
                continue;
            }
            if (isset($seen[$verse])) {
                $errors[] = "line {$n}: verse {$verse} already cued on line {$seen[$verse]}";
                continue;
            }

            $t = $this->parseTime($timeRaw);
            if ($t === null) {
                $errors[] = "line {$n}: time '{$timeRaw}' not understood (use ss, m:ss, or h:mm:ss, decimals allowed)";
                continue;
            }

            if ($lastTime !== null && $t <= $lastTime) {
                $errors[] = "line {$n}: time {$timeRaw} does not increase past the previous cue";
                continue;
            }
            if ($lastVerse !== null && $verse < $lastVerse) {
                $this->warn("line {$n}: verse {$verse} comes after verse {$lastVerse} — imported, but check it is intentional.");
            }

            $seen[$verse] = $n;
            $lastTime     = $t;
            $lastVerse    = max($lastVerse ?? 0, $verse);
            $cues[]       = ['k' => 'v', 'v' => $verse, 't' => round($t, 2)];
        }

        fclose($fh);

        if ($errors !== []) {
            $this->warn(count($errors) . ' line(s) rejected — NOTHING was written:');
            foreach ($errors as $e) {
                $this->line("  {$e}");
            }
            // Cues are all-or-nothing, unlike the complexity import: a cue
            // list with holes where the errors were would silently mis-time
            // the highlight for the rest of the video.
            return self::FAILURE;
        }

        if ($cues === []) {
            $this->error('No cues found in the file.');
            return self::FAILURE;
        }

        // ---- Coverage report ---------------------------------------------
        $missing = $chapterVerses->keys()->reject(fn ($v) => isset($seen[$v]))->sort()->values();
        if ($missing->isNotEmpty()) {
            $shown = $missing->take(12)->implode(', ');
            $more  = $missing->count() > 12 ? '…' : '';
            $this->warn("{$missing->count()} verse(s) have no cue ({$shown}{$more}) — the highlight will hold through them.");
        }

        if ($this->option('dry')) {
            $first = $cues[0];
            $last  = $cues[count($cues) - 1];
            $this->info('DRY RUN: ' . count($cues) . " cue(s) parsed for {$where} — v{$first['v']} @ {$first['t']}s … v{$last['v']} @ {$last['t']}s. Nothing written.");
            return self::SUCCESS;
        }

        $video->update(['cues' => $cues]);
        $this->info(count($cues) . " cue(s) stored for {$where}.");

        return self::SUCCESS;
    }

    /** "87", "87.5", "1:27", "1:27.5", "1:02:07.2" → seconds, or null. */
    private function parseTime(string $s): ?float
    {
        if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d(?:\.\d+)?)$/', $s, $m)) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
        }
        if (preg_match('/^(\d+):([0-5]?\d(?:\.\d+)?)$/', $s, $m)) {
            return ((int) $m[1]) * 60 + (float) $m[2];
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
            return (float) $s;
        }
        return null;
    }
}
