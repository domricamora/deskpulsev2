<?php

use App\Http\Middleware\EnsureOrganizationApproved;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureSubscriptionCurrent;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        /*
         * The desktop agent's frozen API. Registered by hand rather than
         * through `api:` so it carries NO middleware group at all — not
         * `api`, which would add throttling the agent reads as failure, and
         * not `web`, which would add sessions and CSRF.
         *
         * The prefix is /webhooks and there is no version segment. See
         * routes/agent.php.
         */
        then: function (): void {
            Route::prefix('webhooks')
                ->group(__DIR__.'/../routes/agent.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Gate 1 — not signed in.
         *
         * The destination round-trips as `?next=`, not through Laravel's
         * intended() session value, because the parameter is visible in the URL
         * and appears in links people have already been sent. See
         * docs/migration/authentication.md §2.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => '/login?next=' . rawurlencode('/' . ltrim($request->path(), '/'))
        );

        /*
         * Three framework behaviours that would each break the agent contract.
         *
         * TrimStrings and ConvertEmptyStringsToNull rewrite input the legacy
         * handlers accept verbatim: a window title of "   " becomes "", and an
         * empty string becomes null. The handlers substr() and cast whatever
         * arrives, so the rewrite silently changes what is stored.
         *
         * CSRF cannot apply — there is no session and no token to carry. The
         * device HMAC is the authentication.
         */
        $middleware->trimStrings(except: ['webhooks/*']);
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('webhooks/*'),
        ]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            // The four gates, in the order require_login() then
            // require_approved_org() applies them.
            'password.changed'      => EnsurePasswordChanged::class,
            'org.approved'          => EnsureOrganizationApproved::class,
            'subscription.current'  => EnsureSubscriptionCurrent::class,

            // Super-admin act-as. Runs before any gate that reads the
            // organization, so a super admin viewing a tenant sees that
            // tenant's gates rather than the platform organization's.
            'tenant'                => ResolveOrganization::class,

            // Authorization. Always a capability, never a role name.
            'cap'                   => RequireCapability::class,
            'super'                 => RequireSuperAdmin::class,
            'staff'                 => RequireStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * The agent calls .json() on every response. An HTML error page — the
         * default for an uncaught exception, a 404 or a method mismatch —
         * raises a decode error it reports as a generic failure and re-queues.
         * Anything under /webhooks must answer JSON whatever goes wrong.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('webhooks/*')
                || $request->is('api/*')
                || $request->expectsJson(),
        );
    })->create();
