<?php

/*
|--------------------------------------------------------------------------
| Import formats
|--------------------------------------------------------------------------
|
| One registry driving THREE things: header matching in the parsers, the
| on-screen naming guide, and the downloadable blank template. Adding a column
| here makes it appear in all three, which is what stops the guide drifting
| away from what the parser actually accepts.
|
| Matching is loose anyway — case, spaces, underscores, hyphens and
| punctuation are all ignored — so an alias is only needed for a genuinely
| different word.
|
| See docs/migration/payroll.md §5.
*/

return [

    'payroll' => [
        'label'     => 'Payroll timesheet',
        'formats'   => '.xlsx',
        'intro'     => 'One row per employee, per day, per client. The header row does not have to be the first row — DeskPulse scans the first 60 rows of every sheet and uses the first one that contains the required headers, so title banners and blank rows above the table are fine.',
        'match'     => 'Employees are matched on VT ID, so re-importing the same period updates the same people instead of creating duplicates.',

        'columns' => [
            [
                'name'       => 'VT ID',
                'aliases'    => ['employee id', 'staff id', 'vtid'],
                'required'   => true,
                'example'    => '1042',
                'desc'       => 'Stable employee reference. Rows without one (or with #N/A) are skipped.',
            ],
            [
                'name'       => 'Contract Name',
                'aliases'    => ['employee name', 'name'],
                'required'   => true,
                'example'    => 'Dominique Cuaton Ricamora',
                'desc'       => 'Employee full name. Used when Wise Name is blank.',
            ],
            [
                'name'       => 'Wise Name',
                'aliases'    => ['payee name'],
                'required'   => false,
                'example'    => 'Dominique Cuaton Ricamora',
                'desc'       => 'Preferred display name; also stored for payouts.',
            ],
            [
                'name'       => 'Client',
                'aliases'    => ['client name', 'customer'],
                'required'   => false,
                'example'    => 'Acme Corp',
                'desc'       => 'Creates the client in your org if it does not exist yet.',
            ],
            [
                'name'       => 'Client ID',
                'aliases'    => ['clientid'],
                'required'   => false,
                'example'    => 'C-208',
                'desc'       => 'Recorded in the client notes.',
            ],
            [
                'name'       => 'Industry',
                'aliases'    => [],
                'required'   => false,
                'example'    => 'Logistics',
                'desc'       => 'Recorded in the client notes.',
            ],
            [
                'name'       => 'Role Name',
                'aliases'    => ['role', 'job title'],
                'required'   => false,
                'example'    => 'Virtual Assistant',
                'desc'       => 'Saved as the employee job title.',
            ],
            [
                'name'       => 'Date',
                'aliases'    => ['work date', 'day'],
                'required'   => true,
                'example'    => '2026-08-05',
                'desc'       => 'Excel date cell or YYYY-MM-DD. One time entry is created per date.',
            ],
            [
                'name'       => 'Adj Credited Hrs',
                'aliases'    => ['credited time', 'credited hours', 'hours'],
                'required'   => true,
                'example'    => '7.5',
                'desc'       => 'Decimal hours credited for that day.',
            ],
            [
                'name'       => 'Payroll Rate',
                'aliases'    => ['rate', 'hourly rate'],
                'required'   => false,
                'example'    => '6.50',
                'desc'       => 'Hourly pay rate; updates the employee record.',
            ],
        ],
    ],

    'wise' => [
        'label'     => 'Wise payout details',
        'formats'   => '.xlsx or .csv',
        'intro'     => 'One row per employee. As with the payroll import, the header row is found by scanning — it does not need to be row 1. Rows whose values are #REF! or #N/A are skipped, and a repeated header row inside the data is ignored.',
        'match'     => 'Employees are matched in this order: Wise Recipient ID → VT ID → EMAIL → Wise Name. The first match wins; unmatched rows are reported rather than creating new people.',

        'columns' => [
            [
                'name'       => 'Wise Recipient ID',
                'aliases'    => ['recipient id', 'wise id'],
                'required'   => false,
                'example'    => '7c8fc17a-54ba-4b13-d86a-f8b0d627577e',
                'desc'       => 'Wise recipient UUID. Best match key — include it when you have it.',
            ],
            [
                'name'       => 'Wise Name',
                'aliases'    => ['account holder', 'recipient name', 'name'],
                'required'   => true,
                'example'    => 'CINDY RUFILA ESPORLAS',
                'desc'       => 'Account holder name exactly as Wise holds it.',
            ],
            [
                'name'       => 'EMAIL',
                'aliases'    => ['email address', 'recipient email'],
                'required'   => false,
                'example'    => 'cindy@example.com',
                'desc'       => 'Recipient email. Also used to match the employee.',
            ],
            [
                'name'       => 'Wise account',
                'aliases'    => ['account', 'account summary', 'bank'],
                'required'   => false,
                'example'    => 'Wise account  /  BPI ending ·· 1593',
                'desc'       => 'Payout target as shown in Wise. Free text.',
            ],
            [
                'name'       => 'from Currency',
                'aliases'    => ['source currency'],
                'required'   => false,
                'example'    => 'USD',
                'desc'       => 'Currency you pay from. Defaults to the org pay currency.',
            ],
            [
                'name'       => 'to Currency',
                'aliases'    => ['target currency'],
                'required'   => false,
                'example'    => 'USD',
                'desc'       => 'Currency the recipient receives.',
            ],
            [
                'name'       => 'Source',
                'aliases'    => ['source account'],
                'required'   => false,
                'example'    => 'source',
                'desc'       => 'Wise source-account label; passed straight through to the export.',
            ],
            [
                'name'       => 'Type',
                'aliases'    => ['recipient type'],
                'required'   => false,
                'example'    => 'PERSON',
                'desc'       => 'PERSON or BUSINESS. Defaults to PERSON.',
            ],
            [
                'name'       => 'VT ID',
                'aliases'    => ['employee id', 'staff id'],
                'required'   => false,
                'example'    => '1042',
                'desc'       => 'Employee reference — the most reliable way to match your DeskPulse people.',
            ],
            [
                'name'       => 'Amount',
                'aliases'    => [],
                'required'   => false,
                'example'    => '(leave blank)',
                'desc'       => 'Ignored on import. DeskPulse fills this when it generates a salary run.',
            ],
        ],
    ],
];
