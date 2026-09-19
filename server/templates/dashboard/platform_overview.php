<?php
$statusClass = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'];
$billClass = ['active' => 'approved', 'paused' => 'rejected', 'none' => 'pending'];
?>
<div class="page-actions">
  <a class="btn sm ghost" href="<?= e(url('/app/platform')) ?>">Operations console</a>
  <a class="btn sm ghost" href="<?= e(url('/app/platform/billing')) ?>">Billing</a>
  <a class="btn sm ghost" href="<?= e(url('/app/platform/orgs')) ?>">Organizations</a>
</div>

<div class="stat-row">
  <div class="stat"><span class="lbl">MRR (this cycle)</span><b><?= e(money($stats['mrr'], 'USD')) ?></b>
    <span class="sub"><?= (int) $stats['billing'] ?> paying customer<?= $stats['billing'] == 1 ? '' : 's' ?></span></div>
  <div class="stat"><span class="lbl">Customers</span><b><?= (int) $stats['customers'] ?></b>
    <span class="sub"><?= (int) $stats['new_month'] ?> new this month</span></div>
  <div class="stat <?= $stats['leads'] ? 'alert' : '' ?>"><span class="lbl">Leads</span><b><?= (int) $stats['leads'] ?></b>
    <span class="sub"><?= $stats['leads'] ? 'awaiting review' : 'none open' ?></span></div>
  <div class="stat"><span class="lbl">Billable employees</span><b><?= (int) $stats['seats'] ?></b></div>
  <div class="stat"><span class="lbl">Tracked this week</span><b><?= e(fmt_hms($stats['week_secs'])) ?></b>
    <span class="sub">across all customers</span></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Leads — signups to convert</h3>
    <?php if ($leads): ?><span class="status pending"><?= count($leads) ?> open</span><?php endif; ?>
  </div>
  <?php if (!$leads): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      <div>No open leads — every signup has been reviewed.</div>
    </div>
  <?php else: ?>
    <div class="signup-list">
      <?php foreach ($leads as $o): ?>
        <div class="signup-item">
          <div class="signup-meta">
            <b><?= e($o['name']) ?></b>
            <div class="who muted">
              <?= e($o['owner_name']) ?><?= $o['owner_email'] ? ' · ' . e($o['owner_email']) : '' ?>
              · <?= (int) $o['users'] ?> user<?= $o['users'] == 1 ? '' : 's' ?>
              · signed up <?= tlocal($o['created_at'], 'date') ?>
            </div>
          </div>
          <div class="signup-actions">
            <form method="post" action="<?= e(url('/app/platform')) ?>"><?= csrf_field() ?>
              <input type="hidden" name="return" value="overview">
              <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm" name="action" value="approve_org">Approve</button></form>
            <form method="post" action="<?= e(url('/app/platform')) ?>"
                  onsubmit="return confirm('Reject this signup?')"><?= csrf_field() ?>
              <input type="hidden" name="return" value="overview">
              <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm danger" name="action" value="reject_org">Reject</button></form>
            <a class="btn sm ghost" href="<?= e(url('/app/platform/act/' . $o['id'])) ?>">View</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Top customers by revenue</h3>
    <table class="data">
      <thead><tr><th>Customer</th><th>Employees</th><th>Due / cycle</th><th>Billing</th></tr></thead>
      <tbody>
        <?php if (!$top): ?><tr><td colspan="4" class="muted">No customers yet.</td></tr><?php endif; ?>
        <?php foreach ($top as $t): ?>
          <tr>
            <td><b><?= e($t['name']) ?></b></td>
            <td><?= (int) $t['seats'] ?></td>
            <td><?= e(money($t['due'], $t['currency'])) ?></td>
            <td><span class="status <?= $billClass[$t['status']] ?? 'pending' ?>"><?= e($t['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel">
    <h3>Recent signups</h3>
    <table class="data">
      <thead><tr><th>Organization</th><th>When</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$recent): ?><tr><td colspan="3" class="muted">No organizations yet.</td></tr><?php endif; ?>
        <?php foreach ($recent as $o): ?>
          <tr>
            <td><?= e($o['name']) ?></td>
            <td><?= tlocal($o['created_at'], 'date') ?></td>
            <td><span class="status <?= $statusClass[$o['status']] ?? 'pending' ?>"><?= e($o['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
