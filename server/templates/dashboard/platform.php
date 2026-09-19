<?php
$statusClass = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'];
$hasTraffic = array_sum($traffic['values']) > 0;
?>
<div class="page-actions">
  <a class="btn sm ghost" href="<?= e(url('/app/platform/orgs')) ?>">Manage organizations</a>
  <a class="btn sm ghost" href="<?= e(url('/app/platform/accounts')) ?>">Manage accounts</a>
</div>

<div class="stat-row">
  <div class="stat"><span class="lbl">Organizations</span><b><?= (int) $stats['orgs'] ?></b></div>
  <div class="stat <?= $stats['pending'] ? 'alert' : '' ?>">
    <span class="lbl">Pending signups</span><b><?= (int) $stats['pending'] ?></b>
    <span class="sub"><?= $stats['pending'] ? 'awaiting review' : 'all caught up' ?></span>
  </div>
  <div class="stat"><span class="lbl">Employees</span><b><?= (int) $stats['users'] ?></b></div>
  <div class="stat"><span class="lbl">Tracking now</span><b><?= (int) $stats['open_now'] ?></b>
    <span class="sub">open sessions</span></div>
  <div class="stat"><span class="lbl">Active this week</span><b><?= e(fmt_hms($stats['week_secs'])) ?></b></div>
  <div class="stat"><span class="lbl">MRR</span><b><?= e(money($stats['mrr'], 'USD')) ?></b>
    <span class="sub"><?= (int) $stats['billing_active'] ?> billing</span></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Demo &amp; data tools</h3>
  </div>
  <p class="muted">Populate a full demo organization to explore every dashboard, or wipe
     all tenant data and start fresh (your super-admin account is always kept).</p>
  <div class="signup-actions">
    <form method="post" action="<?= e(url('/app/platform')) ?>"><?= csrf_field() ?>
      <button class="btn sm" name="action" value="seed_demo">Seed demo data</button></form>
    <form method="post" action="<?= e(url('/app/platform')) ?>"
          onsubmit="return confirm('Delete ALL tenant data (organizations, users, sessions, screenshots, everything) and keep only your super-admin account? This cannot be undone.')">
      <?= csrf_field() ?>
      <button class="btn sm danger" name="action" value="reset_data">Reset all data</button></form>
  </div>

  <h4 class="sched-h" style="margin-top:1rem">Update database</h4>
  <p class="muted small">Upload a <code>.sql</code> script (migration, schema change or data
     fix) to run against the current database. Comments are stripped and statements run in
     order; results are reported. Stored-procedure <code>DELIMITER</code> blocks aren't supported.</p>
  <form method="post" action="<?= e(url('/app/platform')) ?>" class="row-form" enctype="multipart/form-data"
        onsubmit="return confirm('Run this SQL file against the live database? Back up first — changes may be irreversible.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_db">
    <label class="grow">SQL file <input type="file" name="sqlfile" accept=".sql,text/plain" required></label>
    <button class="btn sm" type="submit">Run SQL update</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Signups awaiting review</h3>
    <?php if ($pending): ?><span class="status pending"><?= count($pending) ?> pending</span><?php endif; ?>
  </div>
  <?php if (!$pending): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      <div>No signups awaiting review — you're all caught up.</div>
    </div>
  <?php else: ?>
    <div class="signup-list">
      <?php foreach ($pending as $o): ?>
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
              <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm" name="action" value="approve_org">Approve</button></form>
            <form method="post" action="<?= e(url('/app/platform')) ?>"
                  onsubmit="return confirm('Reject this signup?')"><?= csrf_field() ?>
              <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm danger" name="action" value="reject_org">Reject</button></form>
            <a class="btn sm ghost" href="<?= e(url('/app/platform/act/' . $o['id'])) ?>">View</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Employee activity — across all organizations</h3>
  <?php if ($timeline['points']): ?>
    <canvas class="dp-chart" height="240"
      data-type="timeline"
      data-points='<?= e(json_encode($timeline['points'])) ?>'
      data-markers='<?= e(json_encode($timeline['markers'])) ?>'
      data-start='<?= (int) $timeline['start'] ?>'
      data-end='<?= (int) $timeline['end'] ?>'></canvas>
    <p class="muted">Activity % over the last 7 days, platform-wide. The <b>●</b> markers are
       screenshots — hover for the app/window and a preview.</p>
  <?php else: ?>
    <div class="empty-state">No tracked activity in the last 7 days yet.</div>
  <?php endif; ?>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Site traffic</h3>
    <?php if ($hasTraffic): ?>
      <canvas class="dp-chart" height="220" data-type="bars"
        data-labels='<?= e(json_encode($traffic['labels'])) ?>'
        data-series='<?= e(json_encode([['name' => 'Active employees', 'data' => $traffic['values'], 'color' => '#3b82f6']])) ?>'></canvas>
      <p class="muted">Distinct employees who tracked time each day (last 14 days).</p>
    <?php else: ?>
      <div class="empty-state">No activity recorded in the last 14 days.</div>
    <?php endif; ?>
  </div>
  <div class="panel">
    <div class="panel-head">
      <h3>Audit log</h3>
      <span class="muted small">platform-wide</span>
    </div>
    <table class="data">
      <thead><tr><th>When</th><th>Organization</th><th>Event</th></tr></thead>
      <tbody>
        <?php if (!$audit): ?><tr><td colspan="3" class="muted">No activity yet.</td></tr><?php endif; ?>
        <?php foreach ($audit as $ev): ?>
          <tr>
            <td><?= tlocal($ev['ts'], 'full') ?></td>
            <td class="trunc"><?= e($ev['org']) ?></td>
            <td><span class="tag"><?= e($ev['event']) ?></span>
              <?= $ev['detail'] ? '<small class="muted"> ' . e($ev['detail']) . '</small>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
