<?php
/** Small helpers: config access, escaping, URLs, responses, time formatting. */

/** Read a config key (dot-less, top-level) loaded in bootstrap. */
function config(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/** HTML-escape. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Build an absolute app URL from a root-relative path, honoring the base dir. */
function url(string $path = '/'): string
{
    global $BASE_PATH;
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return ($BASE_PATH === '' ? '' : $BASE_PATH) . $path;
}

/**
 * Is this request HTTPS? Direct TLS, behind a proxy/LiteSpeed terminator that sets
 * X-Forwarded-Proto, or arriving on port 443.
 *
 * bootstrap.php used to inline this while public_base_url() checked $_SERVER['HTTPS']
 * alone — so behind a TLS-terminating proxy the app redirected correctly but still
 * emitted http:// in canonical, og:url and the robots.txt Sitemap: line.
 */
function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    // X-Forwarded-Proto can be a comma-joined chain ("https, http") — the client-facing
    // hop is the first entry.
    $fwd = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return ($https !== '' && $https !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || $fwd === 'https';
}

/** Public base URL (scheme://host + base path) for share links shown to users. */
function public_base_url(): string
{
    $configured = config('public_base_url');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $scheme = request_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    global $BASE_PATH;
    return $scheme . '://' . $host . $BASE_PATH;
}

/**
 * Absolute public URL for a root-relative app path — for canonical links, og:/twitter:
 * tags, sitemaps and anything that leaves the page.
 *
 * Use this instead of public_base_url() . url($p): both apply $BASE_PATH, so composing
 * them doubles it (…/server/public/server/public/assets/…) on a subdirectory install.
 */
function abs_url(string $path = '/'): string
{
    return rtrim(public_base_url(), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function abort(int $status, string $msg = ''): void
{
    http_response_code($status);
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/webhooks/')
        || str_contains($_SERVER['REQUEST_URI'] ?? '', '/data')) {
        json_out(['error' => $msg ?: 'error'], $status);
    }
    echo '<h1>' . $status . '</h1><p>' . e($msg) . '</p>';
    exit;
}

/** Read the raw request body once (for JSON / HMAC verification). */
function raw_body(): string
{
    static $body = null;
    if ($body === null) {
        $body = file_get_contents('php://input') ?: '';
    }
    return $body;
}

function json_body(): array
{
    $data = json_decode(raw_body(), true);
    return is_array($data) ? $data : [];
}

/**
 * Encrypt a secret for storage in the database (AES-256-GCM, key derived from
 * config('app_secret')). Used for the Wise API token, which would otherwise sit in
 * a plaintext column that every DB backup and SQL export would carry.
 *
 * Returns a self-describing "v1:<base64 iv|tag|ciphertext>" string, or '' for empty
 * input. Rotating app_secret invalidates stored secrets — they must be re-entered.
 */
function dp_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = hash('sha256', (string) config('app_secret', 'deskpulse'), true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        return '';
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** Reverse of dp_encrypt(). Returns '' when the value is empty, malformed or was
 *  encrypted under a different app_secret. */
function dp_decrypt(?string $stored): string
{
    $stored = (string) $stored;
    if ($stored === '' || strncmp($stored, 'v1:', 3) !== 0) {
        return '';
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $key = hash('sha256', (string) config('app_secret', 'deskpulse'), true);
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? '' : $out;
}

/** Mask a secret for display: keep a short tail so an admin can tell which key it is. */
function dp_mask_secret(string $secret, int $tail = 4): string
{
    if ($secret === '') {
        return '';
    }
    return strlen($secret) <= $tail ? str_repeat('•', 8)
        : str_repeat('•', 8) . substr($secret, -$tail);
}

/** A URL-safe random token. */
function random_token(int $bytes = 18): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Absolute path to the web docroot. Resolution order:
 *   1. config('public_dir') — absolute, or relative to the app dir (server/).
 *      Use '.' for a flat single-docroot host (all files in the web root),
 *      '..' when the app dir sits one level below the docroot.
 *   2. `public_html/` sibling (cPanel/shared hosting with code above the root).
 *   3. `public/` sibling (WAMP dev).
 * Returns the base dir, or a path inside it when $rel is given.
 */
function public_dir(string $rel = ''): string
{
    static $root = null;
    if ($root === null) {
        $base = dirname(__DIR__);                       // app dir (server/)
        $cfg = (string) config('public_dir', '');
        if ($cfg !== '') {
            $root = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $cfg)
                ? $cfg                                  // absolute
                : (realpath($base . '/' . $cfg) ?: $base . '/' . $cfg);
        } elseif (is_dir($base . '/public_html')) {
            $root = $base . '/public_html';
        } else {
            $root = $base . '/public';
        }
    }
    return $rel ? $root . '/' . ltrim($rel, '/') : $root;
}

/** Resolve an absolute path inside the configured screenshot upload dir. */
function upload_path(string $rel = ''): string
{
    $dir = config('upload_dir', 'public/uploads');
    if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
        // Absolute path — use as configured.
    } elseif (preg_match('#^public(_html)?/(.*)$#', $dir, $m)) {
        $dir = public_dir($m[2]);               // docroot-relative (public/ or public_html/)
    } else {
        $dir = dirname(__DIR__) . '/' . $dir;   // relative to server/
    }
    return rtrim($dir, '/\\') . ($rel ? '/' . $rel : '');
}

/**
 * Convert an uploaded image to a resized WebP under uploads/logos/ for branding.
 * Preserves aspect ratio and transparency, never upscales, and fits a tidy box.
 * Returns [relativePath, null] on success or [false, errorMessage] on failure.
 */
function store_logo_webp(array $file, string $prefix = 'org'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'No file was uploaded.'];
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return [false, 'Image is too large (max 8 MB).'];
    }
    if (!function_exists('imagewebp') || !function_exists('imagecreatefromstring')) {
        return [false, 'Server image support (GD/WebP) is unavailable.'];
    }
    $raw = @file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        return [false, 'Could not read the uploaded file.'];
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return [false, 'Unsupported image — use PNG, JPG, GIF or WebP.'];
    }
    $w = imagesx($src);
    $h = imagesy($src);
    // Fit within a branding box (logos are usually wide); don't upscale small art.
    $maxW = 480;
    $maxH = 160;
    $scale = min($maxW / $w, $maxH / $h, 1.0);
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $dir = upload_path('logos');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $rel = 'logos/' . preg_replace('/[^a-z0-9]+/i', '', $prefix) . '-' . random_token(8) . '.webp';
    $ok = imagewebp($dst, upload_path($rel), 82);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        return [false, 'Could not save the converted image.'];
    }
    return [$rel, null];
}

