<?php

namespace App\Http\Middleware;

use App\Models\Translation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the translation the reader is currently in.
 *
 * Any route that carries a {translation} segment (the book hub, the chapter
 * reader, parallel view later) drops a year-long cookie with that slug — but
 * only when the page actually rendered (a 200), so a typo'd / non-existent
 * translation that 404s never gets remembered.
 *
 * Only GLOBAL translations (is_global) are remembered. Full-canon editions like
 * KJV/WEB become the reader's sticky default; single-work editions (e.g. a lone
 * 1 Enoch translation) are readable but never hijack that default — so clicking
 * into Enoch from KJV leaves you still "in KJV" everywhere else on the site.
 *
 * Translation-agnostic pages — chiefly the homepage — read this cookie to
 * decide which translation their book links point at, so the reader stays in
 * WEB (or whatever they chose) instead of snapping back to KJV.
 */
class RememberTranslation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $slug = $request->route('translation');
        if ($slug && $response->getStatusCode() === 200) {
            $t = Translation::findBySlug($slug);

            // Only persist real, canon-spanning translations as the default.
            if ($t && $t->is_global) {
                // 1 year, in minutes.
                cookie()->queue(cookie('reader_translation', strtolower($slug), 60 * 24 * 365));
            }
        }

        return $response;
    }
}