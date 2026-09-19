<div class="auth-card">
  <h2>Thanks for signing up 🎉</h2>
  <p><b><?= e($org['name']) ?></b> is awaiting review by a DeskPulse administrator.</p>
  <?php if (($org['status'] ?? '') === 'rejected'): ?>
    <p class="flash error">This signup was not approved. Please contact support if you think this is a mistake.</p>
  <?php else: ?>
    <p class="muted">You'll be able to access your dashboard as soon as your account is
       approved. This page will let you in automatically once that happens — check back
       shortly, or sign in again later.</p>
  <?php endif; ?>
  <p><a class="btn" href="<?= e(url('/app/pending')) ?>">Refresh status</a>
     <a class="ghost" href="<?= e(url('/logout')) ?>">Sign out</a></p>
</div>
