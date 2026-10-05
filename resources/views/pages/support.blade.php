@extends('layouts.app')

@section('title', 'Support — MEGABIBLE.net')

{{-- ============================================================
     SUPPORT PAGE — r2, PATH style A: "Branch Line"

     Hero and hero screenshot (same lightbox pattern as About), a
     short share message with a Copy-the-Link button, then the PATH:
     a vertical accent rule with a station dot for each group of
     milestones. Each milestone is a bordered cell that branches off the main
     rule, with a node dot on the rule (filled once started, hollow
     at 0%) and a slim meter inside the cell. A 0% milestone is
     "planned": dashed outline (drawn by an SVG mask so the dash
     size is ours to set), no percentage shown. Started milestones
     swell on hover and play a press animation on click or tap.

     The milestone data lives in one PHP array at the top of the
     content section. To update progress, change a number there;
     the markup below is a loop and never needs touching.

     The giving card is DORMANT: its CSS and chip script are kept
     below (clearly marked) and its markup is stashed in a Blade
     comment near the FAQ, ready to restore when payments are
     built. Everything leans on the design tokens in layouts/app.
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

    /* Inline links in body copy: accent, semibold, underline on hover
       (the same treatment as the app links on About). Buttons styled
       as links keep their own look. */
    .prose a:not(.btn){color:var(--accent);font-weight:600;text-decoration:none;}
    .prose a:not(.btn):hover{text-decoration:underline;text-underline-offset:.15em;}
    .prose a:not(.btn):focus-visible{outline:none;border-radius:3px;box-shadow:0 0 0 3px rgba(107,31,31,.25);}

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
       A vertical accent rule with a station dot for each group. The
       gutter between rule and content is one variable, so the mobile
       breakpoint only changes --path-gutter and everything that
       hangs off the rule (dots, branches) follows along. */
    .path{--path-gutter:1.9rem;margin:1.8rem 0 0;padding-left:var(--path-gutter);border-left:4px solid var(--accent);}
    .path-leg{position:relative;margin:0 0 2.4rem;}
    .path-leg:last-child{margin-bottom:0;}
    .path-leg::before{
        content:'';position:absolute;top:.25rem;
        left:calc(-1 * var(--path-gutter) - 2px - .5rem);
        width:1rem;height:1rem;border-radius:50%;
        background:var(--accent);
        box-shadow:0 0 0 4px var(--bg);
    }
    .path-leg-head{font-size:1.15rem;font-weight:600;margin:0 0 .8rem;}
    .path-note{font-family:var(--sans);font-size:.85rem;color:var(--muted);}

    /* Milestone cells: the list itself is unstyled; each li is a cell. */
    .milestones{list-style:none;margin:0;padding:0;}
    .milestone-label{font-family:var(--sans);font-size:.98rem;font-weight:600;line-height:1.35;}
    .milestone-pct{
        font-family:var(--sans);font-size:.92rem;font-weight:600;
        color:var(--accent);font-variant-numeric:tabular-nums;white-space:nowrap;
    }
    /* Planned (0%): a dashed outline instead of a solid one, and the
       markup leaves out the percentage entirely.

       A plain "border-style:dashed" lets the browser pick the dash
       length and gap (and every browser picks differently), so the
       dashes are drawn by an SVG instead, used as a mask over a span
       filled with the theme's --rule color. The real 1px border goes
       transparent so the cell keeps exactly the same size.

       DASH KNOBS live in the SVG inside --dash-shape:
         stroke-width      = 2x the visible thickness (the outer half
                             is clipped at the edge, so 4 shows 2px)
         stroke-dasharray  = 'dash gap' in px, e.g. '10 7'
         rx / ry           = corner radius; keep equal to the cell's 8px
       Color comes from background-color below, so it follows themes. */
    .milestone.is-planned .milestone-card{border-color:transparent;}
    .milestone-dash{
        --dash-shape:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'%3E%3Crect width='100%25' height='100%25' rx='8' ry='8' fill='none' stroke='black' stroke-width='4' stroke-dasharray='10 7'/%3E%3C/svg%3E");
        position:absolute;inset:-1px;border-radius:8px;
        background-color:var(--rule);
        -webkit-mask-image:var(--dash-shape);mask-image:var(--dash-shape);
        -webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;
        pointer-events:none;
    }

    /* ---- Style A: branch line ----
       Each cell hangs off the main rule on a short branch, with a
       small node where the branch meets the rule: filled once work
       has started, hollow while it is still at 0%. Inside the cell,
       a slim meter fills to the percentage.

       Two layers per milestone: the li owns the branch and node (its
       ::before / ::after), and the inner .milestone-card owns the box.
       Only the card ever scales, so the branch and node stay pinned to
       the rule instead of drifting with the transform. */
    .milestones{display:flex;flex-direction:column;gap:.6rem;}
    .milestone{position:relative;}
    .milestone-card{
        position:relative;
        padding:.75rem 1rem .85rem;
        background:var(--bg);
        border:1px solid var(--rule);border-radius:8px;
        transition:border-color .12s, scale .14s ease, box-shadow .14s ease;
    }
    /* The branch: from the center of the rule to the cell's edge. */
    .milestone::before{
        content:'';position:absolute;top:1.2rem;
        left:calc(-1 * var(--path-gutter) - 2px - 1px);
        width:calc(var(--path-gutter) + 2px);height:2px;
        background:var(--rule);
    }
    /* The node: a 10px dot centered on the rule, level with the branch. */
    .milestone::after{
        content:'';position:absolute;box-sizing:border-box;
        top:calc(1.2rem - 4px);
        left:calc(-1 * var(--path-gutter) - 2px - 1px - 5px);
        width:10px;height:10px;border-radius:50%;
        background:var(--accent);border:2px solid var(--accent);
        box-shadow:0 0 0 3px var(--bg);
    }
    .milestone.is-planned::after{background:var(--bg);}

    .milestone-row{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;margin:0 0 .55rem;}

    .meter{height:6px;border-radius:999px;background:var(--panel);overflow:hidden;}
    .meter-fill{display:block;height:100%;width:var(--p);background:var(--accent);border-radius:inherit;}

    /* Started milestones (anything above 0%) swell on hover and do a
       quick press-and-release when clicked or tapped. Planned cells
       stay still.

       The hover uses the standalone "scale" property and the press uses
       "transform", so the two stack instead of fighting: clicking a
       hovered cell dips from its swollen size and returns to it.

       The hover is wrapped in a hover-capable media query so phones
       never get a "stuck" hover after a tap; they get only the press.
       The swell matches the full-width Vigil chapter rows (1.012). */
    .milestone:not(.is-planned) .milestone-card{
        -webkit-tap-highlight-color:transparent;
        touch-action:manipulation;
    }
    @media (hover:hover) and (pointer:fine){
        .milestone:not(.is-planned) .milestone-card:hover{
            border-color:var(--accent);
            scale:1.012;                        /* webfeel: the footnote-popover swell */
            box-shadow:0 4px 14px rgba(0,0,0,.08);
            z-index:2;
        }
    }
    .milestone-card.is-pressed{animation:milestone-press .22s ease;}
    @keyframes milestone-press{
        0%   {transform:scale(1);}
        35%  {transform:scale(.975);}
        100% {transform:scale(1);}
    }
    @media (prefers-reduced-motion:reduce){
        .milestone-card{transition:border-color .12s, box-shadow .14s ease;}
        .milestone:not(.is-planned) .milestone-card:hover{scale:none;}
        .milestone-card.is-pressed{animation:none;}
    }

    /* ---- FAQ accordion (native details element, no JS needed) ---- */
    .faq{border-top:1px solid var(--rule);margin-top:1rem;}
    .faq details{border-bottom:1px solid var(--rule);}
    .faq summary{cursor:pointer;list-style:none;padding:1rem .2rem;font-family:var(--sans);font-weight:600;font-size:1.02rem;
    display:flex;justify-content:space-between;align-items:center;gap:1rem;}
    /* The summary is a flex row (question left, +/- right). Its text
       must sit inside ONE span: loose text plus an inline element like
       the brand wordmark would each become a separate flex item and get
       spread across the row by space-between. */
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
        .path{--path-gutter:1.4rem;}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">Support Free Bible Software</h1>
        <p class="lead">
            Everything provided on <x-brand/> is free and ad-free, forever.
            The best way to support the website is by telling someone about it! Share a link to a verse
            you've recently typed, or share a Pericope you've built from hours of study.
        </p>
    </section>

    {{-- Hero screenshot. --}}
    <figure class="hero-shot">
        <a class="hero-zoom" id="hero-zoom"
           href="{{ asset('images/support_hero_vigil.png') }}"
           aria-label="View the full-size screenshot">
            <img src="{{ asset('images/support_hero_vigil.png') }}"
                 alt="Progress from a Typing Vigil">
        </a>
        <figcaption>Progress from a Typing Vigil.</figcaption>
    </figure>

    {{-- The full-resolution lightbox. Renders nothing until opened. --}}
    <dialog class="lightbox" id="hero-lightbox">
        <img src="{{ asset('images/support_hero_vigil.png') }}"
             alt="Progress from a Typing Vigil">
    </dialog>

    {{-- ============ WHY WORD OF MOUTH ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Why word of mouth matters</h2>
        <p>
            <x-brand/> is easy to share and easy to remember. Everyone has a smartphone in their pocket, and <x-brand/> requires
            no sign ups, no downloads, no passwords, and no user accounts to get started. Just type in <x-brand/> to start reading, studying,
            and typing the Bible! Tell your church group, tell your youth pastor, tell your YouTuber, tell your Bible scholar!
        </p>
        <div class="cta-row">
            <button class="btn" type="button" id="copy-link">Share this Link</button>
        </div>
    </section>

    {{-- The PATH milestones. Percentages are rough, hand-updated
         estimates. Edit a number here; the loop below does the rest. --}}
    @php
        $pathGroups = [
            ['title' => 'Bible Text', 'items' => [
                ['label' => 'Interlinear text for deuterocanon and apocryphal books',     'pct' => 0],
                ['label' => 'Cross-references for deuterocanon and apocryphal books', 'pct' => 35],
                ['label' => 'Headings for deuterocanon and apocryphal books',        'pct' => 15],
                ['label' => 'Footnotes for apocryphal books',                        'pct' => 42],
                ['label' => 'Footnotes for every book chapter in KJV',                           'pct' => 0],
            ]],
            ['title' => 'Book Hub', 'items' => [
                ['label' => 'Excerpts for all 91 books',                'pct' => 59],
                ['label' => 'Expanded timeline system',                 'pct' => 10],
                ['label' => 'Improved navigation on desktop & mobile',  'pct' => 0],
                ['label' => 'Improved chapter and outline system',          'pct' => 20],
                ['label' => 'Bible character pages',                    'pct' => 0],
                ['label' => 'Bible location pages',                     'pct' => 0],
                ['label' => 'Bible historical pages',                   'pct' => 0],
            ]],
            ['title' => 'Pericope', 'items' => [
                ['label' => 'Add verses from search results',          'pct' => 0],
                ['label' => 'Add verses from inside Pericope', 'pct' => 20],
                ['label' => 'Select all cards in grid',         'pct' => 0],
            ]],
            ['title' => 'Animation', 'items' => [
                ['label' => 'Animation web system',          'pct' => 40],
                ['label' => 'Animation sourcing framework',  'pct' => 0],
                ['label' => 'Animation 01',                  'pct' => 5],
            ]],
            ['title' => 'Spanish Language', 'items' => [
                ['label' => 'Spanish translations for all 91 books', 'pct' => 60],
                ['label' => 'Spanish translations for book hub excerpts', 'pct' => 0],
                ['label' => 'Launch of MEGABIBLIA.net',                 'pct' => 0],
            ]],
        ];
    @endphp

    {{-- ============ THE PATH ============ --}}
    {{-- One vertical rule, one station per group. Every milestone cell
         branches off the rule with its own node dot. --}}
    <section class="page-section prose">
        <h2 class="section-head">Follow us on the PATH</h2>
        <p>
            <x-brand/> has large ambitions. We are continually working on fixing bugs and adding new features.
            Support us as we embark on completing the following major milestones:
        </p>
        <p class="path-note">Progress figures are rough estimates.</p>

        <div class="path">
            @foreach ($pathGroups as $group)
                <div class="path-leg">
                    <h3 class="path-leg-head">{{ $group['title'] }}</h3>
                    <ul class="milestones">
                        @foreach ($group['items'] as $item)
                            @php
                                $labelId = 'milestone-' . $loop->parent->index . '-' . $loop->index;
                            @endphp
                            <li class="milestone {{ $item['pct'] === 0 ? 'is-planned' : '' }}">
                                <div class="milestone-card">
                                    @if ($item['pct'] === 0)
                                        <span class="milestone-dash" aria-hidden="true"></span>
                                    @endif
                                    <div class="milestone-row">
                                        <span class="milestone-label" id="{{ $labelId }}">{{ $item['label'] }}</span>
                                        @if ($item['pct'] > 0)
                                            <span class="milestone-pct">{{ $item['pct'] }}%</span>
                                        @endif
                                    </div>
                                    <div class="meter" role="progressbar"
                                         aria-labelledby="{{ $labelId }}"
                                         aria-valuemin="0" aria-valuemax="100"
                                         aria-valuenow="{{ $item['pct'] }}"
                                         aria-valuetext="{{ $item['pct'] === 0 ? 'Planned, not yet started' : $item['pct'] . '%' }}">
                                        <span class="meter-fill" style="--p: {{ $item['pct'] }}%"></span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ============ FAQ ============ --}}
    <section class="page-section">
        <h2 class="section-head">FAQs</h2>
        <div class="faq">
            <details>
                <summary><span>How can I report a technical issue?</span></summary>
                <div class="faq-body prose">
                    <p>If you encounter a technical issue on the website, please <a href="mailto:admin@megabible.net">send us an email</a> and be sure to include
                    details about the problem, including what kind of device you are using and which browser.</a> </p>
                </div>
            </details>
            <details>
                <summary><span>How do I download my data?</span></summary>
                <div class="faq-body prose">
                    <p>Your data can be exported into JSON format with our nifty DATA EXPORTER on the <a href="{{ route('extras.acts') }}">Acts of the User page</a>.</p>
                </div>
            </details>
            <details>
                <summary><span>I noticed something wrong on <x-brand/>!</span></summary>
                <div class="faq-body prose">
                    <p>Thank you for your attention to detail! We are always working to improve the content and structure of the website.
                        If you notice a typo or experience a bug on the website, please
                        <a href="mailto:admin@megabible.net">send us an email</a>.
                    </p>
                </div>
            </details>
            <details>
                <summary><span>Is this a non-profit organization?</span></summary>
                <div class="faq-body prose">
                    <p>We are currently in the beginning stages of setting up a non-profit organization in the state of Texas, USA.</p>
                </div>
            </details>
            <details>
                <summary><span>Is <x-brand/> for sale?</span></summary>
                <div class="faq-body prose">
                    <p>No! Megacorps keep knocking at our door but the answer will always be the same: <x-brand/> is not for sale!</p>
                </div>
            </details>
            <details>
                <summary><span>How do I send you money?</span></summary>
                <div class="faq-body prose">
                    <p>We are honored that you want to put your money on <x-brand/>! While we are not yet a non-profit organization, you can buy us a <a href="https://ko-fi.com/megabible" target="_blank">Kofi</a> if you have found our website helpful.</p>
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
        var label   = btn.textContent;   // whatever the markup says, restored after "Copied!"
        btn.addEventListener('click', function () {
            function done() {
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = label; }, 1600);
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

    // Milestone press: clicking or tapping a started milestone (above
    // 0%) plays a short shrink-and-return and does nothing else. One
    // listener on the PATH handles every cell. Removing the class and
    // reading offsetWidth before re-adding it restarts the animation,
    // so rapid repeat taps each get their own press.
    (function () {
        'use strict';
        var path = document.querySelector('.path');
        if (!path) return;
        path.addEventListener('click', function (e) {
            var card = e.target.closest('.milestone:not(.is-planned) .milestone-card');
            if (!card) return;
            card.classList.remove('is-pressed');
            void card.offsetWidth;
            card.classList.add('is-pressed');
        });
        path.addEventListener('animationend', function (e) {
            if (e.animationName === 'milestone-press') {
                e.target.classList.remove('is-pressed');
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