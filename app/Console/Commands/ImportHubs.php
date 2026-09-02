<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\BookIntro;
use App\Models\Manuscript;
use App\Models\TimelineEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportHubs extends Command
{
    protected $signature = 'import:hubs
                            {book? : OSIS id of a single book, e.g. John. Omit to import all.}
                            {--dir=hubs : Directory under storage/app/ holding catalogs and books/*.json}';

    protected $description = 'Import book hub content (intros, manuscripts, timeline events, outlines) from JSON';

    public function handle(): int
    {
        $dir = trim($this->option('dir'), '/');

        // 1. Manuscript catalog (required)
        $catalogPath = "{$dir}/manuscripts.json";
        if (! Storage::exists($catalogPath)) {
            $this->error("Manuscript catalog not found: storage/app/{$catalogPath}");
            return self::FAILURE;
        }
        $catalog = json_decode(Storage::get($catalogPath), true);
        if (! is_array($catalog)) {
            $this->error("Could not parse {$catalogPath} as JSON.");
            return self::FAILURE;
        }
        foreach ($catalog as $slug => $m) {
            Manuscript::updateOrCreate(['slug' => $slug], [
                'name'         => $m['name'] ?? $slug,
                'siglum'       => $m['siglum'] ?? null,
                'kind'         => $m['kind'] ?? 'other',
                'date_display' => $m['date_display'] ?? null,
                'date_sort'    => $m['date_sort'] ?? null,
                'description'  => $m['description'] ?? null,
            ]);
        }
        $this->info('Synced ' . count($catalog) . ' manuscripts.');

        // 2. Timeline event catalog (optional)
        $eventsPath = "{$dir}/events.json";
        if (Storage::exists($eventsPath)) {
            $events = json_decode(Storage::get($eventsPath), true) ?: [];
            foreach ($events as $slug => $e) {
                TimelineEvent::updateOrCreate(['slug' => $slug], [
                    'label'        => $e['label'] ?? $slug,
                    'date_display' => $e['date_display'] ?? null,
                    'date_sort'    => $e['date_sort'] ?? null,
                    'kind'         => $e['kind'] ?? 'event',
                ]);
            }
            $this->info('Synced ' . count($events) . ' timeline events.');
        } else {
            $this->line("No events.json found — skipping timeline event catalog.");
        }

        // Source bibliography catalog (optional)
        $sourcesPath = "{$dir}/sources.json";
        if (Storage::exists($sourcesPath)) {
            $sources = json_decode(Storage::get($sourcesPath), true) ?: [];
            foreach ($sources as $slug => $s) {
                \App\Models\Source::updateOrCreate(['slug' => $slug], [
                    'citation'  => $s['citation'] ?? $slug,
                    'author'    => $s['author'] ?? null,
                    'title'     => $s['title'] ?? null,
                    'year'      => $s['year'] ?? null,
                    'publisher' => $s['publisher'] ?? null,
                    'url'       => $s['url'] ?? null,
                ]);
            }
            $this->info('Synced ' . count($sources) . ' sources.');
        } else {
            $this->line("No sources.json found — skipping source catalog.");
        }

        // 3. Which book files?
        $single = $this->argument('book');
        $files = $single
            ? ["{$dir}/books/{$single}.json"]
            : array_filter(Storage::files("{$dir}/books"), fn ($f) => str_ends_with($f, '.json'));

        if (empty($files)) {
            $this->warn('No book hub files found to import.');
            return self::SUCCESS;
        }

        // Reload catalogs AFTER syncing so new entries are present
        $manuscriptsBySlug = Manuscript::all()->keyBy('slug');
        $eventsBySlug      = TimelineEvent::all()->keyBy('slug');
        $sourcesBySlug = \App\Models\Source::all()->keyBy('slug');
        $done = 0;

        foreach ($files as $file) {
            if (! Storage::exists($file)) {
                $this->warn("Skipping missing file: storage/app/{$file}");
                continue;
            }
            $data = json_decode(Storage::get($file), true);
            if (! is_array($data) || empty($data['osis'])) {
                $this->warn("Skipping {$file}: invalid JSON (needs an \"osis\" key).");
                continue;
            }
            $book = Book::findByOsis($data['osis']);
            if (! $book) {
                $this->warn("Skipping {$file}: no book with OSIS '{$data['osis']}'.");
                continue;
            }

            $intro = $data['intro'] ?? [];
            $tl    = $data['timeline'] ?? [];

            BookIntro::updateOrCreate(['book_id' => $book->id], [
                'original_name'                 => $intro['original_name'] ?? null,
                'original_name_transliteration' => $intro['original_name_transliteration'] ?? null,
                'traditional_author'            => $intro['traditional_author'] ?? null,
                'scholarly_view'                => $intro['scholarly_view'] ?? null,
                'dating'                        => $intro['dating'] ?? null,
                'dating_sort'                   => $intro['dating_sort'] ?? null,
                'dating_start'                  => $intro['dating_start'] ?? null,   // bar start year
                'dating_end'                    => $intro['dating_end'] ?? null,     // bar end year
                'language'                      => $intro['language'] ?? null,
                'genre'                         => $intro['genre'] ?? null,
                'place_written'                 => $intro['place_written'] ?? null,
                'summary'                       => $intro['summary'] ?? null,
                'authorship_note'               => $intro['authorship_note'] ?? null,
                'timeline_start'                => $tl['start'] ?? null,
                'timeline_end'                  => $tl['end'] ?? null,
                'timeline_color'                => $tl['color'] ?? null,
                'timeline_text'                 => $tl['text'] ?? null,            // descriptive blurb under the Timeline header
                'timeline_books'                => $tl['books'] ?? null,           // LEGACY fallback (optional now)
                'timeline_groups'               => $tl['groups'] ?? null,          // [{label,color,books:[]}, ...]
                'outline'                       => $data['outline'] ?? null,
                'composition_layers'            => $intro['composition_layers'] ?? null,
            ]);

            // Manuscript links (with per-book notes)
            $msLinks = [];
            foreach (($data['manuscripts'] ?? []) as $link) {
                $slug = $link['slug'] ?? null;
                $ms = $slug ? $manuscriptsBySlug->get($slug) : null;
                if (! $ms) { $this->warn("  {$book->name}: unknown manuscript '{$slug}'."); continue; }
                $msLinks[$ms->id] = ['note' => $link['note'] ?? null];
            }
            $book->manuscripts()->sync($msLinks);

            // Timeline event links (no note)
            $eventIds = [];
            foreach (($tl['events'] ?? []) as $slug) {
                $ev = $eventsBySlug->get($slug);
                if (! $ev) { $this->warn("  {$book->name}: unknown event '{$slug}'."); continue; }
                $eventIds[] = $ev->id;
            }
            $book->timelineEvents()->sync($eventIds);

            // Source citations (with per-book note + ordering)
            $srcLinks = [];
            $order = 0;
            foreach (($data['sources'] ?? []) as $link) {
                // accept either a bare "slug" string or an object {slug, note}
                $slug = is_array($link) ? ($link['slug'] ?? null) : $link;
                $src = $slug ? $sourcesBySlug->get($slug) : null;
                if (! $src) { $this->warn("  {$book->name}: unknown source '{$slug}'."); continue; }
                $srcLinks[$src->id] = [
                    'note'       => is_array($link) ? ($link['note'] ?? null) : null,
                    'sort_order' => $order++,
                ];
            }
            $book->sources()->sync($srcLinks);

            $this->line("Imported hub: {$book->name}");
            $done++;
        }

        $this->info("Done. Imported {$done} book hub(s).");
        return self::SUCCESS;
    }
}