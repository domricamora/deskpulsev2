<?php
/** Super-admin Subscribers: who the customers are and whether they're actually using it.
 *  Expects: $rows, $totals */
$cents = fn ($c) => e(money(((int) $c) / 100, 'USD'));
$day   = fn ($d) => $d ? e(date('j M Y', strtotime($d))) : '—';
$dormant = count(array_filter($rows, fn ($r) => $r['dormant']));
$lapsed  = count(array_filter($rows, fn ($r) => !$r['is_current']));
?>
<div class="page-actions">
  <a class="ghost" href="<?= e(url('/app/platform/accounting')) ?>">Accounting →</a>
  <a class="btn sm ghost" href="<?= e(url('/app/platform/subscribers.csv')) ?>">Export CSV</a>
</div>

<div class="stat-row">
  <div class="stat"><span class="lbl">Subscribers</span><b><?= (int) $totals['subscribers'] ?></b>
    <span class="sub">organizations</span></div>
  <div class="stat"><span class="lbl">Active</span><b><?= (int) ($totals['active'] ?? 0) ?></b></div>
  <div class="stat"><span class="lbl">On trial</span><b><?= (int) ($totals['trialing'] ?? 0) ?></b></div>
  <div class="stat <?= !empty($totals['past_due']) ? 'alert' : '' ?>">
    <span class="lbl">Past due</span><b><?= (int) ($totals['past_due'] ?? 0) ?></b></div>
  <div class="stat"><span class="lbl">MRR</span><b><?= e(money($totals['mrr'], 'USD')) ?></b></div>
  <div class="stat"><span class="lbl">Total seats</span><b><?= (int) $totals['seats'] ?></b></div>
  <div class="stat <?= $dormant ? 'alert' : '' ?>"><span class="lbl">Dormant</span><b><?= $dormant ?></b>
    <span class="sub">no tracking in 30 days</span></div>
  <div class="stat <?= $lapsed ? 'alert' : '' ?>"><span class="lbl">Not current</span><b><?= $lapsed ?></b>
    <span class="sub">unpaid or expired</span></div>
</div>

<div class="panel">
  <h3>Subscribers</h3>
  <p class="muted">Every organization on the platform, what they're on, and whether they're still
     using it. <b>Dormant</b> means nobody has tracked any time in 30 days — the strongest churn
     signal here, and worth a call before the renewal rather than after.
     Money and invoices live on <a href="<?= e(url('/app/platform/accounting')) ?>">Accounting</a>.</p>
  <?php if (!$rows): ?>
    <div class="empty-state">No organizations yet.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr>
      <th>Organization</th><th>Status</th><th>Plan</th><th>Due / cycle</th>
      <th>Seats</th><th>People</th><th>Agents</th><th>Sessions 30d</th>
      <th>Last activity</th><th>Signed up</th><th>Paid until</th><th>Lifetime paid</th><th>Contact</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $o = $r['org']; ?>
      <tr>
        <td><a href="<?= e(url('/app/platform/subscription/' . (int) $o['id'])) ?>"><b><?= e($o['name']) ?></b></a>
          <?php if ($o['status'] !== 'approved'): ?>
            <br><span class="status pending"><?= e($o['status']) ?></span>
          <?php endif; ?>
        </td>
        <td data-sort="<?= e($r['state']) ?>">
          <span class="status <?= $r['is_current'] ? 'approved' : ($r['state'] === 'trialing' ? 'pending' : 'rejected') ?>">
            <?= e($r['state_label']) ?></span>
          <?php if ($r['dormant']): ?><br><span class="status pending">dormant</span><?php endif; ?>
        </td>
        <td><span class="tag"><?= e($o['plan_type']) ?></span>
          <?php if ((float) $o['discount_pct'] > 0): ?>
            <br><small class="muted"><?= e(rtrim(rtrim(number_format((float) $o['discount_pct'], 2), '0'), '.')) ?>% off</small>
          <?php endif; ?>
        </td>
        <td data-sort="<?= e(number_format($r['due'], 2, '.', '')) ?>">
          <?= e(money($r['due'], $o['billing_currency'] ?: 'USD')) ?></td>
        <td data-sort="<?= (int) $r['seats'] ?>"><?= (int) $r['seats'] ?></td>
        <td data-sort="<?= (int) $r['people'] ?>"><?= (int) $r['people'] ?></td>
        <td data-sort="<?= (int) $r['devices'] ?>"><?= (int) $r['devices'] ?></td>
        <td data-sort="<?= (int) $r['sessions30'] ?>"><?= (int) $r['sessions30'] ?></td>
        <td data-sort="<?= e((string) $r['last_active']) ?>">
          <?= $r['last_active'] ? tlocal($r['last_active'], 'date') : '<span class="muted">never</span>' ?></td>
        <td data-sort="<?= e((string) $o['created_at']) ?>"><?= $day($o['created_at']) ?></td>
        <td data-sort="<?= e((string) $o['current_period_end']) ?>"><?= $day($o['current_period_end']) ?></td>
        <td data-sort="<?= (int) $r['paid_cents'] ?>"><?= $cents($r['paid_cents']) ?></td>
        <td class="small">
          <?php if ($r['admin']): ?>
            <?= e($r['admin']['name']) ?><br><small class="muted"><?= e($r['admin']['email']) ?></small>
          <?php else: ?><span class="muted">no admin</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
