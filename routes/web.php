<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\ActAsController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PendingController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Migrated from the legacy front controller, URL for URL. The full map is in
| docs/migration/routes.md and the existing paths are preserved exactly — every
| bookmark, every emailed reset link and every share URL must keep working.
|
| The agent API (/webhooks/*) is a separate, frozen contract registered in
| Phase 7; see docs/migration/api-contract.md.
|
*/

/* ── Scaffold check (Phase 2, temporary) ─────────────────────────────────── */

// Replaced by the marketing home page when Phase 14 ports it.
Route::get('/', function () {
    return view('dev.preview', [
        'icons' => require resource_path('icons/icons.php'),
    ]);
})->name('dev.preview');

/* ── Auth (public) ───────────────────────────────────────────────────────── */

Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store']);

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);

// GET, not POST: the legacy sign-out is a plain link in the sidebar and on the
// change-password and pending pages, and every one of those links must keep
// working. It is exempt from both the forced-password-change and paywall gates
// so leaving is always possible.
Route::get('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm']);
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);

Route::get('/reset-password', [PasswordResetController::class, 'showResetForm']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

/* ── Federated sign-in (OIDC) ────────────────────────────────────────────── */

// `provider` is a platform provider key or the literal `sso`, which resolves to
// the organization named by ?org=. Constrained so it cannot become a path.
Route::get('/auth/{provider}', [OAuthController::class, 'start'])
    ->where('provider', '[a-z][a-z0-9_-]*');
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->where('provider', '[a-z][a-z0-9_-]*');

/* ── Dashboard ───────────────────────────────────────────────────────────── */

Route::middleware(['auth', 'password.changed', 'tenant'])->group(function () {

    // Gate destinations. Deliberately OUTSIDE the org.approved and
    // subscription.current middleware: they are where those gates send people,
    // and putting them behind the gates would loop.
    Route::get('/app/pending', [PendingController::class, 'show']);

    Route::get('/app/change-password', [ChangePasswordController::class, 'show']);
    Route::post('/app/change-password', [ChangePasswordController::class, 'update']);

    // Act-as. Behind the super gate and outside gates 3 and 4: a platform
    // operator must be able to open a tenant that is itself pending approval or
    // lapsed, because those are the two they most need to look at.
    Route::middleware('super')->group(function () {
        Route::get('/app/platform/act/{id}', [ActAsController::class, 'store'])
            ->whereNumber('id');
        Route::get('/app/platform/return', [ActAsController::class, 'destroy']);
    });

    // Everything else in the app is behind gates 3 and 4.
    Route::middleware(['org.approved', 'subscription.current'])->group(function () {

        /**
         * /app is a router, not a page: each role has a different home.
         *
         * A super admin lands on the platform console UNLESS they are acting as
         * a tenant, in which case they get that tenant's dashboard — which is
         * the whole point of act-as. A client portal login lands on the agents
         * working its engagement.
         *
         * The destinations arrive in Phase 6. See docs/migration/routes.md §5.
         */
        Route::get('/app', function (Request $request) {
            $user = $request->user();

            if ($user->isSuperAdmin() && ! $user->isActingAsOrganization()) {
                return redirect('/app/platform');
            }

            if ($user->role === UserRole::ClientViewer) {
                return redirect('/app/agents');
            }

            return redirect('/app/overview');
        })->name('app');
    });
});
