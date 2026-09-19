<?php
/**
 * Shared marketing header. Links are root-relative so they work from any page —
 * the /#anchor links still scroll in-page when you are already on the landing page.
 * The Blog entry only appears once a post exists, so the nav never leads to an
 * empty index.
 */
$logged_in = $logged_in ?? current_user();
$navBlog = function_exists('content_list') && content_list('blog');
?>
<header class="pub-top">
  <a class="brand" href="<?= e(url('/')) ?>" style="text-decoration:none;display:inline-flex;align-items:center;gap:.5rem">
    <img src="<?= e(url('/assets/img/favicon.svg')) ?>" width="22" height="22" alt="" style="display:block">
    <span style="font-family:'Plus Jakarta Sans','Inter',sans-serif;font-weight:800;letter-spacing:-.02em;font-size:1.25rem;color:#e9eef8">Desk<span style="color:#2dd4bf">Pulse</span></span>
  </a>
  <nav>
    <a href="<?= e(url('/#features')) ?>">Features</a>
    <a href="<?= e(url('/#tour')) ?>">Tour</a>
    <a href="<?= e(url('/pricing')) ?>">Pricing</a>
    <?php if ($navBlog): ?><a href="<?= e(url('/blog')) ?>">Blog</a><?php endif; ?>
    <a href="<?= e(url('/security')) ?>">Security</a>
    <a href="<?= e(url('/download')) ?>">Download</a>
    <?php if ($logged_in): ?>
      <a class="btn" href="<?= e(url('/app')) ?>">Open dashboard</a>
    <?php else: ?>
      <a href="<?= e(url('/login')) ?>">Sign in</a>
      <a class="btn" href="<?= e(url('/register')) ?>">Get started free</a>
    <?php endif; ?>
  </nav>
</header>
