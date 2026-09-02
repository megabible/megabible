<?php

$t = \App\Models\Translation::where('abbreviation', 'WEB')->first();
$b = \App\Models\Book::where('osis_id', 'EsthGr')->first();

\App\Models\Verse::where('translation_id', $t->id)
    ->where('book_id', $b->id)
    ->orderBy('chapter')->orderBy('verse_number')
    ->get(['chapter', 'verse_number', 'text'])
    ->each(function ($v) {
        $words   = preg_split('/\s+/', trim($v->text));
        $incipit = implode(' ', array_slice($words, 0, 12));
        printf("%2d:%-3d [%4d] %s\n", $v->chapter, $v->verse_number, mb_strlen($v->text), $incipit);
    });