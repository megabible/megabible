@extends('layouts.app')

@section('title', 'Support — MEGABIBLE.net')

{{-- ============================================================
     SUPPORT PAGE — OPTION B "The PATH as Centerpiece" r1

     The roadmap is the star. Hero and hero screenshot (same
     lightbox pattern as About), a short share message with a
     Copy-the-Link button, then the PATH rendered as a literal
     path: a vertical accent rule with a station dot at each leg
     (The Site, The Library, The Vision), borrowing the left-rule
     language of the ethos verses on About. FAQ closes the page.

     The giving card is DORMANT: its CSS and chip script are kept
     below (clearly marked) and its markup is stashed in a Blade
     comment near the FAQ, ready to restore when payments are
     built. Everything leans on the design tokens in layouts/app.

     To adopt: rename this file to support.blade.php and drop the
     real hero asset at public/images/support_hero.png.
     ============================================================ --}}
@section('styles')
<style>
    /* ---- shared with the About page ---- */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}

    .page-hero{margin:.5rem 0 2rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}

    /* Hero screenshot + lightbox — identical pattern to About. */
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
    .lightbox{border:none;padding:0;background:transparent;max-width:96vw;max-height:96vh;}
    .lightbox::backdrop{background:rgba(42,31,23,.78);}
    .lightbox img{
        display:block;max-width:96vw;max-height:92vh;
        border:1px solid var(--rule);border-radius:10px;
        background:var(--panel);cursor:zoom-out;
    }

    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}

    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .subsection-head{font-size:1.15rem;font-weight:600;margin:1.6rem 0 .6rem;}

    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;
    text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);
    color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    /* ---- support-only: the PATH ----
       A vertical accent rule with a station dot at each leg. The
       dot is centered on the rule; the page-background halo around
       it makes each station read as a stop on the line. */
    .path{margin:1.8rem 0 0;padding-left:1.9rem;border-left:4px solid var(--accent);}
    .path-leg{position:relative;margin:0 0 2.2rem;}
    .path-leg:last-child{margin-bottom:0;}
    .path-leg::before{
        content:'';position:absolute;top:.25rem;
        left:calc(-1.9rem - 2px - .5rem);
        width:1rem;height:1rem;border-radius:50%;
        background:var(--accent);
        box-shadow:0 0 0 4px var(--bg);
    }
    .path-leg-head{font-size:1.15rem;font-weight:600;margin:0 0 .5rem;}
    .path-items{list-style:none;margin:0;padding:0;}
    .path-items li{padding:.32rem 0;font-size:1.02rem;line-height:1.55;}

    /* ---- FAQ accordion (native details element, no JS needed) ---- */
    .faq{border-top:1px solid var(--rule);margin-top:1rem;}
    .faq details{border-bottom:1px solid var(--rule);}
    .faq summary{cursor:pointer;list-style:none;padding:1rem .2rem;font-family:var(--sans);font-weight:600;font-size:1.02rem;
    display:flex;justify-content:space-between;align-items:center;gap:1rem;}
    .faq summary::-webkit-details-marker{display:none;}
    .faq summary::after{content:'+';color:var(--accent);font-size:1.4rem;line-height:1;}
    .faq details[open] summary::after{content:'\2013';}
    .faq .faq-body{padding:0 .2rem 1.1rem;}
    .faq .faq-body p{margin:0 0 .8rem;}
    .faq .faq-body p:last-child{margin:0;}

    /* ---- DORMANT: giving card ----
       Not rendered anywhere yet. Kept so the design work is ready
       the day payments are built; the matching markup lives in a
       Blade comment near the FAQ below. */
    .give-card{background:var(--bg);border:1px solid var(--rule);border-radius:12px;padding:1.6rem;margin:1.4rem 0;}
    .give-label{font-family:var(--sans);font-size:.78rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.1em;color:var(--muted);margin:0 0 .65rem;}
    .chip-row{display:flex;flex-wrap:wrap;gap:.55rem;margin:0 0 1.4rem;}
    .chip{font-family:var(--sans);font-size:1rem;font-weight:600;padding:.6rem 1.15rem;border:1px solid var(--rule);
    border-radius:999px;background:var(--bg);color:var(--ink);cursor:pointer;transition:background .12s,border-color .12s,color .12s;}
    .chip:hover{border-color:var(--accent);}
    .chip.is-active{background:var(--accent);border-color:var(--accent);color:#fff;}
    .give-note{font-family:var(--sans);font-size:.85rem;color:var(--muted);margin:.5rem 0 0;}

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
        .hero-shot img{aspect-ratio:16/10;}
        .path{padding-left:1.4rem;}
        .path-leg::before{left:calc(-1.4rem - 2px - .5rem);}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">Support Free Information</h1>
        <p class="lead">
            Everything provided on <x-brand/> is free, sponsor-free, and ad-free, forever.
            The best way to support the website is by telling someone about it! Share a link to a verse
            you've recently typed, or share a Pericope you've built from hours of study.
        </p>
    </section>

    {{-- Hero screenshot. Drop the real asset at
         public/images/support_hero.png and update the alt text
         plus the caption to match what it shows. --}}
    <figure class="hero-shot">
        <a class="hero-zoom" id="hero-zoom"
           href="{{ asset('images/support_hero.png') }}"
           aria-label="View the full-size screenshot">
            <img src="{{ asset('images/support_hero.png') }}"
                 alt="Readers sharing MEGABIBLE.net verses and Pericope boards">
        </a>
        <figcaption>Free to read, free to share.</figcaption>
    </figure>

    {{-- The full-resolution lightbox. Renders nothing until opened. --}}
    <dialog class="lightbox" id="hero-lightbox">
        <img src="{{ asset('images/support_hero.png') }}"
             alt="Readers sharing MEGABIBLE.net verses and Pericope boards">
    </dialog>

    {{-- ============ WHY WORD OF MOUTH ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Why word of mouth matters</h2>
        <p>
            <x-brand/> is easy to share and easy to remember. Tell your church group,
            tell your youth pastor, tell your YouTuber, tell your Bible scholar!
        </p>
        <div class="cta-row">
            <button class="btn" type="button" id="copy-link">Copy the Link</button>
        </div>
    </section>

    {{-- ============ THE PATH ============ --}}
    {{-- This flagship mission appears on BOTH the About and Support
         pages. Here it is the centerpiece: one vertical line, three
         stations, walked top to bottom. --}}
    <section class="page-section prose">
        <h2 class="section-head">Follow us on the PATH</h2>
        <p>
            <x-brand/> has large ambitions. Join us as we embark on completing the following milestones:
        </p>

        <div class="path">
            <div class="path-leg">
                <h3 class="path-leg-head">The Site</h3>
                <ul class="path-items">
                    <li>Complete hub pages for all 91 books</li>
                    <li>Original introductions for every book hub</li>
                    <li>Improvements to the Pericope study system</li>
                    <li>Character pages for every character in the Bible</li>
                    <li>A complete Spanish version of the site, with Spanish translations</li>
                </ul>
            </div>

            <div class="path-leg">
                <h3 class="path-leg-head">The Library</h3>
                <ul class="path-items">
                    <li>A new tier of texts: the Apostolic Fathers (Ignatius, Polycarp, and more)</li>
                    <li>The Ante-Nicene fathers (Justin Martyr, Irenaeus, Origen, Tertullian)</li>
                    <li>The Post-Nicene fathers (Augustine, Jerome, Chrysostom, Athanasius)</li>
                    <li>The medieval fathers (Aquinas)</li>
                    <li>Reformation works (Luther, Calvin)</li>
                    <li>More original languages: the Greek Old Testament, Greek for the Apocrypha, and Syriac and Aramaic where available</li>
                </ul>
            </div>

            <div class="path-leg">
                <h3 class="path-leg-head">The Vision</h3>
                <ul class="path-items">
                    <li>An animation for every chapter of every book</li>
                </ul>
            </div>
        </div>
    </section>

    <hr class="divider">

    {{-- ============ FAQ ============ --}}
    <section class="page-section">
        <h2 class="section-head">FAQs</h2>
        <div class="faq">
            <details>
                <summary>How do I download my data?</summary>
                <div class="faq-body prose">
                    <p>Your data can be exported into JSON format with our nifty DATA EXPORTER on the Acts of the User page.</p>
                </div>
            </details>
            <details>
                <summary>I noticed something wrong on MEGABIBLE.net!</summary>
                <div class="faq-body prose">
                    <p>Thank you for your attention to detail! We are always working to improve the content and structure of the website.
                        If you notice a typo or experience a bug on the website, please
                        <a href="mailto:email@megabible.net">email us</a>.
                    </p>
                </div>
            </details>
            <details>
                <summary>Is MEGABIBLE.net for sale?</summary>
                <div class="faq-body prose">
                    <p>No! Megacorps keep knocking at our door but the answer will always be the same: NO!</p>
                </div>
            </details>
            <details>
                <summary>How do I send you money?</summary>
                <div class="faq-body prose">
                    <p>We are honored that you want to put your money on MEGABIBLE.net! We currently are not accepting donations.</p>
                </div>
            </details>
        </div>
    </section>

    {{-- DORMANT giving card markup. Restore this block (and wire
         megabibleGivePlaceholder to real Stripe Checkout) when
         payments are built. Rendered nowhere today.

    <section class="page-section">
        <h2 class="section-head">Give</h2>
        <div class="give-card">
            <p class="give-label">Amount</p>
            <div class="chip-row" data-give-group="amount">
                <button class="chip is-active" type="button">$5</button>
                <button class="chip" type="button">$10</button>
                <button class="chip" type="button">$25</button>
                <button class="chip" type="button">$50</button>
            </div>
            <p class="give-label">Frequency</p>
            <div class="chip-row" data-give-group="frequency">
                <button class="chip is-active" type="button">One-time</button>
                <button class="chip" type="button">Monthly</button>
            </div>
            <button class="btn" type="button" onclick="megabibleGivePlaceholder()">Give</button>
            <p class="give-note">Payments are not wired up yet.</p>
        </div>
    </section>
    --}}

@endsection

@section('scripts')
<script>
    // Lightbox: the hero zoom opens its dialog instead of navigating
    // to the image file. Escape + backdrop are native; a click inside
    // closes it. With no dialog support the anchor just opens the
    // image — no broken click.
    (function () {
        'use strict';
        if (typeof HTMLDialogElement === 'undefined') return;
        var heroTrigger = document.getElementById('hero-zoom');
        var heroBox     = document.getElementById('hero-lightbox');
        if (heroTrigger && heroBox && typeof heroBox.showModal === 'function') {
            heroTrigger.addEventListener('click', function (e) { e.preventDefault(); heroBox.showModal(); });
            heroBox.addEventListener('click', function () { heroBox.close(); });
        }
    })();

    // Copy the Link: puts the site root on the clipboard and confirms
    // on the button itself. Falls back to a copyable prompt when the
    // clipboard API is unavailable (older browsers, plain http).
    (function () {
        'use strict';
        var btn = document.getElementById('copy-link');
        if (!btn) return;
        var siteUrl = @json(url('/'));
        btn.addEventListener('click', function () {
            function done() {
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = 'Copy the Link'; }, 1600);
            }
            function fallback() {
                window.prompt('Copy this link:', siteUrl);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(siteUrl).then(done, fallback);
            } else {
                fallback();
            }
        });
    })();

    // DORMANT: give-chip toggling for the stashed giving card above.
    // Runs as a harmless no-op today (no data-give-group in the DOM).
    document.querySelectorAll('[data-give-group]').forEach(function (group) {
        group.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip');
            if (!chip) return;
            group.querySelectorAll('.chip').forEach(function (c) {
                c.classList.remove('is-active');
            });
            chip.classList.add('is-active');
        });
    });

    function megabibleGivePlaceholder() {
        window.alert('Donations are coming soon — payments aren\'t wired up yet. Thank you!');
    }
</script>
@endsection
