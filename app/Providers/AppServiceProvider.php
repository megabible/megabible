<?php

namespace App\Providers;

use App\View\Composers\QuicknavComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the QuickNav composer as a singleton so its one-time-per-request
        // memoisation (see QuicknavComposer::$data) actually holds: the panel
        // partial is included twice on reader pages (header logo + book-name
        // trigger), and without a shared instance we'd run the availability query
        // once per include. Under standard PHP-FPM the container is rebuilt each
        // request, so this stays request-scoped and never serves stale data.
        $this->app->singleton(QuicknavComposer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Feed $quicknav to the shared QuickNav panel wherever it's included — the
        // header logo trigger (via layouts.app) and the book-name trigger in the
        // chapter + parallel readers. Binding to the partial (rather than the
        // layout) guarantees the data is present even when the panel is included
        // from a child view whose own scope doesn't carry $quicknav.
        View::composer('bible.partials.quicknav-panel', QuicknavComposer::class);

        RateLimiter::for('score-submit', fn (Request $r) => [
            Limit::perMinute(20)->by($r->ip()),
            Limit::perDay(300)->by($r->ip()),
        ]);
    }
}