<?php

/**
 * Phase 3 gate — the migrations must rebuild the legacy schema exactly.
 *
 * The authoritative check is `php tools/schema_diff.php`, which compares a
 * migration-built database against the live one column by column. That needs a
 * live database, so it cannot run in CI; this test asserts the shape that CI
 * *can* verify, and will fail loudly if a migration is dropped or a table renamed.
 *
 * docs/migration/database.md
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The 37 tables that exist in the live schema. */
function deskpulseTables(): array
{
    return [
        'activity_samples', 'agent_clients', 'clients', 'contract_members', 'contracts',
        'devices', 'email_outbox', 'idle_periods', 'invoices', 'leave_entitlements',
        'leave_requests', 'leave_types', 'notice_reads', 'notices', 'organizations',
        'password_resets', 'pay_adjustments', 'payment_claims', 'payments', 'payslips',
        'process_snapshots', 'projects', 'promo_codes', 'promo_redemptions',
        'remote_input_events', 'remote_sessions', 'screenshots', 'sessions',
        'share_links', 'tasks', 'team_members', 'teams', 'user_identities', 'users',
        'webhook_events', 'window_events', 'wise_accounts',
    ];
}

test('every table from the live schema is created', function () {
    foreach (deskpulseTables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: $table");
    }

    expect(deskpulseTables())->toHaveCount(37);
});

test('the migrations add no tables the legacy schema does not have', function () {
    // Laravel's own bookkeeping is expected; anything else is scope creep.
    $allowed = array_merge(deskpulseTables(), ['migrations']);

    $actual = array_map(
        fn ($row) => array_values((array) $row)[0],
        DB::select('SHOW TABLES')
    );

    expect(array_values(array_diff($actual, $allowed)))->toBe([]);
});

test('primary keys are signed, matching the legacy schema', function () {
    // Laravel's increments()/bigIncrements() would make these UNSIGNED, which makes
    // every signed foreign key incompatible (MySQL error 3780).
    foreach (['organizations', 'users', 'sessions', 'clients'] as $table) {
        $col = collect(DB::select("SHOW COLUMNS FROM `$table` WHERE Field = 'id'"))->first();

        expect(strtolower($col->Type))->not->toContain('unsigned');
        expect(strtolower($col->Extra))->toContain('auto_increment');
    }
});

test('money columns keep their legacy types', function () {
    // Decision D1: migrate as-is so Phase 11 can prove report parity. Converting to
    // decimal or cents is a separate, approved change.
    $expected = [
        'users'         => ['pay_rate' => 'double', 'bill_rate' => 'double'],
        'clients'       => ['bill_rate' => 'double'],
        'contracts'     => ['bill_rate' => 'double'],
        'organizations' => ['monthly_fee' => 'double', 'price_individual' => 'decimal(10,2)'],
        'payments'      => ['amount_cents' => 'int'],
        'payslips'      => ['gross' => 'decimal(12,2)', 'net' => 'decimal(12,2)'],
    ];

    foreach ($expected as $table => $cols) {
        foreach ($cols as $column => $type) {
            $actual = collect(DB::select("SHOW COLUMNS FROM `$table` WHERE Field = ?", [$column]))->first();
            expect(strtolower($actual->Type))->toBe($type);
        }
    }
});

test('the monitoring tables cascade from their session', function () {
    // Deleting a session must take its samples with it, as it does today.
    foreach (['activity_samples', 'window_events', 'process_snapshots', 'idle_periods', 'screenshots'] as $table) {
        $fk = collect(DB::select(
            'SELECT r.DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS r
              WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME = ?
                AND r.REFERENCED_TABLE_NAME = ?',
            [$table, 'sessions']
        ))->first();

        expect($fk)->not->toBeNull("$table has no foreign key to sessions");
        expect(strtoupper($fk->DELETE_RULE))->toBe('CASCADE');
    }
});

test('sessions reach a tenant only through users', function () {
    // 17 tables have no org_id; tenancy is transitive (decision D6). If someone
    // later denormalises org_id onto sessions, this test should be updated
    // deliberately rather than silently.
    expect(Schema::hasColumn('sessions', 'org_id'))->toBeFalse()
        ->and(Schema::hasColumn('sessions', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'org_id'))->toBeTrue();
});
