@extends('layouts.app')

@section('title', 'About — MEGABIBLE.net')

{{-- ============================================================
     ABOUT PAGE — HYBRID r2
     Changes in this revision:
       - Hero image is clickable: opens a full-resolution lightbox
         (native dialog element; the anchor href is the full image,
         so with JS unavailable the click still opens the file).
       - Canon grid colors come from config('canon.section_colors')
         — one source of truth with the QuickNav. No hardcoded
         palette names in this file.
       - Canon tile text: big colored count + "books" on line one,
         the "of the ..." remainder on line two.
       - Canon tiles are links to the homepage section anchors
         (ids added to index.blade.php in the same pass) and scale
         up on hover.
       - Typing Vigil / Typing Scrimmage headers link to their
         pages via route names (typing.vigil.home / typing.scrimmage).
       - Isaiah 55:1 (KJV) verse block added after "We believe
         differently", with a pill citation linking to the verse
         in the reader.

     NOTE: this page is registered as a view route with no
     controller, so the canon config is read in the php block
     below. If /about ever gets a controller, move that lookup
     there per the Blade-light convention.
     ============================================================ --}}
@section('styles')
<style>
    /* Eyebrow — small uppercase sans label above a title. */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}

    /* Hero block */
    .page-hero{margin:.5rem 0 2rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}

    /* ---- Hero image ------------------------------------------------
       Framed screenshot; the anchor around it opens the full-res
       lightbox below. Delete the aspect-ratio and object-fit lines
       to show the full uncropped screenshot inline instead. */
    .hero-shot{margin:0 0 2.4rem;}
    .hero-zoom{display:block;cursor:zoom-in;border-radius:10px;outline:none;transition:transform .14s ease;}
    .hero-zoom:hover{transform:scale(1.05);}
    .hero-zoom:focus-visible{box-shadow:0 0 0 3px rgba(107,31,31,.25);}
    .hero-shot img{
        display:block;width:100%;aspect-ratio:21/9;object-fit:cover;object-position:center top;
        border:1px solid var(--rule);border-radius:10px;
        box-shadow:0 12px 32px rgba(42,31,23,.12);
        background:var(--panel);
    }
    .hero-shot figcaption{
        font-family:var(--sans);font-size:.85rem;color:var(--muted);
        margin-top:.6rem;line-height:1.45;
    }

    /* The lightbox — a native dialog. Escape and the backdrop are
       handled by the browser; the script below adds click-to-close. */
    .lightbox{border:none;padding:0;background:transparent;max-width:96vw;max-height:96vh;}
    .lightbox::backdrop{background:rgba(42,31,23,.78);}
    .lightbox img{
        display:block;max-width:96vw;max-height:92vh;
        border:1px solid var(--rule);border-radius:10px;
        background:var(--panel);cursor:zoom-out;
    }

    /* Standard reading paragraphs inside a section */
    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}

    /* Section rhythm + the accent heading */
    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .subsection-head{font-size:1.15rem;font-weight:600;margin:1.6rem 0 .6rem;}

    /* ---- The ethos verse -----------------------------
       Poetry-set serif behind a left accent rule, with a pill
       citation (the translation-switcher pill language) linking to
       the verse in the reader. */
    .ethos-verse{margin:1.6rem 0;padding:.3rem 0 .3rem 1.4rem;border-left:4px solid var(--accent);}
    .ethos-verse p{font-size:1.22rem;line-height:1.8;font-style:italic;margin:0;}
    .ethos-verse-ref{margin-top:.8rem;font-family:var(--sans);font-size:.85rem;}
    .ethos-verse-ref a{
        display:inline-flex;align-items:center;gap:.4rem;
        padding:.25rem .8rem;border:1px solid var(--rule);border-radius:999px;
        background:var(--bg);color:var(--accent);font-weight:600;text-decoration:none;
        transition:background .12s,border-color .12s;
    }
    .ethos-verse-ref a:hover{background:var(--panel);border-color:var(--accent);}

    /* ---- The canon grid ---------------------------------------------
       One tile per canon division; each is a LINK to that section on
       the homepage. The division color arrives as --cg, resolved from
       config canon.section_colors in the php block below — the same
       source the QuickNav tints from. Line one: big colored count +
       "books"; line two: the "of the ..." remainder. */
    .canon-grid{list-style:none;display:grid;gap:.7rem;
        grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
        margin:.6rem 0 1.6rem;padding:0;}

    /* Canon group headers — First / Second Testament, above each grid. */
    .canon-group-head{font-size:1.15rem;font-weight:600;color:var(--ink);margin:1.8rem 0 .2rem;}
    .canon-group-head:first-of-type{margin-top:1.2rem;}
    .canon-group-head .cnt{font-family:var(--sans);font-weight:400;font-size:.9rem;color:var(--muted);margin-left:.4rem;letter-spacing:.02em;}
    .canon-grid a{
        --cg:var(--tl-clay);
        display:flex;flex-direction:column;gap:.15rem;height:100%;
        background:var(--bg);border:1px solid var(--rule);border-top:4px solid var(--cg);
        border-radius:8px;padding:.85rem 1rem .9rem;
        text-decoration:none;color:var(--ink);
        transition:transform .14s ease,border-color .12s;
    }
    .canon-grid a:hover{transform:scale(1.05);border-color:var(--cg);}
    .canon-grid a:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.25);}
    .canon-grid .ct{
        display:flex;align-items:baseline;gap:.35rem;
        font-size:1.9rem;font-weight:600;line-height:1;color:var(--cg);
        font-variant-numeric:tabular-nums;
    }
    .canon-grid .bw{font-family:var(--sans);font-size:.85rem;font-weight:400;color:var(--ink);}
    .canon-grid .lb{font-family:var(--sans);font-size:.85rem;line-height:1.4;color:var(--ink);}

    /* The three-engines list — plain rows that anchor-link down to
       their rail sections. */
    .engine-list{list-style:none;margin:1.2rem 0 0;padding:0;border-top:1px solid var(--rule);}
    .engine-list li{border-bottom:1px solid var(--rule);}
    .engine-list a{
        display:flex;align-items:baseline;gap:.5rem;
        padding:.55rem .2rem;color:var(--ink);text-decoration:none;
        font-size:1.05rem;line-height:1.5;
    }
    .engine-list a:hover{color:var(--accent);}
    .engine-list .go{margin-left:auto;color:var(--muted);font-family:var(--sans);font-size:.8rem;}
    .engine-list a:hover .go{color:var(--accent);}

    /* ---- Engine rail sections ---------------------------------------
       Desktop: a 190px left rail holds the engine name beside the
       prose column. Mobile: the rail stacks above the prose. */
    .rail{display:grid;grid-template-columns:190px 1fr;gap:1.6rem;padding-top:1.6rem;}
    .rail-head{color:var(--accent);font-size:1.35rem;font-weight:600;
        line-height:1.25;margin:0;letter-spacing:.01em;}
    .rail-body{min-width:0;}

    /* Vigil / Scrimmage as a definition list inside the typing rail.
       Each dt is now a link to its app. */
    .apps{margin:1.2rem 0 0;}
    .apps dt{margin:1rem 0 .25rem;font-size:1.05rem;}
    .apps dt:first-child{margin-top:0;}
    .apps dt a{color:var(--accent);font-weight:600;text-decoration:none;}
    .apps dt a:hover{text-decoration:underline;}
    .apps dt .go{font-family:var(--sans);font-size:.8rem;margin-left:.3rem;}
    .apps dd{margin:0;line-height:1.6;}

    /* Image placeholder — dashed parchment panel marking where real art
       will go. Swap each one for a real image when the asset is ready. */
    .ph{display:flex;flex-direction:column;align-items:center;justify-content:center;
        text-align:center;gap:.7rem;background:var(--panel);border:2px dashed var(--soon);
        border-radius:10px;color:var(--soon);padding:2rem;aspect-ratio:16/9;margin:0 0 1.1rem;}
    .ph .ph-cap{font-family:var(--sans);font-size:.85rem;letter-spacing:.02em;line-height:1.45;max-width:36ch;}
    .ph.tall{aspect-ratio:4/5;}
    .ph.short{aspect-ratio:21/9;}

    /* Real feature screenshots — same wide crop as the .ph placeholders
    they replace (21/9 via .short). The anchor scales on hover like the
    canon tiles and opens a full-res lightbox like the hero. */
    .feature-shot{margin:0 0 1.1rem;}
    .feature-shot .shot-zoom{
        display:block;cursor:zoom-in;border-radius:10px;outline:none;
        transition:transform .14s ease;
    }
    .feature-shot .shot-zoom:hover{transform:scale(1.05);}
    .feature-shot .shot-zoom:focus-visible{box-shadow:0 0 0 3px rgba(107,31,31,.25);}
    .feature-shot img{
        display:block;width:100%;aspect-ratio:21/9;object-fit:cover;object-position:center top;
        border:1px solid var(--rule);border-radius:10px;box-shadow:0 12px 32px rgba(42,31,23,.12);background:var(--panel);
    }

    /* Call-to-action buttons */
    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);
        font-size:1rem;font-weight:600;text-decoration:none;padding:.7rem 1.3rem;
        border-radius:8px;border:1px solid var(--accent);background:var(--accent);
        color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    @media (max-width:700px){
        .rail{grid-template-columns:1fr;gap:.7rem;}
    }
    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
        .hero-shot img{aspect-ratio:16/10;}
        .canon-grid{grid-template-columns:repeat(2,1fr);}
    }
