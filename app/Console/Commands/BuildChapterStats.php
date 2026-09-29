<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\ChapterStat;
use App\Models\Translation;
use App\Models\Verse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build (or rebuild) chapter_stats — verse and word counts per chapter —
 * for the animation planning pages.
 *
 *   php artisan mb:chapter-stats                 # every book
 *   php artisan mb:chapter-stats --book=genesis  # one book (after an import)
 *
 * EDITION CHOICE, PER CHAPTER: counts come from the script translation
 * (config animations.script_translation, KJV) wherever it carries the
 * chapter, else the first edition down the site's standard chain (global
 * editions first, then sort_order) that does. Per CHAPTER, not per book,
 * because coverage can differ inside one book (WEB carries only chapter 1
 * of the Five Psalms of David). The chosen edition is stored on the row
 * so the planning guide can label its source.
 *
 * Safe to re-run any time: rows are upserted, and a FULL run prunes rows
 * for chapters that no longer exist in any edition. Hand-rated
 * complexity lives in its own table and is never touched here.
 */
class BuildChapterStats extends Command
{
    protected $signature = 'mb:chapter-stats
                            {--book= : Limit to one book by slug}';

    protected $description = 'Compute per-chapter verse/word counts into chapter_stats';

    /**
     * Word tokenizer — THE SAME pattern DifficultyRater uses (letters and
     * apostrophes, unicode-aware), so "word count" means one thing
     * everywhere on the site. str_word_count is not unicode-safe; never
     * swap it in.
     */
    private const WORD_RE = '/[\p{L}\']+/u';

    public function handle(): int
    {
        // ---- 0. Scope ----------------------------------------------------
        $onlyBook = null;
        if ($slug = $this->option('book')) {
            $onlyBook = Book::findBySlug($slug);
            if (! $onlyBook) {
                $this->error("No book with slug '{$slug}'.");
                return self::FAILURE;
            }
        }

        // ---- 1. Edition priority list ------------------------------------
        // Script translation first, then the standard chain. array_values()
        // after the unshift-dedup keeps ids in a clean indexed list.
        $scriptAbbr = (string) config('animations.script_translation', 'KJV');
        $script     = Translation::findBySlug($scriptAbbr);

        $chain = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();

        $priority = collect();
        if ($script) {
            $priority->push($script);
        } else {
            $this->warn("Script translation '{$scriptAbbr}' not found — using the standard chain only.");
        }
        foreach ($chain as $t) {
            if (! $priority->contains('id', $t->id)) {
                $priority->push($t);
            }
        }
        if ($priority->isEmpty()) {
            $this->error('No translations in the database; nothing to count.');
            return self::FAILURE;
        }

        $nameById = $priority->pluck('abbreviation', 'id');

        // ---- 2. Coverage map: which editions carry which chapters --------
        // One grouped query over the whole verses table; a few editions ×
        // ~1,600 chapters is small. Keys are "book|chapter" → [translation
        // ids that carry it].
        $coverageQuery = DB::table('verses')
            ->select('translation_id', 'book_id', 'chapter', DB::raw('COUNT(*) as verse_count'))
            ->groupBy('translation_id', 'book_id', 'chapter');

        if ($onlyBook) {
            $coverageQuery->where('book_id', $onlyBook->id);
        }

        $covered    = [];   // "book|chapter" => [translation_id => verse_count]
        $chaptersOf = [];   // book_id => [chapter => true]  (union of editions)
        foreach ($coverageQuery->get() as $row) {
            $covered["{$row->book_id}|{$row->chapter}"][$row->translation_id] = $row->verse_count;
            $chaptersOf[$row->book_id][$row->chapter] = true;
        }

        if ($covered === []) {
            $this->warn('No verses found in scope; nothing written.');
            return self::SUCCESS;
        }

        // ---- 3. Walk the books, choose an edition per chapter -----------
        $books = $onlyBook
            ? collect([$onlyBook])
            : Book::whereIn('id', array_keys($chaptersOf))->orderBy('book_order')->get();

        $written   = 0;
        $fallbacks = [];   // "book via ABBR" => chapter count (non-script editions)
        $seenKeys  = [];   // every "book|chapter" written, for the prune pass

        foreach ($books as $book) {
            $chapters = array_keys($chaptersOf[$book->id] ?? []);
            sort($chapters);
            if ($chapters === []) {
                continue;
            }

            // chapter => chosen translation_id (first in priority that covers it)
            $chosen = [];
            foreach ($chapters as $c) {
                foreach ($priority as $t) {
                    if (isset($covered["{$book->id}|{$c}"][$t->id])) {
                        $chosen[$c] = $t->id;
                        break;
                    }
                }
            }

            // Group chapters by their edition → ONE verse query per group
            // instead of one per chapter.
            $byEdition = [];
            foreach ($chosen as $c => $tid) {
                $byEdition[$tid][] = $c;
            }

            foreach ($byEdition as $tid => $chs) {
                $verses = Verse::where('translation_id', $tid)
                    ->where('book_id', $book->id)
                    ->whereIn('chapter', $chs)
                    ->get(['chapter', 'text', 'format']);

                $words = [];   // chapter => running word count
                foreach ($verses as $v) {
                    $words[$v->chapter] = ($words[$v->chapter] ?? 0) + $this->countWords($v);
                }

                foreach ($chs as $c) {
                    ChapterStat::updateOrCreate(
                        ['book_id' => $book->id, 'chapter' => $c],
                        [
                            'translation_id' => $tid,
                            'verse_count'    => $covered["{$book->id}|{$c}"][$tid],
                            'word_count'     => $words[$c] ?? 0,
                        ]
                    );
                    $written++;
                    $seenKeys["{$book->id}|{$c}"] = true;
                }

                if (! $script || $tid !== $script->id) {
                    $label = "{$book->slug} via {$nameById[$tid]}";
                    $fallbacks[$label] = ($fallbacks[$label] ?? 0) + count($chs);
                }
            }
        }

        // ---- 4. Prune rows whose chapter vanished (full runs only) -------
        // A --book run only saw one book's coverage, so it must not judge
        // the rest of the table.
        $pruned = 0;
        if (! $onlyBook) {
            foreach (ChapterStat::get(['id', 'book_id', 'chapter']) as $row) {
                if (! isset($seenKeys["{$row->book_id}|{$row->chapter}"])) {
                    $row->delete();
                    $pruned++;
                }
            }
        }

        // ---- 5. Report ---------------------------------------------------
        $this->info("chapter_stats: {$written} chapter rows written" . ($pruned ? ", {$pruned} stale rows pruned" : '') . '.');

        if ($fallbacks !== []) {
            $this->line('Chapters counted from a non-script edition:');
            ksort($fallbacks);
            foreach ($fallbacks as $label => $n) {
                $this->line("  {$label}: {$n} chapter(s)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Words in one verse. Structured verses (format !== null) keep their
     * text in the block list's 't' fields — count THOSE, not ->text, so
     * poetry counts exactly what the reader sees. Stanza breaks ('b')
     * carry no text and fall out naturally.
     */
    private function countWords(Verse $v): int
    {
        if ($v->format === null) {
            $text = $v->text;
        } else {
            $parts = [];
            foreach ($v->format as $block) {
                if (($block['t'] ?? '') !== '') {
                    $parts[] = $block['t'];
                }
            }
            $text = implode(' ', $parts);
        }

        return (int) preg_match_all(self::WORD_RE, $text ?? '');
    }
}