/** Public URL for an org's branding logo, or null if none is set. */
function org_logo_url(?string $logoPath): ?string
{
    return $logoPath ? url('/uploads/' . $logoPath) : null;
}

/**
 * List the worksheet names in an .xlsx file, in workbook order.
 * Returns [] if the file can't be opened / parsed.
 */
function xlsx_sheet_names(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }
    $wb = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wb === false) {
        return [];
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($wb)) {
        return [];
    }
    $names = [];
    foreach ($doc->getElementsByTagName('sheet') as $sh) {
        $names[] = $sh->getAttribute('name');
    }
    return $names;
}

/**
 * Read an .xlsx worksheet with only PHP built-ins (ZipArchive + DOM) — no
 * third-party library, matching the project's dependency-free approach.
 *
 * Returns rows keyed by their 1-based spreadsheet row number (so sparse/empty
 * rows never shift the numbering), each row an assoc array keyed by column
 * letter: [30 => ['A' => 'Date', 'C' => 'Contract Name', ...], 31 => [...]].
 * All values are returned as strings; the caller casts. Shared strings, inline
 * strings, formula-result strings and numeric cells are all resolved.
 *
 * $sheetName selects a sheet by name (case-insensitive); null uses the first.
 * Returns [] when the file, or a named sheet, can't be found.
 */
