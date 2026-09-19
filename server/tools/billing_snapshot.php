<?php
/**
 * Billing snapshot (CLI, read-only) — the before/after check for any pricing change.
 *
 * Dumps the platform price row and every tenant's plan, discount, monthly_fee and
 * billable seat count as JSON. Capture one before touching pricing code and one
 * after; the diff must be empty until the price ladder is deliberately switched on
 * from Platform settings.
 *
 *     "c:/wamp64/bin/php/php8.2.26/php.exe" server/tools/billing_snapshot.php > before.json
 *     …make changes, run migrations…
 *     "c:/wamp64/bin/php/php8.2.26/php.exe" server/tools/billing_snapshot.php > after.json
 *
 * Reads server/config.php if present, otherwise server/config.example.php.
 */
$SERVER_DIR = dirname(__DIR__);
require_once $SERVER_DIR . '/src/helpers.php';
$cfgFile = file_exists($SERVER_DIR . '/config.php')
    ? $SERVER_DIR . '/config.php' : $SERVER_DIR . '/config.example.php';
$GLOBALS['CONFIG'] = require $cfgFile;
require_once $SERVER_DIR . '/src/db.php';

$cols = db()->query('SHOW COLUMNS FROM organizations')->fetchAll(PDO::FETCH_COLUMN);
$has = fn(string $c) => in_array($c, $cols, true);

// Only select columns that exist, so this runs against a database from before or
// after the price-ladder migration.
$priceCols = array_values(array_filter(
    ['price_individual', 'price_organization', 'price_per_seat', 'price_solo',
     'price_seat_cap', 'seats_min', 'seats_max', 'seats_bill_min', 'seats_cap_covers'],
    $has
));

$out = [
    'config_file'   => basename($cfgFile),
    'price_columns' => $priceCols,
    'orgs'          => [],
];

$plat = db_one("SELECT id FROM organizations WHERE name = 'DeskPulse Platform' ORDER BY id LIMIT 1");
$platId = (int) ($plat['id'] ?? 0);
$out['platform_org_id'] = $platId;
$out['platform_org_row'] = $platId
    ? db_one('SELECT ' . implode(', ', $priceCols) . ' FROM organizations WHERE id = ?', [$platId])
    : null;

$sel = 'id, name, status, plan_type, discount_pct, monthly_fee, billing_status, billing_currency';
if ($has('custom_fee')) {
    $sel .= ', custom_fee';
}
foreach (db_all("SELECT $sel FROM organizations ORDER BY id") as $o) {
    // Mirrors org_seat_count() without loading dashboard.php (which needs a session).
    $seats = db_one("SELECT COUNT(*) c FROM users WHERE org_id = ?
                     AND role NOT IN ('client_viewer','super_admin')", [(int) $o['id']]);
    $o['seat_count'] = (int) ($seats['c'] ?? 0);
    $out['orgs'][] = $o;
}

$out['invoices'] = db_all('SELECT id, org_id, amount_cents, currency, status, period_start, period_end
                           FROM invoices ORDER BY id');
$out['payments'] = db_all('SELECT id, org_id, amount_cents, currency FROM payments ORDER BY id');

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
