<?php

namespace App\Providers;

use App\Services\Oidc\OidcClient;
use App\Services\Oidc\ProviderRegistry;
use App\Support\Period;
use App\Support\PlanLimits;
use App\Support\Plans;
use App\Support\Platform;
use App\Support\Subscription;
use App\View\Composers\NavigationComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Each of these memoises a query that the legacy code caches in a
         * per-request static: the platform organization's id and settings, the
         * plan price table, the configured OIDC providers, and an OIDC
         * discovery document.
         *
         * Singletons rather than statics so the memo dies with the request —
         * and, just as importantly, with the test. A static would carry one
         * test's platform organization into the next.
         */
        $this->app->singleton(Platform::class);
        $this->app->singleton(Period::class);
        $this->app->singleton(PlanLimits::class);
        $this->app->singleton(Plans::class);
        $this->app->singleton(Subscription::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(OidcClient::class);
    }

    public function boot(): void
    {
        /*
         * The app shell's sidebar, badges and acting-as banner. Attached to
         * the layout rather than assembled per controller: the legacy
         * nav_context() must be merged into every page's variables by hand,
         * and a page that forgets loses its badges silently.
         */
        View::composer('layouts.app', NavigationComposer::class);
    }
}
