<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\ActAsController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PendingController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Dashboard\ApprovalController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\ClientController;
use App\Http\Controllers\Dashboard\EfficiencyController;
use App\Http\Controllers\Dashboard\LiveController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\PayrollController;
use App\Http\Controllers\Dashboard\ScreenshotController;
use App\Http\Controllers\Dashboard\SessionController;
use App\Http\Controllers\Dashboard\TaskController;
use App\Http\Controllers\Dashboard\TimesheetController;
use App\Http\Controllers\Dashboard\TeamController;
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

        /* ── Core dashboard (Phase 6) ────────────────────────────────────── */

        // Login only. What each role sees is decided by Visibility::userIds()
        // and by capability checks inside the views, not at the route — see
        // docs/migration/authorization.md §2.
        Route::get('/app/overview', [OverviewController::class, 'show'])->name('overview');

        // Also login only. Whether the page is editable is decided by ROLE
        // inside the controller: tasks belong to the person doing the work, and
        // no capability grants editing someone else's.
        Route::get('/app/tasks', [TaskController::class, 'show'])->name('tasks');
        Route::post('/app/tasks', [TaskController::class, 'update']);

        // view_all opens the page; users_manage, profiles_manage and
        // set_pay_rate decide what may be changed on it, inside the controller.
        Route::middleware('cap:view_all')->group(function () {
            Route::get('/app/team', [TeamController::class, 'show'])->name('team');
            Route::post('/app/team', [TeamController::class, 'update']);
        });

        // Clients and their contracts. No read-only variant: a role that can
        // open this page can change everything on it.
        Route::middleware('cap:clients_manage')->group(function () {
            Route::get('/app/clients', [ClientController::class, 'show'])->name('clients');
            Route::post('/app/clients', [ClientController::class, 'update']);
        });

        /* ── Time and reports (Phase 11) ─────────────────────────────────── */

        // Login only, scoped by Visibility. Timesheets is the one page that
        // shows pending and rejected entries — it is where you look to find out
        // what happened to one you filed.
        Route::get('/app/timesheets', [TimesheetController::class, 'show'])->name('timesheets');
        Route::post('/app/timesheets', [TimesheetController::class, 'store']);
        Route::get('/app/export.csv', [TimesheetController::class, 'export'])->name('export.csv');

        // Ownership is checked in the handler, not here: "anyone whose scope
        // includes this session's owner" is not a capability.
        Route::get('/app/session/{id}', [SessionController::class, 'show'])
            ->whereNumber('id')
            ->name('session');

        // Two queues, two different holders. approve_time decides whether a
        // manual entry counts; approve_overtime decides whether the premium
        // gets paid. A team manager holds the first and not the second.
        Route::middleware('cap:approve_time')->group(function () {
            Route::get('/app/approvals', [ApprovalController::class, 'time'])->name('approvals');
            Route::post('/app/approvals/{id}', [ApprovalController::class, 'decideTime'])->whereNumber('id');
        });

        Route::middleware('cap:approve_overtime')->group(function () {
            Route::get('/app/overtime', [ApprovalController::class, 'overtime'])->name('overtime');
            Route::post('/app/overtime/{id}', [ApprovalController::class, 'decideOvertime'])->whereNumber('id');
        });

        // The only /app/reports/* route there has ever been. §51 describes a
        // bare /app/reports; it does not exist.
        Route::middleware('cap:reports')->group(function () {
            Route::get('/app/reports/efficiency', [EfficiencyController::class, 'show'])->name('efficiency');
            Route::get('/app/reports/efficiency.csv', [EfficiencyController::class, 'export']);
        });

        // The live board, and the JSON it polls every 15 seconds. The data
        // route keeps its existing path — live.js targets it, and §51's
        // /api/v1/live rename is what the replica constraint rules out.
        Route::middleware('cap:live')->group(function () {
            Route::get('/app/live', [LiveController::class, 'index'])->name('live');
            Route::get('/app/live/data', [LiveController::class, 'data'])->name('live.data');
        });

        /* ── Payroll (Phase 13) ──────────────────────────────────────────── */

        // Every money figure on this page comes from PayRun, which the payslip
        // PDF and the salary run also read, so the three cannot disagree.
        Route::middleware('cap:payroll')->group(function () {
            Route::get('/app/payroll', [PayrollController::class, 'show'])->name('payroll');
        });

        /* ── Billing (Phase 12) ──────────────────────────────────────────── */

        // What clients are charged — never what workers cost. A client portal
        // holds this capability and is locked to its own record by
        // Visibility::clientFilter(), so one route serves the agency's whole
        // book and a customer's single invoice.
        Route::middleware('cap:billing')->group(function () {
            Route::get('/app/billing', [BillingController::class, 'show'])->name('billing');
            Route::get('/app/billing.csv', [BillingController::class, 'export']);
        });

        /* ── Monitoring (Phase 9) ────────────────────────────────────────── */

        // The gallery needs the capability; an individual image deliberately
        // does not. /app/overview shows recent screenshots to a member and to
        // an HR manager, neither of whom may open this page, so the image route
        // authorizes on VISIBILITY instead — see ScreenshotController.
        Route::middleware('cap:screenshots')->group(function () {
            Route::get('/app/screenshots', [ScreenshotController::class, 'index'])
                ->name('screenshots');
        });

        Route::get('/app/screenshots/{id}/image', [ScreenshotController::class, 'image'])
            ->whereNumber('id')
            ->name('screenshots.image');
    });
});
