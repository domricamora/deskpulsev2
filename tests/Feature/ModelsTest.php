<?php

/**
 * Phase 4 — every model maps to a real table, and Laravel's conventions that this
 * schema breaks are configured correctly.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return list<class-string<\Illuminate\Database\Eloquent\Model>> */
function allModels(): array
{
    return collect(glob(base_path('app/Models/*.php')))
        ->map(fn ($f) => 'App\\Models\\' . basename($f, '.php'))
        ->filter(fn ($c) => class_exists($c))
        ->values()
        ->all();
}

test('every table has a model', function () {
    expect(allModels())->toHaveCount(37);
});

test('every model points at a table that exists', function () {
    foreach (allModels() as $class) {
        $model = new $class;

        expect(Schema::hasTable($model->getTable()))
            ->toBeTrue("{$class} maps to missing table {$model->getTable()}");
    }
});

test('no model expects an updated_at column the table does not have', function () {
    // Only wise_accounts has updated_at. Laravel's default timestamps would make
    // every insert elsewhere fail on an unknown column.
    foreach (allModels() as $class) {
        $model = new $class;
        $table = $model->getTable();

        if (! $model->usesTimestamps()) {
            continue;
        }

        $updated = $model->getUpdatedAtColumn();

        if ($updated !== null) {
            expect(Schema::hasColumn($table, $updated))
                ->toBeTrue("{$class} manages '{$updated}' but {$table} has no such column");
        }

        $created = $model->getCreatedAtColumn();

        if ($created !== null) {
            expect(Schema::hasColumn($table, $created))
                ->toBeTrue("{$class} manages '{$created}' but {$table} has no such column");
        }
    }
});

test('wise_accounts is the only model managing updated_at', function () {
    $managing = [];

    foreach (allModels() as $class) {
        $model = new $class;
        if ($model->usesTimestamps() && $model->getUpdatedAtColumn() !== null) {
            $managing[] = $model->getTable();
        }
    }

    expect($managing)->toBe(['wise_accounts']);
});

test('the work session model maps to the legacy sessions table', function () {
    // Named WorkSession because Laravel's own `sessions` concept is HTTP sessions.
    expect((new App\Models\WorkSession)->getTable())->toBe('sessions');
});

test('models that can be scoped to an organization expose the scope', function () {
    $scoped = 0;

    foreach (allModels() as $class) {
        if (method_exists($class, 'scopeForOrganization')) {
            $scoped++;
            // The scope must build without touching the database.
            expect($class::query()->forOrganization(1)->toSql())->toBeString();
        }
    }

    // 20 direct + 7 through user + 6 through session. organizations, agent_clients,
    // promo_codes and webhook_events are not tenant-scoped this way.
    expect($scoped)->toBe(33);
});

test('a stored role round-trips as an enum and answers capability checks', function () {
    $org = App\Models\Organization::create(['name' => 'Cast check']);

    $hr = App\Models\User::create([
        'org_id'        => $org->id,
        'name'          => 'HR',
        'email'         => 'hr@example.test',
        'password_hash' => bcrypt('secret'),
        'role'          => App\Enums\UserRole::HrManager,
    ]);

    $fresh = App\Models\User::find($hr->id);

    expect($fresh->role)->toBe(App\Enums\UserRole::HrManager)
        // The counter-intuitive pair, asserted through the model rather than the enum.
        ->and($fresh->hasCapability(App\Enums\Capability::SetPayRate))->toBeTrue()
        ->and($fresh->hasCapability(App\Enums\Capability::ViewRates))->toBeFalse()
        ->and($fresh->hasCapability(App\Enums\Capability::Screenshots))->toBeFalse()
        ->and($fresh->isSuperAdmin())->toBeFalse()
        ->and($fresh->isStaff())->toBeTrue();

    // And the raw column still holds the legacy string value.
    expect(DB::table('users')->where('id', $hr->id)->value('role'))->toBe('hr_manager');
});