function xlsx_rows(string $path, ?string $sheetName = null): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    // Shared strings table: cells with t="s" hold an index into this list.
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sd = new DOMDocument();
        if (@$sd->loadXML($ssXml)) {
            foreach ($sd->getElementsByTagName('si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagName('t') as $t) {
                    $text .= $t->textContent;
                }
                $shared[] = $text;
            }
        }
    }

    // Resolve the worksheet part: workbook.xml maps sheet name -> r:id, and the
    // rels file maps r:id -> the sheetN.xml part path.
    $target = null;
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $rid2target = [];
        $rd = new DOMDocument();
        if (@$rd->loadXML($relsXml)) {
            foreach ($rd->getElementsByTagName('Relationship') as $rel) {
                $rid2target[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
        $wd = new DOMDocument();
        if (@$wd->loadXML($wbXml)) {
            $rid = null;
            foreach ($wd->getElementsByTagName('sheet') as $sh) {
                $thisRid = $sh->getAttribute('r:id');
                if ($sheetName === null) {
                    $rid = $thisRid;   // first sheet
                    break;
                }
                if (strcasecmp($sh->getAttribute('name'), $sheetName) === 0) {
                    $rid = $thisRid;
                    break;
                }
            }
            if ($rid !== null && isset($rid2target[$rid])) {
                $t = $rid2target[$rid];
                $target = (strpos($t, '/') === 0) ? ltrim($t, '/') : 'xl/' . $t;
            }
        }
    }
    if ($target === null && $sheetName === null) {
        $target = 'xl/worksheets/sheet1.xml';   // sensible fallback
    }
    if ($target === null) {
        $zip->close();
        return [];   // named sheet not found
    }

    $wsXml = $zip->getFromName($target);
    $zip->close();
    if ($wsXml === false) {
        return [];
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($wsXml)) {
        return [];
    }

    $rows = [];
    foreach ($doc->getElementsByTagName('row') as $rowEl) {
        $rn = (int) $rowEl->getAttribute('r');
        if ($rn <= 0) {
            continue;
        }
        $row = [];
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            $col = preg_replace('/[0-9]+/', '', $c->getAttribute('r'));   // "A31" -> "A"
            if ($col === '') {
                continue;
            }
            $t = $c->getAttribute('t');
            if ($t === 'inlineStr') {
                $val = '';
                foreach ($c->getElementsByTagName('t') as $tt) {
                    $val .= $tt->textContent;
                }
            } else {
                $vEl = $c->getElementsByTagName('v')->item(0);
                $raw = $vEl ? $vEl->textContent : '';
                $val = ($t === 's') ? ($shared[(int) $raw] ?? '') : $raw;
            }
            $row[$col] = $val;
        }
        $rows[$rn] = $row;
    }
    return $rows;
}

/**
 * Convert an Excel 1900-system date serial to a 'Y-m-d' string, or null if the
 * value isn't a plausible date serial. The 25569 offset (days from the Excel
 * epoch 1899-12-30 to the Unix epoch) already absorbs Excel's phantom
 * 1900-02-29 for every serial >= 61, which covers all real payroll dates.
 */
function xlsx_serial_to_date($serial): ?string
{
    if (!is_numeric($serial)) {
        return null;
    }
    $s = (float) $serial;
    if ($s < 61) {
        return null;   // pre-1900-03-01 / bogus
    }
    return gmdate('Y-m-d', (int) (($s - 25569) * 86400));
}

/**
 * Path inside the PRIVATE storage dir (server/storage), which is deliberately
 * outside the document root. Payslips live here rather than under uploads/,
 * because public/.htaccess serves any real file it finds — uploads/ has no
 * access control at all, so a payslip there would be readable by anyone who
 * guessed the URL. A deny-all .htaccess is written as a second line of defence
 * in case the directory is ever relocated inside a docroot.
 */
function private_path(string $rel = ''): string
{
    $base = config('private_dir') ?: dirname(__DIR__) . '/storage';
    if (!is_dir($base)) {
        @mkdir($base, 0770, true);
    }
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
}

/** Spreadsheet column index (0-based) → letter: 0 => 'A', 26 => 'AA'. */
function col_letter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $i--;
        $s = chr(65 + ($i % 26)) . $s;
        $i = intdiv($i, 26);
    }
    return $s;
}

