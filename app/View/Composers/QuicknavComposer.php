<?php

namespace App\View\Composers;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Builds the data behind the "quicknav" popup and shares it with the shared
 * QuickNav panel partial (bible.partials.quicknav-panel) wherever it's included.
 * The structure it produces:
 *
 *   [
 *     ['label' => 'First Testament', 'books' => [
 *         ['name'=>'Genesis','color'=>'gold','available'=>true,
 *          'url'=>'/bible/kjv/genesis','chapters'=>50],
 *         ...
 *     ]],
 *     ['label' => 'Second Testament', 'books' => [...]],
 *   ]
 *
 * The per-book "where should a click land" rule mirrors BibleController@index
 * exactly, so a quicknav link can never 404.
 */
class QuicknavComposer
{
    /**
     * Per-request memo. The panel partial is included more than once on reader
     * pages, but the data is identical every time within a request, so we build
     * it once. This holds because AppServiceProvider registers the composer as a
     * singleton; under standard PHP-FPM the container (and thus this instance) is
     * rebuilt each request, so the memo is request-scoped.
     */
    private ?array $data = null;

    public function compose(View $view): void
    {
        $view->with('quicknav', $this->data ??= $this->build());
    }

    private function build(): array
    {
        // slug => Book, so we can resolve the slugs listed in config/canon.php.
        $books = Book::all()->keyBy('slug');

        // Translations in priority order (global / full-canon first), the same
        // rule the homepage uses to choose a sensible fallback per book.
        $translations = Translation::orderByDesc('is_global')
            ->orderBy('sort_order')
            ->get();

        // For every (book, translation) pair that has verses: its highest
        // chapter number. One grouped query gives us BOTH which translations
        // carry a book AND how many chapters to draw. If the verses table ever
        // grows large enough that this query is noticeable, wrap it in
        // Cache::remember() and clear the cache after each import.
        $avail = [];   // book_id => [ translation_id => maxChapter ]
        DB::table('verses')
            ->select('book_id', 'translation_id', DB::raw('MAX(chapter) AS max_chapter'))
            ->groupBy('book_id', 'translation_id')
            ->get()
            ->each(function ($row) use (&$avail) {
                $avail[$row->book_id][$row->translation_id] = (int) $row->max_chapter;
            });

        // The reader's current translation (cookie set while reading), KJV if absent.
        $pref    = strtolower(request()->cookie('reader_translation', 'kjv'));
        $primary = Translation::findBySlug($pref) ?? Translation::findBySlug('kjv');

        $colors   = config('canon.section_colors', []);
        $sections = config('canon.sections', []);

        $out = [];

        foreach (config('canon.testaments', []) as $testament) {
            $bookList = [];

            foreach ($testament['sections'] as $sectionKey) {
                $section = $sections[$sectionKey] ?? null;
                if (! $section) {
                    continue;
                }

                $color = $colors[$sectionKey] ?? 'clay';

                // A section lists its books either flat ('books') or in labelled
                // subgroups. Flatten both into one ordered slug list.
                if (! empty($section['subgroups'])) {
                    $slugs = collect($section['subgroups'])
                        ->flatMap(fn ($g) => $g['books'] ?? [])
                        ->all();
                } else {
                    $slugs = $section['books'] ?? [];
                }

                foreach ($slugs as $slug) {
                    $book = $books->get($slug);
                    if (! $book) {
                        continue;
                    }

                    // Short label for the button — every book has a short_name
                    // in the DB ("Gen", "Exod", "1 Cor"…). Fall back to the full
                    // name only if one is ever missing.
                    $label = $book->short_name ?: $book->name;

                    // Where should a click land? Reader's current translation if
                    // it has the book; else the highest-priority one that does.
                    $ids    = array_keys($avail[$book->id] ?? []);
                    $chosen = ($primary && in_array($primary->id, $ids, true))
                        ? $primary
                        : $translations->first(fn ($t) => in_array($t->id, $ids, true));

                    if ($chosen) {
                        $bookList[] = [
                            'name'      => $book->name,
                            'label'     => $label,
                            'color'     => $color,
                            'available' => true,
                            'url'       => route('bible.book', [
                                strtolower($chosen->abbreviation),
                                $book->slug,
                            ]),
                            'chapters'  => $avail[$book->id][$chosen->id] ?? 0,
                            // Display-only shift for chapter cells (150 for the
                            // Five Psalms of David → cells read 151..155).
                            'offset'    => $book->chapterCellOffset(),
                        ];
                    } else {
                        // No verses anywhere yet → dashed "soon" button.
                        $bookList[] = [
                            'name'      => $book->name,
                            'label'     => $label,
                            'color'     => $color,
                            'available' => false,
                            'url'       => null,
                            'chapters'  => 0,
                        ];
                    }
                }
            }

            $out[] = [
                'label' => $testament['label'],
                'books' => $bookList,
            ];
        }

        return $out;
    }
}