<?php
/**
 * Minimal public shell for auth + share pages. Expects $title, $content.
 *
 * Defaults to noindex: /login and /register are thin duplicate-content pages, and a
 * share URL is a capability token — anything holding one should never be crawlable.
 * A page that wants indexing passes $robots explicitly.
 */
$meta = marketing_meta([
    'title'     => ($title ?? 'DeskPulse') . ' · DeskPulse',
    'robots'    => $robots ?? 'noindex,nofollow',
    // Only an explicitly indexable page declares a canonical; a share token must not.
    'canonical' => isset($canonical) ? abs_url($canonical) : '',
    'og_image'  => isset($canonical) ? abs_url(OG_IMAGE_PATH) : '',
]);
?>
<!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/marketing/_head.php'; ?>
</head>
<body class="public">
<header class="pub-top">
  <a class="brand" href="<?= e(url('/')) ?>" style="text-decoration:none;display:inline-flex;align-items:center;gap:.5rem">
    <img src="<?= e(url('/assets/img/favicon.svg')) ?>" width="22" height="22" alt="" style="display:block">
    <span style="font-family:'Plus Jakarta Sans','Inter',sans-serif;font-weight:800;letter-spacing:-.02em;font-size:1.25rem;color:#e9eef8">Desk<span style="color:#2dd4bf">Pulse</span></span>
  </a>
  <nav>
    <a href="<?= e(url('/login')) ?>">Sign in</a>
    <a class="btn" href="<?= e(url('/register')) ?>">Get started</a>
  </nav>
</header>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash <?= e($f['type']) ?> center"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<main class="pub-main"><?= $content ?></main>
<footer class="pub-foot">© DeskPulse · Remote work monitoring for teams &amp; individuals</footer>
<script src="<?= e(url('/assets/js/vendor/chart.umd.js')) ?>"></script>
<script src="<?= e(url('/assets/js/charts.js')) ?>"></script>
<script src="<?= e(url('/assets/js/dashboard.js')) ?>"></script>
<script src="<?= e(url('/assets/js/tables.js')) ?>"></script>
</body>
</html>
