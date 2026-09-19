<?php
/**
 * DeskPulse one-time web installer.
 *
 * Imports install/deskpulse-fresh.sql (full schema + the super-admin account only)
 * into the database configured in server/config.php — so on the live server it uses
 * the production credentials automatically.
 *
 * Usage: browse to /setup.php, click "Run installer", then DELETE this file.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$cfg = require __DIR__ . '/../config.php';
$db = $cfg['db'];
$sqlFile = __DIR__ . '/../install/deskpulse-fresh.sql';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>DeskPulse installer</title>';
echo '<body style="font-family:system-ui,Segoe UI,Arial;max-width:700px;margin:3rem auto;padding:0 1rem;line-height:1.55;color:#1c2333">';
echo '<h2 style="color:#16224a">DeskPulse — database installer</h2>';
$line = fn($s) => print('<p style="margin:.4rem 0">' . $s . '</p>');

// 1. Connect using the app's (environment-aware) DB credentials.
try {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    $line('<b style="color:#c00">Cannot connect</b> to database <b>' . $h($db['name']) . '</b> as <b>' . $h($db['user']) . '</b>.');
    $line('Error: ' . $h($e->getMessage()));
    $line('Create the database first and confirm the credentials in <code>server/config.php</code>, then reload.');
    exit;
}
$line('Connected to <b>' . $h($db['name']) . '</b> as <b>' . $h($db['user']) . '</b>.');

// 2. Guard against accidentally wiping an installed database.
$installed = false;
try {
    $installed = (bool) $pdo->query("SELECT 1 FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
} catch (Throwable $e) { /* fresh DB — tables don't exist yet */ }

$run = isset($_GET['run']);
$force = isset($_GET['force']);

if ($installed && !$force) {
    $line('<b style="color:#157a4f">Already installed</b> — a super-admin account exists.');
    $line('To wipe everything and reinstall (this <b>erases all data</b>): <code>?run=1&amp;force=1</code>.');
    $line('Otherwise please <b>delete this file</b> (<code>public/setup.php</code>) now.');
    exit;
}
if (!is_file($sqlFile)) {
    $line('<b style="color:#c00">SQL file missing.</b> Upload <code>server/install/deskpulse-fresh.sql</code> and reload.');
    exit;
}
if (!$run) {
    $line('This imports the schema and the super-admin account into <b>' . $h($db['name']) . '</b>.');
    if ($installed) {
        $line('<b style="color:#c00">Warning:</b> existing data will be ERASED.');
    }
    echo '<p><a href="?run=1' . ($force ? '&force=1' : '') . '" '
       . 'style="display:inline-block;background:#2f5bd6;color:#fff;padding:.6rem 1.1rem;border-radius:8px;text-decoration:none;font-weight:600">Run installer</a></p>';
    exit;
}

// 3. Execute the SQL statement-by-statement (tolerant of CRLF and -- comments).
$sql = str_replace("\r\n", "\n", file_get_contents($sqlFile));
$clean = [];
foreach (explode("\n", $sql) as $ln) {
    if (str_starts_with(ltrim($ln), '--')) {
        continue;
    }
    $clean[] = $ln;
}
$statements = array_filter(array_map('trim', explode(";\n", implode("\n", $clean))));

$ok = 0; $errors = 0;
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (Throwable $e) {
        $errors++;
        $line('<span style="color:#c00">Error:</span> ' . $h(substr($stmt, 0, 70)) . '… — ' . $h($e->getMessage()));
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$line('<b style="color:#157a4f">Import finished.</b> ' . $ok . ' statements run, ' . $errors . ' error(s).');
try {
    $su = $pdo->query("SELECT email FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
    $line('Super-admin account: <b>' . $h($su ?: '—') . '</b> (use your existing password).');
} catch (Throwable $e) { /* ignore */ }
$line('<b style="color:#c00">IMPORTANT:</b> delete this file now — <code>public/setup.php</code> can erase your database.');
