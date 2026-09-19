<?php
/**
 * DeskPulse web installer.
 *
 * Upload the whole package, browse to https://your-domain/install.php and fill in
 * the form. It will:
 *   1. test the MySQL connection,
 *   2. write ../config.php with your production credentials,
 *   3. create all tables from ../schema.sql,
 *   4. create (or keep) the super-admin login,
 *   5. create the uploads/ directory.
 *
 * SECURITY: delete this file the moment the install succeeds. It can overwrite
 * your config and wipe data.
 *
 * Layout it expects (public_html is the web docroot):
 *   <home>/
 *     ├── public_html/  index.php .htaccess install.php assets/ uploads/ ...
 *     ├── src/ templates/
 *     ├── schema.sql  config.example.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Layout-aware: "flat" = all files in one docroot (src/ alongside install.php);
// otherwise the app code sits one level above the docroot (public_html layout).
$FLAT       = is_dir(__DIR__ . '/src');
$APP_DIR    = $FLAT ? __DIR__ : dirname(__DIR__);
$CONFIG     = $APP_DIR . '/config.php';
$SCHEMA     = $APP_DIR . '/schema.sql';
$UPLOADS    = __DIR__ . '/uploads';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Import schema.sql statement-by-statement (tolerant of `--` comments + CRLF). */
function dp_import_sql(PDO $pdo, string $file): array
{
    $sql = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $clean = [];
    foreach (explode("\n", $sql) as $ln) {
        $pos = strpos($ln, '--');
        $clean[] = $pos === false ? $ln : substr($ln, 0, $pos);
    }
    $stmts = array_filter(array_map('trim', explode(';', implode("\n", $clean))));
    $ok = 0; $err = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($stmts as $s) {
        if ($s === '') continue;
        try { $pdo->exec($s); $ok++; }
        catch (Throwable $e) { $err[] = substr($s, 0, 60) . '… — ' . $e->getMessage(); }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return [$ok, $err];
}

$done   = false;
$errors = [];
$notes  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $suEmail = trim($_POST['su_email'] ?? '');
    $suPass  = (string) ($_POST['su_pass'] ?? '');
    $wipe    = !empty($_POST['wipe']);

    if ($dbName === '' || $dbUser === '') $errors[] = 'Database name and user are required.';
    if ($suEmail === '' || !filter_var($suEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid super-admin email is required.';
    if (strlen($suPass) < 8) $errors[] = 'Super-admin password must be at least 8 characters.';

    $pdo = null;
    if (!$errors) {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $notes[] = "Connected to database <b>{$h($dbName)}</b> as <b>{$h($dbUser)}</b>.";
        } catch (Throwable $e) {
            $errors[] = 'Cannot connect: ' . $h($e->getMessage())
                . ' — create the database in your hosting panel and check the credentials.';
        }
    }

    if (!$errors && $pdo) {
        // 1. Write config.php
        $secret = bin2hex(random_bytes(24));
        $cfg = "<?php\nreturn [\n"
            . "    'db' => [\n"
            . "        'host' => " . var_export($dbHost, true) . ",\n"
            . "        'port' => " . var_export($dbPort, true) . ",\n"
            . "        'name' => " . var_export($dbName, true) . ",\n"
            . "        'user' => " . var_export($dbUser, true) . ",\n"
            . "        'pass' => " . var_export($dbPass, true) . ",\n"
            . "        'charset' => 'utf8mb4',\n"
            . "    ],\n"
            . "    'app_secret' => " . var_export($secret, true) . ",\n"
            . "    'public_base_url' => " . var_export($baseUrl, true) . ",\n"
            . ($FLAT ? "    'public_dir' => '.',\n" : "")
            . "    'upload_dir' => 'public_html/uploads',\n"
            . "    'max_upload_mb' => 10,\n"
            . "    'idle_threshold_min' => 15,\n"
            . "];\n";
        if (@file_put_contents($CONFIG, $cfg) === false) {
            $errors[] = 'Could not write <code>config.php</code> to <code>' . $h($APP_DIR) . '</code> — check folder permissions (755) and ownership.';
        } else {
            $notes[] = 'Wrote <b>config.php</b> with your production credentials.';
        }
    }

    if (!$errors && $pdo) {
        // 2. Optionally wipe, then import schema if the core table is missing.
        $hasTables = (bool) $pdo->query("SHOW TABLES LIKE 'organizations'")->fetch();
        if ($hasTables && $wipe) {
            $tbls = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tbls as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $notes[] = 'Dropped ' . count($tbls) . ' existing table(s).';
            $hasTables = false;
        }
        if (!$hasTables) {
            if (!is_file($SCHEMA)) {
                $errors[] = 'schema.sql is missing at <code>' . $h($SCHEMA) . '</code> — re-upload the package.';
            } else {
                [$ok, $err] = dp_import_sql($pdo, $SCHEMA);
                $notes[] = "Imported schema — <b>{$ok}</b> statements run.";
                foreach ($err as $e) $errors[] = 'SQL: ' . $h($e);
            }
        } else {
            $notes[] = 'Tables already exist — left data intact.';
        }
    }

    if (!$errors && $pdo) {
        // 3. Ensure a platform org + super-admin account.
        $orgId = $pdo->query("SELECT id FROM organizations WHERE name='DeskPulse Platform' LIMIT 1")->fetchColumn();
        if (!$orgId) {
            $pdo->prepare("INSERT INTO organizations (name,status,billing_status,billing_currency) VALUES ('DeskPulse Platform','approved','none','USD')")->execute();
            $orgId = (int) $pdo->lastInsertId();
        }
        $hash = password_hash($suPass, PASSWORD_DEFAULT);
        $u = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $u->execute([$suEmail]);
        $uid = $u->fetchColumn();
        if ($uid) {
            $pdo->prepare('UPDATE users SET password_hash=?, role=?, org_id=? WHERE id=?')
                ->execute([$hash, 'super_admin', $orgId, $uid]);
            $notes[] = "Updated super-admin <b>{$h($suEmail)}</b> (password reset).";
        } else {
            $pdo->prepare("INSERT INTO users (org_id,name,email,password_hash,role,created_at) VALUES (?,?,?,?,?,NOW())")
                ->execute([$orgId, 'Super Admin', $suEmail, $hash, 'super_admin']);
            $notes[] = "Created super-admin <b>{$h($suEmail)}</b>.";
        }
    }

    if (!$errors) {
        if (!is_dir($UPLOADS)) @mkdir($UPLOADS, 0775, true);
        @mkdir($UPLOADS . '/logos', 0775, true);
        $notes[] = 'Created <b>uploads/</b> directory.';
        $done = true;
    }
}

// Pre-fill base URL guess.
$guessUrl = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DeskPulse installer</title>
<style>
 body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:640px;margin:2.5rem auto;padding:0 1rem;color:#1c2333;line-height:1.55}
 h1{color:#16224a;font-size:1.5rem;margin-bottom:.2rem}
 .sub{color:#5b6685;margin-top:0}
 label{display:block;font-weight:600;margin:.9rem 0 .25rem}
 input{width:100%;padding:.55rem .65rem;border:1px solid #c7cfe0;border-radius:8px;font-size:1rem;box-sizing:border-box}
 .row{display:flex;gap:.75rem}.row>div{flex:1}
 button{margin-top:1.4rem;background:#2f5bd6;color:#fff;border:0;padding:.7rem 1.3rem;border-radius:9px;font-size:1rem;font-weight:600;cursor:pointer}
 .box{padding:.8rem 1rem;border-radius:9px;margin:1rem 0}
 .ok{background:#e8f6ee;border:1px solid #9cd9b6;color:#13633f}
 .err{background:#fdeaea;border:1px solid #f0b4b4;color:#a11}
 .muted{color:#5b6685;font-size:.9rem}
 code{background:#eef1f8;padding:.1rem .35rem;border-radius:5px}
 .chk{display:flex;gap:.5rem;align-items:center;margin-top:.9rem}.chk input{width:auto}
</style></head><body>
<h1>DeskPulse — installer</h1>
<p class="sub">Set up the database and your administrator login.</p>

<?php if ($notes): ?><div class="box ok"><?php foreach ($notes as $n) echo "<div>✓ $n</div>"; ?></div><?php endif; ?>
<?php if ($errors): ?><div class="box err"><b>Please fix:</b><?php foreach ($errors as $e) echo "<div>• $e</div>"; ?></div><?php endif; ?>

<?php if ($done): ?>
  <div class="box ok"><b>Installation complete.</b></div>
  <p>You can now sign in at <a href="login">/login</a> with your super-admin account.</p>
  <p class="err"><b>Important:</b> delete <code>install.php</code> now — it can overwrite config and wipe data.</p>
<?php else: ?>
  <form method="post" autocomplete="off">
    <label>Database host <input name="db_host" value="<?= $h($_POST['db_host'] ?? 'localhost') ?>"></label>
    <div class="row">
      <div><label>Database name <input name="db_name" value="<?= $h($_POST['db_name'] ?? '') ?>" placeholder="deskpulse"></label></div>
      <div><label>Port <input name="db_port" value="<?= $h($_POST['db_port'] ?? '3306') ?>"></label></div>
    </div>
    <div class="row">
      <div><label>Database user <input name="db_user" value="<?= $h($_POST['db_user'] ?? '') ?>"></label></div>
      <div><label>Database password <input name="db_pass" type="password" value=""></label></div>
    </div>
    <p class="muted">Create the database + user in your hosting control panel (e.g. cPanel → MySQL Databases) first.</p>

    <label>Public base URL <input name="base_url" value="<?= $h($_POST['base_url'] ?? $guessUrl) ?>"></label>
    <p class="muted">Used for share links. Leave the auto-detected value if unsure.</p>

    <div class="row">
      <div><label>Super-admin email <input name="su_email" type="email" value="<?= $h($_POST['su_email'] ?? '') ?>"></label></div>
      <div><label>Super-admin password <input name="su_pass" type="password" value=""></label></div>
    </div>

    <label class="chk"><input type="checkbox" name="wipe" value="1"> Wipe any existing tables first (erases all data)</label>

    <button type="submit">Run installer</button>
  </form>
  <p class="muted" style="margin-top:1.5rem">After it succeeds, delete <code>install.php</code> from the server.</p>
<?php endif; ?>
</body></html>
