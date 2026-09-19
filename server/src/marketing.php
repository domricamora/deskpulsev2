<?php
/** Public, company-agnostic marketing site: landing + download pages. */

// ─────────────────────────────────────────────────────────────────────────────
// Page metadata + structured data
//
// Every marketing page renders its <head> through templates/marketing/_head.php,
// which expects the array marketing_meta() returns. Before this existed each page
// hand-rolled its own <head>, so og:image, JSON-LD and the analytics snippet had
// to be pasted into landing.php, download.php, layout_public.php and every new
// page separately — and they had already drifted (download.php carried no
// og:image, no twitter tags and no structured data at all).
// ─────────────────────────────────────────────────────────────────────────────

/** Default social-share image. A raster, deliberately: no platform renders an SVG. */
const OG_IMAGE_PATH = '/assets/img/og-image.png';
const OG_IMAGE_W = 1200;
const OG_IMAGE_H = 630;

/**
 * Build the <head> data for a marketing page. Every URL is resolved through
 * abs_url() — never public_base_url() . url(), which applies the base path twice.
 */
function marketing_meta(array $o = []): array
{
    $path = $o['path'] ?? '/';
    $meta = array_merge([
        'title'        => 'DeskPulse — Remote Work Monitoring & Time Tracking',
        'description'  => 'Remote-work monitoring and time tracking for teams and individuals: '
            . 'automatic time tracking, activity and idle detection, app and window insights, '
            . 'screenshots, per-task time, client billing, payroll and shareable reports.',
        'keywords'     => '',
        'canonical'    => abs_url($path),
        'robots'       => 'index,follow',
        'og_type'      => 'website',
        'og_title'     => null,          // falls back to title
        'og_desc'      => null,          // falls back to description
        'og_image'     => abs_url(OG_IMAGE_PATH),
        'og_image_alt' => 'DeskPulse — remote-work monitoring dashboard showing team activity, '
            . 'tracked hours and billable value',
        'twitter_site' => '@deskpulse',
        'jsonld'       => [],
    ], $o);

    // A page may pass a bare path for og_image; make it absolute either way.
    if ($meta['og_image'] !== '' && !preg_match('~^https?://~i', (string) $meta['og_image'])) {
        $meta['og_image'] = abs_url((string) $meta['og_image']);
    }
    $meta['og_title'] = $meta['og_title'] ?? $meta['title'];
    $meta['og_desc']  = $meta['og_desc'] ?? $meta['description'];
    return $meta;
}

/**
 * SoftwareApplication + the price ladder as an AggregateOffer.
 *
 * The offers block used to be a hardcoded `"price": "0"`, which told Google the
 * product was free while the pricing table on the same page said otherwise. It is
 * now derived from plan_base_prices(), so it cannot contradict what we charge.
 */
function schema_software(array $prices): array
{
    $money = fn($v) => number_format((float) $v, 2, '.', '');
    $perSeat = (float) $prices['per_seat'];
    $cap     = (float) ($prices['seat_cap'] ?? 0);
    $seatMin = max(1, (int) ($prices['seats_bill_min'] ?? 0));

    $offers = [[
        '@type' => 'Offer', 'name' => 'Solo', 'price' => $money($prices['solo'] ?? 0),
        'priceCurrency' => 'USD', 'description' => 'Free forever, one user',
    ], [
        '@type' => 'Offer', 'name' => 'Individual', 'price' => $money($prices['individual']),
        'priceCurrency' => 'USD', 'description' => 'Per month, one user',
    ], [
        '@type' => 'Offer', 'name' => 'Team', 'price' => $money($perSeat),
        'priceCurrency' => 'USD',
        'description' => 'Per seat per month' . ($seatMin > 1 ? ", {$seatMin} seat minimum" : ''),
    ]];
    if ($cap > 0) {
        $covers = (int) ($prices['seats_cap_covers'] ?? 0);
        $offers[] = [
            '@type' => 'Offer', 'name' => 'Organization', 'price' => $money($cap),
            'priceCurrency' => 'USD',
            'description' => 'Flat monthly — your bill never exceeds this'
                . ($covers > 0 ? ", up to {$covers} seats" : ''),
        ];
    }
    $high = $cap > 0 ? $cap : max((float) $prices['organization'], $perSeat);

    return [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'DeskPulse',
        'url' => abs_url('/'),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Windows, macOS, Linux',
        'softwareVersion' => DOWNLOAD_VERSION,
        'description' => 'Remote work monitoring and time tracking for teams and individuals: '
            . 'automatic time tracking, activity & idle detection, app/window insights, screenshots, '
            . 'per-task time, role-based access, client billing, audit log and shareable reports.',
        'publisher' => ['@type' => 'Organization', 'name' => 'DeskPulse', 'url' => abs_url('/')],
        'offers' => [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'USD',
            'lowPrice' => $money($prices['solo'] ?? 0),
            'highPrice' => $money($high),
            'offerCount' => (string) count($offers),
            'offers' => $offers,
        ],
    ];
}

