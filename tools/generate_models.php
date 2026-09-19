<?php
/**
 * Generate Eloquent models from the live schema.
 *
 * Handles the two conventions this schema breaks:
 *  - Only `wise_accounts` has `updated_at`. Everything else has `created_at` only,
 *    or neither, so timestamps must be configured per model or every insert fails
 *    on an unknown column.
 *  - The tenant column is `org_id`, not `organization_id`, and 17 tables have no
 *    tenant column at all — they reach one through `user_id` or `session_id`.
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=information_schema;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db = 'deskpulse';
$out = getcwd() . '/app/Models';
@mkdir($out, 0775, true);

/** table => model class. `sessions` is deliberately WorkSession: Laravel's own
 *  `sessions` concept is HTTP sessions, and these are work sessions. */
$map = [
    'activity_samples' => 'ActivitySample', 'agent_clients' => 'AgentClient',
    'clients' => 'Client', 'contract_members' => 'ContractMember', 'contracts' => 'Contract',
    'devices' => 'Device', 'email_outbox' => 'EmailOutbox', 'idle_periods' => 'IdlePeriod',
    'invoices' => 'Invoice', 'leave_entitlements' => 'LeaveEntitlement',
    'leave_requests' => 'LeaveRequest', 'leave_types' => 'LeaveType',
    'notice_reads' => 'NoticeRead', 'notices' => 'Notice', 'organizations' => 'Organization',
    'password_resets' => 'PasswordReset', 'pay_adjustments' => 'PayAdjustment',
    'payment_claims' => 'PaymentClaim', 'payments' => 'Payment', 'payslips' => 'Payslip',
    'process_snapshots' => 'ProcessSnapshot', 'projects' => 'Project',
    'promo_codes' => 'PromoCode', 'promo_redemptions' => 'PromoRedemption',
    'remote_input_events' => 'RemoteInputEvent', 'remote_sessions' => 'RemoteSession',
    'screenshots' => 'Screenshot', 'sessions' => 'WorkSession', 'share_links' => 'ShareLink',
    'tasks' => 'Task', 'team_members' => 'TeamMember', 'teams' => 'Team',
    'user_identities' => 'UserIdentity', 'users' => 'User', 'webhook_events' => 'WebhookEvent',
    'window_events' => 'WindowEvent', 'wise_accounts' => 'WiseAccount',
];

/** Written by hand — richer relationships than a generator can infer. */
$skip = ['users', 'organizations', 'sessions'];

$written = 0;
foreach ($map as $table => $class) {
    if (in_array($table, $skip, true)) {
        continue;
    }

    $cols = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE
           FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $cols->execute([$db, $table]);
    $cols = $cols->fetchAll();
    $names = array_column($cols, 'COLUMN_NAME');

    $hasCreated = in_array('created_at', $names, true);
    $hasUpdated = in_array('updated_at', $names, true);

    /* casts */
    $casts = [];
    foreach ($cols as $c) {
        $n = $c['COLUMN_NAME'];
        if ($n === 'id') {
            continue;
        }
        $t = strtolower($c['COLUMN_TYPE']);
        $d = strtolower($c['DATA_TYPE']);
        if ($t === 'tinyint(1)') {
            $casts[$n] = 'boolean';
        } elseif ($d === 'datetime' || $d === 'timestamp') {
            $casts[$n] = 'datetime';
        } elseif ($d === 'date') {
            $casts[$n] = 'date';
        } elseif ($d === 'decimal') {
            preg_match('/\((\d+),(\d+)\)/', $t, $m);
            $casts[$n] = 'decimal:' . ($m[2] ?? 2);
        } elseif ($d === 'double' || $d === 'float') {
            $casts[$n] = 'float';
        } elseif (in_array($d, ['int', 'bigint', 'smallint', 'mediumint'], true)) {
            $casts[$n] = 'integer';
        }
    }

    /* tenancy trait */
    $trait = null;
    if (in_array('org_id', $names, true)) {
        $trait = 'BelongsToOrganization';
    } elseif (in_array('session_id', $names, true)) {
        $trait = 'BelongsToOrganizationThroughSession';
    } elseif (in_array('user_id', $names, true)) {
        $trait = 'BelongsToOrganizationThroughUser';
    }

    /* belongsTo relations for foreign keys we can name */
    $fk = $pdo->prepare(
        'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME
           FROM KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $fk->execute([$db, $table]);
    $relations = [];
    foreach ($fk->fetchAll() as $r) {
        $col = $r['COLUMN_NAME'];
        $refClass = $map[$r['REFERENCED_TABLE_NAME']] ?? null;
        if (!$refClass) {
            continue;
        }
        // The trait already supplies organization()/user()/session().
        if ($trait === 'BelongsToOrganization' && $col === 'org_id') continue;
        if ($trait === 'BelongsToOrganizationThroughUser' && $col === 'user_id') continue;
        if ($trait === 'BelongsToOrganizationThroughSession' && $col === 'session_id') continue;

        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', preg_replace('/_id$/', '', $col)))));
        $relations[$method] = [$refClass, $col];
    }

    /* build */
    $uses = ["use Illuminate\\Database\\Eloquent\\Model;"];
    if ($relations) {
        $uses[] = "use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;";
    }
    sort($uses);
    $useBlock = implode("\n", $uses);

    $traitUse = $trait ? "\n    use Concerns\\{$trait};\n" : '';

    $ts = $hasUpdated
        ? ''
        : ($hasCreated
            ? "\n    /** This table has created_at but no updated_at. */\n    public const UPDATED_AT = null;\n"
            : "\n    /** This table has no timestamp columns at all. */\n    public \$timestamps = false;\n");

    $castLines = '';
    foreach ($casts as $k => $v) {
        $castLines .= "            '$k' => '$v',\n";
    }
    $castBlock = $castLines
        ? "\n    protected function casts(): array\n    {\n        return [\n{$castLines}        ];\n    }\n"
        : '';

    $relBlock = '';
    foreach ($relations as $method => [$refClass, $col]) {
        $relBlock .= "\n    public function {$method}(): BelongsTo\n    {\n"
                   . "        return \$this->belongsTo({$refClass}::class, '{$col}');\n    }\n";
    }

    $php = "<?php\n\nnamespace App\\Models;\n\n{$useBlock}\n\n"
         . "/**\n * @see docs/migration/database.md\n */\n"
         . "class {$class} extends Model\n{\n"
         . "{$traitUse}"
         . "\n    protected \$table = '{$table}';\n"
         . "{$ts}"
         . "\n    protected \$guarded = ['id'];\n"
         . "{$castBlock}"
         . "{$relBlock}"
         . "}\n";

    file_put_contents("$out/{$class}.php", $php);
    $written++;
}

echo "generated $written models\n";
