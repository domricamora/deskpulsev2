<?php

/**
 * Phase 13 — the spreadsheet readers and the payroll import.
 *
 * Two properties carry this subsystem. The readers must tolerate what real
 * payroll exports actually look like — banners above the table, headers
 * spelled differently every month, `#N/A` where a lookup broke. And the import
 * must be idempotent, because the alternative is doubling a month's payroll.
 *
 * @see docs/migration/payroll.md §5
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Import\PayrollIngest;
use App\Services\Import\PayrollSheet;
use App\Support\Sheet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A real .xlsx, built with the same ZipArchive the reader uses to open it. */
function workbook(array $rows, string $sheetName = 'Payroll'): string
{
    $path = tempnam(sys_get_temp_dir(), 'dp') . '.xlsx';

    $cells = '';
    foreach ($rows as $number => $row) {
        $cells .= '<row r="' . $number . '">';
        foreach ($row as $letter => $value) {
            // t="inlineStr" keeps the fixture readable — no shared string table.
            $cells .= is_numeric($value)
                ? '<c r="' . $letter . $number . '"><v>' . $value . '</v></c>'
                : '<c r="' . $letter . $number . '" t="inlineStr"><is><t>'
                    . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>';
        }
        $cells .= '</row>';
    }

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0"?><worksheet><sheetData>' . $cells . '</sheetData></worksheet>');
    $zip->close();

    return $path;
}

/** Excel's serial for a date, which is what a date cell actually contains. */
function serial(string $date): int
{
    return (int) (strtotime($date . ' 00:00:00 UTC') / 86400) + 25569;
}

/**
 * A payroll sheet with a title banner above the header, as real ones have.
 *
 * @param  list<array<string, mixed>>  $people
 */
function payrollFile(array $people): string
{
    $rows = [
        1 => ['A' => 'ACME OUTSOURCING — PAYROLL'],       // a banner
        2 => [],                                           // a blank row
        3 => ['A' => 'VT ID', 'B' => 'Contract Name', 'C' => 'Client', 'D' => 'Date',
            'E' => 'Adj Credited Hrs', 'F' => 'Payroll Rate', 'G' => 'NET PAYROLL', 'H' => 'Role Name'],
    ];

    $number = 4;

    foreach ($people as $person) {
        $rows[$number++] = [
            'A' => $person['vt'] ?? '1001',
            'B' => $person['name'] ?? 'Ana Cruz',
            'C' => $person['client'] ?? 'Acme Corp',
            'D' => isset($person['date']) ? serial($person['date']) : serial('2026-09-07'),
            'E' => $person['hours'] ?? 8,
            'F' => $person['rate'] ?? 6.5,
            'G' => 100,
            'H' => $person['role'] ?? 'Virtual Assistant',
        ];
    }

    return workbook($rows);
}

/* ── The readers ─────────────────────────────────────────────────────────── */

test('rows are keyed by spreadsheet row number, not by position', function () {
    // A blank row must not shift the numbering — an importer reports "row 31"
    // and somebody goes and looks at row 31.
    $path = workbook([1 => ['A' => 'first'], 5 => ['A' => 'fifth']]);

    $rows = Sheet::xlsxRows($path);

    expect(array_keys($rows))->toBe([1, 5])
        ->and($rows[5]['A'])->toBe('fifth');
});

test('a csv reads into the same shape as a workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'dp') . '.csv';
    file_put_contents($path, "Name,Hours\nAna,8\n");

    $rows = Sheet::rows($path);

    expect($rows[1])->toBe(['A' => 'Name', 'B' => 'Hours'])
        ->and($rows[2])->toBe(['A' => 'Ana', 'B' => '8']);
});

test('the csv delimiter is sniffed and a BOM is stripped', function () {
    $path = tempnam(sys_get_temp_dir(), 'dp') . '.csv';
    file_put_contents($path, "\xEF\xBB\xBFName;Hours\nAna;8\n");

    expect(Sheet::rows($path)[1]['A'])->toBe('Name');
});

test('headers match however they are spelled', function () {
    foreach (['Wise Recipient ID', 'wise_recipient_id', 'WISE-RECIPIENT-ID', 'Wise  Recipient  Id'] as $spelling) {
        expect(Sheet::normalizeLabel($spelling))->toBe('wise recipient id', $spelling);
    }
});