function schema_organization(): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'DeskPulse',
        'url' => abs_url('/'),
        'logo' => abs_url('/assets/img/logo.svg'),
        'description' => 'Remote-work monitoring and time tracking for BPOs, outsourcing teams '
            . 'and individuals.',
    ];
}

/** @param array<int,array{0:string,1:string}> $qa question/answer pairs */
function schema_faq(array $qa): array
{
    $items = [];
    foreach ($qa as [$q, $a]) {
        $items[] = ['@type' => 'Question', 'name' => $q,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
}

/** @param array<int,array{0:string,1:string}> $trail name/path pairs, in order */
function schema_breadcrumb(array $trail): array
{
    $items = [];
    foreach ($trail as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1,
                    'name' => $name, 'item' => abs_url($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
            'itemListElement' => $items];
}

function schema_article(array $fm, string $path): array
{
    $out = [
        '@context' => 'https://schema.org',
        '@type' => $fm['schema_type'] ?? 'Article',
        'headline' => $fm['title'] ?? '',
        'description' => $fm['description'] ?? '',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => abs_url($path)],
        'publisher' => ['@type' => 'Organization', 'name' => 'DeskPulse', 'url' => abs_url('/')],
    ];
    if (!empty($fm['author'])) {
        $out['author'] = ['@type' => 'Person', 'name' => $fm['author']];
    }
    if (!empty($fm['published'])) {
        $out['datePublished'] = $fm['published'];
    }
    if (!empty($fm['lastmod'])) {
        $out['dateModified'] = $fm['lastmod'];
    }
    if (!empty($fm['og_image'])) {
        $out['image'] = abs_url($fm['og_image']);
    }
    return $out;
}

/**
 * Analytics measurement IDs: the platform org row wins, then config.php, then none.
 *
 * These are echoed inside a <script> in _analytics.php, so they are validated on the
 * way OUT as well as on the way in — without that, a typo (or a compromised super
 * admin) in a settings field becomes stored XSS on the public marketing site.
 * Nothing renders when unconfigured.
 */
function analytics_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cfg = (array) config('analytics', []);
    $ga = (string) ($cfg['ga4_id'] ?? '');
    $cl = (string) ($cfg['clarity_id'] ?? '');
    try {
        $id = platform_org_id();
        if ($id) {
            $r = db_one('SELECT ga4_measurement_id, clarity_project_id FROM organizations WHERE id = ?', [$id]);
            $ga = trim((string) ($r['ga4_measurement_id'] ?? '')) ?: $ga;
            $cl = trim((string) ($r['clarity_project_id'] ?? '')) ?: $cl;
        }
    } catch (Throwable $e) {
        // A marketing page must render even if the database is unreachable.
    }
    $ids = [
        'ga4'     => preg_match('/^G-[A-Z0-9]{4,15}$/', $ga) ? $ga : '',
        'clarity' => preg_match('/^[a-z0-9]{6,15}$/i', $cl) ? $cl : '',
    ];
    return $ids;
}

/**
 * Remember the campaign that brought this visitor, first-touch.
 *
 * Called on every public page render. First touch wins: if someone arrives from a
 * LinkedIn post, reads a comparison page, then returns via Google a week later, the
 * LinkedIn post earned it — overwriting would credit the last click and quietly
 * over-reward branded search.
 *
 * GA4 can tell you which channel produced sessions. Only this can tell you which
 * channel produced a PAYING organization, because the stamp lands on the org row.
 */
function attribution_capture(): void
{
    if (!empty($_SESSION['dp_utm'])) {
        return;
    }
    $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    $utm = [];
    foreach ($keys as $k) {
        $v = trim((string) ($_GET[$k] ?? ''));
        if ($v !== '') {
            $utm[$k] = substr(preg_replace('/[^\w \-\.\+]/u', '', $v), 0, 80);
        }
    }
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    // An off-site referrer is worth keeping even with no UTM — that is how directory
    // and community traffic shows up, and neither ever carries our tags.
    if (!$utm && $ref !== '') {
        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        $self = strtolower((string) parse_url(public_base_url(), PHP_URL_HOST));
        if ($host !== '' && $host !== $self) {
            $utm['utm_source'] = substr($host, 0, 80);
            $utm['utm_medium'] = 'referral';
        }
    }
    if ($utm) {
        $utm['landing'] = substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 190);
        $_SESSION['dp_utm'] = $utm;
    }
}

