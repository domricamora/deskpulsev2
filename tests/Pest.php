<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
| RefreshDatabase is applied per test FILE rather than globally, because most
| feature tests here assert configuration or rendering and need no database at
| all — making them migrate would only slow the suite down. Add
| `uses(RefreshDatabase::class);` at the top of any file that touches data.
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A tenant. Approved and already set up by default, because that is what every
 * gate downstream expects and a test that wants a pending or brand-new one says
 * so.
 *
 * `onboarded_at` matters as much as `status`: without it RedirectFirstRun sends
 * every company admin to the setup wizard, and a test of some other page would
 * be asserting against the wizard instead.
 */
function org(array $attributes = []): \App\Models\Organization
{
    return \App\Models\Organization::create(
        array_merge([
            'name'         => 'Tenant',
            'status'       => 'approved',
            'onboarded_at' => '2024-01-01 00:00:00',
        ], $attributes)
    );
}

/**
 * Somebody in that tenant. The email is unique per call because `users.email`
 * is unique across every organization rather than within one, and several
 * tests create the same role twice.
 *
 * `welcomed_at` is stamped for the same reason `org()` stamps `onboarded_at`:
 * an unwelcomed member, HR admin, IT admin or client viewer is redirected to
 * the role guide by RedirectFirstRun, and an unwelcomed manager to the roster
 * wizard. Pass `['welcomed_at' => null]` to test that.
 */
function member(
    \App\Models\Organization $organization,
    \App\Enums\UserRole $role = \App\Enums\UserRole::Member,
    array $attributes = []
): \App\Models\User {
    return \App\Models\User::create(array_merge([
        'org_id'        => $organization->id,
        'name'          => $role->value,
        'email'         => $role->value . '-' . uniqid() . '@example.test',
        'password_hash' => bcrypt('secret'),
        'role'          => $role,
        'welcomed_at'   => '2024-01-01 00:00:00',
    ], $attributes));
}

/** Absolute path to the legacy application, which remains the behavioural reference. */
function legacy_path(string $rel = ''): string
{
    return __DIR__ . '/../server' . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}
