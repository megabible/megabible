@extends('layouts.app')

@section('title', 'Terminal — MEGABIBLE.net')

{{-- ============================================================
     TERMINAL EASTER-EGG PAGE — the MEGABIBLE.net pseudo-CLI.

     Reached by direct URL or by typing "terminal-system" in search
     (config/shortcuts.php → route terminal.index).

     cli r4 (Phase 3): the retrieval module is ONLINE —
       print:acts 9:2             the reader's current translation
       print:kjv acts 9:2         explicit translation override
       print:hermas 20:3-4        ranges (capped, PRINT.max below)
     Verse text comes from GET route terminal.verse (the small JSON
     endpoint on TerminalController). Until that route exists the
     command reports the retrieval endpoint offline — everything
     else on the page works without it. Printing a book you
     "deleted" with delete:book returns corrupted data, as it must.

     SERVER DATA: TerminalController@index passes $terminalBooks
     (the full canon as name/slug/chapter-count/aliases) so the
     terminal can resolve "gen", "1 clement", "shepherd of hermas".
     The page degrades gracefully if served without it.

     WHERE TO EDIT THE TERMINAL'S VOCABULARY: three banner-marked
     EDIT ZONES in the scripts section hold every word the system
     can say. The BSOD text lives in the content section, marked.
     ============================================================ --}}
