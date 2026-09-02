@extends('layouts.app')

@section('title', 'Privacy & Terms — MEGABIBLE.net')

{{-- ============================================================
     PRIVACY & TERMS CSS.

     The first block is the SAME shared set used on about.blade.php
     and support.blade.php (eyebrow, hero, sections, callout, pillars,
     buttons, divider). The second block adds three small legal-only
     pieces: the "last updated" meta line, the jump-link table of
     contents, and a readable list style for the policy bullets.

     Everything leans on the design tokens from layouts/app.blade.php —
     --bg, --ink, --muted, --accent, --rule, --panel, --soon,
     --serif, --sans.
     ============================================================ --}}
@section('styles')
<style>
    /* ---- shared with about.blade.php / support.blade.php ---- */
    .eyebrow{font-family:var(--sans);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0 0 .6rem;}

    .page-hero{margin:.5rem 0 2.4rem;}
    .page-title{font-size:2.6rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.22rem;line-height:1.6;margin:0 0 1rem;}

    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}

    .page-section{margin:2.8rem 0;}
    .section-head{color:var(--accent);font-size:1.5rem;font-weight:600;letter-spacing:.01em;margin:0 0 1rem;}
    .subsection-head{font-size:1.15rem;font-weight:600;margin:1.6rem 0 .6rem;}

    .callout{background:var(--panel);border:1px solid var(--rule);border-left:4px solid var(--accent);border-radius:8px;padding:1.4rem 1.5rem;margin:1.4rem 0;}
    .callout .section-head{margin-top:0;}
    .callout p:last-child{margin-bottom:0;}

    .pillars{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));margin:1.4rem 0 0;}
    .pillar{border:1px solid var(--rule);border-radius:8px;background:var(--bg);padding:1.1rem 1.2rem;}
    .pillar h3{font-family:var(--sans);font-size:.95rem;font-weight:700;letter-spacing:.01em;margin:0 0 .4rem;color:var(--accent);}
    .pillar p{font-size:1rem;line-height:1.5;margin:0;}

    .cta-row{display:flex;flex-wrap:wrap;gap:.7rem;margin:1.6rem 0 0;}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--sans);font-size:1rem;font-weight:600;text-decoration:none;padding:.7rem 1.3rem;border-radius:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer;transition:filter .12s,background .12s,color .12s;}
    .btn:hover{filter:brightness(1.1);}
    .btn-ghost{background:transparent;color:var(--accent);}
    .btn-ghost:hover{filter:none;background:var(--panel);}

    .divider{border:none;border-top:1px solid var(--rule);margin:2.8rem 0;}

    /* ---- legal-only additions ---- */

    /* "Last updated" + plain-language note that sits under the hero. */
    .legal-meta{font-family:var(--sans);font-size:.85rem;color:var(--muted);line-height:1.5;margin:1.2rem 0 0;}
    .legal-meta strong{color:var(--ink);font-weight:600;}

    /* Jump links to the two halves of the page. */
    .toc{display:flex;flex-wrap:wrap;gap:.55rem;margin:1.4rem 0 0;}
    .toc a{font-family:var(--sans);font-size:.9rem;font-weight:600;text-decoration:none;color:var(--accent);border:1px solid var(--rule);border-radius:999px;padding:.45rem 1rem;transition:background .12s,border-color .12s;}
    .toc a:hover{background:var(--panel);border-color:var(--accent);}

    /* Readable bullet lists for the policy. The base layout sets no list
       styling, so we define a calm, parchment-friendly one here. */
    .legal-list{margin:.2rem 0 1.1rem;padding-left:1.3rem;}
    .legal-list li{margin:0 0 .55rem;line-height:1.6;}
    .legal-list li:last-child{margin-bottom:0;}
    .legal-list strong{font-weight:600;}

    /* Anchor offset so jump links don't tuck a heading under the top edge. */
    .anchor{scroll-margin-top:1.5rem;}

    @media (max-width:560px){
        .page-title{font-size:2.1rem;}
        .lead{font-size:1.12rem;}
        .section-head{font-size:1.3rem;}
    }
</style>
@endsection

