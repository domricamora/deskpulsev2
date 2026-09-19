<?php
$cur      = $org['billing_currency'] ?: 'USD';
$amount   = $amount_cents / 100;
$trialing = ($org['subscription_status'] ?? '') === 'trialing';
$due      = !$is_current;   // trial ended / no active subscription
$planType = plan_type_clean($org['plan_type'] ?? null);
$planName = plan_labels()[$planType] ?? ucfirst($planType);
$period   = plan_period_label();
$planPrices = plan_base_prices();
// Seats BILLED, not seats present — the Team plan has a minimum, so a 3-person org on a
// 5-seat floor pays for 5 and should be told so rather than left to wonder at the total.
$seats    = $planType === 'per_seat' ? plan_billable_seats((int) $org['id'], $planPrices) : 0;
$capSeats = plan_cap_seats($planPrices);
$atCap    = $planType === 'per_seat' && $capSeats > 0 && $seats >= $capSeats;
?>
<div class="stat-row">
  <div class="stat"><span class="lbl">Status</span><b><?= e($state_label) ?></b></div>
  <div class="stat"><span class="lbl">Plan</span><b><?= e($planName) ?></b>
    <?php if ($planType === 'per_seat'): ?><span class="sub"><?= (int) $seats ?> seat<?= $seats === 1 ? '' : 's' ?><?php
      if ($atCap): ?> &middot; capped<?php endif; ?></span><?php endif; ?></div>
  <div class="stat"><span class="lbl">Amount</span><b><?= e(money($amount, $cur)) ?></b><span class="sub"><?= e($period) ?></span></div>
</div>

<?php if (!$can_manage): ?>
  <div class="panel">
    <h3>Subscription</h3>
    <p class="muted">Your organization&rsquo;s subscription is managed by your company administrator.
      <?php if ($due): ?><br><b>Access is paused pending payment</b> &mdash; please contact your admin.<?php endif; ?></p>
  </div>
