<?php
$periods = period_options(['week', 'pay', 'month']);
$activeColor = '#3b82f6'; $idleColor = '#fbbf24';   // blue active · amber inactive (matches overview.php)
$scoreClass = fn (int $pct) => $pct >= 80 ? 'approved' : ($pct >= 50 ? 'pending' : 'rejected');
?>
<?= period_switch_html($periods, $period, $period_date, '/app/reports/efficiency', [],
  '<a class="ghost right" href="' . e(url('/app/reports/efficiency.csv?' . period_qs($period, $period_date))) . '">Export CSV</a>') ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">People reported</span><b><?= count($rows) ?></b></div>
  <div class="stat"><span class="lbl">Active time</span><b><?= e(fmt_hms($org_active_s)) ?></b></div>
  <div class="stat"><span class="lbl">Inactive time</span><b><?= e(fmt_hms($org_inactive_s)) ?></b></div>
  <div class="stat"><span class="lbl">Avg effectiveness</span><b><?= (int) $org_avg_effectiveness ?>%</b></div>
</div>

<div class="panel">
  <h3>Employee efficiency &amp; effectiveness</h3>
  <p class="muted">Ranked by effectiveness — a blend of activity % and task completion % (activity %
     alone when someone has no tasks assigned). Use this alongside the Team page's per-person
     activity donuts for a fuller performance picture.</p>

  <?php if (!$rows): ?>
    <div class="empty-state">No one to report on for this period yet.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr>
      <th>Name</th><th>Role</th><th>Activity</th>
      <th>Active</th><th>Inactive</th><th>Sessions</th>
      <th>Tasks done</th><th>Effectiveness</th>
      <?php if ($can_rates): ?><th>Labor cost</th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): $rTotal = $r['active_s'] + $r['inactive_s']; ?>
      <tr>
        <td><b><?= e($r['name']) ?></b></td>
        <td><span class="tag"><?= e(role_label($r['role'])) ?></span></td>
        <td>
          <?php if ($rTotal > 0): ?>
            <canvas class="dp-chart" data-type="donut" data-mini height="46"
              data-values='<?= e(json_encode([round($r['active_s'] / 3600, 2), round($r['inactive_s'] / 3600, 2)])) ?>'
              data-labels='<?= e(json_encode(['Active', 'Inactive'])) ?>'
              data-colors='<?= e(json_encode([$activeColor, $idleColor])) ?>'
              title="<?= (int) $r['activity_pct'] ?>% active"></canvas>
          <?php else: ?><span class="muted small">—</span><?php endif; ?>
        </td>
        <td><?= e(fmt_hms($r['active_s'])) ?></td>
        <td><?= e(fmt_hms($r['inactive_s'])) ?></td>
        <td><?= (int) $r['sessions'] ?></td>
        <td><?= $r['tasks_total'] ? $r['tasks_done'] . ' / ' . $r['tasks_total'] . ' (' . (int) $r['task_completion_pct'] . '%)' : '<span class="muted">no tasks</span>' ?></td>
        <td><?= $r['effectiveness_pct'] === null
              ? '<span class="muted small">no data</span>'
              : '<span class="status ' . $scoreClass((int) $r['effectiveness_pct']) . '">' . (int) $r['effectiveness_pct'] . '%</span>' ?></td>
        <?php if ($can_rates): ?><td><?= $r['cost'] !== null ? e(money($r['cost'], $r['currency'])) : '—' ?></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
