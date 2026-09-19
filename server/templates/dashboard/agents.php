<?php if ($can_manage): ?>
  <p class="muted">Manage the agents under you — assign each to one or more clients, and
     create, update or remove their tasks. Click <b>View full details</b> for activity, devices and more.</p>
<?php else: ?>
  <p class="muted">The team members working on your engagement. Click <b>View full details</b>
     for each agent's activity, tasks and screenshots.</p>
<?php endif; ?>

<?php if (!$agents): ?>
  <div class="panel"><div class="empty-state"><?= $can_manage ? 'No agents under you yet.' : 'No agents are assigned to your account yet.' ?></div></div>
<?php endif; ?>

<?php foreach ($agents as $a): ?>
<details class="org-panel">
  <summary>
    <span class="op-name">
      <?php if ($a['live']): ?><span class="dot live"></span><?php endif; ?>
      <b><?= e($a['name']) ?></b>
      <?php if ($can_manage): ?><span class="status pending"><?= e(role_label($a['role'])) ?></span><?php endif; ?>
    </span>
    <span class="muted small"><?php if ($can_manage): ?><?= count($a['tasks']) ?> task<?= count($a['tasks']) === 1 ? '' : 's' ?>
      · <?= count($a['client_ids']) ?> client<?= count($a['client_ids']) === 1 ? '' : 's' ?>
      · <?php endif; ?><?= e(fmt_hms($a['week_active_s'])) ?> this week</span>
  </summary>
  <div class="op-body">
    <div class="agent-meta">
      <span class="muted"><?= e($a['email']) ?><?php if ($a['job_title']): ?> · <?= e($a['job_title']) ?><?php endif; ?></span>
      <a class="btn sm ghost" href="<?= e(url('/app/agents/' . (int) $a['id'])) ?>">View full details &rarr;</a>
    </div>

    <?php if ($can_manage): ?>
      <h4>Client assignment</h4>
      <?php if ($clients): ?>
        <form method="post" action="<?= e(url('/app/agents')) ?>" class="row-form assign-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign_clients">
          <input type="hidden" name="agent_id" value="<?= (int) $a['id'] ?>">
          <label class="grow">Clients <span class="muted small">(Ctrl/⌘-click for multiple)</span>
            <select name="client_ids[]" multiple size="<?= max(3, min(6, count($clients))) ?>">
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $a['client_ids'], true) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn" type="submit">Save clients</button>
        </form>
      <?php else: ?>
        <p class="muted small">No clients yet — add them on the <a href="<?= e(url('/app/clients')) ?>">Clients</a> page.</p>
      <?php endif; ?>

      <h4>Tasks &amp; time spent <span class="muted small">— managed by the agent</span></h4>
      <table class="data">
        <thead><tr><th>Task</th><th>Client</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
        <tbody>
          <?php if (!$a['tasks']): ?><tr><td colspan="5" class="muted">No tasks yet.</td></tr><?php endif; ?>
          <?php foreach ($a['tasks'] as $t): $tt = $time_by_task[$t['id']] ?? ['secs' => 0, 'cnt' => 0]; ?>
            <tr>
              <td><?= e($t['title']) ?></td>
              <td><?= $t['client_id'] ? e(client_name($t['client_id'])) : '<span class="muted">—</span>' ?></td>
              <td><span class="status <?= $t['status'] === 'done' ? 'approved' : 'pending' ?>"><?= e($t['status']) ?></span></td>
              <td><?= (int) $tt['cnt'] ?></td>
              <td><b><?= e(fmt_hms($tt['secs'])) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</details>
<?php endforeach; ?>