/**
 * Read a .csv/.tsv file into the SAME shape xlsx_rows() returns — rows keyed by
 * their 1-based line number, cells keyed by column letter — so every importer can
 * accept either format through one code path. The delimiter is sniffed from the
 * header line (comma, semicolon or tab) and a UTF-8 BOM is stripped.
 */
function csv_rows(string $path): array
{
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    // Sniff the delimiter on the first non-empty line, then rewind.
    $delim = ',';
    while (($probe = fgets($fh)) !== false) {
        if (trim($probe) === '') {
            continue;
        }
        $probe = preg_replace('/^\xEF\xBB\xBF/', '', $probe);
        $counts = [',' => substr_count($probe, ','), ';' => substr_count($probe, ';'), "\t" => substr_count($probe, "\t")];
        arsort($counts);
        $best = array_key_first($counts);
        if ($counts[$best] > 0) {
            $delim = $best;
        }
        break;
    }
    rewind($fh);

    $rows = [];
    $n = 0;
    $first = true;
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        $n++;
        if ($cells === [null]) {
            continue;   // blank line
        }
        if ($first) {
            $first = false;
            if (isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            }
        }
        $row = [];
        foreach (array_values($cells) as $i => $v) {
            $row[col_letter($i)] = $v === null ? '' : trim((string) $v);
        }
        $rows[$n] = $row;
    }
    fclose($fh);
    return $rows;
}

/**
 * Read any supported upload (.xlsx / .csv / .tsv) into the common row shape.
 * $sheetName only applies to workbooks.
 */
function sheet_rows(string $path, ?string $sheetName = null): array
{
    return preg_match('/\.(csv|tsv|txt)$/i', $path) ? csv_rows($path) : xlsx_rows($path, $sheetName);
}

/**
 * Normalize a header cell for matching: lowercase, strip accents/punctuation and
 * collapse whitespace, so "Wise Recipient ID", "wise_recipient_id" and
 * "Wise-Recipient  Id" all reduce to "wise recipient id".
 */
