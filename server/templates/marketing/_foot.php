<?php
/**
 * Shared marketing footer.
 *
 * The Legal column is not decoration: G2, Capterra and most software directories
 * reject a submission that has no reachable privacy policy and terms, and it is the
 * first thing a BPO's IT reviewer looks for before approving a monitoring vendor.
 */
$footBlog = function_exists('content_list') && content_list('blog');
$footCompare = function_exists('content_list') ? content_list('compare') : [];
?>
<footer class="site-foot">
  <div class="foot-grid">
    <div>
      <div style="display:inline-flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
        <img src="<?= e(url('/assets/img/favicon.svg')) ?>" width="22" height="22" alt="" style="display:block">
        <span style="font-family:'Plus Jakarta Sans','Inter',sans-serif;font-weight:800;letter-spacing:-.02em;font-size:1.25rem;color:#e9eef8">Desk<span style="color:#2dd4bf">Pulse</span></span>
      </div>
      <p class="muted">The standard for remote-work monitoring — for teams, BPOs &amp; individuals.</p>
    </div>
    <div><h4>Product</h4>
      <a href="<?= e(url('/#features')) ?>">Features</a><a href="<?= e(url('/#roles')) ?>">Roles</a><a href="<?= e(url('/pricing')) ?>">Pricing</a><a href="<?= e(url('/download')) ?>">Download</a><?php if ($footBlog): ?><a href="<?= e(url('/blog')) ?>">Blog</a><?php endif; ?></div>
    <?php if ($footCompare): ?>
    <div><h4>Compare</h4>
      <?php foreach (array_slice($footCompare, 0, 4) as $c): ?>
        <a href="<?= e(url('/compare/' . $c['slug'])) ?>"><?= e($c['nav_title'] ?? $c['title']) ?></a>
      <?php endforeach; ?></div>
    <?php endif; ?>
    <div><h4>Company</h4>
      <a href="<?= e(url('/about')) ?>">About</a><a href="<?= e(url('/contact')) ?>">Contact</a><a href="<?= e(url('/security')) ?>">Security</a></div>
    <div><h4>Legal</h4>
      <a href="<?= e(url('/privacy')) ?>">Privacy policy</a><a href="<?= e(url('/terms')) ?>">Terms of service</a></div>
    <div><h4>Account</h4>
      <a href="<?= e(url('/login')) ?>">Sign in</a><a href="<?= e(url('/register')) ?>">Create workspace</a><a href="<?= e(url('/app')) ?>">Dashboard</a></div>
  </div>
  <p class="copy">© DeskPulse · All rights reserved.</p>
</footer>
