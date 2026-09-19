<div class="auth-card">
  <h2>Set your password</h2>
  <p class="muted">Welcome, <?= e($user['name']) ?>. For security, choose your own password
    before continuing — your account was created with a temporary one.</p>
  <form method="post" action="<?= e(url('/app/change-password')) ?>">
    <?= csrf_field() ?>
    <label>New password <input type="password" name="password" required autofocus minlength="8"></label>
    <label>Confirm password <input type="password" name="password_confirm" required minlength="8"></label>
    <button class="btn block" type="submit">Save password</button>
  </form>
  <p class="muted"><a href="<?= e(url('/logout')) ?>">Sign out</a></p>
</div>
