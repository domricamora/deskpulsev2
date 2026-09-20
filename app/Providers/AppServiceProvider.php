<?php

namespace App\Providers;

use App\Services\Oidc\OidcClient;
use App\Services\Oidc\ProviderRegistry;
use App\Support\Plans;
use App\Support\Platform;
use App\Support\Subscription;
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
        $this->app->singleton(Plans::class);
        $this->app->singleton(Subscription::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(OidcClient::class);
    }

    public function boot(): void
    {
        //
    }
}
