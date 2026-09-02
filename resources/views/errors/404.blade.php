@extends('layouts.app')

@section('title', 'Page not found — MEGABIBLE.net')

{{-- ============================================================
     404 — PAGE NOT FOUND.

     Lives at resources/views/errors/404.blade.php. Laravel finds it
     by convention: any NotFoundHttpException — a bad URL, or any
     abort(404) / abort_if(..., 404) in a controller — renders this
     view automatically. No route, no controller, no registration.

     Because it extends layouts.app, the visitor keeps the full site
     chrome: header (logo QuickNav + wordmark + search), footer, and
     whatever theme they've chosen. They are lost, but not alone.

     Styles follow the same class language as about/support
     (eyebrow, page-title, lead, cta-row, btn) so it reads as a
     sibling of those pages.

     BLADE TRAP (learned the hard way): never write a literal
     directive name — at-sign + word — inside a Blade comment.
     Directives compile BEFORE comments are stripped, so the
     compiler will happily start a raw PHP block from inside a
     comment and mangle the file. Escape with a double at-sign
     if you must mention one.
     ============================================================ --}}

{{-- ------------------------------------------------------------
     THE VERSE. One page load = one verse, chosen at random from
     this list. Add or remove entries freely; each needs `text`
     (public-domain wording — KJV here) and `ref`.

     Error views have no controller, so this small raw-PHP block
     below is the idiomatic home for the pick. Block form only,
     per the house rule.
     ------------------------------------------------------------ --}}
@php
    $verses = [
        [
            'text' => 'And Enoch walked with God: and he was not; for God took him.',
            'ref'  => 'Genesis 5:24',
        ],
    ];

    $verse = $verses[array_rand($verses)];
@endphp

@section('styles')
<style>
    /* Shared page-language classes (same as about/support). */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}
    .page-hero{margin:.5rem 0 2.4rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}
    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    /* ---- 404-specific ---- */

    /* The big numeral: wordmark font, ghosted into the page like a
       watermark. Theme-safe — it's just --muted at low opacity. */
    .err-code{
        font-family:var(--wordmark-font);
        font-size:clamp(6rem,22vw,11rem);
        font-weight:600;line-height:1;
        color:var(--muted);opacity:.28;
        letter-spacing:.02em;
        margin:1.5rem 0 0;
        user-select:none;
    }

    /* The verse: presented the way the site presents scripture —
       serif, generous leading, a panel with the accent rule on the
       left (same construction as the callout on about/support). */
    .err-verse{
        background:var(--panel);border:1px solid var(--rule);
        border-left:4px solid var(--accent);border-radius:8px;
        padding:1.6rem 1.8rem;margin:2.2rem 0;
    }
    .err-verse blockquote{
        margin:0;font-family:var(--serif);
        font-size:1.35rem;line-height:1.7;font-style:italic;
    }
    .err-verse cite{
        display:block;margin-top:.9rem;
        font-family:var(--sans);font-style:normal;
        font-size:.85rem;letter-spacing:.08em;text-transform:uppercase;
    }
    .err-verse cite a{color:var(--accent);text-decoration:none;}
    .err-verse cite a:hover{text-decoration:underline;}

    .err-help{color:var(--muted);font-size:1rem;margin:1.4rem 0 0;}

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .err-verse{padding:1.2rem 1.3rem;}
        .err-verse blockquote{font-size:1.18rem;}
    }
</style>
@endsection

@section('content')

    <section class="page-hero">
        <h1 class="page-title">This page is not.</h1>
        <p class="lead">
            The page you're looking for has not been found.
        </p>
    </section>

    <div class="err-code" aria-hidden="true">404</div>

    <figure class="err-verse">
        <blockquote>&ldquo;{{ $verse['text'] }}&rdquo;</blockquote>
        <cite>
            &mdash; <a href="{{ route('search', ['q' => $verse['ref']]) }}"
                       title="Read {{ $verse['ref'] }} on MEGABIBLE.net">{{ $verse['ref'] }} (KJV)</a>
        </cite>
    </figure>

    <p class="err-help">
        Try the search bar or click on the MEGABIBLE.net logo to quickly jump into a Bible book.
    </p>

@endsection