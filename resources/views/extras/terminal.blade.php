@extends('layouts.app')

@section('title', 'Terminal — MEGABIBLE.net')

{{-- ============================================================
     TERMINAL EASTER-EGG PAGE.

     Reached by typing "terminal-system" in search (config/shortcuts.php
     → route terminal.index). The page itself renders under whatever theme
     is active, so the moment a visitor hits "Enter", everything — this page
     included — flips to Terminal green. All state lives client-side in
     window.MB.reader (localStorage); nothing here touches the server.
     ============================================================ --}}
@section('styles')
<style>
    /* A console card, themed via the base tokens so it looks right in every
       theme and turns properly green once Terminal is on. */
    .term-wrap{max-width:640px;margin:2.5rem auto;}
    .term-eyebrow{
        font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        font-size:.8rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin:0 0 .8rem;
    }
    .term-card{
        font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        background:var(--panel);border:1px solid var(--rule);border-radius:12px;
        padding:1.6rem 1.7rem;line-height:1.7;color:var(--ink);
    }
    .term-card .prompt{color:var(--accent);}       /* the ">" glyphs */
    .term-card .dim{color:var(--muted);}
    .term-line{margin:0 0 .35rem;}
    .term-line:last-of-type{margin-bottom:0;}

    .term-status{
        margin:1.3rem 0 0;padding-top:1rem;border-top:1px dashed var(--rule);
        font-size:.9rem;letter-spacing:.04em;
    }
    .term-status .on{color:var(--accent);font-weight:700;}

    .term-actions{display:flex;flex-wrap:wrap;gap:.8rem;margin-top:1.5rem;}
    .term-btn{
        font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        font-size:.95rem;letter-spacing:.06em;cursor:pointer;
        padding:.7rem 1.3rem;border-radius:8px;
        color:var(--bg);background:var(--accent);border:1px solid var(--accent);
        transition:filter .12s;
    }
    .term-btn:hover{filter:brightness(1.12);}
    .term-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(107,31,31,.2);}
    .term-back{
        font-family:ui-monospace,'SF Mono','Cascadia Mono','Roboto Mono',Menlo,Consolas,monospace;
        font-size:.9rem;color:var(--muted);text-decoration:none;align-self:center;
    }
    .term-back:hover{color:var(--accent);}

    /* Blinking cursor for a little life. */
    .term-cursor{display:inline-block;width:.6ch;background:var(--accent);animation:term-blink 1.1s step-end infinite;}
    @keyframes term-blink{50%{opacity:0;}}
</style>
@endsection

@section('content')
<div class="term-wrap">
    <p class="term-eyebrow">extras / terminal</p>

    <div class="term-card">
        <p class="term-line"><span class="prompt">&gt;</span> ACCESS: <span class="dim">megabible.sys</span></p>
        <p class="term-line"><span class="prompt">&gt;</span> You found it.</p>
        <p class="term-line dim">&nbsp;</p>
        <p class="term-line">A hidden reading mode: black canvas, phosphor-green text,
            everything rendered in monospace — the whole site, top to bottom.</p>
        <p class="term-line dim">&nbsp;</p>
        <p class="term-line">Switch it on below. Once unlocked it lives in
            <span class="prompt">Text&nbsp;Settings</span> (the <span class="prompt">Aa</span> button),
            so you can flip in and out any time.</p>

        <p class="term-status" id="term-status">
            <span class="prompt">&gt;</span> STATUS: <span id="term-status-value">…</span><span class="term-cursor">&nbsp;</span>
        </p>
    </div>

    <div class="term-actions">
        <button type="button" class="term-btn" id="term-toggle">…</button>
        <a class="term-back" href="{{ url('/') }}">&larr; back to safety</a>
    </div>
</div>
@endsection

@section('scripts')
<script>
    /* All wiring goes through the same MB.reader API the settings panel uses.
       We just reflect and toggle its state; the head script does the rest. */
    (function () {
        const btn    = document.getElementById('term-toggle');
        const status = document.getElementById('term-status-value');
        if (!btn || !status || !window.MB || !window.MB.reader) return;
        const R = window.MB.reader;

        function render() {
            const s = R.get();
            if (s.theme === 'terminal') {
                status.textContent = 'TERMINAL ONLINE';
                status.className   = 'on';
                btn.textContent    = 'Exit Terminal Mode';
            } else if (s.terminalUnlocked) {
                status.textContent = 'ACCESS GRANTED — available in Text Settings';
                status.className   = '';
                btn.textContent    = 'Enter Terminal Mode';
            } else {
                status.textContent = 'LOCKED';
                status.className   = '';
                btn.textContent    = 'Enter Terminal Mode';
            }
        }

        btn.addEventListener('click', function () {
            R.get().theme === 'terminal' ? R.exitTerminal() : R.unlockTerminal();
        });

        // Keep the page in sync if the theme changes from elsewhere (e.g. the
        // settings panel open in this same tab).
        document.addEventListener('mb:reader-change', render);
        render();
    })();
</script>
@endsection