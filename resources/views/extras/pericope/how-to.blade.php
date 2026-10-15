@extends('layouts.app')

@section('title', 'How to Pericope — MEGABIBLE.net')

{{-- ============================================================
     HOW TO PERICOPE  ·  /extras/pericope/how-to-pericope
     ------------------------------------------------------------
     The tutorial for the site's most capable (and least obvious)
     feature. Anatomy mirrors About/Support: hero, accent
     .section-head headers, .prose paragraphs, one GIF per idea.

     GIF SLOTS. Every <figure class="howto-shot"> points at
     public/images/howto/<name>.gif (ship the gifs there). To stub a
     section out while you re-record, swap the <img> for the dashed
     placeholder, the About page's convention:

         <div class="ph short"><span class="ph-cap">GIF: …</span></div>

     Each gif also exists as a much smaller .mp4 — swap the <img>
     for <video class="howto-vid" src="…mp4" autoplay loop muted
     playsinline></video> any time weight matters; the CSS below
     styles both the same.

     ROUTE. Registered INSIDE the extras/pericope group, BEFORE the
     {slug} board route (see web.php) — and `how-to-pericope` should
     join RESERVED_SLUGS in pericope-store.js so no board can take
     the name.
     ============================================================ --}}
@section('styles')
<style>
    /* ---- shared with About / Support ---- */
    
    .page-hero{margin:.5rem 0 1.6rem;}
    /* Back link lives BELOW the head, on the scrolling surface (the vigil-book
       pattern), so it slides up under the sticky head with the cards. */
    .pb-back {
        display: inline-flex; align-items: center; gap: .35rem;
        font-family: var(--sans); font-size: .85rem; color: var(--muted);
        text-decoration: none; margin: .3rem 0 1.4rem;
    }
    .pb-back:hover { color: var(--accent); }

    .page-title{font-size:2.4rem;font-weight:400;line-height:1.1;letter-spacing:-.01em;margin:0 0 .8rem;}
    .lead{font-size:1.12rem;line-height:1.6;margin:0 0 1rem;}
    .prose p{margin:0 0 1.1rem;}
    .prose p:last-child{margin-bottom:0;}
    .prose a:not(.btn){color:var(--accent);font-weight:600;text-decoration:none;}
    .prose a:not(.btn):hover{text-decoration:underline;text-underline-offset:.15em;}
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

    /* Anchor targets breathe. */
    .howto-section[id]{scroll-margin-top:1rem;}

    /* ---- the table of contents: arrow links, home-links style ---- */
    .howto-toc{list-style:none;margin:1.3rem 0 0;padding:0;display:flex;flex-direction:column;gap:.55rem;}
    .howto-toc a{display:inline-flex;align-items:baseline;gap:.55rem;
        font-family:var(--sans);font-size:1.02rem;font-weight:600;
        color:var(--accent);text-decoration:none;}
    .howto-toc .hl-arrow{transition:transform .16s ease;}
    .howto-toc a:hover .hl-arrow{transform:translateX(5px);}
    .howto-toc a:hover .hl-text{text-decoration:underline;}
    .howto-toc .hl-count{font-weight:400;font-size:.85rem;color:var(--muted);}

    /* ---- GIF figures ----
       The moving pictures. Framed like the About page's screenshots; the
       caption reads as stage direction. .howto-vid (the mp4 swap) takes
       the identical chrome. */
    .howto-shot{margin:0 0 1.2rem;}
    .howto-shot img, .howto-vid{
        display:block;width:100%;
        border:1px solid var(--rule);border-radius:10px;
        box-shadow:0 12px 32px rgba(42,31,23,.12);
        background:var(--panel);
    }
    .howto-shot figcaption{
        font-family:var(--sans);font-size:.85rem;color:var(--muted);
        margin-top:.6rem;line-height:1.45;
    }

    /* Dashed placeholder — the About page's .ph, for stubbed sections. */
    .ph{display:flex;flex-direction:column;align-items:center;justify-content:center;
        text-align:center;gap:.7rem;background:var(--panel);border:2px dashed var(--soon);
        border-radius:10px;color:var(--soon);padding:2rem;aspect-ratio:16/9;margin:0 0 1.1rem;}
    .ph .ph-cap{font-family:var(--sans);font-size:.85rem;letter-spacing:.02em;line-height:1.45;max-width:36ch;}
    .ph.short{aspect-ratio:21/9;}

    /* ---- "Try it" tip box ----
       The ethos-verse's left accent rule at caption scale: one gesture
       worth doing right now, set apart from the explanation. */
    .howto-tip{margin:1.2rem 0 1.4rem;padding:.55rem 0 .55rem 1.1rem;border-left:4px solid var(--accent);}
    /* TRY-IT SWITCH: the "Try it" blurbs are written in the markup below but
       hidden for launch. DELETE (or comment out) the next line to turn them
       all back on. The scroll section's "Note" box is not affected. */
    .howto-tip--tryit{display:none;}
    .howto-tip p{font-family:var(--sans);font-size:.92rem;line-height:1.6;margin:0;color:var(--ink);}
    .howto-tip .tip-label{font-weight:700;color:var(--accent);text-transform:uppercase;
        font-size:.72rem;letter-spacing:.1em;margin-right:.45rem;}

    /* Toolbar sub-blocks: a small inline icon beside each sub-head so the
       reader can match the button on their own screen. */
    .howto-btnhead{display:flex;align-items:center;gap:.6rem;font-size:1.15rem;font-weight:600;margin:2rem 0 .6rem;}
    .howto-btnhead .bh-icon{
        flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;
        width:34px;height:34px;border-radius:50%;
        color:var(--muted);background:var(--bg);border:1px solid var(--rule);
    }
    .howto-btnhead .bh-icon svg{width:18px;height:18px;display:block;}

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
        <h1 class="page-title">How to Pericope</h1>
        <a class="pb-back" href="{{ route('extras.pericope') }}">&larr; All pericopae</a>
        <p class="lead">
            A <strong>Pericope</strong> (puh&#8209;RIK&#8209;uh&#8209;pee, from the Greek
            περικοπή, &ldquo;to cut around&rdquo;) is a self-contained passage cut from a larger text.
            Fill a Pericope board with verses that you collect while you read the Bible, then edit, share, and present your Pericope. Editing a Pericope
            in Grid mode works best on a desktop, laptop, or tablet.
        </p>
        <p class="lead">
            This page is a walkthrough which explains everything the Pericope system can do.
        </p>

        <ul class="howto-toc">
            <li><a href="#add-verses"><span class="hl-arrow" aria-hidden="true">&rarr;</span><span class="hl-text">Add verses to a Pericope</span></a></li>
            <li><a href="#cards"><span class="hl-arrow" aria-hidden="true">&rarr;</span><span class="hl-text">Edit and move verse cards</span><span class="hl-count"></span></a></li>
            <li><a href="#toolbar"><span class="hl-arrow" aria-hidden="true">&rarr;</span><span class="hl-text">The Pericope Toolbar</span><span class="hl-count"></span></a></li>
            <li><a href="#views"><span class="hl-arrow" aria-hidden="true">&rarr;</span><span class="hl-text">Scroll mode / Grid mode</span></a></li>
            <li><a href="#scroll-mode"><span class="hl-arrow" aria-hidden="true">&rarr;</span><span class="hl-text">Scroll mode features</span></a></li>
        </ul>
    </section>

    <hr class="divider">

    {{-- ============ ADD VERSES ============ --}}
    <section class="page-section prose howto-section" id="add-verses">
        <h2 class="section-head">Add verses to a Pericope</h2>

        <figure class="howto-shot">
            <img src="{{ asset('images/howto/add-verses.gif') }}"
                 alt="Selecting two verses in the reader, opening the toolbar folder, and adding them to a pericope through the scissors panel">
            <figcaption>Tap a verse &rarr; open the folder &rarr; scissors &rarr; select a pericope or create a new one</figcaption>
        </figure>

        <p>
            You can't create a good Pericope if you don't read your Bible! <strong>Tap a verse</strong> to select one, and you can tap more to grow the
            selection. The action bar rises from the bottom, counting your verses held.
        </p>
        <p>
            With a verse or verses selected, open the <strong>folder</strong> in the top-right corner and choose the
            <strong>scissors icon</strong> to open the Pericope panel. Every Pericope on your device is listed here. Click one and your
            selection is added. Or type a name at the bottom and <strong>Create</strong> a brand-new
            pericope.
        </p>
        <div class="howto-tip howto-tip--tryit">
            <p><span class="tip-label">Try it</span>Verses don't have to be neighbours. Select 6:14 and 6:17 and skip
            the two between — each unbroken run becomes its own card.</p>
        </div>
    </section>

    {{-- ============ CARDS ============ --}}
    <section class="page-section prose howto-section" id="cards">
        <h2 class="section-head">Edit and move verse cards</h2>

        <h3 class="subsection-head">Open, close, and switch translations</h3>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/cards-expand.gif') }}"
                 alt="Collapsing a card, expanding it again, and switching its translation from KJV to WEB">
            <figcaption>Tap a small card to open it / Collapse with the corner button / Switch translation</figcaption>
        </figure>
        <p>
            Click a compressed card and it opens to show its full passage, then use the bottom-right <strong>corner button</strong> 
            to collapse it again. The reference at the top of every card
            links back to the reader, and the small translation code beneath it (KJV, WEB) is a menu: pick
            another edition and the card automatically updates itself in that translation.
        </p>

        <h3 class="subsection-head">Resize a card</h3>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/cards-resize.gif') }}"
                 alt="Holding the collapse button until resize mode engages, then dragging the corner to make the card wider">
            <figcaption>Hold the corner button for a sec, then drag it</figcaption>
        </figure>
        <p>
            The corner button has a second job: <strong>press and hold it</strong> for a moment, and instead of
            collapsing, the card enters <strong>resize mode</strong>. While in resize mode, drag the corner and the card grows or shrinks.
            A card only gets as wide as its text can fill. Extra
            text past the card's height will get a scrollbar.
        </p>        

        <h3 class="subsection-head">Move cards</h3>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/cards-drag.gif') }}"
                 alt="Dragging a card by its grip to an empty column, then onto an occupied cell where other cards shuffle down">
            <figcaption>Drag a verse card by the dotted grip</figcaption>
        </figure>
        <p>
            Grab a card by its <strong>dotted grip</strong> in the top left corner and carry it anywhere! Wow! The dashed outline shows
            exactly where it will land, including brand-new columns past the left and right edges of the board. Drop it on an occupied
            cell and the cards beneath will make room.
        </p>
        <div class="howto-tip howto-tip--tryit">
            <p><span class="tip-label">Try it</span>Changed your mind mid-drag? Press <strong>Escape</strong> and the
            card snaps home untouched.</p>
        </div>

        <h3 class="subsection-head">Card Edit</h3>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/cards-scissors.gif') }}"
                 alt="Opening a card's scissors menu and choosing Interlinear, which spawns an original-language child card tethered to its parent">
            <figcaption>Interlinear creates an original-language companion card</figcaption>
        </figure>
        <p>
            Every expanded card carries a <strong>scissors icon</strong> in its top-right corner. Click it to open the 
            <strong>Card Edit</strong> menu: 
            <ul>
                <li><strong>Duplicate</strong> - places a twin beside it</li> 
                <li><strong>Delete</strong> - removes the card (undo brings it back)</li>
                <li><strong>Interlinear</strong> - whenever the passage has original-language
                    text, this button will create a companion card showing the Hebrew, Aramaic, or Greek with transliteration and a literal
                    gloss, tied to its parent by a drawn tether.</li>
            </ul>
        </p>
        <div class="howto-tip howto-tip--tryit">
            <p><span class="tip-label">Try it</span>On any interlinear card, tap a word. Its original form,
            pronunciation, and meaning light up together — tap more words to pin more, tap elsewhere to clear.</p>
        </div>
    </section>

    {{-- ============ THE TOOLBAR ============ --}}
    <section class="page-section prose howto-section" id="toolbar">
        <h2 class="section-head">The Pericope Toolbar</h2>
        <p>
            The folder in the top-right corner holds the Pericope board's tools. When in Grid mode, the folder is expanded with new tools to help you manage your Pericope.
            Here's what each tool does.
        </p>

        {{-- a · HOME --}}
        <div class="howto-btnhead" id="toolbar-home">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.5 20.5 19.5H3.5Z"/></svg></span>
            Home
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/toolbar-home.gif') }}"
                 alt="Panning the board away from its cards, then pressing Home to return to the resting view">
            <figcaption>Home (Delta) brings the board back to its resting view</figcaption>
        </figure>
        <p>
            When the canvas is bigger than the screen, drag the empty background (or use the pill at the bottom) to pan
            across it. Click <strong>Home</strong> to return to the top of the board with the first column under the title.
            It grays out when you're already there.
        </p>

        {{-- b · UNDO / REDO --}}
        <div class="howto-btnhead" id="toolbar-undo">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg></span>
            Undo &amp; Redo
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/toolbar-undo.gif') }}"
                 alt="Moving a card, undoing the move, then redoing it">
            <figcaption>Every board edit can be stepped back and forth</figcaption>
        </figure>
        <p>
            Every change to the board is undoable: moves, resizes, deletions, duplicates, groups and group names. <strong>Undo</strong>
            and <strong>Redo</strong> walk the history for the current Pericope session, and <strong>Ctrl+Z</strong> /
            <strong>Ctrl+Shift+Z</strong> (Cmd on Mac) do the same from the keyboard.
        </p>

        {{-- c · ZOOM --}}
        <div class="howto-btnhead" id="toolbar-zoom">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.2" y2="16.2"/><line x1="8" y1="11" x2="14" y2="11"/></svg></span>
            Zoom
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/toolbar-zoom.gif') }}"
                 alt="Zooming the board out to survey it, panning, and zooming back in to the same spot">
            <figcaption>Zoomed out and cards can still be dragged</figcaption>
        </figure>
        <p>
            <strong>Zoom</strong> pulls the camera back for a survey of the whole board. When zoomed out, you can 
            drag verse cards by their top edge to rearrange at a distance. Press Zoom again to return to the default zoom level.
        </p>

        {{-- d · EDIT MODE --}}
        <div class="howto-btnhead" id="toolbar-edit">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg></span>
            Edit mode
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/toolbar-edit.gif') }}"
                 alt="Entering edit mode, selecting two cards, creating a labelled colour group, and leaving edit mode">
            <figcaption>Select cards, then Group, label, and color</figcaption>
        </figure>
        <p>
            <strong>Edit mode</strong> turns the board into a selection surface. Tap cards to select them, then press the 
            <strong>Group</strong> button at the bottom of the screen to join the selection together under a
            label and a colour. Selecting grouped cards in Edit mode flips that same button to <strong>Ungroup</strong>.
        </p>
        <p>
            Edit mode also reveals each card's <strong>order badge</strong>, which shows where each verse card falls in the
            reading order that Scroll mode and presentations follow. Group
            members share their group's slot as 3.1, 3.2, etc.
        </p>
        <div class="howto-tip howto-tip--tryit">
            <p><span class="tip-label">Try it</span>To pull one more card INTO an existing group, drag it over the
            group's outline and hold it there a moment — the group brightens and adopts it on the drop.</p>
        </div>

        {{-- e1 · SHARE: PRESENTATION --}}
        <div class="howto-btnhead" id="toolbar-present">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 18v3"/></svg></span>
            Share &middot; Presentation
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/share-present.gif') }}"
                 alt="Opening the share panel, starting a presentation, and advancing through slides including an interlinear slide">
            <figcaption>The Pericope board becomes a presentation deck in full screen</figcaption>
        </figure>
        <p>
            The <strong>Share</strong> button opens a panel whose first button is <strong>Presentation</strong>: your
            board as a fullscreen deck of slides. Each group becomes a slide, headings become title cards, and long passages continue
            across slides. During a presentation, click or use the arrow keys to advance; a card with an interlinear companion presents the
            original language right beside the verse. The gear inside the Presentation button styles the deck with font, alignment,
            colour tint, and wall pattern.
        </p>

        {{-- e2 · SHARE: SHORT LINKS --}}
        <div class="howto-btnhead" id="toolbar-share">
            <span class="bh-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="14"/></svg></span>
            Share &middot; Short links
        </div>
        <figure class="howto-shot">
            <img src="{{ asset('images/howto/share-links.gif') }}"
                 alt="Minting a short link for the board and copying the memorable URL">
            <figcaption>Create an easy to remember short link with a few clicks</figcaption>
        </figure>
        <p>
            Below the Presentation button is the link share. <strong>Create short link</strong> mints a memorable
            address like <strong>megabible.net/SweetHoneyedEmber</strong> which will rebuild a snapshot of your board for
            anyone who opens it. Continue editing the board after sharind and the panel offers the
            <strong>Update link</strong> option to update the live link with your new snapshot. <strong>Delete link</strong> retires the address.
            Your board itself never leaves your browser! Links that you create only carry the snapshot you choose to share.
        </p>
    </section>

    {{-- ============ SCROLL VS GRID ============ --}}
    <section class="page-section prose howto-section" id="views">
        <h2 class="section-head">Scroll mode / Grid mode</h2>

        <figure class="howto-shot">
            <img src="{{ asset('images/howto/view-pill.gif') }}"
                 alt="Switching a pericope from Grid to Scroll with the view pill, then reading the feed">
            <figcaption>Click on Scroll or Grid to switch modes</figcaption>
        </figure>

        <p>
            Every pericope has two modes: 
            <ul>
                <li><strong>Grid</strong> is a desktop-first view primarily for studying and arranging: an open canvas where every verse is 
                a card you can move, resize, group, and present.</li>
                <li><strong>Scroll</strong> is a mobile-first view for reading your verses as a social media feed, each run of verses on its own post. </li>
            </ul>
        </p>
        <p>
            Flip between them with the <strong>Scroll/Grid mode switcher</strong> under your Pericope's title.
        </p>
    </section>

    {{-- ============ SCROLL MODE FEATURES ============ --}}
    <section class="page-section prose howto-section" id="scroll-mode">
        <h2 class="section-head">Scroll mode features</h2>

        <figure class="howto-shot">
            <img src="{{ asset('images/howto/scroll-features.gif') }}"
                 alt="Double-tapping a post to like it, then paging a post sideways to its original-language page">
            <figcaption>Double-tap to like</figcaption>
        </figure>

        <p>
            Scroll mode reads like a feed: each card (or group) is a post, wearing its book's colour. The features of Scroll mode should be familiar:
        </p>
        <p>
            <strong>Likes.</strong> Tap the heart, or <strong>double-tap</strong> the post itself, to like it.
            Counts are anonymous and shared: every visitor who likes the same passage feeds the same tally.
        </p>
        <p>
            <strong>Pages.</strong> Swipe sideways to see all posts in a long passage (the dots show where you are). A card with an interlinear companion carries an
            extra page of the original language; tap any word stack to pin its meaning.
        </p>
        <p>
            <strong>The gear.</strong> Scroll has its own look settings in the share panel.
        </p>
        <div class="howto-tip">
            <p><span class="tip-label">Note</span>Scroll is read-only by design. To rearrange, resize, or group
            anything, switch back to Grid mode.</p>
        </div>
    </section>

    <hr class="divider">

    {{-- ============ CLOSING ============ --}}
    <section class="page-section prose">
        <h2 class="section-head">Cut your first pericope</h2>
        <p>
            The fastest way to learn to Pericope is to cut one. Open a chapter, tap a few verses, and craft your Pericope.
        </p>
        <div class="cta-row">
            <a class="btn" href="{{ url('/') }}">Read the Bible</a>
            <a class="btn btn-ghost" href="{{ route('extras.pericope') }}">Your Pericopae</a>
        </div>
    </section>

@endsection
