<?php

/**
 * Phase 13 — Wise payout details and the salary run.
 *
 * The salary-run CSV is not a report: it is the file uploaded to Wise to
 * actually move money. Everything here is about it containing the right
 * people, in the right format, with the right amounts.
 *
 * @see docs/migration/payroll.md §3
 */

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WiseAccount;
use App\Models\WorkSession;
use App\Services\Payroll\WisePayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A Wise export as a CSV, which reads into the same shape as the workbook. */
function payoutFile(array $rows, array $header = null): string
{
    $header ??= ['Wise Recipient ID', 'Wise Name', 'EMAIL', 'Wise account',
        'from Currency', 'to Currency', 'Source', 'Type', 'VT ID'];

    $path = tempnam(sys_get_temp_dir(), 'dp') . '.csv';
    $handle = fopen($path, 'w');

    fputcsv($handle, $header);

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}

function payoutRow(array $overrides = []): array
{
    return array_values(array_merge([
        'recipient_id' => '', 'name' => 'Ana Cruz', 'email' => '', 'account' => 'BPI ·· 1593',
        'from' => 'USD', 'to' => 'PHP', 'source' => 'source', 'type' => 'PERSON', 'vt' => '',
    ], $overrides));
}

/* ── Matching ────────────────────────────────────────────────────────────── */

test('rows match on recipient id, VT ID, email then name — in that order', function () {
    $organization = org();

    $byRecipient = member($organization, UserRole::Member, ['name' => 'By Recipient', 'wise_id' => 'wise-123']);
    $byReference = member($organization, UserRole::Member, ['name' => 'By Reference', 'external_ref' => 'VT-9']);
    $byEmail = member($organization, UserRole::Member, ['name' => 'By Email', 'email' => 'match@example.test']);
    $byName = member($organization, UserRole::Member, ['name' => 'Exactly This Name']);

    $path = payoutFile([
        payoutRow(['recipient_id' => 'wise-123', 'name' => 'Someone Else Entirely']),
        payoutRow(['vt' => 'VT-9', 'name' => 'Also Not Their Name']),
        payoutRow(['email' => 'match@example.test', 'name' => 'Nor This']),
        payoutRow(['name' => 'Exactly This Name']),
    ]);

    $parsed = app(WisePayouts::class)->parse($path);
    $summary = app(WisePayouts::class)->ingest($organization->id, $parsed['rows'], commit: false);

    expect($summary['matched'])->toBe(4)
        ->and(array_column($summary['matches'], 'how'))
        ->toBe(['Wise Recipient ID', 'VT ID', 'email', 'name'])
        ->and(array_column($summary['matches'], 'name'))
        ->toBe(['By Recipient', 'By Reference', 'By Email', 'Exactly This Name']);
});

test('an unmatched row is reported and creates nobody', function () {
    // A payout sheet says where to send money. It is not a roster.
    $organization = org();
    $before = User::query()->where('org_id', $organization->id)->count();

    $parsed = app(WisePayouts::class)->parse(payoutFile([payoutRow(['name' => 'Stranger Danger'])]));
    $summary = app(WisePayouts::class)->ingest($organization->id, $parsed['rows'], commit: true);

    expect($summary['unmatched'])->toBe(1)
        ->and($summary['unmatched_names'])->toContain('Stranger Danger')
        ->and(User::query()->where('org_id', $organization->id)->count())->toBe($before);
});

test('a client portal login is never a payout target', function () {
    $organization = org();
    member($organization, UserRole::ClientViewer, ['name' => 'Customer Contact']);

    $parsed = app(WisePayouts::class)->parse(payoutFile([payoutRow(['name' => 'Customer Contact'])]));
    $summary = app(WisePayouts::class)->ingest($organization->id, $parsed['rows'], commit: false);

    expect($summary['matched'])->toBe(0)
        ->and($summary['unmatched'])->toBe(1);
});

test('a commit saves the account and mirrors it onto the person', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);

    $parsed = app(WisePayouts::class)->parse(payoutFile([
        payoutRow(['recipient_id' => 'wise-abc', 'name' => 'Ana Cruz', 'from' => 'USD', 'to' => 'PHP']),
    ]));

    app(WisePayouts::class)->ingest($organization->id, $parsed['rows'], commit: true);

    $account = WiseAccount::query()->where('user_id', $worker->id)->first();

    expect($account->recipient_id)->toBe('wise-abc')
        ->and($account->source_currency)->toBe('USD')
        ->and($account->target_currency)->toBe('PHP')
        ->and($worker->fresh()->wise_id)->toBe('wise-abc');
});

/* ── What real exports contain ───────────────────────────────────────────── */

test('broken lookup cells are treated as empty, not as values', function () {
    $organization = org();
    member($organization, UserRole::Member, ['name' => 'Ana Cruz']);

    $parsed = app(WisePayouts::class)->parse(payoutFile([
        payoutRow(['recipient_id' => '#REF!', 'name' => 'Ana Cruz', 'email' => '#N/A']),
    ]));

    expect($parsed['rows'][0]['recipient_id'])->toBe('')
        ->and($parsed['rows'][0]['email'])->toBe('');
});

test('a row with no usable name is skipped with a reason', function () {
    $parsed = app(WisePayouts::class)->parse(payoutFile([
        payoutRow(['name' => '#REF!']),
        payoutRow(['name' => 'Ana Cruz']),
    ]));

    expect($parsed['rows'])->toHaveCount(1)
        ->and($parsed['skipped']['count'])->toBe(1)
        ->and($parsed['skipped']['reasons'])->toHaveKey('Broken or empty "Wise Name"');
});

