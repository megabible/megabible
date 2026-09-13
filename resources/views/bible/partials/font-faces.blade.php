{{--
    FONT FACES  ·  bible/partials/font-faces.blade.php  ·  fonts r1
    ---------------------------------------------------------------
    RAW CSS, NO <style> WRAPPER: include INSIDE the page's open <style>
    block, the present-styles convention. Emits one font-face rule per
    family in the config/fonts.php manifest — the ONE place the pool's
    families, files and licences are declared. Pass a set name to take a
    slice of the pool:

        (at)include('bible.partials.font-faces', ['fontSet' => 'pericope'])

    (no set, or an unknown set, emits the whole pool — a page asking for
    fonts should never silently get none). Files are self-hosted at
    public/{fonts.dir}, put there by `php artisan fonts:fetch`.

    BLADE NOTE: keep every rule's braces apart — two adjacent opening
    braces in a Blade file read as an echo tag.
--}}
    /* ---- the font pool (config/fonts.php; fetched by fonts:fetch) ------- */
@foreach (\App\Support\Fonts::families($fontSet ?? null) as $mbFontKey => $mbFont)
    @font-face { font-family: "{{ $mbFont['family'] }}"; src: url("/{{ config('fonts.dir', 'fonts/pool') }}/{{ $mbFont['file'] }}") format("woff2"); font-display: swap; }
@endforeach