test('the header row is found below a banner', function () {
    $rows = [
        1 => ['A' => 'PAYROLL FOR SEPTEMBER'],
        2 => [],
        3 => ['A' => 'VT ID', 'B' => 'Contract Name'],
        4 => ['A' => '1001', 'B' => 'Ana'],
    ];

    [$number, $map] = Sheet::findHeader($rows, ['VT ID', 'Contract Name']);

    expect($number)->toBe(3)
        ->and($map['vt id'])->toBe('A');
});

test('a header beyond the scan limit is not found', function () {
    // The 60-row bound stops a runaway scan over a huge sheet of data that
    // happens to contain the words.
    $rows = [];

    for ($i = 1; $i <= 70; $i++) {
        $rows[$i] = ['A' => 'filler'];
    }

    $rows[65] = ['A' => 'VT ID', 'B' => 'Contract Name'];

    expect(Sheet::findHeader($rows, ['VT ID', 'Contract Name'])[0])->toBe(0);
});

test('an excel date serial becomes a date, and nonsense does not', function () {
    expect(Sheet::serialToDate(serial('2026-09-07')))->toBe('2026-09-07')
        ->and(Sheet::serialToDate('#N/A'))->toBeNull()
        ->and(Sheet::serialToDate(3))->toBeNull();
});

test('column letters carry past Z', function () {
    expect(Sheet::columnLetter(0))->toBe('A')
        ->and(Sheet::columnLetter(25))->toBe('Z')
        ->and(Sheet::columnLetter(26))->toBe('AA');
});

/* ── Parsing a payroll file ──────────────────────────────────────────────── */

test('a payroll sheet parses past its banner', function () {
    $parsed = app(PayrollSheet::class)->parse(payrollFile([
        ['vt' => '1001', 'name' => 'Ana Cruz', 'hours' => 7.5],
    ]));

    expect($parsed['error'])->toBeNull()
        ->and($parsed['rows'])->toHaveCount(1)
        ->and($parsed['rows'][0]['vt_id'])->toBe('1001')
        ->and($parsed['rows'][0]['active_s'])->toBe(27000);      // 7.5 h
});

test('bad rows are counted with a reason rather than throwing', function () {
    $parsed = app(PayrollSheet::class)->parse(payrollFile([
        ['vt' => '1001', 'hours' => 8],
        ['vt' => '#N/A', 'hours' => 8],
        ['vt' => '1003', 'hours' => 0],
    ]));

    expect($parsed['rows'])->toHaveCount(1)
        ->and($parsed['skipped']['count'])->toBe(2)
        ->and($parsed['skipped']['reasons'])->toHaveKey('missing or invalid VT ID')
        ->and($parsed['skipped']['reasons'])->toHaveKey('zero credited hours');
});

test('a file with no payroll sheet reports why instead of failing', function () {
    $path = workbook([1 => ['A' => 'Something'], 2 => ['A' => 'else']]);

    $parsed = app(PayrollSheet::class)->parse($path);

    expect($parsed['rows'])->toBe([])
        ->and($parsed['error'])->toContain('Could not find a payroll data sheet');
});

/* ── Ingesting ───────────────────────────────────────────────────────────── */

test('a preview writes nothing and reports what it would do', function () {
    $organization = org();
    $parsed = app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'name' => 'Ana Cruz']]));

    $summary = app(PayrollIngest::class)->ingest($organization->id, $parsed['rows'], commit: false);

    expect($summary['emps_new'])->toBe(1)
        ->and($summary['clients_new'])->toBe(1)
        ->and($summary['sessions_new'])->toBe(1)
        ->and(User::query()->where('org_id', $organization->id)->count())->toBe(0)
        ->and(WorkSession::query()->count())->toBe(0);
});

test('a commit creates the client, the employee and the time entry', function () {
    $organization = org();
    $parsed = app(PayrollSheet::class)->parse(payrollFile([
        ['vt' => '1001', 'name' => 'Ana Cruz', 'client' => 'Acme Corp', 'rate' => 6.5, 'hours' => 8],
    ]));

    app(PayrollIngest::class)->ingest($organization->id, $parsed['rows'], commit: true);

    $employee = User::query()->where('org_id', $organization->id)->first();
    $session = WorkSession::query()->first();

    expect($employee->name)->toBe('Ana Cruz')
        ->and($employee->external_ref)->toBe('1001')
        ->and((float) $employee->pay_rate)->toBe(6.5)
        ->and($employee->email)->toContain('@import.deskpulse.local')
        ->and(Client::query()->where('org_id', $organization->id)->value('name'))->toBe('Acme Corp')
        ->and($session->source)->toBe('import')
        ->and($session->approval_status)->toBe('approved')
        ->and($session->active_s)->toBe(28800);
});

