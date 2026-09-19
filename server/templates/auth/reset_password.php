<?php /** Choose a new password. Expects $token, $error, $email. */ ?>
<div class="auth-card">
  <?php if ($error): ?>
    <h2>That link didn&rsquo;t work</h2>
    <p class="muted"><?= e($error) ?></p>
    <p><a class="btn block" href="<?= e(url('/forgot-password')) ?>">Request a new link</a></p>
    <p class="muted"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
  <?php else: ?>
    <h2>Choose a new password</h2>
    <?php if ($email): ?>
      <p class="muted">For <b><?= e($email) ?></b>.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/reset-password')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New password
        <span class="pw-wrap">
          <input type="password" name="password" minlength="8" required autofocus
                 id="dp-pass" autocomplete="new-password">
          <button type="button" class="pw-toggle" id="dp-pass-toggle"
                  aria-label="Show password">Show</button>
        </span>
        <small class="muted">At least 8 characters.</small>
      </label>
      <label>Confirm new password
        <input type="password" name="password_confirm" minlength="8" required
               autocomplete="new-password">
      </label>
      <button class="btn block" type="submit">Set new password and sign in</button>
    </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var pass = document.getElementById('dp-pass');
  var toggle = document.getElementById('dp-pass-toggle');
  if (!pass || !toggle) { return; }
  toggle.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' password');
    pass.focus();
  });
})();
</script>
