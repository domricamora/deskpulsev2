<?php
/**
 * Bootstrap: load config, compute base path, start the session, wire helpers and
 * handler modules, and expose a tiny Router. Included by public/index.php.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$SERVER_DIR = dirname(__DIR__);            // .../deskpulse/server
require $SERVER_DIR . '/src/helpers.php';

// ── Config ──
$configFile = $SERVER_DIR . '/config.php';
if (!file_exists($configFile)) {
    $configFile = $SERVER_DIR . '/config.example.php';   // dev fallback
}
$CONFIG = require $configFile;

// ── Base path (so the app works under any subdirectory on WAMP) ──
// SCRIPT_NAME is e.g. /vtnew/deskpulse/server/public/index.php
$BASE_PATH = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// ── Transport security (must run before session_start()/any output) ──
// Detect HTTPS directly OR behind a proxy/LiteSpeed terminator (X-Forwarded-Proto,
// or the request arriving on port 443). request_is_https() lives in helpers.php so
// public_base_url() applies the identical rule — they used to disagree.
$dpHttps = request_is_https();

$dpHost = $_SERVER['HTTP_HOST'] ?? '';
// Localhost/loopback → this is WAMP dev over plain http; never redirect or HSTS it.
$dpIsLocal = $dpHost !== '' && (
    str_starts_with($dpHost, 'localhost')
    || str_starts_with($dpHost, '127.0.0.1')
    || str_starts_with($dpHost, '[::1]')
    || $dpHost === '::1'
);

/**
 * Emit hardening headers on every response. Called early, before session_start()
 * or any echo, so header() succeeds (nothing has been output yet). These are
 * page/HTML headers; the JSON/webhook API sets its own Content-Type and is
 * unaffected — the headers are harmless there too.
 *
 * NOTE: the CSP keeps 'unsafe-inline' for style-src AND script-src on purpose —
 * the app uses inline <style>/<script> and inline event/handlers heavily, so a
 * strict policy would break pages. This is a deliberate pragmatic choice; a future
 * hardening pass could move to per-request nonces and drop 'unsafe-inline'.
 */
function send_security_headers(bool $https): void
{
    if (headers_sent()) {
        return;
    }
    // KEEP IN SYNC WITH public/.htaccess — Apache serves static files without invoking
    // PHP, so a one-sided change leaves assets under a different policy.
    // The analytics hosts are required for GA4 and Microsoft Clarity; without them the
    // tags load into a blocked request and silently collect nothing, which is the most
    // common reason analytics "just doesn't work".
    header(
        "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "img-src 'self' data: https://*.google-analytics.com https://www.googletagmanager.com https://c.clarity.ms; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.clarity.ms https://*.clarity.ms; "
        . "font-src 'self'; "
        . "connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com "
        . "https://www.googletagmanager.com https://*.clarity.ms https://c.bing.com; "
        . "form-action 'self'"
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
    // HSTS only over real HTTPS — never on plain http/localhost, or browsers would
    // pin the host to https and break local dev.
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Force HTTPS on the live server only. No-op on local WAMP dev (localhost over
// plain http keeps working) and when the host is unknown.
if (!$dpHttps && !$dpIsLocal && $dpHost !== '') {
    header('Location: https://' . $dpHost . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

send_security_headers($dpHttps);

// ── Sessions ──
// Store sessions in a private per-app directory rather than the server's shared
// default. On shared hosting the default save_path is often world-shared or purged
// by other tenants' garbage collection, which silently drops our sessions and breaks
// CSRF (a lost $_SESSION['csrf'] → 400). $SERVER_DIR sits above the web docroot, so
// this folder is never web-accessible. Falls back to the default if it isn't writable.
$sessDir = $SERVER_DIR . '/sessions';
if (!is_dir($sessDir)) {
    @mkdir($sessDir, 0700, true);
}
// Belt-and-braces: deny web access in case a deployment ever places this under the
// docroot (on the standard layout it already sits above it).
$sessHt = $sessDir . '/.htaccess';
if (is_dir($sessDir) && !is_file($sessHt)) {
    @file_put_contents($sessHt,
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}
if (is_dir($sessDir) && is_writable($sessDir)) {
    session_save_path($sessDir);
    ini_set('session.gc_maxlifetime', '86400');   // keep sessions ~1 day
}
// $dpHttps was computed above (transport-security block) and drives the secure flag.
session_name('deskpulse');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $BASE_PATH !== '' ? $BASE_PATH : '/',
    'httponly' => true,
    'secure'   => $dpHttps,
    'samesite' => 'Lax',
]);
session_start();

// ── Modules ──
require $SERVER_DIR . '/src/db.php';
require $SERVER_DIR . '/src/auth.php';
require $SERVER_DIR . '/src/reports.php';
require $SERVER_DIR . '/src/webhooks.php';
require $SERVER_DIR . '/src/remote.php';
require $SERVER_DIR . '/src/dashboard.php';
require $SERVER_DIR . '/src/pdf.php';
require $SERVER_DIR . '/src/mailer.php';
require $SERVER_DIR . '/src/payroll.php';
require $SERVER_DIR . '/src/messaging.php';
require $SERVER_DIR . '/src/payments.php';
require $SERVER_DIR . '/src/wise.php';
require $SERVER_DIR . '/src/share.php';
require $SERVER_DIR . '/src/marketing.php';
require $SERVER_DIR . '/src/content.php';
require $SERVER_DIR . '/src/oauth.php';

// First-touch campaign attribution. After the modules (it lives in marketing.php) and
// before any handler, so a visitor is credited to the channel that brought them even
// if they sign up several pages later.
attribution_capture();

/** Minimal path router with {param} placeholders. */
class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): void  { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        // HEAD is GET without a body (RFC 9110). Routing it separately meant every PHP
        // route answered 404 to a HEAD request — which is what crawlers, link checkers,
        // uptime monitors and several social unfurlers send first. PHP discards the body
        // for a HEAD response automatically, so dispatching to the GET handler is correct
        // and the Content-Length stays accurate.
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            if ($m !== $lookup) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return;
            }
        }
        abort(404, 'Not found');
    }
}

/** Compute the route path relative to the base directory. */
function current_route_path(): string
{
    global $BASE_PATH;
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $uri = rawurldecode($uri);
    if ($BASE_PATH && str_starts_with($uri, $BASE_PATH)) {
        $uri = substr($uri, strlen($BASE_PATH));
    }
    return $uri === '' ? '/' : $uri;
}

/** PATCH/DELETE method override for clients/forms that can't send them. */
function request_method(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'POST' && !empty($_POST['_method'])) {
        return strtoupper($_POST['_method']);
    }
    return $m;
}