</style>
@endsection

@section('content')
    @php
        // Canon divisions for the tile grid. The color for each tile is
        // resolved from config canon.section_colors — the SAME map the
        // QuickNav tints its book buttons from, so the two can never
        // drift apart. Each key is also the homepage anchor id (added
        // to index.blade.php), so the tiles deep-link straight to their
        // section. Counts mirror the page copy.
        $canonColors = config('canon.section_colors', []);
        // Grouped into the two testaments. The group 'count' is the sum of
        // its divisions (57 and 34), shown in each header.
        $canonGroups = [
            ['label' => 'First Testament', 'count' => 57, 'divisions' => [
                ['key' => 'torah',           'count' => 5,  'rest' => 'of the Torah'],
                ['key' => 'neviim',          'count' => 21, 'rest' => "of the Nevi'im"],
                ['key' => 'ketuvim',         'count' => 13, 'rest' => 'of the Ketuvim'],
                ['key' => 'ft_deuterocanon', 'count' => 9,  'rest' => 'of the Deuterocanon'],
                ['key' => 'ft_apocrypha',    'count' => 9,  'rest' => 'of the First Testament Apocrypha'],
            ]],
            ['label' => 'Second Testament', 'count' => 34, 'divisions' => [
                ['key' => 'pauline_epistles',  'count' => 10, 'rest' => 'of the Pauline Epistles'],
                ['key' => 'gospels_acts',      'count' => 4,  'rest' => 'of the Synoptic Gospels and Acts'],
                ['key' => 'johannine',         'count' => 5,  'rest' => 'of the Johannine Works'],
                ['key' => 'pastoral_epistles', 'count' => 3,  'rest' => 'of the Pastoral Epistles'],
                ['key' => 'catholic_epistles', 'count' => 5,  'rest' => 'of the Catholic Epistles'],
                ['key' => 'st_apocrypha',      'count' => 7,  'rest' => 'of the Second Testament Apocrypha'],
            ]],
        ];
    @endphp

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">The #1 Bible Site in the World.</h1>
        <p class="lead">
            <x-brand/> is a free, ad-free place to read, study, and type all <strong>91</strong> Hebrew and Christian books of the Bible.
        </p>
    </section>

    {{-- hero screenshot --}}
    <figure class="hero-shot">
        <a class="hero-zoom" id="hero-zoom"
           href="{{ asset('images/about_hero_pericope.png') }}"
           aria-label="View the full-size screenshot">
            <img src="{{ asset('images/about_hero_pericope.png') }}"
                 alt="A Pericope study board with verse cards arranged in a grid">
        </a>
        <figcaption>A Pericope study board in Grid mode.</figcaption>
    </figure>

    {{-- The full-resolution lightbox. Renders nothing until opened. --}}
    <dialog class="lightbox" id="hero-lightbox">
        <img src="{{ asset('images/about_hero_pericope.png') }}"
             alt="A Pericope study board">
    </dialog>

    {{-- ============ ETHOS ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">The <x-brand/> Ethos</h2>
        <p>
            The books of the Hebrew Bible have been in circulation for over two thousand years, and the 
            books of the Christian Bible for almost as long. These sacred works were a luxury for the rich
            until the advent of the printing press in the 15th century, which fueled the spread of the Bible and the Protestant Reformation.
            This boon of free information made Jewish and Christian theology accessible to the common man and woman.
        </p>

        <blockquote class="ethos-verse">
            <p>
                If there be among you a poor man of one of thy brethren within any of thy gates in thy land which the 
                Lord thy God giveth thee, thou shalt not harden thy heart, nor shut thine hand from thy poor brother:
                but thou shalt open thine hand wide unto him, and shalt surely lend him sufficient for his need, in that which he wanteth.
            </p>
            <footer class="ethos-verse-ref">
                <a href="{{ route('bible.verse', ['translation' => 'kjv', 'book' => 'deuteronomy', 'chapter' => 15, 'verse' => 7, 'v' => 7]) }}">Deuteronomy 15:7-8 &middot; KJV</a>
            </footer>
        </blockquote>        

        <p>            
            In the last century, there has been a gold rush to create copyrighted translations of these Bible books,
            resulting in an ever-growing translation catalog that has become daunting to the lay reader. Additionally, the dawn of the world wide web
            saw the rise of numerous Bible websites, the most popular of which host multiple advertisements right next to the verses themselves,
            our modern illuminated Bible, while popular Bible software services place valuable tools and scholarly information behind paywalls and subscriptions.
        </p>
        <p>
            <strong>Not so at <x-brand/></strong>
        </p>

        <blockquote class="ethos-verse">
            <p>
                Ho, every one that thirsteth, come ye to the waters,<br>
                And he that hath no money;<br>
                Come ye, buy, and eat;<br>
                Yea, come, buy wine and milk without money and without price.
            </p>
            <footer class="ethos-verse-ref">
                <a href="{{ route('bible.verse', ['translation' => 'kjv', 'book' => 'isaiah', 'chapter' => 55, 'verse' => 1, 'v' => 1]) }}">Isaiah 55:1 &middot; KJV</a>
            </footer>
        </blockquote>

        <p>
            Here, we host the extended 91 book Bible canon entirely in the public domain. The majority of the corpus is covered by two 
            English translations, one traditional (KJV) and one modern (World English Bible, completed in 2020). Extended Apocryphal works are hosted
            with the same level of detail, with cross-references to the main canonical body.
        </p>
        <p>  
            These books are divided further into the following subgroups:
        </p>
        @foreach ($canonGroups as $group)
            <h3 class="canon-group-head">{{ $group['label'] }}<span class="cnt">({{ $group['count'] }} books)</span></h3>
            <ul class="canon-grid">
                @foreach ($group['divisions'] as $d)
                    <li>
                        <a href="{{ route('home') }}#{{ $d['key'] }}"
                           style="--cg:var(--tl-{{ $canonColors[$d['key']] ?? 'clay' }})">
                            <span class="ct">{{ $d['count'] }} <span class="bw">books</span></span>
                            <span class="lb">{{ $d['rest'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
        <p>
            This library of books is hosted on <x-brand/> sponsor-free and ad-free, allowing readers to copy as much
            of the text for their own purposes as they need. Every text shows its provenance
            and original source.
        </p>
    </section>

    {{-- ============ CORE FEATURES ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Three Core Features</h2>
        <p>
            Building on this free information ethos, <x-brand/> has developed three applications for interacting with
            the 91 books of the Bible:
        </p>
    </section>

    {{-- ============ THE READER ============ --}}
    <section class="page-section rail" id="reader">
        <h2 class="rail-head">The <x-brand/> Reader</h2>
        <div class="rail-body prose">
            <figure class="feature-shot">
                <a class="shot-zoom" href="{{ asset('images/about_synthesis.png') }}"
                data-lightbox="fs-reader" aria-label="View the full-size screenshot">
                    <img src="{{ asset('images/about_synthesis.png') }}"
                        alt="A Typing Vigil screen for the book of Esther, showing per-chapter progress bars">
                </a>
                <dialog class="lightbox" id="fs-reader">
                    <img src="{{ asset('images/about_synthesis.png') }}"
                        alt="A Typing Vigil screen for the book of Esther, full size">
                </dialog>
            </figure>
            <p>
                The responsive web framework of <x-brand/> has been tested thoroughly on desktop and
                mobile devices by real humans, ensuring maximum readability and a great user experience.
                Readers can easily jump from Bible book to Bible book by using the quick navigation shortcut, or expand a verse to
                view the words in their original Hebrew, Greek, or Aramaic.
            </p>
            <p>
                Each Bible book also includes a book hub page with an easy to read timeline and links to sources.
                New information is added and updated every week.
            </p>
        </div>
    </section>

    {{-- ============ THE STUDY ENGINE ============ --}}
    <section class="page-section rail" id="study">
        <h2 class="rail-head">The <x-brand/> Study Engine</h2>
        <div class="rail-body prose">
        <figure class="feature-shot">
            <a class="shot-zoom" href="{{ asset('images/about_scroll.png') }}"
            data-lightbox="fs-study" aria-label="View the full-size screenshot">
                <img src="{{ asset('images/about_scroll.png') }}"
                    alt="The MEGABIBLE.net reader showing a verse in its original Hebrew language">
            </a>
            <dialog class="lightbox" id="fs-study">
                <img src="{{ asset('images/about_scroll.png') }}"
                    alt="The MEGABIBLE.net reader showing a verse in its original Hebrew language">
            </dialog>
        </figure>
            <p>
                <x-brand/> employs a new intuitive Bible study system that allows readers to collect verses into study boards 
                called <strong>Pericope</strong>. 
            </p>
            <dl class="apps">
                <dt><a href="{{ route('extras.pericope') }}">Pericope<span class="go">&rarr;</span></a></dt>
                <dd>Pericope boards can be viewed in <strong>Scroll</strong> mode, allowing verses to be read in a social media-like
                feed, and <strong>Grid</strong> mode, which allows readers to arrange and present collected verses in new ways.</dd>
            </dl>                
        </div>
    </section>

    {{-- ============ THE TYPING ENGINE ============ --}}
    <section class="page-section rail" id="typing">
        <h2 class="rail-head">The <x-brand/> Typing Engine</h2>
        <div class="rail-body prose">
            <figure class="feature-shot">
                <a class="shot-zoom" href="{{ asset('images/about_typing.png') }}"
                data-lightbox="fs-type" aria-label="View the full-size screenshot">
                    <img src="{{ asset('images/about_typing.png') }}"
                        alt="TA Pericope study board in Scroll mode with a dark theme">
                </a>
                <dialog class="lightbox" id="fs-type">
                    <img src="{{ asset('images/about_typing.png') }}"
                        alt="A Pericope study board in Scroll mode with a dark theme">
                </dialog>
            </figure>
            <p>
                Whenever people start talking about Bible websites, they inevitably end up at the same question: Where can I type the Bible?
                <x-brand/> has finally solved this issue with a new robust typing engine built in a revolutionary computer language called JavaScript.
                This engine is used in two applications: <strong>Vigil</strong> and <strong>Scrimmage</strong>.
            </p>
            <dl class="apps">
                <dt><a href="{{ route('typing.vigil.home') }}">Typing Vigil<span class="go">&rarr;</span></a></dt>
                <dd>Type all 91 books of the Bible, one verse at a time. Progress is tracked without any user accounts or passwords.</dd>
                <dt><a href="{{ route('typing.scrimmage') }}">Typing Scrimmage<span class="go">&rarr;</span></a></dt>
                <dd>Every verse in the Bible is a race against the 20 second clock. Type your favorite verse in a friendly Scrim and share it with
                a friend to etch names on the Scrimboard.</dd>
            </dl>
        </div>
    </section>

    <hr class="divider">

    {{-- ============ CLOSING ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Get into the Bible</h2>
        <p>
            If you've never read or studied the Hebrew or Christian Bible, the best time to start is now.
        </p>
        <div class="cta-row">
            <a class="btn" href="{{ url('/') }}">Read the Bible</a>
            <a class="btn" href="{{ route('typing.vigil.home') }}"">Type the Bible</a>
            <a class="btn btn-ghost" href="{{ route('support') }}">Support <x-brand/></a>
        </div>
    </section>

@endsection

@section('scripts')
<script>
    // Lightbox: any zoom trigger (the hero, plus each feature shot) opens
    // its paired <dialog> instead of navigating to the image file. The
    // trigger names its dialog via data-lightbox; the hero keeps its own
    // id wiring. Escape + backdrop are native; a click inside closes it.
    // If <dialog> is unsupported, the anchor just opens the image — no
    // broken click.
    (function () {
        'use strict';
        if (typeof HTMLDialogElement === 'undefined') return;

        // Feature shots: data-lightbox holds the dialog id to open.
        var zooms = document.querySelectorAll('.shot-zoom[data-lightbox]');
        Array.prototype.forEach.call(zooms, function (a) {
            var box = document.getElementById(a.getAttribute('data-lightbox'));
            if (!box || typeof box.showModal !== 'function') return;
            a.addEventListener('click', function (e) { e.preventDefault(); box.showModal(); });
            box.addEventListener('click', function () { box.close(); });
        });

        // Hero: unchanged id-based wiring.
        var heroTrigger = document.getElementById('hero-zoom');
        var heroBox     = document.getElementById('hero-lightbox');
        if (heroTrigger && heroBox && typeof heroBox.showModal === 'function') {
            heroTrigger.addEventListener('click', function (e) { e.preventDefault(); heroBox.showModal(); });
            heroBox.addEventListener('click', function () { heroBox.close(); });
        }
    })();
</script>
@endsection