test('re-importing the same file changes nothing', function () {
    // The property the whole subsystem rests on. Without it, running an import
    // twice doubles everybody's month.
    $organization = org();
    $rows = app(PayrollSheet::class)->parse(payrollFile([
        ['vt' => '1001', 'name' => 'Ana Cruz'],
        ['vt' => '1002', 'name' => 'Ben Santos'],
    ]))['rows'];

    app(PayrollIngest::class)->ingest($organization->id, $rows, commit: true);
    $second = app(PayrollIngest::class)->ingest($organization->id, $rows, commit: true);

    expect($second['emps_new'])->toBe(0)
        ->and($second['emps_updated'])->toBe(2)
        ->and($second['sessions_new'])->toBe(0)
        ->and($second['sessions_updated'])->toBe(2)
        ->and(User::query()->where('org_id', $organization->id)->count())->toBe(2)
        ->and(WorkSession::query()->count())->toBe(2);
});

test('a corrected re-import updates the hours in place', function () {
    $organization = org();

    $first = app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'hours' => 8]]))['rows'];
    app(PayrollIngest::class)->ingest($organization->id, $first, commit: true);

    $corrected = app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'hours' => 6]]))['rows'];
    app(PayrollIngest::class)->ingest($organization->id, $corrected, commit: true);

    expect(WorkSession::query()->count())->toBe(1)
        ->and(WorkSession::query()->value('active_s'))->toBe(21600);
});

test('the same day for two different clients is two entries', function () {
    // The session key includes the client, so one person splitting a day
    // between two customers keeps both rows.
    $organization = org();

    $rows = app(PayrollSheet::class)->parse(payrollFile([
        ['vt' => '1001', 'client' => 'Acme Corp', 'hours' => 4],
        ['vt' => '1001', 'client' => 'Globex', 'hours' => 4],
    ]))['rows'];

    app(PayrollIngest::class)->ingest($organization->id, $rows, commit: true);

    expect(WorkSession::query()->count())->toBe(2);
});

test('a rate change is surfaced before it is committed', function () {
    $organization = org();

    app(PayrollIngest::class)->ingest(
        $organization->id,
        app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'rate' => 6.5]]))['rows'],
        commit: true
    );

    $preview = app(PayrollIngest::class)->ingest(
        $organization->id,
        app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'rate' => 7.25]]))['rows'],
        commit: false
    );

    expect($preview['rate_changes'])->toHaveCount(1)
        ->and($preview['rate_changes'][0]['from'])->toBe(6.5)
        ->and($preview['rate_changes'][0]['to'])->toBe(7.25);
});

test('two tenants importing the same VT ID do not collide', function () {
    // The synthetic address is scoped by organization, so the same employee
    // reference in two companies is two different people.
    $acme = org(['name' => 'Acme']);
    $globex = org(['name' => 'Globex']);

    $rows = app(PayrollSheet::class)->parse(payrollFile([['vt' => '1001', 'name' => 'Ana Cruz']]))['rows'];

    app(PayrollIngest::class)->ingest($acme->id, $rows, commit: true);
    $second = app(PayrollIngest::class)->ingest($globex->id, $rows, commit: true);

    expect($second['errors'])->toBe([])
        ->and($second['emps_new'])->toBe(1)
        ->and(User::query()->where('org_id', $globex->id)->count())->toBe(1);
});

/* ── The page ────────────────────────────────────────────────────────────── */

test('import needs its capability', function () {
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/import')->assertOk();
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/import')->assertOk();
    $this->actingAs(member(org(), UserRole::Manager))->get('/app/import')->assertRedirect();
});

test('the blank template carries exactly the headers the parser accepts', function () {
    $response = $this->actingAs(member(org(), UserRole::HrManager))
        ->get('/app/import/template/payroll')
        ->assertOk();

    $csv = $response->streamedContent();

    foreach (array_column(config('imports.payroll.columns'), 'name') as $header) {
        expect($csv)->toContain($header);
    }
});

test('an unknown template key is a 404', function () {
    $this->actingAs(member(org(), UserRole::HrManager))
        ->get('/app/import/template/nonsense')
        ->assertNotFound();
});