function sheet_norm_label($s): string
{
    $s = strtolower(trim((string) $s));
    $s = str_replace(['_', '-', '.', '/', '\\'], ' ', $s);
    $s = preg_replace('/[^a-z0-9 %]+/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Locate the header row in a parsed sheet by looking for the columns an import
 * declares. Real-world payroll exports have blank leading rows, title banners and
 * merged cells above the table, so we scan rather than assuming row 1.
 *
 * Returns [rowNumber, map] where map is normalizedLabel => columnLetter, or
 * [0, []] when no row contains all the required labels.
 */
function sheet_find_header(array $rows, array $requiredLabels, int $maxScan = 60): array
{
    $need = array_map('sheet_norm_label', $requiredLabels);
    $scanned = 0;
    foreach ($rows as $rowNum => $row) {
        if (++$scanned > $maxScan) {
            break;
        }
        $map = [];
        foreach ($row as $letter => $val) {
            $norm = sheet_norm_label($val);
            if ($norm !== '' && !isset($map[$norm])) {
                $map[$norm] = $letter;
            }
        }
        $hit = true;
        foreach ($need as $label) {
            if (!isset($map[$label])) {
                $hit = false;
                break;
            }
        }
        if ($hit) {
            return [(int) $rowNum, $map];
        }
    }
    return [0, []];
}

/**
 * Import format registry — the single source of truth for what column headers each
 * upload accepts. It drives three things at once: header matching, the on-screen
 * naming-convention guide, and the downloadable blank template. Adding a column
 * here is all that's needed for it to appear in the guide and the template.
 *
 * Each column: name (the canonical header to print in the guide), aliases (other
 * spellings accepted), required, example, desc.
 */
function import_specs(): array
{
    static $specs = null;
    if ($specs !== null) {
        return $specs;
    }
    $specs = [
        'payroll' => [
            'label'   => 'Payroll timesheet',
            'formats' => '.xlsx',
            'intro'   => 'One row per employee, per day, per client. The header row does not have to be '
                       . 'the first row — DeskPulse scans the first 60 rows of every sheet and uses the '
                       . 'first one that contains the required headers, so title banners and blank rows above '
                       . 'the table are fine.',
            'match'   => 'Employees are matched on VT ID, so re-importing the same period updates the same '
                       . 'people instead of creating duplicates.',
            'columns' => [
                ['name' => 'VT ID',        'aliases' => ['employee id', 'staff id', 'vtid'], 'required' => true,
                 'example' => '1042', 'desc' => 'Stable employee reference. Rows without one (or with #N/A) are skipped.'],
                ['name' => 'Contract Name', 'aliases' => ['employee name', 'name'], 'required' => true,
                 'example' => 'Dominique Cuaton Ricamora', 'desc' => 'Employee full name. Used when Wise Name is blank.'],
                ['name' => 'Wise Name',    'aliases' => ['payee name'], 'required' => false,
                 'example' => 'Dominique Cuaton Ricamora', 'desc' => 'Preferred display name; also stored for payouts.'],
                ['name' => 'Client',       'aliases' => ['client name', 'customer'], 'required' => false,
                 'example' => 'Acme Corp', 'desc' => 'Creates the client in your org if it does not exist yet.'],
                ['name' => 'Client ID',    'aliases' => ['clientid'], 'required' => false,
                 'example' => 'C-208', 'desc' => 'Recorded in the client notes.'],
                ['name' => 'Industry',     'aliases' => [], 'required' => false,
                 'example' => 'Logistics', 'desc' => 'Recorded in the client notes.'],
                ['name' => 'Role Name',    'aliases' => ['role', 'job title'], 'required' => false,
                 'example' => 'Virtual Assistant', 'desc' => 'Saved as the employee job title.'],
                ['name' => 'Date',         'aliases' => ['work date', 'day'], 'required' => true,
                 'example' => '2026-08-05', 'desc' => 'Excel date cell or YYYY-MM-DD. One time entry is created per date.'],
                ['name' => 'Adj Credited Hrs', 'aliases' => ['credited time', 'credited hours', 'hours'], 'required' => true,
                 'example' => '7.5', 'desc' => 'Decimal hours credited for that day.'],
                ['name' => 'Payroll Rate', 'aliases' => ['rate', 'hourly rate'], 'required' => false,
                 'example' => '6.50', 'desc' => 'Hourly pay rate; updates the employee record.'],
            ],
        ],
        'wise' => [
            'label'   => 'Wise payout details',
            'formats' => '.xlsx or .csv',
            'intro'   => 'One row per employee. As with the payroll import, the header row is found by '
                       . 'scanning — it does not need to be row 1. Rows whose values are #REF! or #N/A '
                       . 'are skipped, and a repeated header row inside the data is ignored.',
            'match'   => 'Employees are matched in this order: Wise Recipient ID → VT ID → EMAIL → Wise Name. '
                       . 'The first match wins; unmatched rows are reported rather than creating new people.',
            'columns' => [
                ['name' => 'Wise Recipient ID', 'aliases' => ['recipient id', 'wise id'], 'required' => false,
                 'example' => '7c8fc17a-54ba-4b13-d86a-f8b0d627577e', 'desc' => 'Wise recipient UUID. Best match key — include it when you have it.'],
                ['name' => 'Wise Name',   'aliases' => ['account holder', 'recipient name', 'name'], 'required' => true,
                 'example' => 'CINDY RUFILA ESPORLAS', 'desc' => 'Account holder name exactly as Wise holds it.'],
                ['name' => 'EMAIL',       'aliases' => ['email address', 'recipient email'], 'required' => false,
                 'example' => 'cindy@example.com', 'desc' => 'Recipient email. Also used to match the employee.'],
                ['name' => 'Wise account', 'aliases' => ['account', 'account summary', 'bank'], 'required' => false,
                 'example' => 'Wise account  /  BPI ending ·· 1593', 'desc' => 'Payout target as shown in Wise. Free text.'],
                ['name' => 'from Currency', 'aliases' => ['source currency'], 'required' => false,
                 'example' => 'USD', 'desc' => 'Currency you pay from. Defaults to the org pay currency.'],
                ['name' => 'to Currency',  'aliases' => ['target currency'], 'required' => false,
                 'example' => 'USD', 'desc' => 'Currency the recipient receives.'],
                ['name' => 'Source',      'aliases' => ['source account'], 'required' => false,
                 'example' => 'source', 'desc' => 'Wise source-account label; passed straight through to the export.'],
                ['name' => 'Type',        'aliases' => ['recipient type'], 'required' => false,
                 'example' => 'PERSON', 'desc' => 'PERSON or BUSINESS. Defaults to PERSON.'],
                ['name' => 'VT ID',       'aliases' => ['employee id', 'staff id'], 'required' => false,
                 'example' => '1042', 'desc' => 'Employee reference — the most reliable way to match your DeskPulse people.'],
                ['name' => 'Amount',      'aliases' => [], 'required' => false,
                 'example' => '(leave blank)', 'desc' => 'Ignored on import. DeskPulse fills this when it generates a salary run.'],
            ],
        ],
    ];
    return $specs;
}

/** One import spec by key, or null. */
function import_spec(string $key): ?array
{
    return import_specs()[$key] ?? null;
}

/** Canonical + alias labels for a spec column, normalized for matching. */
function import_column_labels(array $col): array
{
    $out = [sheet_norm_label($col['name'])];
    foreach ($col['aliases'] ?? [] as $a) {
        $out[] = sheet_norm_label($a);
    }
    return array_values(array_unique(array_filter($out)));
}

/**
 * Build a reader closure for a spec: given the header map from
 * sheet_find_header(), returns fn(array $row, string $columnName) => string,
 * resolving the canonical name or any of its accepted aliases.
 */
function import_cell_reader(array $spec, array $headerMap): callable
{
    $byName = [];
    foreach ($spec['columns'] as $col) {
        foreach (import_column_labels($col) as $label) {
            if (isset($headerMap[$label]) && !isset($byName[$col['name']])) {
                $byName[$col['name']] = $headerMap[$label];
            }
        }
    }
    return function (array $row, string $columnName) use ($byName): string {
        $letter = $byName[$columnName] ?? null;
        return $letter === null ? '' : trim((string) ($row[$letter] ?? ''));
    };
}

/** The labels a spec must see to consider a row the header row. */
function import_required_labels(array $spec): array
{
    $out = [];
    foreach ($spec['columns'] as $col) {
        if (!empty($col['required'])) {
            $out[] = $col['name'];
        }
    }
    return $out;
}

/** Render a template from server/templates with the given vars. */
function render(string $template, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include dirname(__DIR__) . '/templates/' . $template . '.php';
    return ob_get_clean();
}

/** Render a page inside the main layout. */
function view(string $template, array $vars = [], string $layout = 'layout'): void
{
    $content = render($template, $vars);
    echo render($layout, array_merge($vars, ['content' => $content]));
    exit;
}

// ── CSRF ──
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(24);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        abort(400, 'Invalid CSRF token');
    }
}

