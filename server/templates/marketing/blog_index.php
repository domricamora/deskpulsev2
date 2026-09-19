<?php /** /blog — post index. Expects $meta, $posts. */ ?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing article-page">
<?php include __DIR__ . '/_nav.php'; ?>

<section class="hero compact">
  <span class="aurora" aria-hidden="true"></span>
  <div class="hero-in" data-reveal>
    <h1>Blog</h1>
    <p class="lead">Running remote and outsourced teams — monitoring costs, activity metrics that
      mean something, and rolling tracking out without wrecking morale.</p>
  </div>
</section>

<section class="features">
  <?php if (!$posts): ?>
    <p class="muted" style="text-align:center">No posts yet. The first one is being written.</p>
  <?php else: ?>
    <div class="feat-grid">
      <?php foreach ($posts as $p): ?>
        <article class="card" data-reveal>
          <?php if (!empty($p['published'])): ?>
            <p class="muted small"><time datetime="<?= e($p['published']) ?>"><?= e(date('j F Y', strtotime($p['published']))) ?></time></p>
          <?php endif; ?>
          <h3><a href="<?= e(url($p['url'])) ?>"><?= e($p['title']) ?></a></h3>
          <?php if (!empty($p['description'])): ?><p><?= e($p['description']) ?></p><?php endif; ?>
          <p><a href="<?= e(url($p['url'])) ?>">Read &rarr;</a></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(url('/assets/js/reveal.js')) ?>" defer></script>
</body>
</html>
