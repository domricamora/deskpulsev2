<?php
// Inline SVG icon set (stroke = currentColor). No emoji, no CDN.
$I = [
  'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
  'win'      => '<path d="M3 5.5 10 4.5v7H3z"/><path d="M10.8 4.4 21 3v8.5H10.8z"/><path d="M3 12.5h7v7L3 18.5z"/><path d="M10.8 12.5H21V21l-10.2-1.4z"/>',
  'apple'    => '<path d="M12 7c1-2 3-2.5 4-2.4-.1 1.4-.7 2.6-1.6 3.3-.9.8-2 1.2-3 1.1"/><path d="M16 12c0-2 1.4-3 2-3.3-.8-1.2-2-1.7-3.2-1.7-1.2 0-1.9.7-2.8.7-.9 0-1.7-.7-2.8-.7C7.3 7 5.6 8.2 5 10.2c-1 2.9.2 7 1.9 8.9.7.8 1.4 1.6 2.4 1.6.9 0 1.3-.6 2.5-.6s1.5.6 2.5.6 1.6-.8 2.3-1.6c.5-.7.9-1.4 1.1-2-1.7-.7-1.7-3.1-.7-4.5"/>',
  'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
  'eye-off'  => '<path d="M9.9 4.2A10 10 0 0 1 12 4c6.5 0 10 7 10 7a18 18 0 0 1-2.3 3.2M6.1 6.1A18 18 0 0 0 2 11s3.5 7 10 7a10 10 0 0 0 4-.8"/><path d="m4 4 16 16"/>',
  'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14 0"/><path d="M8.5 16.1a6 6 0 0 1 7 0"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M12 20h.01"/>',
  'download-cloud' => '<path d="M8 17l4 4 4-4"/><path d="M12 12v9"/><path d="M20.9 18.4A5 5 0 0 0 18 9h-1.3A8 8 0 1 0 4 16.9"/>',
  'check'    => '<path d="M20 6 9 17l-5-5"/>',
];
$svg = function (string $name) use ($I): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($I[$name] ?? '') . '</svg>';
};
$meta = marketing_meta([
    'path'        => '/download',
    'title'       => 'Download DeskPulse for Windows, macOS & Linux — Remote Work Monitoring Agent',
    'description' => 'Download the DeskPulse desktop agent for Windows, macOS and Linux. Lightweight '
        . 'time tracking, activity, screenshots and task monitoring that syncs to your DeskPulse '
        . 'dashboard.',
    'og_title'    => 'Download DeskPulse for Windows, macOS & Linux',
    'og_desc'     => 'Get the DeskPulse desktop agent — time tracking, activity and screenshots that '
        . 'sync to your dashboard.',
    'jsonld'      => [schema_breadcrumb([['DeskPulse', '/'], ['Download', '/download']])],
]);
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing">
<?php include __DIR__ . '/_nav.php'; ?>

<section class="hero">
  <span class="aurora" aria-hidden="true"></span>
  <div class="hero-in" data-reveal>
    <span class="kicker"><?= $svg('download-cloud') ?> Desktop agent · Windows, macOS &amp; Linux</span>
    <h1>Download <span class="grad">DeskPulse</span></h1>
    <p class="lead">Install the lightweight desktop app, sign in with your DeskPulse account,
      and start tracking. It runs quietly in your system tray and only monitors while a
      session is active.</p>
    <div class="cta-row">
      <a class="btn lg" href="<?= e(url('/download/app?os=win')) ?>"><?= $svg('win') ?> Download for Windows</a>
      <a class="btn lg ghost" href="<?= e(url('/download/app?os=mac')) ?>"><?= $svg('apple') ?> Download for macOS</a>
      <a class="btn lg ghost" href="<?= e(url('/download/app?os=linux')) ?>"><?= $svg('download') ?> Download for Linux</a>
    </div>
    <p class="micro muted">
      Windows: <?= $has_win ? 'one-click installer (.exe)' : 'portable package (Python 3.10+)' ?>
      · macOS: <?= $has_mac ? 'disk image (.dmg)' : 'portable package (Python 3.10+)' ?>
      · Linux: <?= $has_linux ? 'native package (.tar.gz)' : 'portable package (Python 3.10+)' ?>
      · <a href="<?= e(url('/register')) ?>">create an account first</a>
    </p>
  </div>
</section>

<section class="features">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Setup</span>
    <h2>Installing &amp; first run</h2>
    <p class="sub">Three steps from download to live dashboards.</p>
  </div>
  <div class="feat-grid">
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('download') ?></span>
      <h3>1 · Install</h3>
      <p><b>Windows:</b> run <code>DeskPulse-Setup.exe</code> (installs just for you, no admin needed).
         <b>macOS:</b> open the <code>.dmg</code> and drag DeskPulse to Applications.
         <b>Portable:</b> unzip and run the included <code>Install</code> then <code>Run</code> script.</p>
    </article>
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('shield') ?></span>
      <h3>2 · Sign in</h3>
      <p>Enter your DeskPulse email and password. The app connects to your workspace automatically
         and registers this computer — no server address to configure.</p>
    </article>
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('check') ?></span>
      <h3>3 · Pick a task &amp; Start</h3>
      <p>Choose a client/task and press <b>Start</b>. A “● Monitoring” badge shows while tracking
         is on. Stop anytime — nothing is captured when a session is off.</p>
    </article>
  </div>
</section>

<section class="features alt">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Good to know</span>
    <h2>Private, resilient, out of the way</h2>
  </div>
  <div class="feat-grid">
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('eye-off') ?></span>
      <h3>Transparent</h3>
      <p>DeskPulse only tracks while you press Start. Screenshots can be blurred and the recording
         state is always visible.</p>
    </article>
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('shield') ?></span>
      <h3>First-launch prompts</h3>
      <p>Windows SmartScreen: <b>More info → Run anyway</b>. macOS Gatekeeper:
         <b>right-click → Open</b>. Signed/notarized builds remove these.</p>
    </article>
    <article class="card" data-reveal>
      <span class="feat-ico"><?= $svg('wifi') ?></span>
      <h3>Works offline</h3>
      <p>If your connection drops, data is queued locally and synced automatically when you're
         back online.</p>
    </article>
  </div>
</section>

<?php if (!$has_win || !$has_mac || !$has_linux): ?>
<section class="cta-band">
  <div class="cta-in" data-reveal id="build">
    <h2>Native installers</h2>
    <p>Windows installers are built via <code>agent\packaging\build.ps1</code> (PyInstaller + Inno Setup
       → <code>.exe</code>), macOS via <code>build_mac.sh</code> (→ <code>.dmg</code>), and Linux via
       PyInstaller in the same workflow (→ <code>.tar.gz</code>). The included GitHub Actions workflow
       builds all three automatically on a version tag. Until a native build is published, the buttons
       above serve a cross-platform portable package (Windows, macOS, Linux).</p>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(url('/assets/js/reveal.js')) ?>" defer></script>
<script src="<?= e(url('/assets/js/hero-globe.js')) ?>" defer></script>
</body>
</html>
