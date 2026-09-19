<?php
/** A content page from server/content/. Expects $meta, $fm, $body, $trail, $collection. */
$isPost = $collection === 'blog';
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing article-page">
<?php include __DIR__ . '/_nav.php'; ?>

<article class="prose-wrap">
  <nav class="crumbs" aria-label="Breadcrumb">
    <?php foreach ($trail as $i => [$name, $path]): ?>
      <?php if ($i < count($trail) - 1): ?>
        <a href="<?= e(url($path)) ?>"><?= e($name) ?></a> <span aria-hidden="true">/</span>
      <?php else: ?>
        <span aria-current="page"><?= e($name) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <header class="prose-head">
    <h1><?= e($fm['title'] ?? '') ?></h1>
    <?php if (!empty($fm['description'])): ?>
      <p class="lead"><?= e($fm['description']) ?></p>
    <?php endif; ?>
    <?php if ($isPost): ?>
      <p class="muted small">
        <?php if (!empty($fm['author'])): ?><?= e($fm['author']) ?> · <?php endif; ?>
        <?php if (!empty($fm['published'])): ?>
          <time datetime="<?= e($fm['published']) ?>"><?= e(date('j F Y', strtotime($fm['published']))) ?></time>
        <?php endif; ?>
      </p>
    <?php elseif (!empty($fm['updated_label']) || !empty($fm['lastmod'])): ?>
      <p class="muted small">Last updated
        <time datetime="<?= e($fm['lastmod']) ?>"><?= e(date('j F Y', strtotime($fm['lastmod']))) ?></time>
      </p>
    <?php endif; ?>
  </header>

  <div class="prose"><?= $body ?></div>

  <?php if (($fm['cta'] ?? 'true') !== 'false'): ?>
    <aside class="prose-cta">
      <h3><?= e($fm['cta_title'] ?? 'Per-seat pricing that stops') ?></h3>
      <p><?= e($fm['cta_body'] ?? 'DeskPulse is billed per seat with a cap, and every feature is '
          . 'included at every price. No card to start.') ?></p>
      <div class="cta-row">
        <a class="btn" href="<?= e(url('/register?plan=per_seat')) ?>">Start a free trial</a>
        <a class="btn ghost" href="<?= e(url('/tools/cost-calculator')) ?>">Calculate your cost</a>
      </div>
    </aside>
  <?php endif; ?>
</article>

<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(url('/assets/js/reveal.js')) ?>" defer></script>
<script src="<?= e(url('/assets/js/tables.js')) ?>" defer></script>
</body>
</html>
