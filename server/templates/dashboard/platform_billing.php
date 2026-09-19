<?php
$badge = ['active' => 'approved', 'paused' => 'rejected', 'none' => 'pending'];
$fmtFee = function (float $fee, float $base, float $disc, string $cur): string {
    $s = '<b>' . e(money($fee, $cur)) . '</b> <span class="muted small">' . e(plan_period_label()) . '</span>';
    if ($disc > 0) {
        $s .= ' <span class="muted small">(' . rtrim(rtrim(number_format($disc, 2), '0'), '.')
            . '% off ' . e(money($base, $cur)) . ')</span>';
    }
    return $s;
};
?>
<?php include __DIR__ . '/_platform_tabs.php'; ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">MRR (this cycle)</span><b><?= e(money($mrr, 'USD')) ?></b>
    <span class="sub">across active customers</span></div>
  <div class="stat"><span class="lbl">Billing active</span><b><?= (int) $active_count ?></b>
    <span class="sub">customers</span></div>
</div>

<div class="panel">
  <h3>Customer billing — subscription plan &amp; discount</h3>
  <p class="muted">Each customer is on a standard <b>plan</b> (Individual or Organization) and pays that
     plan's monthly price less an optional <b>percentage discount</b>. Set the two standard prices on
     <a href="<?= e(url('/app/platform/settings')) ?>">Platform settings</a>. The payment cycle is 30 days
     from the start of service.</p>
  <table class="data">
    <thead><tr><th>Customer</th><th>Plan &amp; discount</th><th>Effective fee</th>
      <th>Current cycle</th><th>Due this cycle</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No customers yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): $o = $row['org']; $b = $row['bill']; ?>
      <tr>
        <td><a href="<?= e(url('/app/platform/subscription/' . (int) $o['id'])) ?>"><b><?= e($o['name']) ?></b></a>
          <br><small class="muted">invoices, payments &amp; details →</small></td>
        <td>
          <form method="post" action="<?= e(url('/app/platform/billing')) ?>" class="bill-form"><?= csrf_field() ?>
            <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
            <select name="plan_type" style="width:150px">
              <?php foreach (plan_labels() as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= $b['plan'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="discount_pct" step="0.01" min="0" max="100" style="width:74px"
                   value="<?= e(rtrim(rtrim(number_format((float) $o['discount_pct'], 2), '0'), '.')) ?>" placeholder="0"> %
            <input type="text" name="billing_currency" value="<?= e($o['billing_currency']) ?>" style="width:60px">
            <button class="btn sm" name="action" value="set_rate">Save</button>
          </form>
        </td>
        <td><?= $fmtFee((float) $b['rate'], (float) $b['base'], (float) $b['discount_pct'], $b['currency']) ?></td>
        <td>
          <?php if ($b['cycle_start']): ?>
            <small><?= tlocal($b['cycle_start'], 'date') ?> – <?= tlocal($b['cycle_end'], 'date') ?></small>
            <br><small class="muted">next due <?= tlocal($b['cycle_end'], 'date') ?></small>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td><b><?= e(money($b['due'], $b['currency'])) ?></b></td>
        <td>
          <span class="status <?= $badge[$o['billing_status']] ?? 'pending' ?>"><?= e($o['billing_status']) ?></span>
          <div class="muted small" style="margin-top:.3rem"><?= e($row['state']) ?></div>
          <div class="actions-row" style="margin-top:.4rem">
            <?php if ($o['billing_status'] !== 'active'): ?>
              <form method="post" action="<?= e(url('/app/platform/billing')) ?>"><?= csrf_field() ?>
                <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
                <button class="btn sm" name="action" value="start_billing">Comp</button></form>
            <?php else: ?>
              <form method="post" action="<?= e(url('/app/platform/billing')) ?>"><?= csrf_field() ?>
                <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
                <button class="lnk" name="action" value="pause_billing">un-comp</button></form>
            <?php endif; ?>
          </div>
          <form method="post" action="<?= e(url('/app/platform/billing')) ?>" class="bill-form" style="margin-top:.4rem"><?= csrf_field() ?>
            <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
            <input type="number" name="amount" step="0.01" min="0" style="width:82px" placeholder="amount">
            <button class="btn sm" name="action" value="record_payment">Record payment</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($unmatched)): ?>
<div class="panel">
  <h3>Unmatched deposits</h3>
  <p class="muted">Incoming Wise credits with no matching payment reference. Assign each to a customer to
     mark their current invoice paid and extend their subscription.</p>
  <table class="data">
    <thead><tr><th>Received</th><th>Amount</th><th>Reference</th><th>Assign to</th></tr></thead>
    <tbody>
    <?php foreach ($unmatched as $p): ?>
      <tr>
        <td><small><?= tlocal($p['occurred_at'], 'datetime') ?></small></td>
        <td><b><?= e(money($p['amount_cents'] / 100, $p['currency'])) ?></b></td>
        <td class="muted small"><?= e($p['reference_raw'] ?: '—') ?></td>
        <td>
          <form method="post" action="<?= e(url('/app/platform/billing')) ?>" class="bill-form"><?= csrf_field() ?>
            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
            <select name="org_id" style="width:170px">
              <option value="">— choose customer —</option>
              <?php foreach ($rows as $r2): ?>
                <option value="<?= (int) $r2['org']['id'] ?>"><?= e($r2['org']['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn sm" name="action" value="assign_payment">Assign</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
