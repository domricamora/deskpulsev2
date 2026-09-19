<?php
/**
 * Signup. Expects $plan, $soloish, $old, $prices, $trial_days, $plan_price.
 *
 * Design notes (see the signup CRO audit in CLAUDE.md):
 *  - The plan rides as a hidden field, so a pricing-page CTA actually survives the POST.
 *  - Easiest field first (name), hardest last (company) — and company is optional on the
 *    single-user plans, where "company workspace name" is meaningless friction.
 *  - Every field repopulates on error. Losing the form to one typo is the biggest
 *    avoidable drop-off there is.
 *  - Trial length and "no card required" are stated at the point of commitment.
 */
$planNames = ['solo' => 'Solo', 'individual' => 'Individual',
              'per_seat' => 'Team', 'organization' => 'Organization'];
$planName = $planNames[$plan] ?? 'Team';
$free = $plan === 'solo';
?>
<div class="auth-card">
  <h2>Create your account</h2>

  <div class="signup-plan">
    <div>
      <span class="muted small">Selected plan</span>
      <b><?= e($planName) ?></b>
    </div>
    <div class="signup-plan-price"><?= e($plan_price) ?></div>
    <a class="signup-plan-change" href="<?= e(url('/pricing')) ?>">Change</a>
  </div>

  <p class="muted small signup-assure">
    <?php if ($free): ?>
      Free forever, one user. No card, no trial clock.
    <?php else: ?>
      <?= (int) $trial_days ?>-day free trial. <b>No credit card required</b> — you pay by
      bank transfer only when you decide to keep it.
    <?php endif; ?>
  </p>

  <form method="post" action="<?= e(url('/register')) ?>" class="signup-form">
    <?= csrf_field() ?>
    <input type="hidden" name="plan" value="<?= e($plan) ?>">

    <label>Your name
      <input type="text" name="name" required autofocus autocomplete="name"
             value="<?= e($old['name']) ?>"></label>

    <label>Work email
      <input type="email" name="email" required autocomplete="email" inputmode="email"
             id="dp-email" value="<?= e($old['email']) ?>">
      <small class="muted" id="dp-email-hint" hidden></small></label>

    <label>Password
      <span class="pw-wrap">
        <input type="password" name="password" minlength="8" required id="dp-pass"
               autocomplete="new-password">
        <button type="button" class="pw-toggle" id="dp-pass-toggle"
                aria-label="Show password">Show</button>
      </span>
      <small class="muted">At least 8 characters.</small></label>

    <label><?= $soloish ? 'Workspace name <span class="muted">(optional)</span>' : 'Company / workspace name' ?>
      <input type="text" name="company" <?= $soloish ? '' : 'required' ?> autocomplete="organization"
             value="<?= e($old['company']) ?>"
             placeholder="<?= $soloish ? 'Defaults to your name' : '' ?>">
      <?php if (!$soloish): ?><small class="muted">This is your private, isolated workspace.</small><?php endif; ?>
    </label>

    <button class="btn block" type="submit">
      <?= $free ? 'Create free account' : 'Start free trial' ?>
    </button>
  </form>

  <p class="muted small">By creating an account you agree to our
    <a href="<?= e(url('/terms')) ?>">Terms</a> and
    <a href="<?= e(url('/privacy')) ?>">Privacy policy</a>.
    Tracking only ever runs on a worker's own machine, after they press Start.</p>

  <p class="muted">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
</div>

<script>
(function () {
  // Show/hide password. Never block paste — it pushes people to weaker passwords.
  var pass = document.getElementById('dp-pass');
  var toggle = document.getElementById('dp-pass-toggle');
  if (pass && toggle) {
    toggle.addEventListener('click', function () {
      var show = pass.type === 'password';
      pass.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Hide' : 'Show';
      toggle.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' password');
      pass.focus();
    });
  }
  // Catch the handful of typos that account for most bounced signup emails.
  var TYPOS = {
    'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gmail.co': 'gmail.com',
    'gnail.com': 'gmail.com', 'hotmial.com': 'hotmail.com', 'hotmai.com': 'hotmail.com',
    'yahooo.com': 'yahoo.com', 'yaho.com': 'yahoo.com', 'outlok.com': 'outlook.com',
    'outloo.com': 'outlook.com', 'iclod.com': 'icloud.com'
  };
  var email = document.getElementById('dp-email');
  var hint = document.getElementById('dp-email-hint');
  if (email && hint) {
    email.addEventListener('blur', function () {
      var at = email.value.split('@');
      var fix = at.length === 2 ? TYPOS[at[1].toLowerCase()] : null;
      if (!fix) { hint.hidden = true; return; }
      hint.hidden = false;
      hint.innerHTML = 'Did you mean <b style="cursor:pointer;text-decoration:underline">'
        + at[0] + '@' + fix + '</b>?';
      hint.querySelector('b').addEventListener('click', function () {
        email.value = at[0] + '@' + fix;
        hint.hidden = true;
      });
    });
  }
})();
</script>
