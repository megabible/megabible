@extends('layouts.app')

@section('title', 'Support — MEGABIBLE.net')

{{-- ============================================================
     SUPPORT-PAGE CSS. The first block here is identical to the
     shared set on about.blade.php (hero, sections, media
     placeholders, pillars, callout, buttons). The second block adds
     the support-only pieces: transparency stats, the giving card,
     and the FAQ accordion.

     All of it leans on the design tokens from layouts/app.blade.php.
     ============================================================ --}}
@section('styles')
<style>
    /* ---- shared with about.blade.php ---- */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}

    .page-hero{margin:.5rem 0 2.4rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}

    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}

    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .subsection-head{font-size:1.15rem;font-weight:600;margin:1.6rem 0 .6rem;}

    .media{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.7rem;background:var(--panel);border:2px dashed var(--soon);border-radius:10px;color:var(--soon);padding:2rem;aspect-ratio:16/9;margin:1.4rem 0;}
    .media svg{width:46px;height:46px;opacity:.85;}
    .media .media-cap{font-family:var(--sans);font-size:.85rem;letter-spacing:.02em;line-height:1.45;max-width:36ch;}
    .media.tall{aspect-ratio:4/5;}
    .media.short{aspect-ratio:21/9;}

    .pillars{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));margin:1.4rem 0 0;}
    .pillar{border:1px solid var(--rule);border-radius:8px;background:var(--bg);padding:1.1rem 1.2rem;}
    .pillar h3{font-family:var(--sans);font-size:.95rem;font-weight:700;letter-spacing:.01em;margin:0 0 .4rem;color:var(--accent);}
    .pillar p{font-size:1rem;line-height:1.5;margin:0;}

    .callout{background:var(--panel);border:1px solid var(--rule);border-left:4px solid var(--accent);border-radius:8px;padding:1.4rem 1.5rem;margin:1.4rem 0;}
    .callout .section-head{margin-top:0;}
    .callout p:last-child{margin-bottom:0;}

    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    /* ---- support-only ---- */

    /* Transparency stats row */
    .stats{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:1.4rem 0;}
    .stat{text-align:center;border:1px solid var(--rule);border-radius:8px;background:var(--bg);padding:1.3rem 1rem;}
    .stat .num{font-family:var(--serif);font-size:2.4rem;line-height:1;color:var(--accent);}
    .stat .label{display:block;font-family:var(--sans);font-size:.82rem;letter-spacing:.02em;color:var(--muted);margin-top:.55rem;line-height:1.45;}

    /* Giving card. The amount/frequency chips are a VISUAL placeholder —
       same idea as the inert search box in the layout. They light up when
       tapped (see the script at the bottom) but aren't wired to Stripe yet. */
    .give-card{background:var(--bg);border:1px solid var(--rule);border-radius:12px;padding:1.6rem;margin:1.4rem 0;}
    .give-label{font-family:var(--sans);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:0 0 .65rem;}
    .chip-row{display:flex;flex-wrap:wrap;gap:.55rem;margin:0 0 1.4rem;}
    .chip{font-family:var(--sans);font-size:1rem;font-weight:600;padding:.6rem 1.15rem;border:1px solid var(--rule);border-radius:999px;background:var(--bg);color:var(--ink);cursor:pointer;transition:background .12s,border-color .12s,color .12s;}
    .chip:hover{border-color:var(--accent);}
    .chip.is-active{background:var(--accent);border-color:var(--accent);color:#fff;}
    .give-note{font-family:var(--sans);font-size:.85rem;color:var(--muted);margin:.5rem 0 0;}

    /* FAQ accordion (native <details>, no JS needed) */
    .faq{border-top:1px solid var(--rule);margin-top:1rem;}
    .faq details{border-bottom:1px solid var(--rule);}
    .faq summary{cursor:pointer;list-style:none;padding:1rem .2rem;font-family:var(--sans);font-weight:600;font-size:1.02rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;}
    .faq summary::-webkit-details-marker{display:none;}
    .faq summary::after{content:'+';color:var(--accent);font-size:1.4rem;line-height:1;}
    .faq details[open] summary::after{content:'\2013';}
    .faq .faq-body{padding:0 .2rem 1.1rem;}
    .faq .faq-body p{margin:0 0 .8rem;}
    .faq .faq-body p:last-child{margin:0;}

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
        .stat .num{font-size:2rem;}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">Build MEGABIBLE.net with us.</h1>
        <p class="lead">
            Everything here is free, ad-free, and supported entirely by people like
            you. Your gift keeps the Bible open and accessible to everyone — and helps
            fund something that's never been done before.
        </p>
        <div class="cta-row">
            <a class="btn" href="#give">Give now</a>
        </div>
    </section>

    {{-- Hero image. Replace with a real <img> when ready. --}}
    <div class="media">
        @include('pages._media-icon')
        <span class="media-cap">Warm, welcoming hero image — readers, the team (you!), or the project at work.</span>
    </div>

    {{-- ============ WHY GIVE / THE MISSION ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Why your gift matters</h2>
        <p>
            MEGABIBLE.net exists to make the Bible — and the rich world of texts and
            tradition around it — free and accessible to anyone, anywhere. No ads, no
            paywalls, no upsells. That only works because readers choose to chip in.
        </p>
        <p>
            Every dollar goes straight toward the mission: keeping the site fast and
            online, adding more translations and texts, writing the introductions and
            character pages, and steadily building toward the bigger vision below.
        </p>
    </section>

    {{-- ============ THE VISUAL ANIMATED BIBLE (the big vision) ============ --}}
    {{-- This flagship mission appears on BOTH the About and Support pages. --}}
    <section class="page-section">
        <div class="callout prose">
            <h2 class="section-head">You're helping build the world's first complete visual animated Bible</h2>
            <p>
                Beyond the reading site, the dream MEGABIBLE.net is working toward is the
                world's first <strong>complete visual, animated Bible</strong> — every book,
                brought to life as something you can watch, not only read.
            </p>
            <p>
                It's ambitious, and it's the kind of thing that only exists if a community
                decides to make it. Giving today doesn't just keep the lights on — it moves
                us closer to a Bible that's free to read <em>and</em> free to see.
            </p>
        </div>
    </section>

    {{-- ============ TRANSPARENCY STATS ============ --}}
    {{-- Placeholder figures — replace with real numbers as the project grows.
         The spec calls for transparent operating-cost reporting here. --}}
    <section class="page-section">
        <h2 class="section-head">Where your support goes</h2>
        <div class="stats">
            <div class="stat">
                <span class="num">100%</span>
                <span class="label">of every gift goes straight to the mission</span>
            </div>
            <div class="stat">
                <span class="num">$0</span>
                <span class="label">spent on ads — and never will be</span>
            </div>
            <div class="stat">
                <span class="num">~$XX</span>
                <span class="label">monthly cost to keep the site online</span>
            </div>
        </div>
        <p class="give-note">
            <!-- Swap in real operating costs / an annual report link when you have them. -->
            We'll keep these numbers honest and up to date. Curious about the details?
            A full cost breakdown will live here as the project grows.
        </p>
    </section>

    {{-- ============ THE GIVE CARD (placeholder, not yet wired to Stripe) ============ --}}
    <section class="page-section" id="give">
        <h2 class="section-head">Make a gift</h2>

        <div class="give-card">
            <p class="give-label">How often?</p>
            <div class="chip-row" data-give-group="frequency">
                <button type="button" class="chip is-active">One-time</button>
                <button type="button" class="chip">Monthly</button>
                <button type="button" class="chip">Yearly</button>
            </div>

            <p class="give-label">How much?</p>
            <div class="chip-row" data-give-group="amount">
                <button type="button" class="chip">$10</button>
                <button type="button" class="chip is-active">$25</button>
                <button type="button" class="chip">$50</button>
                <button type="button" class="chip">$100</button>
                <button type="button" class="chip">Other</button>
            </div>

            <div class="cta-row" style="margin-top:0;">
                <button type="button" class="btn" onclick="megabibleGivePlaceholder()">Continue</button>
            </div>

            <p class="give-note">
                Secure donations are coming soon. We're setting up payments through Stripe —
                check back shortly, and thank you for your patience.
            </p>
        </div>
    </section>

    {{-- ============ OTHER WAYS TO HELP (no merch — sharing, following, spreading the word) ============ --}}
    <section class="page-section">
        <h2 class="section-head">Other ways to help</h2>
        <div class="pillars">
            <div class="pillar">
                <h3>Spread the word</h3>
                <p>Tell a friend, a small group, or a class. The single most valuable thing you can do is share what's useful.</p>
            </div>
            <div class="pillar">
                <h3>Follow along</h3>
                <p>Find us on YouTube, Instagram, TikTok, and Discord (links in the footer) and help the community grow.</p>
            </div>
            <div class="pillar">
                <h3>Join the newsletter</h3>
                <p>Get occasional updates on new texts, features, and progress on the animated Bible. No spam, ever.</p>
            </div>
            <div class="pillar">
                <h3>Send feedback</h3>
                <p>Spot a typo or a bad source citation? Tell us. Careful readers make the whole project better.</p>
            </div>
        </div>
    </section>

    <hr class="divider">

    {{-- ============ FAQ ============ --}}
    <section class="page-section">
        <h2 class="section-head">Common questions</h2>
        <div class="faq">
            <details open>
                <summary>Is my gift tax-deductible?</summary>
                <div class="faq-body prose">
                    <p>
                        <!-- Per the spec, MEGABIBLE.net is not yet a 501(c)(3). Update this once that changes. -->
                        Not yet. MEGABIBLE.net isn't currently a registered nonprofit, so gifts
                        aren't tax-deductible. If that changes, we'll say so right here.
                    </p>
                </div>
            </details>
            <details>
                <summary>How will donations be processed?</summary>
                <div class="faq-body prose">
                    <p>Securely through Stripe, with one-time and recurring options. Card details never touch our servers. (This is being set up now.)</p>
                </div>
            </details>
            <details>
                <summary>Will there ever be ads or paid content?</summary>
                <div class="faq-body prose">
                    <p>No. The site is free and ad-free by design, and that won't change. No ads, no affiliate links in the reading experience, no paywalled texts.</p>
                </div>
            </details>
            <details>
                <summary>Can I help in ways other than money?</summary>
                <div class="faq-body prose">
                    <p>Absolutely — see “Other ways to help” above. Sharing the site and sending careful feedback genuinely move the project forward.</p>
                </div>
            </details>
        </div>
    </section>

@endsection

{{-- Page script: lights up the selected give chips. This is purely visual
     for now — it does NOT submit anything. Replace megabibleGivePlaceholder()
     with your real Stripe Checkout call when payments are ready. --}}
@section('scripts')
<script>
    // Within each chip group, make the clicked chip the only active one.
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
