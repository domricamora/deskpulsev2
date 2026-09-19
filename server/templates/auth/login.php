<?php
/**
 * Sign in. Expects $providers (configured OIDC providers) and $sso_hint (an org whose
 * enforced SSO covers the address just attempted, if any).
 *
 * Social buttons sit ABOVE the password form: for a B2B audience they convert better
 * than email/password, and burying them under the form wastes that.
 */
$next = isset($_GET['next']) ? '?next=' . rawurlencode($_GET['next']) : '';
$icons = [
  'google' => '<svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62Z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.84.86-3.05.86-2.35 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18Z"/><path fill="#FBBC05" d="M3.96 10.71a5.4 5.4 0 0 1 0-3.42V4.96H.96a9 9 0 0 0 0 8.08l3-2.33Z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.65 3.58 9 3.58Z"/></svg>',
  'microsoft' => '<svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true"><path fill="#F25022" d="M0 0h8.5v8.5H0z"/><path fill="#7FBA00" d="M9.5 0H18v8.5H9.5z"/><path fill="#00A4EF" d="M0 9.5h8.5V18H0z"/><path fill="#FFB900" d="M9.5 9.5H18V18H9.5z"/></svg>',
];
?>
<div class="auth-card">
  <h2>Sign in</h2>

  <?php if (!empty($sso_hint)): ?>
    <div class="sso-hint">
      <p><b><?= e($sso_hint['name']) ?></b> uses single sign-on. Continue with your
        organization account.</p>
      <a class="btn block" href="<?= e(url('/auth/sso?org=' . (int) $sso_hint['id'])) ?>">
        Continue with single sign-on</a>
    </div>
  <?php endif; ?>

  <?php if ($providers): ?>
    <div class="oauth-row">
      <?php foreach ($providers as $key => $p): ?>
        <a class="btn ghost oauth-btn" href="<?= e(url('/auth/' . $key)) ?>">
          <?= $icons[$key] ?? '' ?> Continue with <?= e($p['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="or-rule"><span>or</span></div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/login' . $next)) ?>">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required autofocus autocomplete="email"
             value="<?= e($old_email ?? '') ?>"></label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn block" type="submit">Sign in</button>
  </form>

  <p class="muted small" style="margin-top:.6rem">
    <a href="<?= e(url('/forgot-password')) ?>">Forgot your password?</a>
  </p>
  <p class="muted">New here? <a href="<?= e(url('/register')) ?>">Create an account</a></p>
</div>