@section('content')

    {{-- ============ HERO ============ --}}
    <section class="page-hero">
        <h1 class="page-title">Privacy &amp; Terms</h1>
        <p class="lead">
            Plain-language version: MEGABIBLE.net is free, ad-free, and built to
            respect you. We don't track you across the web, we don't run ads, and
            we never sell your data — because we don't collect much in the first place.
        </p>

        {{-- Jump links to the two halves of the page. --}}
        <nav class="toc" aria-label="On this page">
            <a href="#privacy">Privacy Policy</a>
            <a href="#terms">Terms of Use</a>
        </nav>

        {{-- TODO: set a real effective date when you publish this page. --}}
        <p class="legal-meta">
            <strong>Last updated:</strong> [EFFECTIVE DATE] &nbsp;·&nbsp;
            This page is written in plain English. It isn't legal advice, and if
            your needs are formal you may want a lawyer to review it.
        </p>
    </section>

    {{-- ============ PRIVACY — THE SHORT VERSION ============ --}}
    <section class="page-section">
        <div class="callout prose">
            <h2 class="section-head">The short version</h2>
            <p>
                You can read the entire site without an account, without logging in,
                and without being personally tracked. We use privacy-friendly,
                cookieless analytics that count visits in aggregate but never identify
                you. We don't run ads, we don't use advertising trackers, and we never
                sell or rent your information. The few cookies the site does use are
                the ordinary ones needed to make it work and to remember your
                translation choice.
            </p>
        </div>
    </section>

    {{-- ============================================================
         PRIVACY POLICY
         ============================================================ --}}
    <section class="page-section prose" id="privacy">
        <h2 class="section-head anchor">Privacy Policy</h2>
        <p>
            This policy explains what information MEGABIBLE.net collects, why, and
            what we do with it. The honest summary is: very little, and nothing we'd
            be uncomfortable telling you about.
        </p>

        <h3 class="subsection-head">What we collect</h3>
        <ul class="legal-list">
            <li>
                <strong>Reading the site:</strong> nothing personal. There are no
                accounts in this version of the site, so there's no profile, no
                reading history tied to you, and nothing to log in to.
            </li>
            <li>
                <strong>Aggregate analytics:</strong> we plan to use a cookieless,
                privacy-first analytics tool (Plausible) to understand things like
                which books and chapters are read most and where visitors come from.
                It measures these in aggregate, sets no cookies, and does not build
                a profile of you or follow you to other websites.
            </li>
            <li>
                <strong>Newsletter (only if you ask):</strong> if you choose to join
                the newsletter, we store the email address you give us so we can send
                occasional updates. That's the only reason we keep it, and you can ask
                us to remove it at any time.
            </li>
            <li>
                <strong>Donations (only if you give):</strong> donations are handled
                by Stripe. Your card details go straight to Stripe and never touch our
                servers — we only see what Stripe shows us about a completed gift (such
                as an amount and a receipt). Stripe handles that data under its own
                privacy policy.
            </li>
            <li>
                <strong>Standard server logs:</strong> like nearly every website, our
                server and our CDN (Cloudflare) keep short-lived technical logs — things
                like IP address and browser type — to keep the site secure, fast, and
                online. These aren't used to identify or profile you and are kept only
                as long as needed for security and troubleshooting.
            </li>
        </ul>

        <h3 class="subsection-head">Cookies we use</h3>
        <p>
            Because the site doesn't use advertising or third-party tracking cookies,
            you won't be greeted by a consent banner. The only cookies in play are the
            essential and functional kind:
        </p>
        <ul class="legal-list">
            <li>
                <strong>A preference cookie</strong> that remembers which translation
                you're reading, so the site stays on your choice as you move around.
            </li>
            <li>
                <strong>A session/security cookie</strong> set by the site's framework
                (Laravel) that's needed for the site to function safely.
            </li>
        </ul>
        <p>
            Our analytics are cookieless, so they add nothing to this list. If we ever
            add something that does require consent — for example, an embedded video
            player from a third party — we'll ask for it properly before it loads.
        </p>

        <h3 class="subsection-head">What we never do</h3>
        <div class="pillars">
            <div class="pillar">
                <h3>No ads, ever</h3>
                <p>No advertising, no ad networks, no advertising trackers anywhere on the site.</p>
            </div>
            <div class="pillar">
                <h3>No selling data</h3>
                <p>We never sell, rent, or trade your information. There's no data broker on the other end.</p>
            </div>
            <div class="pillar">
                <h3>No profiling</h3>
                <p>We don't build a profile of you or follow you across other websites.</p>
            </div>
            <div class="pillar">
                <h3>No hidden third parties</h3>
                <p>The only outside services we rely on are listed below, each for a clear reason.</p>
            </div>
        </div>

        <h3 class="subsection-head">Third parties we rely on</h3>
        <p>
            A small site still leans on a few trusted services to run. Each has its
            own privacy policy:
        </p>
        <ul class="legal-list">
            <li><strong>Cloudflare</strong> — content delivery and security in front of the site.</li>
            <li><strong>Plausible</strong> — cookieless, aggregate analytics (planned).</li>
            <li><strong>Stripe</strong> — payment processing for donations (when enabled).</li>
            <li><strong>Backblaze B2</strong> — encrypted, off-site backups of the site's data.</li>
            <li><strong>An email provider</strong> — to send the newsletter, if and when you subscribe.</li>
        </ul>

        <h3 class="subsection-head">Your choices and rights</h3>
        <p>
            Depending on where you live, you may have rights to access or delete the
            information we hold about you. In practice the only personal thing we'd
            ever hold is a newsletter email — so if you've subscribed and want it
            removed, just ask and we'll take care of it. For anything else, you're
            welcome to reach out with questions about your data at any time.
        </p>

        <h3 class="subsection-head">Children</h3>
        <p>
            MEGABIBLE.net is intended for a general audience and is not directed at
            children under 13. We don't knowingly collect personal information from
            children. If you believe a child has sent us personal information (for
            instance, a newsletter signup), contact us and we'll remove it.
        </p>

        <h3 class="subsection-head">Changes to this policy</h3>
        <p>
            If we change how the site handles data, we'll update this page and revise
            the "last updated" date above. Significant changes will be called out
            clearly rather than slipped in quietly.
        </p>

        {{-- TODO: replace [your-contact-email] with a real address (e.g. privacy@megabible.net). --}}
        <h3 class="subsection-head">Contact</h3>
        <p>
            Questions about privacy? Email
            <a href="mailto:[your-contact-email]">[your-contact-email]</a>.
        </p>
    </section>

    <hr class="divider">

    {{-- ============================================================
         TERMS OF USE
         ============================================================ --}}
    <section class="page-section prose" id="terms">
        <h2 class="section-head anchor">Terms of Use</h2>
        <p>
            By using MEGABIBLE.net, you agree to the terms below. They're meant to be
            fair and readable, not a wall of fine print.
        </p>

        <h3 class="subsection-head">The texts and our content</h3>
        <p>
            The scripture texts on the site are public-domain translations or are used
            under the licenses stated on each text. Every text carries a visible source
            citation — translator, year, source, and license — so you always know what
            you're reading and under what terms.
        </p>
        <p>
            MEGABIBLE.net's own original writing — book introductions, character pages,
            topic essays, and scholarly notes — is published under the
            <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">Creative
            Commons Attribution-ShareAlike 4.0</a> license. You're free to share and
            build on it, including for other projects, as long as you give credit and
            share alike. Third-party texts keep their own licenses, which may differ.
        </p>

        <h3 class="subsection-head">Acceptable use</h3>
        <p>
            Please use the site for reading, study, teaching, and research. You agree
            not to attack, disrupt, or overload it, not to scrape it in ways that
            degrade it for others, and not to misrepresent its content as something it
            isn't. Reasonable personal and educational use is always welcome.
        </p>

        <h3 class="subsection-head">Accuracy and "as is"</h3>
        <p>
            We work hard to keep the texts and scholarship accurate and well-sourced,
            but the site is provided "as is," without warranties of any kind. Content
            here is for reading and study — it isn't theological, spiritual, legal, or
            professional advice, and where scholars disagree we aim to present the
            disagreement rather than settle it for you. Spotted a mistake? Please tell
            us; careful readers make the whole project better.
        </p>

        <h3 class="subsection-head">External links</h3>
        <p>
            The site links to outside sources and social platforms. We're not
            responsible for the content, accuracy, or privacy practices of websites we
            don't control.
        </p>

        <h3 class="subsection-head">Donations</h3>
        <p>
            Donations are voluntary gifts that help keep the site free and ad-free.
            MEGABIBLE.net is not currently a registered 501(c)(3) nonprofit, so gifts
            are not tax-deductible. If that changes, we'll say so. Payments are
            processed by Stripe under its own terms.
        </p>

        <h3 class="subsection-head">Limitation of liability</h3>
        <p>
            To the extent allowed by law, MEGABIBLE.net and its operator aren't liable
            for any damages arising from your use of — or inability to use — the site
            or its content.
        </p>

        {{-- TODO: confirm the jurisdiction whose law should govern. Defaulting to
             the operator's home state; change if that's not right for you. --}}
        <h3 class="subsection-head">Governing law</h3>
        <p>
            These terms are governed by the laws of the State of Texas, United States,
            without regard to its conflict-of-laws rules.
        </p>

        {{-- TODO: replace [your-contact-email] with a real address. --}}
        <h3 class="subsection-head">Contact</h3>
        <p>
            Questions about these terms? Email
            <a href="mailto:[your-contact-email]">[your-contact-email]</a>.
        </p>

        <div class="cta-row">
            <a class="btn btn-ghost" href="{{ route('about') }}">About the project</a>
        </div>
    </section>

@endsection