/** Write the captured first-touch attribution onto a newly created organization. */
function attribution_stamp(int $orgId): void
{
    $utm = $_SESSION['dp_utm'] ?? null;
    if (!$utm || $orgId <= 0) {
        return;
    }
    try {
        db_exec('UPDATE organizations SET utm_source = ?, utm_medium = ?, utm_campaign = ?,
                 utm_content = ?, utm_landing = ? WHERE id = ?', [
            $utm['utm_source'] ?? null, $utm['utm_medium'] ?? null, $utm['utm_campaign'] ?? null,
            $utm['utm_content'] ?? null, $utm['landing'] ?? null, $orgId,
        ]);
    } catch (Throwable $e) {
        // Attribution is reporting, never a reason to fail a signup.
    }
}

/** The homepage FAQ, shared by the rendered accordion and the FAQPage JSON-LD. */
function marketing_home_faqs(): array
{
    return [
        ['Does DeskPulse work for individuals as well as teams?',
         'Yes. Solo users can track their own time and activity, and teams get manager dashboards, '
         . 'approvals, a live view, role-based access and client billing.'],
        ['What does the desktop agent track?',
         "Active and idle time, the active window and app, running tasks, the task you're working "
         . 'on, and periodic screenshots — only while tracking is on. No keystroke content is ever '
         . 'captured. Capture intervals are set centrally by your admin/IT policy.'],
        ['Which roles are available?',
         'Seven: super admin, company admin, team manager, HR manager, IT admin, employee, and '
         . 'client/external viewer. Access is capability-based; managers and viewers are scoped to '
         . 'their assigned teams.'],
        ['Is it private and transparent?',
         'Tracking runs only while the worker presses Start, a recording indicator is always '
         . 'visible, screenshots can be blurred, and screenshots are never shown on public share '
         . 'links.'],
        ['What platforms does the agent run on?',
         'A native Windows installer, a macOS app, and a cross-platform portable package that also '
         . 'runs on Linux.'],
        ['Can I bill clients with DeskPulse?',
         'Yes. Set a bill rate per worker (hourly or a flat monthly service charge) and DeskPulse '
         . 'reports billing per agent and per customer.'],
        ['Does DeskPulse detect mouse jigglers and input emulators?',
         'Yes. Only genuine human input counts as active time: intervals with no real '
         . 'mouse/keyboard activity are recorded as inactive, jiggler-style movement is filtered by '
         . 'pattern, and synthetic (injected) input is detected on Windows and ignored. Sessions '
         . 'also auto-close when an agent goes offline so live status and totals stay accurate.'],
        ['Can I set work schedules and see payslips?',
         "HR or company admins set each employee's standard work hours; monitoring outside the "
         . 'schedule is allowed but flagged. Every member sees a daily payslip computed from '
         . 'tracked time, and admins/HR get an organization-wide salary breakdown.'],
    ];
}

function marketing_index(): void
{
    // Signed-in users normally land on the app, but the dashboard logo links here
    // with ?site=1 so they can deliberately reach the public marketing page.
    if (current_user() && empty($_GET['site'])) {
        redirect('/app');
    }
    echo render('marketing/landing', [
        'title' => 'DeskPulse — Remote work monitoring for teams & individuals',
    ]);
    exit;
}

const DOWNLOAD_VERSION = 'v1';

/** /pricing — the ladder as a real, indexable page (the landing page anchor is not one). */
function marketing_pricing(): void
{
    $prices = plan_base_prices();
    echo render('marketing/pricing', [
        'meta' => marketing_meta([
            'path'  => '/pricing',
            'title' => 'Pricing — per-seat time tracking that stops at a cap · DeskPulse',
            'description' => 'DeskPulse is ' . money_short((float) $prices['per_seat'])
                . ' per seat per month and your bill never exceeds '
                . money_short((float) $prices['seat_cap'])
                . '. Every feature is included at every price — no screenshot upsell, no add-on modules.',
            'jsonld' => [
                schema_software($prices),
                schema_breadcrumb([['DeskPulse', '/'], ['Pricing', '/pricing']]),
                schema_faq(marketing_pricing_faqs($prices)),
            ],
        ]),
        'prices' => $prices,
        'trial_days' => platform_trial_days(),
    ]);
    exit;
}

