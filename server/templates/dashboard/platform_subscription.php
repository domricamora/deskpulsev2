<?php
/** Super-admin: one customer's subscription, billing and account detail.
 *  Expects: $org, $bill, $state, $days_left, $is_current, $users, $invoices,
 *           $payments, $unmatched, $paid_total, $usage, $period, $prices, $deposit */
$cur   = $org['billing_currency'] ?: 'USD';
$money = fn ($v) => e(money((float) $v, $cur));
$cents = fn ($c) => e(money(((int) $c) / 100, $cur));
$day   = fn ($dt) => $dt ? e(date('j M Y', strtotime($dt))) : '—';
$admins = array_values(array_filter($users, fn ($x) => $x['role'] === 'client_admin'));
?>
<div class="page-actions">
  <a class="ghost" href="<?= e(url('/app/platform/billing')) ?>">← All customers</a>
  <a class="btn sm ghost" href="<?= e(url('/app/platform/act/' . (int) $org['id'])) ?>">Act as this org</a>
</div>

<div class="panel">
  <div class="panel-head">
    <h3><?= e($org['name']) ?></h3>
    <span class="status <?= $is_current ? 'approved' : ($org['status'] === 'pending' ? 'pending' : 'rejected') ?>">
      <?= e($state) ?></span>
  </div>
  <div class="stat-row">
    <div class="stat"><span class="lbl">Plan</span><b><?= e($bill['plan']) ?></b>
      <span class="sub"><?= $money($bill['base']) ?> list<?= (float) $org['discount_pct'] > 0
        ? ' · ' . e(rtrim(rtrim(number_format((float) $org['discount_pct'], 2), '0'), '.')) . '% off' : '' ?></span></div>
    <div class="stat"><span class="lbl">Due per cycle</span><b><?= $money($bill['due']) ?></b>
      <span class="sub">per <?= e($period['label'] ?? 'month') ?></span></div>
    <div class="stat"><span class="lbl">Seats</span><b><?= (int) $bill['seats'] ?></b></div>
    <div class="stat <?= (!$is_current) ? 'alert' : '' ?>"><span class="lbl">Paid until</span>
      <b><?= $day($org['current_period_end']) ?></b>
      <span class="sub"><?= $days_left > 0 ? (int) $days_left . ' day(s) left' : 'not current' ?></span></div>
    <div class="stat"><span class="lbl">Lifetime paid</span><b><?= $cents($paid_total) ?></b></div>
  </div>
  <p class="muted small">
    Org #<?= (int) $org['id'] ?> · created <?= $day($org['created_at']) ?> ·
    signup status <b><?= e($org['status']) ?></b> ·
    billing <b><?= e($org['billing_status']) ?></b> ·
    deposit reference <b><?= e($org['pay_reference'] ?: 'not issued') ?></b>
    <?php if (!empty($org['trial_ends_at'])): ?> · trial ends <?= $day($org['trial_ends_at']) ?><?php endif; ?>
  </p>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Plan &amp; pricing</h3>
    <form method="post" action="" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_plan">
      <div class="inline">
        <label>Plan <select name="plan_type">
          <?php foreach (plan_labels() as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= plan_type_clean($org['plan_type'] ?? null) === $k ? 'selected' : '' ?>>
              <?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Discount %
          <input type="number" name="discount_pct" step="0.01" min="0" max="100"
                 value="<?= e($org['discount_pct']) ?>"></label>
        <label>Enterprise fee
          <input type="number" name="custom_fee" step="0.01" min="0" style="width:110px"
                 value="<?= $org['custom_fee'] !== null ? e(number_format((float) $org['custom_fee'], 2, '.', '')) : '' ?>"
                 placeholder="—">
          <small class="muted">Only used by the Enterprise plan.</small></label>
        <label>Currency
          <input type="text" name="billing_currency" maxlength="8" value="<?= e($cur) ?>"></label>
      </div>
      <?php $capSeats = plan_cap_seats($prices); ?>
      <p class="muted small">List prices: solo <?= $money($prices['solo']) ?>,
         individual <?= $money($prices['individual']) ?>,
         team <?= $money($prices['per_seat']) ?>/seat<?php
           if ((int) $prices['seats_bill_min'] > 0):
             ?> (min <?= (int) $prices['seats_bill_min'] ?> seats = <?= $money($prices['per_seat'] * (int) $prices['seats_bill_min']) ?>)<?php
           endif; ?>,
         organization <?= $money($prices['organization']) ?>.
         <?php if ($capSeats > 0): ?>
           A per-seat bill is capped at <?= $money($prices['seat_cap']) ?>, which engages at
           <?= (int) $capSeats ?> seats<?= (int) $prices['seats_cap_covers'] > 0
             ? ' and covers up to ' . (int) $prices['seats_cap_covers'] . ' seats' : '' ?>.
         <?php else: ?>
           No per-seat cap is configured — a per-seat bill grows without limit.
         <?php endif; ?>
         Changing the plan recalculates the stored monthly fee immediately.</p>
      <button class="btn" type="submit">Save plan</button>
    </form>

    <h4 style="margin-top:1.2rem">Billing switch</h4>
    <div class="actions-row">
      <form method="post" action="">
        <?= csrf_field() ?><input type="hidden" name="action" value="start_billing">
        <button class="btn sm" type="submit"
          <?= $org['billing_status'] === 'active' ? 'disabled' : '' ?>>Start billing</button>
      </form>
      <form method="post" action="">
        <?= csrf_field() ?><input type="hidden" name="action" value="pause_billing">
        <button class="btn sm ghost" type="submit"
          <?= $org['billing_status'] !== 'active' ? 'disabled' : '' ?>>Pause billing</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <h3>Subscription state</h3>
    <p class="muted">Manual override for comped accounts, disputes and cancellations that
       never pass through a payment.</p>
    <form method="post" action="" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_status">
      <label>Status <select name="subscription_status">
        <?php foreach (['none' => 'None', 'trialing' => 'Trialing', 'active' => 'Active',
                        'past_due' => 'Past due', 'canceled' => 'Canceled'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= ($org['subscription_status'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select></label>
      <div class="inline">
        <label>Trial ends
          <input type="date" name="trial_ends_at"
                 value="<?= e($org['trial_ends_at'] ? substr($org['trial_ends_at'], 0, 10) : '') ?>"></label>
        <label>Paid until
          <input type="date" name="current_period_end"
                 value="<?= e($org['current_period_end'] ? substr($org['current_period_end'], 0, 10) : '') ?>"></label>
      </div>
      <button class="btn" type="submit">Save state</button>
    </form>

    <h4 style="margin-top:1.2rem">Extend without payment</h4>
    <form method="post" action="" class="row-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="extend">
      <label>Periods <input type="number" name="periods" min="1" max="24" value="1" style="width:80px"></label>
      <button class="btn sm ghost" type="submit">Extend</button>
    </form>
    <p class="muted small">Adds <?= (int) ($period['count'] ?? 1) ?> <?= e($period['unit'] ?? 'month') ?>(s)
       per period to "paid until". Use for goodwill credit — no invoice or payment is created.</p>
  </div>
</div>

<div class="panel">
  <h3>Record a payment</h3>
  <p class="muted">Marks the open invoice paid and extends the subscription by one cycle.
     Use this for bank transfers, cash or anything settled outside DeskPulse.</p>
  <form method="post" action="" class="row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_payment">
    <label>Amount (<?= e($cur) ?>)
      <input type="number" name="amount" step="0.01" min="0.01"
             value="<?= e(number_format((float) $bill['due'], 2, '.', '')) ?>" required></label>
    <label class="grow">Note <input type="text" name="note" maxlength="255"
           placeholder="e.g. bank transfer ref 88213"></label>
    <button class="btn" type="submit">Record payment</button>
  </form>
</div>

<?php if ($unmatched): ?>
<div class="panel">
  <h3>Unassigned deposits <span class="badge"><?= count($unmatched) ?></span></h3>
  <p class="muted">Money that arrived without a recognizable reference. Assign one here to
     settle this customer's invoice and extend their period.</p>
  <table class="data">
    <thead><tr><th>Received</th><th>Amount</th><th>Reference</th><th>Provider</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($unmatched as $p): ?>
      <tr>
        <td data-sort="<?= e((string) $p['occurred_at']) ?>"><?= tlocal($p['occurred_at']) ?></td>
        <td data-sort="<?= (int) $p['amount_cents'] ?>">
          <?= e(money(((int) $p['amount_cents']) / 100, $p['currency'] ?: 'USD')) ?></td>
        <td class="small trunc"><?= e($p['reference_raw'] ?? '') ?: '—' ?></td>
        <td><span class="tag"><?= e($p['provider']) ?></span></td>
        <td>
          <form method="post" action="" class="inline-form"
                onsubmit="return confirm('Assign this deposit to <?= e($org['name']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
            <button class="lnk" type="submit">assign to this org</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="two-col">
  <div class="panel">
    <div class="panel-head">
      <h3>Invoices</h3>
      <form method="post" action="" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="issue_invoice">
        <button class="btn sm ghost" type="submit">Issue for this cycle</button>
      </form>
    </div>
    <?php if (!$invoices): ?>
      <div class="empty-state">No invoices yet.</div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Reference</th><th>Period</th><th>Amount</th><th>Status</th><th>Issued</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td class="small"><?= e($i['reference']) ?></td>
          <td class="small"><?= $day($i['period_start']) ?> – <?= $day($i['period_end']) ?></td>
          <td data-sort="<?= (int) $i['amount_cents'] ?>"><?= $cents($i['amount_cents']) ?></td>
          <td><span class="status <?= $i['status'] === 'paid' ? 'approved'
                : ($i['status'] === 'void' ? 'rejected' : 'pending') ?>"><?= e($i['status']) ?></span></td>
          <td data-sort="<?= e((string) $i['issued_at']) ?>"><?= tlocal($i['issued_at'], 'date') ?></td>
          <td>
            <?php if ($i['status'] === 'open'): ?>
              <form method="post" action="" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="void_invoice">
                <input type="hidden" name="invoice_id" value="<?= (int) $i['id'] ?>">
                <button class="lnk danger" type="submit">void</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Payments</h3>
    <?php if (!$payments): ?>
      <div class="empty-state">No payments recorded.</div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Received</th><th>Amount</th><th>Via</th><th>Reference / note</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td data-sort="<?= e((string) $p['occurred_at']) ?>"><?= tlocal($p['occurred_at'], 'date') ?></td>
          <td data-sort="<?= (int) $p['amount_cents'] ?>">
            <?= e(money(((int) $p['amount_cents']) / 100, $p['currency'] ?: 'USD')) ?></td>
          <td><span class="tag"><?= e($p['provider']) ?></span></td>
          <td class="small muted trunc"><?= e($p['reference_raw'] ?: ($p['note'] ?? '')) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Account &amp; contacts</h3>
    <?php if ($admins): ?>
      <p class="muted small">Primary contact:
        <b><?= e($admins[0]['name']) ?></b> &lt;<?= e($admins[0]['email']) ?>&gt;</p>
    <?php endif; ?>
    <table class="data">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach ($users as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td>
          <td class="small"><?= e($m['email']) ?></td>
          <td><span class="tag"><?= e(role_label($m['role'])) ?></span></td>
          <td data-sort="<?= e((string) $m['created_at']) ?>"><?= tlocal($m['created_at'], 'date') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h3>Usage</h3>
    <p class="muted">Whether this customer is actually using what they're paying for.</p>
    <div class="stat-row">
      <div class="stat"><span class="lbl">Billable seats</span><b><?= (int) $bill['seats'] ?></b></div>
      <div class="stat"><span class="lbl">Agents installed</span><b><?= (int) $usage['devices'] ?></b></div>
      <div class="stat"><span class="lbl">Sessions (30 days)</span><b><?= (int) $usage['sessions'] ?></b></div>
      <div class="stat"><span class="lbl">Clients tracked</span><b><?= (int) $usage['clients'] ?></b></div>
    </div>
    <p class="muted small">Last tracked activity:
      <?= $usage['last_seen'] ? tlocal($usage['last_seen']) : 'never' ?>.</p>
    <?php if (!empty($deposit['bank'])): ?>
      <h4 style="margin-top:1rem">Deposit instructions given to this customer</h4>
      <ul class="plain small muted">
        <li>Bank: <?= e($deposit['bank']) ?></li>
        <li>Routing: <?= e($deposit['routing']) ?></li>
        <li>Account: <?= e($deposit['account_number']) ?></li>
        <li>Reference: <b><?= e($org['pay_reference'] ?: 'not issued') ?></b></li>
      </ul>
    <?php endif; ?>
  </div>
</div>
