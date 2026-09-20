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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