/** /tools/cost-calculator — a free tool: what a team pays here versus the incumbents. */
function marketing_calculator(): void
{
    $prices = plan_base_prices();
    echo render('marketing/calculator', [
        'meta' => marketing_meta([
            'path'  => '/tools/cost-calculator',
            'title' => 'BPO monitoring cost calculator — what you actually pay per seat · DeskPulse',
            'description' => 'Enter your seat count and see what Hubstaff, Time Doctor, Insightful, '
                . 'ActivTrak, DeskTime and Time Champ cost you a year — against a bill that stops at '
                . money_short((float) $prices['seat_cap']) . '.',
            'jsonld' => [
                schema_breadcrumb([['DeskPulse', '/'], ['Cost calculator', '/tools/cost-calculator']]),
            ],
        ]),
        'prices' => $prices,
        'vendors' => competitor_prices(),
    ]);
    exit;
}

/** Short money label: drops the .00 on whole amounts. Display only. */
function money_short(float $v): string
{
    return '$' . (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 2));
}

/**
 * Published competitor list prices, for the cost calculator and comparison pages.
 *
 * Mirrors campaign-state/data/prices.json. Every entry carries the tier the figure
 * belongs to and the date it was last verified, because quoting a competitor's price
 * without saying which tier it buys is how comparison pages become dishonest — and
 * screenshots, the feature this market actually shops for, sit at very different tiers.
 */
function competitor_prices(): array
{
    return [
        ['key' => 'hubstaff', 'name' => 'Hubstaff', 'tier' => 'Team', 'per_seat' => 10.00,
         'note' => 'Screenshots start here; Insights is a $2.50/seat add-on', 'checked' => '2026-08-08'],
        ['key' => 'timedoctor', 'name' => 'Time Doctor', 'tier' => 'Standard', 'per_seat' => 11.70,
         'note' => 'No free tier', 'checked' => '2026-08-08'],
        ['key' => 'insightful', 'name' => 'Insightful', 'tier' => 'Productivity', 'per_seat' => 10.00,
         'note' => 'Approximate — list price varies by term', 'checked' => '2026-08-08'],
        ['key' => 'activtrak', 'name' => 'ActivTrak', 'tier' => 'Essentials', 'per_seat' => 10.00,
         'note' => 'Approximate — list price varies by term', 'checked' => '2026-08-08'],
        ['key' => 'desktime', 'name' => 'DeskTime', 'tier' => 'Pro', 'per_seat' => 7.00,
         'note' => 'From $6.42/seat billed annually', 'checked' => '2026-08-08'],
        ['key' => 'timechamp', 'name' => 'Time Champ', 'tier' => 'Standard', 'per_seat' => 6.90,
         'note' => 'No free tier', 'checked' => '2026-08-08'],
    ];
}

/** FAQ for /pricing — rendered on the page and emitted as FAQPage structured data. */
function marketing_pricing_faqs(array $prices): array
{
    $cap = money_short((float) $prices['seat_cap']);
    $seat = money_short((float) $prices['per_seat']);
    $capSeats = plan_cap_seats($prices);
    $minSeats = max(1, (int) $prices['seats_bill_min']);
    $covers = (int) $prices['seats_cap_covers'];
    return [
        ["What does \"your bill never exceeds {$cap}\" mean?",
         "DeskPulse is {$seat} per seat per month. Once your team reaches {$capSeats} seats the "
         . "total reaches {$cap} and stops there. Adding people beyond that point costs nothing extra"
         . ($covers > 0 ? ", up to {$covers} seats" : '') . '.'],
        ['Is there a minimum?',
         "The Team plan starts at {$minSeats} seats, so the smallest Team bill is "
         . money_short(plan_seat_price($minSeats, $prices)) . ' a month. Below that, the Individual '
         . 'and free Solo plans cover single users.'],
        ['Are features held back on the cheaper plans?',
         'No. Screenshots, activity tracking, the seven roles, client billing, payroll, the audit '
         . 'log and the API are included at every paid price. Plans differ by seat count, not by '
         . 'feature. This is a deliberate difference from vendors who put screenshots behind a '
         . 'higher tier and sell analytics as a per-seat add-on.'],
        ['Do I need a credit card to try it?',
         'No. The trial needs no card. When you decide to keep DeskPulse you pay by bank transfer, '
         . 'and you tell us it is on the way from your subscription page.'],
        ['What happens if my headcount changes mid-month?',
         'Your bill follows your seat count, and a seat is any staff login that is not a read-only '
         . 'client portal viewer. It is recalculated from your live team, so removing people lowers '
         . 'the bill at the next cycle rather than leaving you paying for empty desks.'],
        ['Can I cancel?',
         'Yes, at any time — there is no annual lock-in on the monthly plans. Your data stays '
         . 'exportable while the account is open, and you can take timesheets, screenshots metadata '
         . 'and payroll out as CSV.'],
    ];
}

