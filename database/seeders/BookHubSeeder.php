<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookIntro;
use App\Models\Manuscript;
use Illuminate\Database\Seeder;

class BookHubSeeder extends Seeder
{
    public function run(): void
    {
        $john = Book::findByOsis('John');

        if (! $john) {
            $this->command->warn('Book "John" not found. Run BookSeeder first.');
            return;
        }

        // --- Manuscripts (shared entities) ---
        $manuscripts = [
            ['p52', 'Rylands Library Papyrus 𝔓52', '𝔓52', 'papyrus', 'c. 125 CE', 125,
                'A small papyrus fragment, generally regarded as the earliest known surviving portion of any New Testament text.'],
            ['p66', 'Papyrus 𝔓66 (Bodmer II)', '𝔓66', 'papyrus', 'c. 200 CE', 200,
                'An almost complete early codex of the Gospel of John, part of the Bodmer Papyri.'],
            ['p75', 'Papyrus 𝔓75 (Bodmer XIV–XV)', '𝔓75', 'papyrus', 'c. 175–225 CE', 200,
                'An early codex preserving large portions of Luke and John in a careful hand.'],
            ['codex-vaticanus', 'Codex Vaticanus', 'B', 'codex', 'c. 300–325 CE', 325,
                'One of the oldest near-complete Bibles in Greek, held in the Vatican Library.'],
            ['codex-sinaiticus', 'Codex Sinaiticus', 'ℵ', 'codex', 'c. 330–360 CE', 350,
                'A fourth-century majuscule containing the complete New Testament.'],
            ['codex-alexandrinus', 'Codex Alexandrinus', 'A', 'codex', '5th century CE', 450,
                'A fifth-century majuscule of the Greek Bible.'],
        ];

        $ms = [];
        foreach ($manuscripts as [$slug, $name, $siglum, $kind, $dateDisplay, $dateSort, $desc]) {
            $ms[$slug] = Manuscript::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'siglum' => $siglum, 'kind' => $kind,
                 'date_display' => $dateDisplay, 'date_sort' => $dateSort, 'description' => $desc]
            );
        }

        // --- The hub content for John ---
        BookIntro::updateOrCreate(
            ['book_id' => $john->id],
            [
                'traditional_author' => 'John the Apostle, son of Zebedee',
                'scholarly_view'     => "Anonymous; product of a later 'Johannine community'",
                'dating'             => 'c. 90–110 CE',
                'dating_sort'        => 95,
                'language'           => 'Koine Greek',
                'genre'              => 'Gospel',
                'place_written'      => 'Traditionally Ephesus',
                'summary' => <<<MD
The Gospel of John is the fourth of the canonical Gospels and the most distinctive of the four. Where Matthew, Mark, and Luke (the Synoptic Gospels) share a common narrative outline, John follows its own path: a soaring prologue identifying Jesus with the divine *Logos* ("the Word"), a sequence of seven "signs," extended discourses, and the famous "I am" sayings.

Its purpose is stated openly near the end — that readers might believe Jesus is the Messiah, the Son of God. John omits much the Synoptics include while preserving unique material such as the wedding at Cana, the night conversation with Nicodemus, and the raising of Lazarus.
MD,
                'authorship_note' => <<<MD
Christian tradition from the late second century attributed the Gospel to John the Apostle, identified within the text only as "the disciple whom Jesus loved." Most contemporary critical scholars regard it instead as the work of a later Johannine community, composed in stages and reaching final form near the end of the first century. The question remains genuinely disputed; the text itself names no author.
MD,
            ]
        );

        // --- Link manuscripts to John, with book-specific notes on the pivot ---
        $john->manuscripts()->syncWithoutDetaching([
            $ms['p52']->id  => ['note' => 'Contains John 18:31–33, 37–38 — the oldest physical witness to this Gospel. Its precise dating (often c. 125 CE) is debated.'],
            $ms['p66']->id  => ['note' => 'Preserves nearly the entire Gospel.'],
            $ms['p75']->id  => ['note' => 'Substantial portions of John survive.'],
            $ms['codex-vaticanus']->id   => ['note' => 'Includes the complete text of John.'],
            $ms['codex-sinaiticus']->id  => ['note' => 'Includes the complete text of John.'],
            $ms['codex-alexandrinus']->id => ['note' => 'Includes John, with a lacuna at John 6:50–8:52 from lost leaves.'],
        ]);

        $this->command->info('Seeded hub content for John.');
    }
}