test('a header block repeated inside the data is ignored', function () {
    // Two exports pasted together. Not an error, just not a row.
    $parsed = app(WisePayouts::class)->parse(payoutFile([
        payoutRow(['name' => 'Ana Cruz']),
        ['', 'Wise Name', '', '', '', '', '', '', ''],
        payoutRow(['name' => 'Ben Santos']),
    ]));

    expect($parsed['rows'])->toHaveCount(2)
        ->and($parsed['skipped']['count'])->toBe(0);
});

/* ── The batch file ──────────────────────────────────────────────────────── */

test('the batch carries Wise column order, blank ninth column and all', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz', 'pay_type' => 'hourly', 'pay_rate' => 10.0]);

    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => gmdate('Y-m-d') . ' 09:00:00',
        'ended_at' => gmdate('Y-m-d') . ' 17:00:00', 'active_s' => 3600, 'inactive_s' => 0,
        'source' => 'agent', 'approval_status' => 'approved',
    ]);

    WiseAccount::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'recipient_id' => 'wise-abc',
        'account_holder' => 'ANA CRUZ', 'email' => 'ana@example.test', 'account_summary' => 'BPI ·· 1593',
        'source_currency' => 'USD', 'target_currency' => 'PHP', 'recipient_type' => 'PERSON',
        'source_label' => 'source', 'active' => 1,
    ]);

    $csv = $this->actingAs($admin)->get('/app/salary-run.csv?period=month')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('"Wise Recipient ID","Wise Name",EMAIL,"Wise account","from Currency","to Currency",Source,Amount,,Type')
        ->and($lines[1])->toContain('wise-abc')
        ->and($lines[1])->toContain('ANA CRUZ')
        // Amount filled, ninth column empty.
        ->and(str_getcsv($lines[1])[7])->toBe('10.00')
        ->and(str_getcsv($lines[1])[8])->toBe('');
});

test('a synthetic import address is blanked rather than exported', function () {
    // Wise would bounce on it — it is not a real mailbox.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Imported Person', 'pay_type' => 'hourly', 'pay_rate' => 10.0,
        'email' => 'vt1001.org9@import.deskpulse.local',
    ]);

    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => gmdate('Y-m-d') . ' 09:00:00',
        'ended_at' => gmdate('Y-m-d') . ' 17:00:00', 'active_s' => 3600, 'inactive_s' => 0,
        'source' => 'agent', 'approval_status' => 'approved',
    ]);

    WiseAccount::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'recipient_id' => 'wise-x',
        'account_holder' => 'Imported Person', 'email' => 'vt1001.org9@import.deskpulse.local',
        'source_currency' => 'USD', 'target_currency' => 'USD', 'recipient_type' => 'PERSON', 'active' => 1,
    ]);

    $csv = $this->actingAs($admin)->get('/app/salary-run.csv?period=month')->assertOk()->streamedContent();

    expect($csv)->toContain('Imported Person')
        ->and($csv)->not->toContain('@import.deskpulse.local');
});

test('somebody without payout details is left out of the batch and named on the page', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'name' => 'No Account Person', 'pay_type' => 'hourly', 'pay_rate' => 10.0,
    ]);

    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => gmdate('Y-m-d') . ' 09:00:00',
        'ended_at' => gmdate('Y-m-d') . ' 17:00:00', 'active_s' => 3600, 'inactive_s' => 0,
        'source' => 'agent', 'approval_status' => 'approved',
    ]);

    $csv = $this->actingAs($admin)->get('/app/salary-run.csv?period=month')->assertOk()->streamedContent();

    expect($csv)->not->toContain('No Account Person');

    // …but the page says so, rather than silently dropping them.
    $this->actingAs($admin)->get('/app/salary-run?period=month')
        ->assertOk()
        ->assertSee('These people are not in the export')
        ->assertSee('No Account Person');
});

test('an inactive account is not paid', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Switched Off', 'pay_type' => 'hourly', 'pay_rate' => 10.0,
    ]);

    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => gmdate('Y-m-d') . ' 09:00:00',
        'ended_at' => gmdate('Y-m-d') . ' 17:00:00', 'active_s' => 3600, 'inactive_s' => 0,
        'source' => 'agent', 'approval_status' => 'approved',
    ]);

    WiseAccount::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'recipient_id' => 'wise-off',
        'account_holder' => 'Switched Off', 'source_currency' => 'USD', 'target_currency' => 'USD',
        'recipient_type' => 'PERSON', 'active' => 0,
    ]);

    expect($this->actingAs($admin)->get('/app/salary-run.csv?period=month')->streamedContent())
        ->not->toContain('wise-off');
});

test('the payout pages need wise_manage', function () {
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/wise')->assertOk();
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/salary-run')->assertOk();
    $this->actingAs(member(org(), UserRole::Manager))->get('/app/wise')->assertRedirect();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/salary-run')->assertRedirect();
});

test('payout details cannot be saved against another tenant', function () {
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ClientAdmin))
        ->post('/app/wise', [
            'action' => 'save', 'user_id' => $outsider->id,
            'account_holder' => 'Hijack', 'recipient_id' => 'wise-evil',
        ]);

    expect(WiseAccount::query()->count())->toBe(0);
});