function marketing_download(): void
{
    echo render('marketing/download', [
        'title' => 'Download DeskPulse ' . DOWNLOAD_VERSION . ' for Windows, macOS & Linux',
        'download_url' => config('download_url', ''),
        'has_win' => prebuilt_installer() !== null,
        'has_mac' => prebuilt_mac() !== null,
        'has_linux' => prebuilt_linux() !== null,
    ]);
    exit;
}

/** Path to a prebuilt macOS installer (.dmg) or bundle zip, or null. */
function prebuilt_mac(): ?string
{
    foreach (['/DeskPulse*.dmg', '/DeskPulse*mac*.zip', '/DeskPulse*macOS*.zip'] as $pat) {
        $glob = glob(downloads_dir() . $pat);
        if ($glob) {
            return $glob[0];
        }
    }
    return null;
}

function downloads_dir(): string
{
    return public_dir('downloads');
}

/** Path to a prebuilt .exe installer dropped into server/public/downloads, or null. */
function prebuilt_installer(): ?string
{
    $dir = downloads_dir();
    foreach (['DeskPulse-Setup.exe', 'DeskPulse-' . DOWNLOAD_VERSION . '-Setup.exe'] as $name) {
        if (is_file("$dir/$name")) {
            return "$dir/$name";
        }
    }
    $glob = glob("$dir/DeskPulse*Setup*.exe");
    return $glob ? $glob[0] : null;
}

/** Path to a prebuilt compiled bundle ZIP (PyInstaller output), or null. */
function prebuilt_bundle(): ?string
{
    $glob = glob(downloads_dir() . '/DeskPulse*Windows*.zip');
    return $glob ? $glob[0] : null;
}

/** Path to a prebuilt native Linux tarball dropped into downloads/, or null. */
function prebuilt_linux(): ?string
{
    foreach (['/DeskPulse*Linux*.tar.gz', '/DeskPulse*linux*.tar.gz', '/DeskPulse*.AppImage'] as $pat) {
        $glob = glob(downloads_dir() . $pat);
        if ($glob) {
            return $glob[0];
        }
    }
    return null;
}

/**
 * GET /download/app — make the app downloadable.
 *
 * Serves a prebuilt Windows installer if an admin has placed one in
 * server/public/downloads/; otherwise builds a portable, ready-to-run
 * DeskPulse-v1.zip on the fly (agent source + one-click .bat launchers).
 */
function marketing_download_app(): void
{
    $os = $_GET['os'] ?? 'win';

    if ($os === 'mac') {
        $mac = prebuilt_mac();
        if ($mac) {
            serve_file($mac, basename($mac));
        }
        build_portable_zip();   // cross-platform fallback (runs on macOS too)
    }

    if ($os === 'linux') {
        // Prefer a native Linux tarball if an admin dropped one in downloads/;
        // otherwise the cross-platform portable package (runs on Linux too).
        $tar = prebuilt_linux();
        if ($tar) {
            serve_file($tar, basename($tar));
        }
        build_portable_zip();
    }

    // Windows (default)
    $installer = prebuilt_installer();
    if ($installer) {
        serve_file($installer, 'DeskPulse-' . DOWNLOAD_VERSION . '-Setup.exe');
    }
    $bundle = prebuilt_bundle();
    if ($bundle) {
        serve_file($bundle, basename($bundle));
    }
    build_portable_zip();
}

/** Stream a file as a download, then exit. */
function serve_file(string $path, string $filename): void
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = $ext === 'exe' ? 'application/vnd.microsoft.portable-executable'
        : ($ext === 'dmg' ? 'application/x-apple-diskimage' : 'application/zip');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** Friendly fallback when no native installer or agent source is available to
 *  package (e.g. macOS/Linux on a server-only production deploy). Avoids a 500. */
function download_unavailable(): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $back = e(url('/download'));
    $win  = e(url('/download/app?os=win'));
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Download · DeskPulse</title>'
       . '<body style="font-family:system-ui,\'Segoe UI\',Arial,sans-serif;max-width:640px;margin:3rem auto;padding:0 1.2rem;line-height:1.6;color:#1c2333">'
       . '<h2 style="color:#16224a">This build isn\'t ready yet</h2>'
       . '<p>A native installer for this platform is on the way. In the meantime you can install the '
       . '<b>Windows</b> app, or ask your administrator for the desktop agent.</p>'
       . '<p><a href="' . $win . '" style="display:inline-block;background:#2f5bd6;color:#fff;padding:.6rem 1.1rem;border-radius:8px;text-decoration:none;font-weight:600">Download for Windows</a></p>'
       . '<p><a href="' . $back . '">&larr; Back to downloads</a></p>';
    exit;
}

