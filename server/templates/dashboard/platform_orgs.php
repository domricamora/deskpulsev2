<?php include __DIR__ . '/_platform_tabs.php'; ?>
<?php
$statusClass = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'];
$agentLink = function (array $a): string {
    return '<button type="button" class="agent-link" data-user-id="' . (int) $a['id'] . '">'
        . e($a['name']) . ' <span class="muted">· ' . e(role_label($a['role'])) . '</span></button>';
};
?>
<p class="muted">Every customer organization. Expand one to explore its teams, clients and agents —
   click an agent for full details.</p>

<details class="panel" style="margin-bottom:1rem">
  <summary style="cursor:pointer;font-weight:600">+ Create organization</summary>
  <p class="muted" style="margin-top:.6rem">Provision a new customer organization and its company-admin login.
     It starts <b>approved</b> and on the standard trial. Leave the password blank to auto-generate a
     temporary one (the admin is then forced to reset it on first sign-in).</p>
  <form method="post" action="<?= e(url('/app/platform')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="orgs">
    <label>Organization name <input type="text" name="company" required></label>
    <label>Plan
      <select name="plan">
        <?php foreach (plan_labels() as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $k === 'per_seat' ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Admin name <input type="text" name="admin_name" required></label>
    <label>Admin email <input type="email" name="admin_email" required></label>
    <label>Admin password <input type="text" name="admin_password" placeholder="leave blank to auto-generate">
      <small class="muted">Optional — at least 8 characters if set.</small></label>
    <button class="btn" name="action" value="create_org" type="submit">Create organization</button>
  </form>
</details>

<?php if (!$orgs): ?>
  <div class="panel"><div class="empty-state">No organizations yet.</div></div>
<?php endif; ?>

<?php foreach ($orgs as $g): $o = $g['row']; ?>
  <details class="org-panel">
    <summary>
      <span class="op-name"><b><?= e($o['name']) ?></b>
        <span class="status <?= $statusClass[$o['status']] ?? 'pending' ?>"><?= e($o['status']) ?></span></span>
      <span class="muted small"><?= (int) $o['users'] ?> agent<?= $o['users'] == 1 ? '' : 's' ?>
        · <?= e(fmt_hms($o['week_active_s'])) ?> this week
        · <?php if ($o['billing_status'] === 'active'): ?>
            <?= e(money(effective_monthly_fee($o), $o['billing_currency'])) ?>/mo
          <?php else: ?>no billing<?php endif; ?></span>
    </summary>
    <div class="op-body">
      <div class="op-manage">
        <div class="actions-row">
          <?php if ($o['status'] !== 'approved'): ?>
            <form method="post" action="<?= e(url('/app/platform')) ?>"><?= csrf_field() ?>
              <input type="hidden" name="return" value="orgs"><input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm" name="action" value="approve_org">Approve</button></form>
          <?php endif; ?>
          <?php if ($o['status'] !== 'rejected'): ?>
            <form method="post" action="<?= e(url('/app/platform')) ?>"><?= csrf_field() ?>
              <input type="hidden" name="return" value="orgs"><input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
              <button class="btn sm danger" name="action" value="reject_org">Reject</button></form>
          <?php endif; ?>
          <a class="btn sm ghost" href="<?= e(url('/app/platform/act/' . $o['id'])) ?>" target="_blank" rel="noopener">View dashboards</a>
          <form method="post" action="<?= e(url('/app/platform')) ?>" onsubmit="return confirm('Delete this organization and ALL its data?')"><?= csrf_field() ?>
            <input type="hidden" name="return" value="orgs"><input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
            <button class="lnk danger" name="action" value="delete_org">delete</button></form>
        </div>
        <form method="post" action="<?= e(url('/app/platform/billing')) ?>" class="bill-form"><?= csrf_field() ?>
          <input type="hidden" name="return" value="orgs"><input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
          <label class="muted small">Plan</label>
          <?php // Every plan must be listed here: an omitted value silently converts the
                // tenant to 'organization' the moment this row is saved. ?>
          <select name="plan_type" style="width:150px">
            <?php foreach (plan_labels() as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= plan_type_clean($o['plan_type'] ?? null) === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="muted small">Discount</label>
          <input type="number" name="discount_pct" step="0.01" min="0" max="100" style="width:62px"
                 value="<?= e(rtrim(rtrim(number_format((float) ($o['discount_pct'] ?? 0), 2), '0'), '.')) ?>" placeholder="0"> %
          <input type="text" name="billing_currency" value="<?= e($o['billing_currency']) ?>" style="width:54px">
          <span class="muted small">= <?= e(money(effective_monthly_fee($o), $o['billing_currency'])) ?>/mo</span>
          <button class="btn sm" name="action" value="set_rate">Save</button>
          <?php if ($o['billing_status'] === 'active'): ?>
            <button class="lnk" name="action" value="pause_billing">pause</button>
          <?php else: ?>
            <button class="btn sm ghost" name="action" value="start_billing">Start billing</button>
          <?php endif; ?>
        </form>
      </div>

      <div class="op-tree">
        <h4>Teams</h4>
        <?php if (!$g['teams'] && !$g['unassigned']): ?><p class="muted">No teams or agents yet.</p><?php endif; ?>
        <?php foreach ($g['teams'] as $t): $mem = $g['by_team'][$t['id']] ?? []; ?>
          <details class="org-group">
            <summary><b><?= e($t['name']) ?></b> <span class="muted">· <?= count($mem) ?> agent<?= count($mem) == 1 ? '' : 's' ?></span></summary>
            <?php if ($mem): ?><div class="agent-rows"><?php foreach ($mem as $a) echo $agentLink($a); ?></div>
            <?php else: ?><p class="muted">No agents on this team.</p><?php endif; ?>
          </details>
        <?php endforeach; ?>
        <?php if ($g['unassigned']): ?>
          <details class="org-group">
            <summary><b>Unassigned</b> <span class="muted">· <?= count($g['unassigned']) ?> not on a team</span></summary>
            <div class="agent-rows"><?php foreach ($g['unassigned'] as $a) echo $agentLink($a); ?></div>
          </details>
        <?php endif; ?>

        <h4>Clients</h4>
        <?php if (!$g['clients']): ?><p class="muted">No clients yet.</p><?php endif; ?>
        <?php foreach ($g['clients'] as $c): $ag = $g['by_client'][$c['id']] ?? []; ?>
          <details class="org-group">
            <summary><b><?= e($c['name']) ?></b>
              <?php if ($c['archived']): ?><span class="status rejected">archived</span><?php endif; ?>
              <span class="muted">· <?= count($ag) ?> agent<?= count($ag) == 1 ? '' : 's' ?></span></summary>
            <?php if ($ag): ?><div class="agent-rows"><?php foreach ($ag as $a) echo $agentLink($a); ?></div>
            <?php else: ?><p class="muted">No agents have logged time under this client.</p><?php endif; ?>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<div id="user-modal" class="modal-backdrop" hidden data-base="<?= e(url('/app/platform/user/')) ?>"
     data-remote-base="<?= e(url('/app/remote/')) ?>">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="um-title">
    <div class="modal-head"><h3 id="um-title">Agent</h3>
      <button type="button" class="modal-close" id="um-close" aria-label="Close">&times;</button></div>
    <div class="modal-body" id="um-body"></div>
  </div>
</div>
<script src="<?= e(url('/assets/js/platform.js')) ?>"></script>
