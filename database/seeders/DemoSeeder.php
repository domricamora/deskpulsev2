<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The demo organization, one account per role.
 *
 * A trimmed port of the legacy seed_demo_data(): enough to sign in as every
 * role and to run the agent harness against, without the generated month of
 * fake sessions, screenshots and payroll the legacy seeder produces.
 *
 * `ava@demo.test / Demo12345` is not an arbitrary choice — `tools/test_webhook.py`
 * is invoked with exactly that account in CI, and that harness is frozen. The
 * credentials here must keep matching it.
 *
 * ## Additive only
 *
 * Nothing here overwrites a row that already exists. An earlier version used
 * updateOrCreate() and, run against a development database that already had a
 * demo organization, rewrote six people's names and reset organization
 * settings — the seeder matched rows it had not created.
 *
 * firstOrCreate() instead, and the organization's attributes are applied only
 * when it is being created. Re-running is then a no-op, which is what a seeder
 * pointed at a database someone is using needs to be.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo12345';

    /** @var array<string, array{0: UserRole, 1: string}> */
    private const PEOPLE = [
        'admin@demo.test'   => [UserRole::ClientAdmin, 'Dana Admin'],
        'manager@demo.test' => [UserRole::Manager, 'Morgan Manager'],
        'hr@demo.test'      => [UserRole::HrManager, 'Harper HR'],
        'it@demo.test'      => [UserRole::ItAdmin, 'Ira IT'],
        'ava@demo.test'     => [UserRole::Member, 'Ava Member'],
        'ben@demo.test'     => [UserRole::Member, 'Ben Member'],
    ];

    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['name' => 'DeskPulse Demo Co'],
            [
                'status'         => 'approved',
                'plan_type'      => 'organization',
                // Past every gate: an unapproved or lapsed organization would
                // bounce the harness before it reached an endpoint.
                'billing_status' => 'active',
                'report_tz'      => 'UTC',
                'week_start'     => 1,
            ]
        );

        foreach (self::PEOPLE as $email => [$role, $name]) {
            User::firstOrCreate(
                ['email' => $email],
                [
                    'org_id'        => $organization->id,
                    'name'          => $name,
                    'password_hash' => Hash::make(self::PASSWORD),
                    'role'          => $role,
                    'currency'      => 'USD',
                    // Never forced to change it — the harness signs in
                    // non-interactively and a forced change would block it.
                    'must_change_password' => 0,
                ]
            );
        }

        Client::firstOrCreate(
            ['org_id' => $organization->id, 'name' => 'Northwind Trading'],
            ['contact_email' => 'ops@northwind.test', 'bill_rate' => 55.0, 'currency' => 'USD']
        );
    }
}