// ── Timezone & time formatting ──

/** The viewer's IANA timezone (set as a cookie by the browser); UTC by default. */
function user_tz(): string
{
    $tz = $_COOKIE['dp_tz'] ?? '';
    if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
        return $tz;
    }
    return 'UTC';
}

/** Convert a submitted datetime (UTC ISO from JS, or a local datetime-local
 *  string interpreted in the viewer's tz) into a UTC 'Y-m-d H:i:s' for storage. */
function utc_store(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return gmdate('Y-m-d H:i:s');
    }
    try {
        $dt = new DateTime($s, new DateTimeZone(user_tz()));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $ts = strtotime($s);
        return gmdate('Y-m-d H:i:s', $ts ?: time());
    }
}

/** [startUTC, endUTC] datetimes bounding a local calendar day (Y-m-d) in the viewer tz. */
function local_day_utc_range(string $ymd): array
{
    $tz = new DateTimeZone(user_tz());
    $start = new DateTime($ymd . ' 00:00:00', $tz);
    $end = (clone $start)->modify('+1 day');
    $start->setTimezone(new DateTimeZone('UTC'));
    $end->setTimezone(new DateTimeZone('UTC'));
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/** Render a stored UTC datetime as a <time> element that JS converts to the
 *  viewer's local time. $mode: datetime|date|time|sec|full. */
function tlocal(?string $dt, string $mode = 'datetime'): string
{
    if (!$dt) {
        return '—';
    }
    $epoch = strtotime($dt . ' UTC');
    $fmts = ['datetime' => 'M j, H:i', 'date' => 'M j, Y', 'time' => 'H:i',
             'sec' => 'H:i:s', 'full' => 'M j, Y H:i'];
    $fmt = $fmts[$mode] ?? $fmts['datetime'];
    $iso = gmdate('c', $epoch);
    $fallback = gmdate($fmt, $epoch);   // UTC fallback shown until JS runs
    return '<time class="dp-time" data-utc="' . e($iso) . '" data-fmt="' . e($mode)
        . '">' . e($fallback) . '</time>';
}

function fmt_hms($seconds): string
{
    $seconds = (int) $seconds;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    // Non-breaking space for the same reason as money(): "3h 05m" must not wrap.
    return $h ? "{$h}h\u{00A0}" . sprintf('%02dm', $m) : "{$m}m";
}

/**
 * Display-only money format. The separator is a NON-BREAKING space so a value never
 * wraps as "USD" / "1,234.00" in a narrow table cell or stat card. Never used to build
 * CSV exports (those format their own numbers), and DpPdf maps U+00A0 back to a normal
 * space when rendering payslips.
 */
function money($amount, string $currency = 'USD'): string
{
    return $currency . "\u{00A0}" . number_format((float) $amount, 2);
}

/**
 * Renders the period pills, the ◀ ▶ period navigator, the resolved period label
 * and a custom from→to range form. Pairs with period_ctx()/
 * period_range_from_request() in reports.php, which stashes the resolved context
 * in $GLOBALS['DP_PERIOD_CTX'] so this widget can show the exact window and its
 * neighbours without re-parsing the query string.
 *
 * $periods is [key => label]; pass period_options() (or a subset) rather than a
 * hand-written literal. $extra is preserved on every link and form, which is how
 * page-level filters (?client=, ?user_id=) survive a period change.
 */
function period_switch_html(array $periods, string $activePeriod, string $periodDate, string $path, array $extra = [], string $trailingHtml = ''): string
{
    $ctx = $GLOBALS['DP_PERIOD_CTX'] ?? null;
    $link = function (array $params) use ($path, $extra): string {
        return e(url($path)) . e('?' . http_build_query(array_merge($extra, $params)));
    };
    $hidden = '';
    foreach ($extra as $k => $v) {
        $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }

    // An active period that isn't one of this page's pills (hand-typed ?period=)
    // still gets a pill so the UI never renders with nothing highlighted.
    if ($activePeriod !== 'range' && !isset($periods[$activePeriod])) {
        $periods[$activePeriod] = period_options()[$activePeriod] ?? ucfirst($activePeriod);
    }

    $html = '<div class="period-switch">';
    foreach ($periods as $k => $label) {
        $cls = $activePeriod === $k ? ' class="on"' : '';
        // Keep the current anchor when switching pills so "this week" → "this month"
        // stays on the week you were looking at rather than jumping to today.
        $html .= '<a' . $cls . ' href="' . $link(['period' => $k, 'date' => $periodDate]) . '">' . e($label) . '</a>';
    }

    // ◀ label ▶ — navigate whole periods. Works for every period including day and
    // a custom range (which steps by its own length).
    if ($ctx) {
        $prevParams = $activePeriod === 'range'
            ? ['period' => 'range', 'from' => $ctx['prev'], 'to' => date_add_days($ctx['prev'], max(0, (int) $ctx['days'] - 1))]
            : ['period' => $activePeriod, 'date' => $ctx['prev']];
        $nextParams = $activePeriod === 'range'
            ? ['period' => 'range', 'from' => $ctx['next'], 'to' => date_add_days($ctx['next'], max(0, (int) $ctx['days'] - 1))]
            : ['period' => $activePeriod, 'date' => $ctx['next']];
        $atToday = strcmp($ctx['start_date'], $ctx['today']) <= 0 && strcmp($ctx['end_date'], $ctx['today']) >= 0;
        $html .= '<span class="period-nav">'
            . '<a class="pnav" rel="prev" aria-label="Previous period" href="' . $link($prevParams) . '">&#9664;</a>'
            . '<span class="period-label"' . ($atToday ? ' data-now="1"' : '') . '>' . e($ctx['label']) . '</span>'
            . '<a class="pnav" rel="next" aria-label="Next period" href="' . $link($nextParams) . '">&#9654;</a>'
            . '</span>';
        if (!$atToday) {
            $html .= '<a class="pnav-today" href="' . $link(['period' => $activePeriod === 'range' ? 'week' : $activePeriod, 'date' => $ctx['today']]) . '">Today</a>';
        }
    }

    // Jump straight to a date — now offered for every period, not just week/month.
    if ($activePeriod !== 'range') {
        $html .= '<form method="get" action="' . e(url($path)) . '" class="period-custom">'
            . '<input type="hidden" name="period" value="' . e($activePeriod) . '">' . $hidden
            . '<input type="date" name="date" value="' . e($periodDate) . '"'
            . ' aria-label="Jump to date" onchange="this.form.submit()"></form>';
    }

    // Custom date range (from → to) — available on every page with a date filter.
    $rf = ($ctx['from'] ?? null) ?: ($_GET['from'] ?? '');
    $rt = ($ctx['to'] ?? null) ?: ($_GET['to'] ?? '');
    $rf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rf) ? $rf : '';
    $rt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rt) ? $rt : '';
    $rngOn = $activePeriod === 'range' ? ' on' : '';
    $html .= '<form method="get" action="' . e(url($path)) . '" class="period-custom period-range' . $rngOn . '">'
        . '<input type="hidden" name="period" value="range">' . $hidden
        . '<input type="date" name="from" value="' . e((string) $rf) . '" aria-label="From date" required>'
        . '<span class="rng-sep">→</span>'
        . '<input type="date" name="to" value="' . e((string) $rt) . '" aria-label="To date" required>'
        . '<button type="submit" class="btn sm">Apply</button></form>';
    return $html . $trailingHtml . '</div>';
}