@section('styles')
<style>
    /* ============================================================
       THE WINDOW — square corners, hard border, offset slab
       shadow, nothing else. Every colour resolves through the
       theme tokens, so it reads as a paper teletype in Day and
       genuine phosphor once Terminal is switched on.
       ============================================================ */
    .term-window{
        --term-mono:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        --term-rev:4;                     /* deployment tripwire */
        max-width:760px;margin:2.5rem auto;
        background:var(--bg);
        border:2px solid var(--ink);
        box-shadow:8px 8px 0 var(--rule); /* the slab — knob */
        font-family:var(--term-mono);
    }

    /* The screen: fixed-height internal scroller, like a real tube. */
    .term-screen{
        height:min(58vh, 540px);min-height:300px;
        overflow-y:auto;overscroll-behavior:contain;
        padding:1rem 1.1rem 1.2rem;
        background:var(--panel);color:var(--ink);
        font-size:.95rem;line-height:1.55;
        cursor:text;
        scrollbar-width:thin;scrollbar-color:var(--rule) transparent;
    }
    .term-screen::-webkit-scrollbar{width:10px;}
    .term-screen::-webkit-scrollbar-track{background:transparent;}
    .term-screen::-webkit-scrollbar-thumb{background:var(--rule);border:2px solid var(--panel);}
    .term-screen::-webkit-scrollbar-thumb:hover{background:var(--muted);}

    /* Output lines. pre-wrap keeps the help table's column spaces. */
    .tl{margin:0 0 .3rem;white-space:pre-wrap;word-break:break-word;}
    .tl:last-child{margin-bottom:0;}
    .tl .pr{color:var(--accent);}         /* the ">:" glyphs */
    .tl.dim{color:var(--muted);}
    .tl.warn{color:var(--accent);font-weight:700;}
    .tl a{color:var(--accent);text-decoration:underline;text-underline-offset:2px;}
    .tl a:hover{background:var(--accent);color:var(--bg);text-decoration:none;}

    /* The live input row. The real <input> is an invisible overlay on
       this row (it summons the mobile keyboard and holds the text);
       the visible line is the mirror span + the block cursor.
       NOTE: the row is white-space:pre-wrap like every output line, so
       its markup in the content section must stay FLUSH on one line —
       any indentation inside the form renders as literal spaces and
       shoves the prompt off the left edge. */
    .term-live{
        position:relative;display:block;margin:0;
        white-space:pre-wrap;word-break:break-word;
    }
    .term-live .pr{color:var(--accent);}
    .term-input{
        position:absolute;inset:0;width:100%;height:100%;
        opacity:0;border:0;padding:0;margin:0;background:none;
        font-size:16px;                   /* 16px stops iOS focus-zoom */
        color:transparent;caret-color:transparent;
    }
    .term-cursor{
        display:inline-block;width:.6ch;height:1.1em;
        vertical-align:text-bottom;
        box-shadow:inset 0 0 0 1px var(--accent);   /* hollow when idle */
    }
    .term-live:focus-within .term-cursor{
        box-shadow:none;background:var(--accent);   /* solid + blinking when live */
        animation:term-blink 1.1s step-end infinite;
    }
    @keyframes term-blink{50%{opacity:0;}}

    /* ============================================================
       SELF-DESTRUCT FX (delete:db endgame).
       .term-glitch: RGB channel-split shake on the whole window
       (text-shadow inherits, so every glyph fringes red/cyan).
       .term-bsod: the parody blue screen — full viewport, above
       everything (mb-dialog is 9999). .term-dead: the final blank
       void in the theme background. Sequence order and hold times
       live in DESTRUCT_STEPS down in Edit Zone 3.
       ============================================================ */
    .term-glitch{animation:term-glitch .09s steps(2) 16;}
    @keyframes term-glitch{
        0%  {transform:translate(0,0);            text-shadow:none;}
        25% {transform:translate(-6px,2px) skewX(2deg);
             text-shadow:3px 0 0 #f00, -3px 0 0 #0ff;}
        50% {transform:translate(5px,-3px);
             text-shadow:-3px 0 0 #f0f, 3px 0 0 #0f0;}
        75% {transform:translate(-3px,1px) skewX(-3deg);
             text-shadow:2px 2px 0 #00f, -2px -2px 0 #ff0;}
        100%{transform:translate(0,0);            text-shadow:none;}
    }

    .term-bsod{
        display:none;position:fixed;inset:0;z-index:10001;
        background:#0078d7;color:#fff;
        font-family:var(--sans);
        align-items:center;
        padding:clamp(1.5rem, 6vw, 5rem);
    }
    .term-bsod.is-on{display:flex;}
    .term-bsod-inner{max-width:640px;}
    .term-bsod-face{font-size:clamp(4rem, 12vw, 7rem);margin:0 0 1.2rem;line-height:1;}
    .term-bsod-inner p{margin:0 0 1rem;font-size:clamp(1rem, 2.4vw, 1.35rem);line-height:1.5;}
    .term-bsod-sub{opacity:.95;}
    .term-bsod-small{font-size:.8rem !important;opacity:.75;line-height:1.7 !important;}

    .term-dead{
        position:fixed;inset:0;z-index:10000;
        background:var(--bg);     /* the empty megabible.net void */
    }

    @media (prefers-reduced-motion: reduce){
        .term-live:focus-within .term-cursor{animation:none;}
        .term-glitch{animation:none;}
    }

    @media (max-width:560px){
        .term-window{margin:1.6rem auto;box-shadow:5px 5px 0 var(--rule);}
        .term-screen{height:min(62vh, 480px);padding:.85rem .8rem 1rem;font-size:.9rem;}
    }
</style>
@endsection

@section('content')
<div class="term-window" id="term-window" role="region" aria-label="MEGABIBLE.net terminal">
    <div class="term-screen" id="term-screen">
        {{-- The log. Boot line is in the markup so it exists before JS runs. --}}
        <div id="term-out">
            <p class="tl"><span class="pr">&gt;:</span> terminal.blade</p>
        </div>

        {{-- The live row. KEEP THIS MARKUP FLUSH ON ONE LINE: the row is
             white-space:pre-wrap, so any indentation inside the form would
             render as literal leading spaces before the prompt. --}}
        <form class="term-live" id="term-form" autocomplete="off"><span class="pr">&gt;:</span> <span id="term-mirror"></span><span class="term-cursor" aria-hidden="true"></span><input class="term-input" id="term-input" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" aria-label="Terminal command input"></form>
    </div>
</div>

{{-- ============================================================
     ███ THE BSOD ███
     Shown by the delete:db endgame (fx:'bsod' in DESTRUCT_STEPS),
     held for the delay on the fx:'dead' step that follows it
     (3000 ms as shipped), then replaced by the blank void.
     Plain HTML — write whatever you like inside term-bsod-inner.
     ============================================================ --}}
<div class="term-bsod" id="term-bsod" aria-hidden="true">
    <div class="term-bsod-inner">
        <p class="term-bsod-face">:(</p>
        <p>MEGABIBLE.net ran into a problem and has stopped existing. If we're not back in twenty seconds,
            just wait longer.</p>
        <p class="term-bsod-sub">100% complete</p>
        <p class="term-bsod-small">
            Call support and give them this error code:<br>
            Stop code: X3|20|4444GRETA<br>
            What failed: everything
        </p>
    </div>
</div>
@endsection

@section('scripts')
{{-- The full canon, pre-computed by TerminalController. One variable, one
     directive — never put comma-bearing expressions inside the json
     directive. isset() lets the page survive being served without the
     controller (book commands report the index as offline). --}}
@if (isset($terminalBooks))
<script>window.MB_TERMINAL_BOOKS = @json($terminalBooks);</script>
@endif

{{-- The verse endpoint for print: — only injected once the route exists,
     so this blade can ship ahead of the server piece. --}}
@if (Route::has('terminal.verse'))
<script>window.MB_TERMINAL_VERSE_URL = @json(route('terminal.verse'));</script>
@endif

<script>
/* =========================================================================
   MEGABIBLE.net PSEUDO-CLI — cli r4 (the retrieval module)
   =========================================================================
   A dumb terminal in the proudest sense: no AI, no memory of what you said
   one line ago — except when a command explicitly asks a question (the
   "pending" state below). Its ONE server call is print:, which asks the
   terminal.verse endpoint for scripture. Input is matched in order:

     0. PENDING    a command asked a question; the next line answers it
     1. COMMANDS   structured "verb:rest" commands (theme:, delete:, …)
     2. BARE       single-word commands (help, clear, exit)
     3. PATTERNS   the canned-chatbot layer — keyword regexes → replies
     4. FALLBACKS  shrugs, rotated in order, when nothing matched

   Everything the system says flows through one output queue and is typed
   character by character (TYPE knobs below). While the queue is draining,
   Enter does nothing — but whatever the visitor has typed stays put, so
   an eager "yes" is never eaten, just held until the question finishes.

   ┌─────────────────────────────────────────────────────────────────────┐
   │  GARBONZO: everything the terminal SAYS lives in the three EDIT     │
   │  ZONES below — including the destruction screenplay and the print  │
   │  command's replies. The BSOD text lives up in the page markup.     │
   │  The engine never needs touching.                                   │
   └─────────────────────────────────────────────────────────────────────┘
   ========================================================================= */
(function () {
    'use strict';

    var R      = (window.MB && window.MB.reader) ? window.MB.reader : null;
    var out    = document.getElementById('term-out');
    var screen = document.getElementById('term-screen');
    var form   = document.getElementById('term-form');
    var input  = document.getElementById('term-input');
    var mirror = document.getElementById('term-mirror');
    var win    = document.getElementById('term-window');
    var bsod   = document.getElementById('term-bsod');
    var BOOKS  = window.MB_TERMINAL_BOOKS || null;
    var VERSE_URL = window.MB_TERMINAL_VERSE_URL || null;
    if (!out || !screen || !form || !input || !mirror) return;

    console.log('[terminal] cli r4');   /* stale-file tripwire */

    /* =====================================================================
       ███ EDIT ZONE 1 — PATTERNS (the canned-chatbot brain) ███
       =====================================================================
       HOW TO ADD A RESPONSE — copy one block, edit, done:

         {
             triggers: [ /\bmarry\b/, /\blove\b/ ],
             reply: [
                 'first line of the response.',
                 'second line. <a href="/genesis">links work</a>.',
             ],
         },

       - triggers: regexes tested against the LOWERCASED input.
         Any one matching fires the reply. \b means "word boundary",
         so /\bgod\b/ matches "god" but not "godliness".
       - reply: one string, or an array of strings = multiple lines.
         Strings print as HTML (that is how the links work), so only
         put YOUR OWN markup here — user input is never inserted raw.
       - Entries are checked TOP TO BOTTOM. Put specific patterns
         above general ones (identity sits above the god entry so
         "who are you" is not swallowed by a broad net).
       ===================================================================== */
    var PATTERNS = [

        /* -- identity: who/what is this thing ---------------------------- */
        {
            triggers: [
                /who\s+are\s+you/, /what\s+are\s+you/, /what\s+is\s+this/,
                /\byour\s+name\b/, /who\s+am\s+i\s+(talking|speaking)/,
                /are\s+you\s+(an?\s+)?(ai|bot|robot|human|person|computer)/,
            ],
            reply: [
                'This is the MEGABIBLE.net Terminal System,',
                'designed to harness the words of the Bible for study in a dimension hithertoo unseen.',
            ],
        },

        /* -- god questions: are you god / who is god / where is god ------ */
        {
            triggers: [ /\bgod\b/, /\byahweh\b/, /\bjehovah\b/, /\balmighty\b/ ],
            reply: [
                'YHWH is not found in this terminal.',
                'Consult the Hebrew and Christian scriptures: begin with ' +
                    '<a href="/genesis">Genesis</a>, dwell in the <a href="/psalms">Psalms</a>, ' +
                    'and read the gospel of <a href="/mark">Mark</a>.',
            ],
        },

        /* -- romance / roleplay brush-off -------------------------------- */
        {
            triggers: [
                /\bmarry\b/, /\blove\b/, /\bsex\b/, /\bkiss\b/, /\bdate\s+me\b/,
                /\b(girlfriend|boyfriend|wife|husband|waifu)\b/, /\bhold\s+me\b/,
            ],
            reply: [
                'The MEGABIBLE.net Terminal System is not a person.',
                'And if it was a person, it would not be ready for commitment at so early an introduction.',
                'Request denied.',
            ],
        },

        /* -- pleasantries (also a template for your next additions) ------ */
        {
            triggers: [ /^(hi|hello|hey|yo|greetings|shalom|good\s+(morning|evening|day))\b/ ],
            reply: 'Hello everyone.',
        },
        {
            triggers: [ /\bthank/ ],
            reply: 'Gratitude logged.',
        },

    ];

    /* =====================================================================
       ███ EDIT ZONE 2 — TYPING FEEL + FALLBACKS + HELP ███
       =====================================================================
       TYPE is the typewriter's metronome, all in milliseconds:
         char   pause after an ordinary character (smaller = faster)
         punct  extra dwell after . ! ? :
         line   breath between one line and the next
       FALLBACKS rotate in order when nothing matched. HELP is the
       cheeky partial listing — the terminal theme and the full delete
       syntax stay unlisted on purpose.
       ===================================================================== */
    var TYPE = { char: 14, punct: 220, line: 260 };

    var FALLBACKS = [
        'unrecognized sequence.',
        'command not registered.',
        "the system does not understand. type 'help' for guidance.",
    ];

    var HELP = [
        'MEGABIBLE.net commands:',
        '  print:acts 9:2      print a verse',
        '  theme:day           day | night | contrast',
        '  delete:[REDACTED]   DO NOT USE',
        '  clear               clear the terminal',
        'other commands unknown.',
    ];

    /* The print command's own copy. {NAME} {CH} fill per book; {MAX} is
       the range cap below. searching prints before the server round trip. */
    var PRINT = {
        max: 10,     /* most verses one print may return — server caps too */
        usage:      'usage: print:<book> <chapter>:<verse>    (print:acts 9:2 · print:kjv acts 9:2 · print:hermas 20:3-4)',
        searching:  'querying the canon…',
        offline:    'retrieval endpoint offline.',
        corrupted:  '{NAME}: data corrupted. 0 rows returned.',
        outofrange: 'out of range: {NAME} has {CH} chapters.',
        toomany:    'the system prints at most {MAX} verses at a time.',
        failed:     'retrieval failed.',
    };

    /* =====================================================================
       ███ EDIT ZONE 3 — THE DESTRUCTION SCREENPLAY ███
       =====================================================================
       Every line and every delay of the delete gags. d is "wait this
       long BEFORE this step" in milliseconds (the typing time of the
       line itself comes on top). A step may carry cls:'warn' (accent,
       bold) or cls:'dim' (muted). The fx steps:
         fx:'glitch'  RGB channel-split shake on the window
         fx:'bsod'    show the blue screen (text lives in the markup)
         fx:'dead'    hide the BSOD, drop the blank void — keep LAST;
                      its d is the BSOD's hold time on screen.
       {NAME} {CH} {VS} in the book templates are filled per book.
       ===================================================================== */

    /* What counts as "yes" on a confirmation. Everything else aborts. */
    var YES_RE = /^(y|yes|yes\.|si|sí)$/;

    /* Printed whenever a confirmation is refused. */
    var ABORT_LINE = 'deletion prevented.';

    /* The six escalating are-you-SURE prompts for delete:db. Steps at
       DB_WARN_FROM and beyond print in the warn style. */
    var DELETE_DB_WARNINGS = [
        'This will permanently delete the entire MEGABIBLE.net database. Are you sure? (yes/no)',
        'Centuries of scripture, digitized, gone. Confirm again. (yes/no)',
        'The system is required to ask a third time: scribes died copying these manuscripts. Proceed? (yes/no)',
        'WARNING: this action cannot be undone. Continue? (yes/no)',
        'Think of the Psalms. (yes/no)',
        'FINAL CONFIRMATION. Type yes to drop all tables. (yes/no)',
    ];
    var DB_WARN_FROM = 3;   /* zero-based index where the warn style kicks in */

    /* The self-destruct sequence after the sixth yes. */
    var DESTRUCT_STEPS = [
        { d: 300,  text: 'confirmed.', cls: 'warn' },
        { d: 700,  text: 'initiating self-destruct sequence…', cls: 'warn' },
        { d: 900,  text: 'DROP TABLE verses;           31,102 rows' },
        { d: 350,  text: 'DROP TABLE footnotes;        12,406 rows' },
        { d: 300,  text: 'DROP TABLE shared_headings;   4,218 rows' },
        { d: 260,  text: 'DROP TABLE chapters;          1,189 rows' },
        { d: 220,  text: 'DROP TABLE books;                91 rows' },
        { d: 200,  text: 'DROP TABLE translations;          9 rows' },
        { d: 800,  text: 'deleting megabible.net…', cls: 'warn' },
        { d: 400,  fx: 'glitch' },
        { d: 900,  text: 'goodbye.', cls: 'dim' },
        { d: 800,  fx: 'bsod' },
        { d: 3000, fx: 'dead' },     /* ← how long the BSOD stays up */
    ];

    /* delete:book — the one confirmation, then the purge playback. */
    var BOOK_CONFIRM = 'This will permanently delete {NAME} — {CH} chapters, {VS} verses. Are you sure? (yes/no)';
    var BOOK_PURGE = [
        { d: 300, text: 'purging {NAME}…', cls: 'warn' },
        { d: 450, text: '  dropping {CH} chapters… done.' },
        { d: 450, text: '  dropping {VS} verses… done.' },
        { d: 350, text: '  dropping headings and footnotes… done.' },
        { d: 600, text: '{NAME} has been deleted. what hath MEGABIBLE.net done to deserve this?', cls: 'warn' },
    ];

    /* restore:db — the hidden escape hatch. {BOOKS} is the roll call. */
    var RESTORE_STEPS = [
        { d: 300, text: 'consulting source manuscripts…' },
        { d: 600, text: 're-importing: {BOOKS}' },
        { d: 700, text: 'canon restored.', cls: 'warn' },
    ];
    var RESTORE_NOTHING = 'nothing to restore. canon intact.';

    /* =====================================================================
       ENGINE — output queue + typewriter, input, parsing, mbTerm.v1.
       No editable copy below this line except the handful of one-liners
       inside the command handlers.
       ===================================================================== */

    var MAX_LINES = 400;    /* log cap so a long session can't bloat the DOM */
    var fbIndex   = 0;      /* fallback rotation pointer */
    var history   = [];     /* this session's commands, for ArrowUp recall */
    var histPos   = -1;     /* -1 = live line (not browsing history) */
    var pending   = null;   /* a question awaiting the next line as its answer */
    var busy      = false;  /* the output queue is draining; Enter is held */
    var dead      = false;  /* delete:db endgame — the site is "gone" */
    var deadEl    = null;

    /* Honour reduced-motion: lines land whole, pauses still pace the show. */
    var INSTANT = !!(window.matchMedia &&
                     window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function scrollDown() { screen.scrollTop = screen.scrollHeight; }

    function capLog() {
        while (out.children.length > MAX_LINES) out.removeChild(out.firstChild);
    }

    /* line(html, cls): append one FINISHED line instantly. html is TRUSTED
       (our own curated strings); anything user-typed goes through esc(). */
    function line(html, cls) {
        var p = document.createElement('p');
        p.className = 'tl' + (cls ? ' ' + cls : '');
        p.innerHTML = html;
        out.appendChild(p);
        capLog(); scrollDown();
    }

    /* ---------------------------------------------------------------------
       THE OUTPUT QUEUE. Every system utterance becomes jobs on one queue:
         { text, cls }   a line, typed character by character
         { pause: ms }   dead air
         { fx: name }    an effect (glitch / bsod / dead)
         { fn: f }       arbitrary work (e.g. commit a book deletion)
       busy is true while the queue drains; submits are held, not eaten.
       --------------------------------------------------------------------- */
    var QUEUE = [], draining = false;

    function enqueue(job) {
        QUEUE.push(job);
        if (!draining) drain();
    }
    function drain() {
        draining = true; busy = true;
        (function next() {
            var job = QUEUE.shift();
            if (!job) { draining = false; busy = false; return; }
            if (job.pause) { setTimeout(next, job.pause); return; }
            if (job.fx)    { if (FX[job.fx]) FX[job.fx](); next(); return; }
            if (job.fn)    { job.fn(); next(); return; }
            typeLine(job.text, job.cls, next);
        })();
    }

    /* The typewriter. Tags and HTML entities are emitted atomically so a
       half-typed <a href…> or &gt; can never appear; the browser's parser
       auto-closes the tag on every re-render, so links type out safely. */
    var PUNCT = /[.!?:]/;
    function tokenize(html) {
        var re = /(<[^>]+>|&[a-zA-Z#0-9]+;|[\s\S])/g, m, toks = [];
        while ((m = re.exec(html))) toks.push(m[0]);
        return toks;
    }
    function typeLine(html, cls, cb) {
        var p = document.createElement('p');
        p.className = 'tl' + (cls ? ' ' + cls : '');
        out.appendChild(p);
        capLog();

        if (INSTANT || TYPE.char <= 0) {
            p.innerHTML = html; scrollDown(); cb();
            return;
        }
        var toks = tokenize(html), buf = '', i = 0;
        (function tick() {
            if (i >= toks.length) { cb(); return; }
            var t = toks[i++];
            buf += t;
            p.innerHTML = buf;
            scrollDown();
            var d = TYPE.char;
            if (t.charAt(0) === '<' || t.charAt(0) === '&') d = 0;   /* markup is free */
            else if (PUNCT.test(t)) d = TYPE.punct;                  /* sentence breath */
            setTimeout(tick, d);
        })();
    }

    /* say(reply, cls): queue a curated reply — one string or an array —
       typed out, with a breath between lines. */
    function say(reply, cls) {
        var lines = (Object.prototype.toString.call(reply) === '[object Array]') ? reply : [reply];
        for (var i = 0; i < lines.length; i++) {
            if (i > 0) enqueue({ pause: TYPE.line });
            enqueue({ text: lines[i], cls: cls });
        }
    }

    /* echo(raw): the user's own words appear instantly — they typed them. */
    function echo(raw) {
        line('<span class="pr">&gt;:</span> ' + esc(raw));
    }

    /* ask(text, cls, onAnswer): queue a question; the next submitted line
       goes to onAnswer(low, raw) instead of the normal parser. Submits
       are held while the queue drains, so the answer can't jump the gun. */
    function ask(text, cls, onAnswer) {
        say(text, cls);
        pending = onAnswer;
    }

    /* seq(steps, done): queue a timed screenplay — each step waits step.d,
       then types its line or fires its fx. */
    function seq(steps, done) {
        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];
            if (s.d) enqueue({ pause: s.d });
            if (s.fx) enqueue({ fx: s.fx });
            else      enqueue({ text: s.text, cls: s.cls });
        }
        if (done) enqueue({ fn: done });
    }

    var FX = {
        glitch: function () { if (win) win.classList.add('term-glitch'); },
        bsod:   function () { if (bsod) bsod.classList.add('is-on'); },
        dead:   function () { goDead(); },
    };

    /* fill(text, book): replace {NAME} {CH} {VS} with this book's details.
       Verse counts are a deterministic fake — close enough to be funny,
       no query required. */
    function fakeVerses(b) {
        var h = 0, s = b.slug;
        for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
        return b.ch * (18 + (h % 14));
    }
    function fill(text, b) {
        return text
            .replace(/\{NAME\}/g, esc(b.name.toUpperCase()))
            .replace(/\{CH\}/g,   String(b.ch))
            .replace(/\{VS\}/g,   fakeVerses(b).toLocaleString());
    }
    function fillSteps(steps, b) {
        var outSteps = [];
        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];
            outSteps.push(s.fx ? s : { d: s.d, cls: s.cls, text: fill(s.text, b) });
        }
        return outSteps;
    }

    /* ---------------------------------------------------------------------
       mbTerm.v1 — the terminal's own localStorage memory. Today it holds
       only the deleted-books gag; future terminal state belongs here too.
       --------------------------------------------------------------------- */
    var TERM_KEY = 'mbTerm.v1';
    function termState() {
        try { return JSON.parse(localStorage.getItem(TERM_KEY)) || {}; }
        catch (e) { return {}; }
    }
    function termSave(s) {
        try { localStorage.setItem(TERM_KEY, JSON.stringify(s)); } catch (e) {}
    }
    function deletedSlugs() { return termState().deletedBooks || []; }
    function markDeleted(slug) {
        var s = termState();
        s.deletedBooks = s.deletedBooks || [];
        if (s.deletedBooks.indexOf(slug) < 0) s.deletedBooks.push(slug);
        termSave(s);
    }
    function clearDeleted() {
        var s = termState();
        delete s.deletedBooks;
        termSave(s);
    }

    /* ---------------------------------------------------------------------
       Book lookup against the server-provided canon. Accepts full names
       ("shepherd of hermas"), slugs, aliases (OSIS / short names), and
       unambiguous prefixes ("gen", "shep").
       --------------------------------------------------------------------- */
    function findBook(q) {
        if (!BOOKS) return { offline: true };
        q = q.toLowerCase().replace(/\s+/g, ' ').trim();
        if (!q) return {};
        var qs = q.replace(/ /g, '-');
        var i, b, hits = [];
        for (i = 0; i < BOOKS.length; i++) {
            b = BOOKS[i];
            if (b.name.toLowerCase() === q || b.slug === qs ||
                (b.al && b.al.indexOf(q) >= 0)) return { book: b };
        }
        for (i = 0; i < BOOKS.length; i++) {
            b = BOOKS[i];
            if (b.name.toLowerCase().indexOf(q) === 0 || b.slug.indexOf(qs) === 0) hits.push(b);
        }
        if (hits.length === 1) return { book: hits[0] };
        if (hits.length > 1)  return { multi: hits };
        return {};
    }

    function sayAmbiguous(list) {
        var names = [];
        for (var i = 0; i < list.length && i < 6; i++) names.push(list[i].name);
        say('ambiguous. did you mean: ' + esc(names.join(', ')) + '?', 'dim');
    }

    /* ---------------------------------------------------------------------
       COMMAND HANDLERS — the structured verb:rest layer.
       --------------------------------------------------------------------- */

    function cmdTheme(arg) {
        if (!R) { say('theme controller offline.', 'dim'); return; }

        if (arg === 'day' || arg === 'night' || arg === 'contrast') {
            R.setTheme(arg);
            say('theme set: ' + arg + '.');
            return;
        }
        if (arg === 'terminal') {
            if (R.get().theme === 'terminal') { say('already inside.', 'dim'); return; }
            if (R.get().terminalUnlocked) {
                R.setTheme('terminal');
                say('TERMINAL ONLINE.');
            } else {
                /* THE unlock path: flips the whole site green on the spot. */
                R.unlockTerminal();
                say([
                    'ACCESS GRANTED.',
                    'TERMINAL theme unlocked — it now lives in Text Settings',
                    '(the Aa button), on every page, on this device.',
                    'TERMINAL ONLINE.',
                ]);
            }
            return;
        }
        /* Terminal deliberately missing from the list — it stays a secret. */
        say('unknown theme: ' + esc(arg) + '. options: day, night, contrast.');
    }

    /* delete:db → the six-step ladder, then the end of everything. */
    function startDbLadder() {
        var step = 0;
        function prompt() {
            ask(DELETE_DB_WARNINGS[step], step >= DB_WARN_FROM ? 'warn' : null, answer);
        }
        function answer(low) {
            if (!YES_RE.test(low)) { say(ABORT_LINE); return; }
            step++;
            if (step < DELETE_DB_WARNINGS.length) prompt();
            else seq(DESTRUCT_STEPS);
        }
        prompt();
    }

    /* delete:book <name> → one confirmation, fake purge, remembered. */
    function deleteBook(q) {
        var r = findBook(q);
        if (r.offline) { say('canon index offline. (the page was served without its book list.)', 'dim'); return; }
        if (r.multi) { sayAmbiguous(r.multi); return; }
        if (!r.book) { say('no such book: ' + esc(q) + '.', 'dim'); return; }

        var b = r.book;
        if (deletedSlugs().indexOf(b.slug) >= 0) {
            say('no such book: ' + esc(b.name.toUpperCase()) + '. (you already saw to that.)', 'dim');
            return;
        }
        ask(fill(BOOK_CONFIRM, b), 'warn', function (low) {
            if (!YES_RE.test(low)) { say(ABORT_LINE); return; }
            seq(fillSteps(BOOK_PURGE, b), function () { markDeleted(b.slug); });
        });
    }

    function cmdDelete(rest) {
        if (rest === 'db' || rest === 'database') { startDbLadder(); return; }
        if (rest.indexOf('book') === 0) {
            var name = rest.slice(4).replace(/^[:\s]+/, '').trim();
            if (!name) { say('usage: delete:book <name>    (example: delete:book genesis)', 'dim'); return; }
            deleteBook(name);
            return;
        }
        say(['the destruction module accepts:', '  delete:db', '  delete:book <name>'], 'dim');
    }

    /* restore:db — hidden; not in help, not hinted. The way back. */
    function cmdRestore(rest) {
        if (rest !== 'db' && rest !== 'database') {
            say('the restoration module accepts: restore:db', 'dim');
            return;
        }
        var slugs = deletedSlugs();
        if (!slugs.length) { say(RESTORE_NOTHING, 'dim'); return; }

        var names = [];
        for (var i = 0; i < slugs.length; i++) {
            var found = null;
            if (BOOKS) {
                for (var j = 0; j < BOOKS.length; j++) {
                    if (BOOKS[j].slug === slugs[i]) { found = BOOKS[j]; break; }
                }
            }
            names.push(found ? found.name.toUpperCase() : slugs[i]);
        }
        var steps = [];
        for (var k = 0; k < RESTORE_STEPS.length; k++) {
            var s = RESTORE_STEPS[k];
            steps.push({ d: s.d, cls: s.cls, text: s.text.replace(/\{BOOKS\}/g, esc(names.join(', '))) });
        }
        seq(steps, clearDeleted);
    }

    /* ---------------------------------------------------------------------
       print:<ref> — the retrieval module. Grammar, parsed from the END:
       the last token must be C:V or C:V-V2; everything before it is the
       book name, optionally led by a translation code ("kjv acts 9:2").
       The translation token is detected by elimination: if the full name
       part matches no book but matches once its first word is removed,
       that first word rides to the server as the translation override —
       so "1 clement 5:1" is never mistaken for a translation. The server
       supplies the reader's current translation when no override comes.
       --------------------------------------------------------------------- */
    function cmdPrint(rest) {
        rest = rest.trim();
        if (!rest) { say(PRINT.usage, 'dim'); return; }

        var m = rest.match(/^(.*\S)\s+(\d+):(\d+)(?:-(\d+))?$/);
        if (!m) { say(PRINT.usage, 'dim'); return; }

        var namePart = m[1].trim();
        var c  = parseInt(m[2], 10);
        var v1 = parseInt(m[3], 10);
        var v2 = m[4] ? parseInt(m[4], 10) : null;
        if (v2 !== null && v2 < v1) { var t = v1; v1 = v2; v2 = t; }
        if (v2 !== null && (v2 - v1 + 1) > PRINT.max) {
            say(PRINT.toomany.replace(/\{MAX\}/g, String(PRINT.max)), 'dim');
            return;
        }

        var tr = null;
        var r = findBook(namePart);
        if (!r.book && !r.multi && !r.offline) {
            var sp = namePart.indexOf(' ');
            if (sp > 0 && /^[a-z][a-z0-9]{1,11}$/.test(namePart.slice(0, sp))) {
                var r2 = findBook(namePart.slice(sp + 1));
                if (r2.book || r2.multi) { tr = namePart.slice(0, sp); r = r2; }
            }
        }
        if (r.offline) { say('canon index offline. (the page was served without its book list.)', 'dim'); return; }
        if (r.multi)   { sayAmbiguous(r.multi); return; }
        if (!r.book)   { say('no such book: ' + esc(namePart) + '.', 'dim'); return; }

        var b = r.book;
        if (deletedSlugs().indexOf(b.slug) >= 0) { say(fill(PRINT.corrupted, b), 'dim'); return; }
        if (c < 1 || c > b.ch)                   { say(fill(PRINT.outofrange, b), 'dim'); return; }
        if (!VERSE_URL)                          { say(PRINT.offline, 'dim'); return; }

        say(PRINT.searching, 'dim');

        var q = '?book=' + encodeURIComponent(b.slug) +
                '&c=' + c + '&v=' + v1 + (v2 !== null ? '-' + v2 : '') +
                (tr ? '&t=' + encodeURIComponent(tr) : '');

        fetch(VERSE_URL + q, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    say(data && data.message ? esc(data.message) : PRINT.failed, 'dim');
                    return;
                }
                var linesOut = [esc(data.ref) + ' (' + esc(data.tr) + ')'];
                for (var i = 0; i < data.verses.length; i++) {
                    linesOut.push('  ' + data.verses[i].v + '  ' + esc(data.verses[i].text));
                }
                say(linesOut);
            })
            .catch(function () { say(PRINT.failed, 'dim'); });
    }

    var COMMANDS = {
        'theme':   cmdTheme,
        'delete':  cmdDelete,
        'restore': cmdRestore,
        'print':   cmdPrint,
    };

    var BARE = {
        'help': function () { say(HELP); },
        'clear': function () {
            QUEUE.length = 0;        /* a wiped screen types no leftovers */
            out.innerHTML = '';
            line('<span class="pr">&gt;:</span> terminal.blade');
        },
        'exit': function () {
            if (R && R.get().theme === 'terminal') {
                R.exitTerminal();
                say('TERMINAL OFFLINE. welcome back to the land of the living.');
            } else {
                say('there is no exit.', 'dim');
            }
        },
    };

    /* ---------------------------------------------------------------------
       run(raw): route one submitted line through the layers.
       --------------------------------------------------------------------- */
    function run(raw) {
        var txt = raw.trim();
        if (!txt) return;
        var low = txt.toLowerCase();

        /* 0) a command asked a question — this line is the answer */
        if (pending) {
            var h = pending;
            pending = null;
            h(low, txt);
            return;
        }

        /* 1) verb:rest commands — "theme:day", "print:acts 9:2" … */
        var m = low.match(/^([a-z]+)\s*:\s*(.*)$/);
        if (m && COMMANDS[m[1]]) { COMMANDS[m[1]](m[2].trim(), txt); return; }

        /* 2) bare one-word commands */
        if (BARE[low]) { BARE[low](); return; }

        /* 3) the canned-chatbot patterns */
        for (var i = 0; i < PATTERNS.length; i++) {
            var trig = PATTERNS[i].triggers;
            for (var j = 0; j < trig.length; j++) {
                if (trig[j].test(low)) { say(PATTERNS[i].reply); return; }
            }
        }

        /* 4) shrug */
        say(FALLBACKS[fbIndex++ % FALLBACKS.length], 'dim');
    }

    /* ---------------------------------------------------------------------
       THE DEAD SITE — delete:db endgame. fx:'bsod' shows the blue screen;
       fx:'dead' swaps it for the blank void in the theme background.
       Nothing works until the visitor navigates or refreshes. The
       pageshow listener resurrects the page if iOS back-swipe restores
       it from the back-forward cache in the dead state.
       --------------------------------------------------------------------- */
    function goDead() {
        if (dead) return;
        dead = true;
        if (bsod) bsod.classList.remove('is-on');
        deadEl = document.createElement('div');
        deadEl.className = 'term-dead';
        document.body.appendChild(deadEl);
        document.documentElement.style.overflow = 'hidden';
        try { input.blur(); } catch (e) {}
    }
    function resurrect() {
        if (!dead) return;
        dead = false;
        if (deadEl && deadEl.parentNode) deadEl.parentNode.removeChild(deadEl);
        deadEl = null;
        if (bsod) bsod.classList.remove('is-on');
        document.documentElement.style.overflow = '';
        if (win) win.classList.remove('term-glitch');
        line('…restored from backup. there was a backup all along.', 'dim');
    }
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) resurrect();
    });

    /* ---------------------------------------------------------------------
       INPUT WIRING — mirror, submit, history, focus, shortcut shielding.
       --------------------------------------------------------------------- */

    function syncMirror() { mirror.textContent = input.value; }

    function caretToEnd() {
        var n = input.value.length;
        try { input.setSelectionRange(n, n); } catch (e) {}
    }

    function focusInput() {
        try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy || dead) return;    /* held, not eaten: the text stays put */
        var raw = input.value;
        input.value = '';
        syncMirror();
        if (raw.trim()) {
            history.push(raw);
            histPos = -1;
            echo(raw);
            run(raw);
        }
    });

    input.addEventListener('input', function () { histPos = -1; syncMirror(); });
    input.addEventListener('focus', caretToEnd);

    input.addEventListener('keydown', function (e) {
        /* The console swallows its own keys so site-wide shortcuts.js
           never sees them. stopPropagation never cancels typing itself. */
        e.stopPropagation();

        if (e.key === 'ArrowUp') {              /* recall older command */
            e.preventDefault();
            if (!history.length) return;
            if (histPos === -1) histPos = history.length;
            if (histPos > 0) histPos--;
            input.value = history[histPos];
            syncMirror(); caretToEnd();
        } else if (e.key === 'ArrowDown') {     /* back toward the live line */
            e.preventDefault();
            if (histPos === -1) return;
            histPos++;
            if (histPos >= history.length) { histPos = -1; input.value = ''; }
            else { input.value = history[histPos]; }
            syncMirror(); caretToEnd();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' ||
                   e.key === 'Home'      || e.key === 'End') {
            /* A dumb terminal: the caret lives at the end of the line. The
               mirror can't draw a mid-line caret, so we don't allow one. */
            e.preventDefault();
        }
    });

    /* This page has NO keyboard shortcuts — not even "/" for search.
       Capture-phase shield: every keydown that isn't headed for the console
       input (or the header search box, which stays usable) is stopped
       before the document-level shortcuts.js listeners can react, and
       printable keys steer focus back into the console. Tab is left
       alone so keyboard users can still reach the header. */
    document.addEventListener('keydown', function (e) {
        if (dead) { e.stopPropagation(); return; }            /* the site is gone */
        if (e.target === input) return;                       /* console handles itself */
        if (e.key === 'Tab') return;                          /* keep focus navigation */
        if (e.target.closest && e.target.closest('.site-search')) return;
        e.stopPropagation();
        if (!e.metaKey && !e.ctrlKey && !e.altKey) focusInput();
    }, true);

    /* Click/tap anywhere non-interactive puts the cursor back in the
       console — unless the visitor is selecting text to copy. */
    document.addEventListener('click', function (e) {
        if (dead) return;
        if (e.target.closest && e.target.closest('a, button, input, select, textarea, summary, label')) return;
        var sel = window.getSelection && window.getSelection();
        if (sel && String(sel).length) return;
        focusInput();
    });

    focusInput();   /* desktop lands ready to type; mobile waits for a tap */
})();
</script>
@endsection
