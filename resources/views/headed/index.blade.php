@php
    $cssPath = public_path('css/headed.css');
    $jsPath  = public_path('js/headed.js');
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath)  ? filemtime($jsPath)  : time();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HEADed — Heading TSV MEGAeditor</title>
    <link rel="stylesheet" href="{{ asset('css/headed.css') }}?v={{ $cssV }}">
</head>
<body
    data-load-url="{{ route('headed.load') }}"
    data-resolve-url="{{ route('headed.resolve') }}"
    data-write-url="{{ route('headed.write') }}">

    <header>
        <h1>HEADed</h1>
        <span class="sub">the Heading TSV MEGAeditor · local sandbox</span>
    </header>

    {{-- LOAD SCREEN --}}
    <main id="loadScreen">
        <div class="loader">
            <div class="set">
                <label for="set">Set key</label>
                <input type="text" id="set" value="{{ $defaultSet }}" spellcheck="false">
            </div>
            <div class="path">
                <label for="path">TSV path (absolute, or relative to storage/app)</label>
                <input type="text" id="path" value="{{ $defaultPath }}" spellcheck="false"
                       placeholder="headings/en-standard.tsv">
            </div>
            <button class="btn primary" id="load">Load</button>
        </div>
        <div id="report"></div>
    </main>

    {{-- EDITOR --}}
    <div id="editor" hidden>
        <div class="topbar">
            <button class="btn" id="backToLoad">← Load</button>
            <span class="filecrumb" id="filecrumb"></span>
            <span class="grow"></span>
            <span class="countcrumb" id="countcrumb"></span>
        </div>

        <div class="workspace">
            <section class="viewer" id="viewer" aria-label="TSV view"></section>

            <aside class="side">
                <div class="pane">
                    <label for="q">Search heading text</label>
                    <input type="text" id="q" spellcheck="false" placeholder="e.g. creation, genealogy…">
                    <div class="results" id="results"></div>
                </div>

                <div class="pane">
                    <label for="jump">Jump to reference</label>
                    <div class="jumprow">
                        <input type="text" id="jump" spellcheck="false" placeholder="gen 1:4">
                        <button class="btn" id="jumpBtn" title="Jump">→</button>
                    </div>
                    <div class="jumpmsg" id="jumpmsg"></div>
                </div>

                <div class="pane detail" id="detail">
                    <div class="detail-empty">Select a heading to see its details.</div>
                </div>
            </aside>
        </div>
    </div>

    <script src="{{ asset('js/headed.js') }}?v={{ $jsV }}"></script>
</body>
</html>