function build_portable_zip(): void
{
    $root = dirname(__DIR__, 1);            // server/
    $repo = dirname($root);                 // repo root
    $base = 'DeskPulse-' . DOWNLOAD_VERSION;

    // Production deployments ship only server/ — the sibling agent/ source tree is
    // absent, so the from-source portable package can't be built. Without this guard
    // the RecursiveDirectoryIterator below throws and the request 500s (this was the
    // cause of the macOS/Linux download failing on the live site). Show a clean page.
    $agentDir = $repo . '/agent';
    if (!is_dir($agentDir)) {
        download_unavailable();
    }

    $tmp = tempnam(sys_get_temp_dir(), 'dpzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    // Agent source (skip caches / compiled files).
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($agentDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (strpos($path, '__pycache__') !== false || str_ends_with($path, '.pyc')) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($path, strlen($repo) + 1));   // agent/...
        $zip->addFile($path, "$base/$rel");
    }
    // requirements.txt
    if (is_file($repo . '/requirements.txt')) {
        $zip->addFile($repo . '/requirements.txt', "$base/requirements.txt");
    }

    $server = public_base_url();
    // Windows launchers
    $zip->addFromString("$base/Install-DeskPulse.bat", install_bat());
    $zip->addFromString("$base/Run-DeskPulse.bat", run_bat());
    // macOS / Linux launchers (double-clickable .command on macOS)
    $zip->addFromString("$base/install.command", install_sh());
    $zip->addFromString("$base/run.command", run_sh());
    $zip->addFromString("$base/README.txt", portable_readme($server));
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $base . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function install_bat(): string
{
    return "@echo off\r\n"
        . "setlocal\r\n"
        . "cd /d \"%~dp0\"\r\n"
        . "echo Installing DeskPulse v1...\r\n"
        . "where py >nul 2>nul\r\n"
        . "if errorlevel 1 (\r\n"
        . "  echo Python 3.10+ is required. Install it from https://www.python.org/downloads/ ^(tick \"Add to PATH\"^), then run this again.\r\n"
        . "  pause & exit /b 1\r\n"
        . ")\r\n"
        . "py -m venv .venv || ( echo Could not create virtual environment. & pause & exit /b 1 )\r\n"
        . ".venv\\Scripts\\python -m pip install --upgrade pip\r\n"
        . ".venv\\Scripts\\python -m pip install -r requirements.txt || ( echo Install failed. & pause & exit /b 1 )\r\n"
        . "echo.\r\n"
        . "echo DeskPulse installed. Double-click Run-DeskPulse.bat to start.\r\n"
        . "pause\r\n";
}

function run_bat(): string
{
    return "@echo off\r\n"
        . "cd /d \"%~dp0\"\r\n"
        . "if not exist .venv\\Scripts\\pythonw.exe (\r\n"
        . "  echo Please run Install-DeskPulse.bat first.\r\n"
        . "  pause & exit /b 1\r\n"
        . ")\r\n"
        . "start \"DeskPulse\" .venv\\Scripts\\pythonw.exe -m agent.main\r\n";
}

function install_sh(): string
{
    return "#!/bin/bash\n"
        . "# DeskPulse v1 installer (macOS / Linux)\n"
        . "cd \"$(dirname \"$0\")\"\n"
        . "echo \"Installing DeskPulse v1...\"\n"
        . "PY=python3\n"
        . "command -v \$PY >/dev/null 2>&1 || { echo \"Python 3.10+ is required (https://www.python.org/downloads/).\"; exit 1; }\n"
        . "\$PY -m venv .venv || { echo \"Could not create virtual environment.\"; exit 1; }\n"
        . ".venv/bin/python -m pip install --upgrade pip\n"
        . ".venv/bin/python -m pip install -r requirements.txt || { echo \"Install failed.\"; exit 1; }\n"
        . "echo \"DeskPulse installed. Run run.command to start.\"\n";
}

function run_sh(): string
{
    return "#!/bin/bash\n"
        . "cd \"$(dirname \"$0\")\"\n"
        . "if [ ! -x .venv/bin/python ]; then echo \"Run install.command first.\"; exit 1; fi\n"
        . ".venv/bin/python -m agent.main\n";
}

function portable_readme(string $server): string
{
    return "DeskPulse v1 - desktop monitoring agent (portable, cross-platform)\r\n"
        . "=================================================================\r\n\r\n"
        . "Requires Python 3.10+ (https://www.python.org/downloads/).\r\n\r\n"
        . "WINDOWS:\r\n"
        . "  1. Double-click Install-DeskPulse.bat (one time).\r\n"
        . "  2. Double-click Run-DeskPulse.bat to launch (runs in the system tray).\r\n\r\n"
        . "macOS / LINUX:\r\n"
        . "  1. In Terminal:  chmod +x install.command run.command\r\n"
        . "  2. Run ./install.command (one time), then ./run.command.\r\n"
        . "     (On macOS you can also double-click them in Finder.)\r\n\r\n"
        . "THEN, in the app:\r\n"
        . "  - Sign in with your DeskPulse account and this Server URL:\r\n"
        . "      $server\r\n"
        . "  - Pick a project/task and press Start. Tracking only runs while a\r\n"
        . "    session is active.\r\n\r\n"
        . "Prefer a native installer? Windows: agent\\packaging\\build.ps1 (.exe).\r\n"
        . "macOS: agent/packaging/build_mac.sh (.app + .dmg, must run on a Mac).\r\n"
        . "Both are also built automatically by the GitHub Actions workflow.\r\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Content pages: /privacy /terms /security /about /contact, /compare/{slug},
// /use-cases/{slug}, /blog and /blog/{slug}. All served from server/content/.
// ─────────────────────────────────────────────────────────────────────────────

/** Render one markdown page from a collection, or 404. */
function marketing_content(string $collection, string $slug): void
{
    $path = content_path($collection, $slug);
    if (!$path) {
        abort(404, 'Page not found');
    }
    $prefix = content_collections()[$collection] ?? '';
    $urlPath = $prefix === '' ? '/' . $slug : $prefix . '/' . $slug;
    $doc = content_parse($path, $urlPath);
    $fm = $doc['meta'];

    $trail = [['DeskPulse', '/']];
    $crumbs = ['compare' => ['Compare', '/pricing'], 'use-cases' => ['Use cases', '/pricing'],
               'blog' => ['Blog', '/blog']];
    if (isset($crumbs[$collection])) {
        $trail[] = $crumbs[$collection];
    }
    $trail[] = [$fm['title'] ?? $slug, $urlPath];

    $jsonld = [schema_breadcrumb($trail)];
    if ($collection === 'blog') {
        $jsonld[] = schema_article($fm, $urlPath);
    }
    if ($doc['faq']) {
        $jsonld[] = schema_faq($doc['faq']);
    }

    echo render('marketing/article', [
        'meta' => marketing_meta([
            'path'        => $urlPath,
            'title'       => ($fm['title'] ?? $slug) . ' · DeskPulse',
            'description' => $fm['description'] ?? '',
            'robots'      => ($fm['noindex'] ?? false) === true ? 'noindex,nofollow' : 'index,follow',
            'og_type'     => $collection === 'blog' ? 'article' : 'website',
            'og_image'    => $fm['og_image'] ?? abs_url(OG_IMAGE_PATH),
            'jsonld'      => $jsonld,
        ]),
        'fm' => $fm, 'body' => $doc['html'], 'trail' => $trail, 'collection' => $collection,
    ]);
    exit;
}

/** /blog — the post index. */
function marketing_blog_index(): void
{
    $posts = content_list('blog');
    echo render('marketing/blog_index', [
        'meta' => marketing_meta([
            'path'        => '/blog',
            'title'       => 'Blog — remote work, BPO operations and monitoring · DeskPulse',
            'description' => 'Practical writing on running remote and outsourced teams: '
                . 'monitoring costs, activity metrics that mean something, and how to roll '
                . 'tracking out without wrecking morale.',
            'jsonld'      => [schema_breadcrumb([['DeskPulse', '/'], ['Blog', '/blog']])],
        ]),
        'posts' => $posts,
    ]);
    exit;
}

/**
 * /llms.txt — a plain-text brief for AI assistants, which increasingly shortlist
 * software on a buyer's behalf. Built from live prices so it can never drift.
 */
function marketing_llms(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $p = plan_base_prices();
    $money = fn(float $v) => '$' . (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 2));
    $capSeats = plan_cap_seats($p);
    $minSeats = max(1, (int) $p['seats_bill_min']);

    echo "# DeskPulse\n\n";
    echo "> Remote-work monitoring and time tracking for BPOs, outsourcing teams and individuals.\n";
    echo "> Automatic time tracking, activity and idle detection, app and window insights, screenshots,\n";
    echo "> per-task time, client billing, payroll and payslips, role-based access and audit logging.\n";
    echo "> Consent-first: tracking runs only while the worker presses Start, with a persistent\n";
    echo "> monitoring indicator. No keystroke content is ever captured. Windows, macOS and Linux.\n\n";

    echo "## Pricing\n";
    echo '- Solo: ' . ($p['solo'] > 0 ? $money((float) $p['solo']) . '/month' : 'free') . ", 1 user\n";
    echo '- Individual: ' . $money((float) $p['individual']) . "/month, 1 user\n";
    echo '- Team: ' . $money((float) $p['per_seat']) . '/seat/month, ' . $minSeats
        . ' seat minimum (' . $money(plan_seat_price($minSeats, $p)) . "/month)\n";
    if ($capSeats > 0) {
        echo '- Organization: ' . $money((float) $p['seat_cap']) . '/month flat — the per-seat bill '
            . 'never exceeds this, which is reached at ' . $capSeats . ' seats'
            . ((int) $p['seats_cap_covers'] > 0 ? ', covering up to ' . (int) $p['seats_cap_covers'] . ' seats' : '') . "\n";
    }
    echo "- Enterprise: custom\n\n";

    echo "## Key documents\n";
    foreach ([['Product overview', '/'], ['Pricing', '/pricing'],
              ['Cost calculator', '/tools/cost-calculator'],
              ['Download (Windows, macOS, Linux)', '/download'],
              ['Security', '/security'], ['Privacy policy', '/privacy'], ['Terms', '/terms'],
              ['About', '/about'], ['Contact', '/contact']] as [$label, $path]) {
        echo "- $label: " . abs_url($path) . "\n";
    }
    foreach (['compare', 'use-cases', 'blog'] as $coll) {
        foreach (content_list($coll) as $doc) {
            echo '- ' . ($doc['title'] ?? $doc['slug']) . ': ' . abs_url($doc['url']) . "\n";
        }
    }
    exit;
}

/** robots.txt — allow indexing, point to the sitemap. */
function marketing_robots(): void
{
    header('Content-Type: text/plain');
    $base = public_base_url();
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /app/\n";
    echo "Disallow: /webhooks/\n";
    // A share token is a capability URL; /uploads holds screenshots. Neither belongs in
    // an index. share_view() also sends X-Robots-Tag, because a Disallow only stops a
    // crawler READING the page — anything already indexed would otherwise stay indexed.
    echo "Disallow: /share/\n";
    echo "Disallow: /uploads/\n";
    echo "Disallow: /setup.php\n";
    echo "Disallow: /install.php\n";
    echo "Sitemap: {$base}/sitemap.xml\n";
    exit;
}

/**
 * Every indexable URL with its lastmod, in one place: the sitemap and /llms.txt both
 * read it, and content pages are enumerated rather than hand-listed.
 *
 * /login and /register are deliberately absent — thin, near-duplicate pages that dilute
 * the crawl budget and can be flagged as low-value.
 */
function marketing_sitemap_urls(): array
{
    $tplTime = function (string $rel): string {
        $p = dirname(__DIR__) . '/templates/' . $rel;
        return gmdate('Y-m-d', is_file($p) ? (int) filemtime($p) : time());
    };
    $urls = [
        ['/', $tplTime('marketing/landing.php'), 'weekly', '1.0'],
        ['/pricing', $tplTime('marketing/pricing.php'), 'weekly', '0.9'],
        ['/tools/cost-calculator', $tplTime('marketing/calculator.php'), 'monthly', '0.8'],
        ['/download', $tplTime('marketing/download.php'), 'weekly', '0.8'],
    ];
    if (content_list('blog')) {
        $urls[] = ['/blog', gmdate('Y-m-d'), 'weekly', '0.6'];
    }
    $priority = ['compare' => '0.8', 'use-cases' => '0.7', 'blog' => '0.6', 'pages' => '0.4'];
    $freq = ['compare' => 'monthly', 'use-cases' => 'monthly', 'blog' => 'yearly', 'pages' => 'yearly'];
    foreach (['compare', 'use-cases', 'blog', 'pages'] as $coll) {
        foreach (content_list($coll) as $doc) {
            if (($doc['noindex'] ?? false) === true) {
                continue;
            }
            $urls[] = [$doc['url'], $doc['lastmod'], $freq[$coll], $priority[$coll]];
        }
    }
    return $urls;
}

/** sitemap.xml — the public, indexable pages. */
function marketing_sitemap(): void
{
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (marketing_sitemap_urls() as [$path, $lastmod, $freq, $prio]) {
        echo '  <url><loc>' . e(abs_url($path)) . '</loc>'
            . '<lastmod>' . e($lastmod) . '</lastmod>'
            . '<changefreq>' . e($freq) . '</changefreq>'
            . '<priority>' . e($prio) . "</priority></url>\n";
    }
    echo "</urlset>\n";
    exit;
}
