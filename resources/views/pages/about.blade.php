@extends('layouts.app')

@section('title', 'About — MEGABIBLE.net')

{{-- ============================================================
     ABOUT-PAGE CSS. Injected into the layout's <head> at
     @yield('styles'), so it loads after (and can lean on) the base
     design tokens defined in layouts/app.blade.php — --bg, --ink,
     --muted, --accent, --rule, --panel, --soon, --serif, --sans.

     These styles are shared, class-for-class, with support.blade.php.
     If you ever want to stop maintaining two copies, lift this whole
     block into a partial and @include it on both pages.
     ============================================================ --}}
@section('styles')
<style>
    /* Eyebrow — the small uppercase sans label that sits above a title.
       Mirrors the tagline treatment in the site header. */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}

    /* Hero block */
    .page-hero{margin:.5rem 0 2.4rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}

    /* Standard reading paragraphs inside a section */
    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}

    /* Section rhythm + the accent heading (same look as the homepage section-head) */
    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .subsection-head{font-size:1.15rem;font-weight:600;margin:1.6rem 0 .6rem;}

    /* Image placeholder — a dashed parchment panel marking where real art
       will go. Same dashed "coming soon" language as the homepage book tiles.
       Swap each one for an <img> when the asset is ready. Add .tall or .short
       to change the aspect ratio. */
    .media{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.7rem;background:var(--panel);border:2px dashed var(--soon);border-radius:10px;color:var(--soon);padding:2rem;aspect-ratio:16/9;margin:1.4rem 0;}
    .media svg{width:46px;height:46px;opacity:.85;}
    .media .media-cap{font-family:var(--sans);font-size:.85rem;letter-spacing:.02em;line-height:1.45;max-width:36ch;}
    .media.tall{aspect-ratio:4/5;}
    .media.short{aspect-ratio:21/9;}

    /* Principles grid */
    .pillars{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));margin:1.4rem 0 0;}
    .pillar{border:1px solid var(--rule);border-radius:8px;background:var(--bg);padding:1.1rem 1.2rem;}
    .pillar h3{font-family:var(--sans);font-size:.95rem;font-weight:700;letter-spacing:.01em;margin:0 0 .4rem;color:var(--accent);}
    .pillar p{font-size:1rem;line-height:1.5;margin:0;}

    /* A soft callout panel — used for the visual-animated-Bible vision. */
    .callout{background:var(--panel);border:1px solid var(--rule);border-left:4px solid var(--accent);border-radius:8px;padding:1.4rem 1.5rem;margin:1.4rem 0;}
    .callout .section-head{margin-top:0;}
    .callout p:last-child{margin-bottom:0;}

    /* Call-to-action buttons */
    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    {{-- Swap this headline + lead for your own copy. --}}
    <section class="page-hero">
        <h1 class="page-title">The #1 Bible Site in the World</h1>
        <p class="lead">
            MEGABIBLE.net is a free, ad-free place to read Scripture and dig into
            the people, texts, and centuries of tradition that surround it — built
            for the genuinely curious, no seminary degree required.
        </p>
    </section>

    {{-- Hero image. Replace with a real <img> when you have one. --}}
    <div class="media">
        @include('pages._media-icon')
        <span class="media-cap">Hero image — e.g. a wide, warm shot of the reading view, or your logo over parchment.</span>
    </div>

    {{-- ============ OUR STORY ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Our story</h2>
        <p>
            <!-- Replace with your real origin story. This is friendly placeholder copy. -->
            MEGABIBLE.net started as one person's itch: every major Bible site
            treated the Apocrypha as an afterthought, ignored the Pseudepigrapha,
            and hid two thousand years of interpretive tradition behind paywalls or
            on websites that looked like they hadn't been touched since 1998. It felt
            like the texts deserved better — and so did the people who wanted to read them.
        </p>
        <p>
            So this is a fresh attempt: the canonical books, the texts that didn't
            make the canon, the voices that shaped them, and the long conversation
            of interpreters who came after — all in one clean, fast, modern place.
            It's a labor of love, built in the open and improved a little every week.
        </p>
    </section>

    {{-- ============ WHAT WE'RE BUILDING ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">What we're building</h2>
        <p>
            A reading and research environment, not just a verse lookup. That means
            multiple public-domain translations side by side, the deuterocanon and
            pseudepigrapha treated as first-class texts, short scholarly introductions
            to every book, and pages on the major figures of the story — written to be
            approachable without dumbing anything down.
        </p>
        <p>
            Every text on the site shows where it came from: the translator, the year,
            the source, and the license. Nothing is hidden, and nothing is behind a paywall.
        </p>

        <div class="media short">
            @include('pages._media-icon')
            <span class="media-cap">Wide image — a screenshot of the parallel translation view, or a book intro page.</span>
        </div>
    </section>

    {{-- ============ THE VISUAL ANIMATED BIBLE (the big vision) ============ --}}
    {{-- This flagship mission appears on BOTH the About and Support pages. --}}
    <section class="page-section">
        <div class="callout prose">
            <h2 class="section-head">The world's first complete visual animated Bible</h2>
            <p>
                The long-term dream behind MEGABIBLE.net is bigger than a website. We're
                working toward the world's first <strong>complete visual, animated Bible</strong> —
                every book, brought to life as something you can watch, not only read.
            </p>
            <p>
                It's an enormous undertaking, and it's exactly the kind of thing that
                only happens when a community decides it should exist. Reading the Bible
                should be free and accessible to everyone, everywhere — and one day, we
                believe, so should <em>seeing</em> it.
            </p>
        </div>

        <div class="media">
            @include('pages._media-icon')
            <span class="media-cap">Concept art / still frame from the animated Bible project goes here.</span>
        </div>
    </section>

    {{-- ============ OUR PRINCIPLES ============ --}}
    <section class="page-section">
        <h2 class="section-head">What we stand for</h2>
        <p class="prose">A few commitments that shape every decision here:</p>

        <div class="pillars">
            <div class="pillar">
                <h3>Scholarly &amp; neutral</h3>
                <p>No denominational gatekeeping. Where scholars disagree, we present the disagreement rather than pick a side for you.</p>
            </div>
            <div class="pillar">
                <h3>Sources always visible</h3>
                <p>Every text carries its provenance — manuscript, translator, year, and license. You always know what you're reading.</p>
            </div>
            <div class="pillar">
                <h3>Comprehensive, not chaotic</h3>
                <p>Texts outside the Protestant canon are clearly labeled but never hidden. Everything stays easy to navigate.</p>
            </div>
            <div class="pillar">
                <h3>Free &amp; ad-free, forever</h3>
                <p>No ads, no affiliate links, no sponsored content. The site is supported entirely by people who believe in it.</p>
            </div>
            <div class="pillar">
                <h3>Generous &amp; open</h3>
                <p>Our original writing is openly licensed so other Bible projects can build on it too. A rising tide lifts all boats.</p>
            </div>
        </div>
    </section>

    <hr class="divider">

    {{-- ============ CLOSING CTA ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Come read with us</h2>
        <p>
            Everything here is free and always will be. If it's useful to you, the
            best thanks is simple: read, share it with a friend, and — if you're able —
            help keep it going.
        </p>
        <div class="cta-row">
            <a class="btn" href="{{ url('/bible') }}">Start reading</a>
            <a class="btn btn-ghost" href="{{ route('support') }}">Support the mission</a>
        </div>
    </section>

@endsection