/**
 * Current date-filter as a query-string fragment, for export/CSV links that must
 * mirror the on-screen filter — a custom range (period=range&from&to) or the
 * period+anchor pills. Pass the page's resolved $period and $periodDate.
 */
function period_qs(string $period, string $periodDate): string
{
    if ($period === 'range') {
        $f = $_GET['from'] ?? '';
        $t = $_GET['to'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
            return 'period=range&from=' . rawurlencode($f) . '&to=' . rawurlencode($t);
        }
    }
    return 'period=' . rawurlencode($period) . '&date=' . rawurlencode($periodDate);
}

/** Human label for a role slug. */
function role_label(string $role): string
{
    return [
        'super_admin'   => 'Super admin',
        'client_admin'  => 'Company admin',
        'manager'       => 'Team manager',
        'hr_manager'    => 'HR manager',
        'it_admin'      => 'IT admin',
        'member'        => 'Employee',
        'client_viewer' => 'Client / viewer',
    ][$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/** Cached project-name lookup for table rows. */
function project_name($id): string
{
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $r = db_one('SELECT name FROM projects WHERE id = ?', [$id]);
        $cache[$id] = $r['name'] ?? '—';
    }
    return $cache[$id];
}

/** Cached task-title lookup for table rows. */
function task_name($id): string
{
    static $cache = [];
    if (!$id) {
        return '—';
    }
    if (!array_key_exists($id, $cache)) {
        $r = db_one('SELECT title FROM tasks WHERE id = ?', [$id]);
        $cache[$id] = $r['title'] ?? '—';
    }
    return $cache[$id];
}

/** Cached client-name lookup for table rows. */
function client_name($id): string
{
    static $cache = [];
    if (!$id) {
        return '—';
    }
    if (!array_key_exists($id, $cache)) {
        $r = db_one('SELECT name FROM clients WHERE id = ?', [$id]);
        $cache[$id] = $r['name'] ?? '—';
    }
    return $cache[$id];
}

/** Flash messages. */
function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
