<?php
/**
 * DeskPulse database installer (CLI).
 *
 * Creates the MySQL database (if missing) and all tables from schema.sql, then
 * optionally seeds demo data.
 *
 * Run from the repo root:
 *     "c:/wamp64/bin/php/php8.2.26/php.exe" server/install.php
 *     "c:/wamp64/bin/php/php8.2.26/php.exe" server/install.php --seed
 *
 * Reads server/config.php if present, otherwise server/config.example.php.
 */
$SERVER_DIR = __DIR__;
require_once $SERVER_DIR . '/src/helpers.php';

$cfgFile = file_exists($SERVER_DIR . '/config.php')
    ? $SERVER_DIR . '/config.php' : $SERVER_DIR . '/config.example.php';
$GLOBALS['CONFIG'] = require $cfgFile;

require_once $SERVER_DIR . '/src/db.php';

if (basename($cfgFile) === 'config.example.php') {
    fwrite(STDOUT, "Note: using config.example.php (no config.php yet). "
        . "Copy it to server/config.php to customise DB credentials.\n");
}

$db = config('db');
fwrite(STDOUT, "Connecting to MySQL at {$db['host']}:{$db['port']} as {$db['user']}…\n");

try {
    // db() creates the database if missing and imports schema.sql via ensure_schema().
    $pdo = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDOUT, "Database '{$db['name']}' is ready with " . count($tables) . " tables:\n");
    fwrite(STDOUT, '  ' . implode(', ', $tables) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Install failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Check your MySQL server is running and credentials in config.php are correct.\n");
    exit(1);
}

// Ensure the upload directory exists.
$uploadDir = upload_path();
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
fwrite(STDOUT, "Upload directory: {$uploadDir}\n");

// Create or promote a super admin:  --make-superadmin=email@x [--password=secret]
$superArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--make-superadmin=')) {
        $superArg = substr($a, strlen('--make-superadmin='));
    }
}
if ($superArg) {
    $passArg = getenv('DESKPULSE_SUPER_PASS') ?: '';
    foreach ($argv as $a) {
        if (str_starts_with($a, '--password=')) {
            $passArg = substr($a, strlen('--password='));
        }
    }
    $email = strtolower(trim($superArg));
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        db_exec('UPDATE users SET role = "super_admin" WHERE id = ?', [$existing['id']]);
        if ($passArg !== '') {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($passArg, PASSWORD_DEFAULT), $existing['id']]);
        }
        fwrite(STDOUT, "Promoted {$email} to super_admin.\n");
    } else {
        if ($passArg === '') {
            fwrite(STDERR, "New super admin needs a password: pass --password=... "
                . "or set DESKPULSE_SUPER_PASS.\n");
            exit(1);
        }
        // Super admins live in their own approved "Platform" org.
        $org = db_one('SELECT id FROM organizations WHERE name = ?', ['DeskPulse Platform']);
        $orgId = $org['id'] ?? db_exec(
            "INSERT INTO organizations (name, status) VALUES ('DeskPulse Platform', 'approved')");
        db_exec(
            'INSERT INTO users (org_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, "super_admin")',
            [$orgId, 'Super Admin', $email, password_hash($passArg, PASSWORD_DEFAULT)]
        );
        fwrite(STDOUT, "Created super admin {$email}.\n");
    }
}

if (in_array('--seed', $argv, true)) {
    fwrite(STDOUT, "\nSeeding demo data…\n");
    require $SERVER_DIR . '/seed.php';   // shares the already-loaded config + PDO
}

fwrite(STDOUT, "\nDeskPulse database install complete.\n");