<?php else: ?>

  <?php if ($trialing && !$due): ?>
    <div class="panel">
      <h3>You&rsquo;re on a free trial</h3>
      <p class="muted"><b><?= (int) $days_left ?> day<?= (int) $days_left === 1 ? '' : 's' ?></b> left of your
        <?= (int) $trial_days ?>-day trial. When it ends, keep your workspace active by sending your monthly
        payment by US bank deposit (details below).</p>
    </div>
  <?php elseif ($is_current): ?>
    <div class="panel">
      <h3>Subscription active</h3>
      <p class="muted">Thanks &mdash; your subscription is active<?php if (!empty($org['current_period_end'])): ?>
        until <time class="dp-time" data-utc="<?= e($org['current_period_end']) ?>" data-fmt="date"></time><?php endif; ?>.</p>
    </div>
  <?php else: ?>
    <div class="panel paywall-due">
      <h3>Payment due</h3>
      <p class="muted">Your free trial has ended. Send <b><?= e(money($amount, $cur)) ?></b> by US bank deposit
        to keep your workspace active.</p>
    </div>
  <?php endif; ?>

  <?php
  // Self-serve plan change. Expansion revenue dies if changing plan means emailing
  // support, and a customer who has outgrown Solo is the easiest upgrade there is.
  $capSeatsUi = plan_cap_seats($planPrices);
  $choices = [
    'solo'       => ['Solo', $planPrices['solo'] > 0 ? money_short((float) $planPrices['solo']) . '/mo' : 'Free',
                     '1 user · 7 days of history · no screenshots'],
    'individual' => ['Individual', money_short((float) $planPrices['individual']) . '/mo',
                     '1 user · full history · screenshots'],
    'per_seat'   => ['Team', money_short((float) $planPrices['per_seat']) . '/seat/mo',
                     max(1, (int) $planPrices['seats_bill_min']) . '-seat minimum'
                     . ($capSeatsUi > 0 ? ' · caps at ' . money_short((float) $planPrices['seat_cap'])
                        . ' from ' . $capSeatsUi . ' seats' : '')],
  ];
  $realSeats = org_seat_count((int) $org['id']);
  ?>
  <div class="panel">
    <h3>Your plan</h3>
    <p class="muted">You&rsquo;re on <b><?= e($planName) ?></b><?php
      if ($planType === 'per_seat'): ?>, billed for <b><?= (int) $seats ?></b> seat<?= $seats === 1 ? '' : 's' ?><?php
        if ($realSeats < $seats): ?> (you have <?= (int) $realSeats ?>; the plan has a
          <?= (int) $planPrices['seats_bill_min'] ?>-seat minimum)<?php endif;
        if ($atCap): ?> &mdash; <b>your bill has hit the cap, so extra seats are free</b><?php endif;
      endif; ?>. Changing plan takes effect on your next invoice.</p>
    <form method="post" action="<?= e(url('/app/subscription')) ?>" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_plan">
      <label>Switch to
        <select name="plan_type">
          <?php foreach ($choices as $k => [$lbl, $price, $note]): ?>
            <option value="<?= e($k) ?>" <?= $planType === $k ? 'selected' : '' ?>>
              <?= e($lbl) ?> — <?= e($price) ?> (<?= e($note) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Change plan</button>
    </form>
    <?php if ($planType !== 'per_seat' && $realSeats >= 1): ?>
      <p class="muted small">Need to add your team? The Team plan covers everyone at
        <?= e(money_short((float) $planPrices['per_seat'])) ?> a seat and stops at
        <?= e(money_short((float) $planPrices['seat_cap'])) ?>.
        <a href="<?= e(url('/tools/cost-calculator')) ?>">Work out your cost &rarr;</a></p>
    <?php endif; ?>
    <p class="muted small">Above <?= (int) ($planPrices['seats_cap_covers'] ?: 100) ?> seats, or need SSO,
      an SLA or a signed DPA? <a href="<?= e(url('/contact')) ?>">Talk to us about Enterprise</a>.</p>
  </div>

  <div class="panel">
    <h3>Pay by bank transfer</h3>
    <p class="muted">Send <b><?= e(money($amount, $cur)) ?></b> (USD) to the account below and
      <b>include your payment reference</b> so we can match the payment to your account.
      Transfers usually clear within one to two business days; access is updated as soon as
      it lands.</p>

    <div class="stat-row" style="margin-bottom:1rem">
      <div class="stat"><span class="lbl">Amount</span><b><?= e(money($amount, $cur)) ?></b>
        <span class="sub"><?= e($plan_period ?? 'per month') ?></span></div>
      <div class="stat"><span class="lbl">Your payment reference</span>
        <b style="font-size:1.05rem"><?= e($reference) ?></b>
        <span class="sub">must be included</span></div>
    </div>

    <?php if (!empty($deposit['holder']) || !empty($deposit['account_number'])): ?>
      <dl class="kv">
        <?php if (!empty($deposit['holder'])): ?>
          <dt>Account name</dt><dd><b><?= e($deposit['holder']) ?></b></dd><?php endif; ?>
        <?php if (!empty($deposit['account_type'])): ?>
          <dt>Account type</dt><dd><?= e($deposit['account_type']) ?></dd><?php endif; ?>
        <?php if (!empty($deposit['account_number'])): ?>
          <dt>Account number</dt><dd><code><?= e($deposit['account_number']) ?></code></dd><?php endif; ?>
      </dl>

      <h4 style="margin-top:1.1rem">Sending from a bank in the United States</h4>
      <p class="muted small">Make a domestic ACH or wire transfer using the routing number.</p>
      <dl class="kv">
        <?php if (!empty($deposit['routing'])): ?>
          <dt>Routing number <span class="muted small">(wire and ACH)</span></dt>
          <dd><code><?= e($deposit['routing']) ?></code></dd><?php endif; ?>
      </dl>

      <h4 style="margin-top:1.1rem">Sending from outside the United States</h4>
      <p class="muted small">Make an international SWIFT transfer.</p>
      <dl class="kv">
        <?php if (!empty($deposit['swift'])): ?>
          <dt>SWIFT / BIC</dt><dd><code><?= e($deposit['swift']) ?></code></dd><?php endif; ?>
        <?php if (!empty($deposit['bank'])): ?>
          <dt>Bank name and address</dt><dd><?= e($deposit['bank']) ?></dd><?php endif; ?>
        <?php if (!empty($deposit['address'])): ?>
          <dt>Bank address</dt><dd><?= e($deposit['address']) ?></dd><?php endif; ?>
      </dl>

      <p class="muted small" style="margin-top:1rem">
        <b>Please put <code><?= e($reference) ?></code> in the transfer reference / message field.</b>
        Without it we can't tell which account the payment belongs to, and activation will be delayed
        until we reach you. Your bank may charge a fee for international transfers — send the full
        amount above so the correct sum arrives.</p>
    <?php else: ?>
      <p class="muted">Bank details will appear here once billing is configured. Your payment
        reference <code><?= e($reference) ?></code> stays the same, so you can note it now.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Tell us about a payment you've sent</h3>
    <p class="muted">Bank transfers don't notify us automatically, so letting us know here gets your
       account reactivated faster — we'll match it against our account and confirm. Attaching the
       transfer receipt makes that quicker, especially for international payments.</p>

    <form method="post" action="<?= e(url('/app/subscription')) ?>" class="stack" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_payment">
      <div class="inline">
        <label>Amount sent
          <input type="number" name="amount" step="0.01" min="0.01" required
                 value="<?= e(number_format($amount_cents / 100, 2, '.', '')) ?>"></label>
        <label>Currency <input type="text" name="currency" maxlength="8" value="<?= e($cur) ?>"></label>
        <label>Date sent <input type="date" name="paid_on" value="<?= e(gmdate('Y-m-d')) ?>"></label>
        <label>How you sent it <select name="method">
          <?php foreach (($methods ?? []) as $k => $lbl): ?>
            <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
      <div class="inline">
        <label>Name on the sending account
          <input type="text" name="sender_name" maxlength="160"
                 placeholder="If it differs from your company name"></label>
        <label>Sending bank <input type="text" name="sender_bank" maxlength="160"></label>
        <label>Reference you used
          <input type="text" name="reference" maxlength="120" value="<?= e($reference) ?>"></label>
      </div>
      <label>Transfer receipt <small class="muted">(optional — PDF or image, max 8&nbsp;MB)</small>
        <input type="file" name="receipt" accept=".pdf,image/png,image/jpeg,image/webp"></label>
      <label>Anything else we should know
        <textarea name="note" rows="2" placeholder="Optional"></textarea></label>
      <button class="btn" type="submit">Submit payment details</button>
    </form>

    <?php if (!empty($claims)): ?>
      <h4 style="margin-top:1.3rem">Your submitted payments</h4>
      <table class="data">
        <thead><tr><th>Submitted</th><th>Amount</th><th>Sent on</th><th>Method</th>
          <th>Reference</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($claims as $c): ?>
          <tr>
            <td data-sort="<?= e((string) $c['created_at']) ?>"><?= tlocal($c['created_at'], 'date') ?></td>
            <td data-sort="<?= (int) $c['amount_cents'] ?>">
              <?= e(money(((int) $c['amount_cents']) / 100, $c['currency'] ?: 'USD')) ?></td>
            <td data-sort="<?= e((string) $c['paid_on']) ?>">
              <?= $c['paid_on'] ? e(date('j M Y', strtotime($c['paid_on']))) : '—' ?></td>
            <td class="small"><?= e(($methods ?? [])[$c['method']] ?? $c['method']) ?></td>
            <td class="small trunc"><?= e($c['reference'] ?? '') ?: '—' ?></td>
            <td><span class="status <?= $c['status'] === 'confirmed' ? 'approved'
                  : ($c['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= e($c['status']) ?></span>
              <?php if (!empty($c['review_note'])): ?>
                <br><small class="muted"><?= e($c['review_note']) ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted small">"Pending" means we haven't matched it in our account yet. Transfers can
         take one to two business days, and international ones sometimes longer.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
