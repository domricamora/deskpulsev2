<?php
$periods = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];
$activeColor = '#3b82f6'; $idleColor = '#475569';
?>
<div class="share-wrap">
  <div class="share-head">
    <div>
      <h2><?= e($link['label'] ?: 'Activity summary') ?></h2>
      <p class="muted"><?= e($org_name) ?> · read-only summary</p>
    </div>
    <div class="period-switch">
      <?php foreach ($periods as $k => $label): ?>
        <a class="<?= $period === $k ? 'on' : '' ?>" href="<?= e(url('/share/' . $token . '?period=' . $k)) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="share-filters">
    <form method="get" action="<?= e(url('/share/' . $token)) ?>" class="row-form">
      <label>Week <input type="week" name="week" value="<?= e($week) ?>"></label>
      <button class="btn" type="submit">View week</button>
    </form>
    <form method="get" action="<?= e(url('/share/' . $token)) ?>" class="row-form">
      <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
      <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
      <button class="btn" type="submit">View range</button>
    </form>
    <span class="muted">Showing: <b><?= e($range_label) ?></b></span>
  </div>

  <div class="stat-row">
    <div class="stat"><span class="lbl">Active time</span><b><?= e(fmt_hms($summary['active_s'])) ?></b></div>
    <div class="stat"><span class="lbl">Inactive time</span><b><?= e(fmt_hms($summary['inactive_s'])) ?></b></div>
    <div class="stat"><span class="lbl">Activity</span><b><?= (int) $summary['activity_pct'] ?>%</b></div>
    <div class="stat"><span class="lbl">Sessions</span><b><?= (int) $summary['count'] ?></b></div>
  </div>

  <div class="panel">
    <h3>Active vs inactive hours</h3>
    <canvas class="dp-chart" height="240" data-type="bars"
      data-labels='<?= e(json_encode($series['labels'])) ?>'
      data-series='<?= e(json_encode([
          ['name' => 'Active', 'data' => $series['active'], 'color' => $activeColor],
          ['name' => 'Inactive', 'data' => $series['inactive'], 'color' => $idleColor],
      ])) ?>'></canvas>
  </div>

  <div class="panel">
    <h3>Top applications</h3>
    <?php if ($apps['labels']): ?>
      <canvas class="dp-chart" height="220" data-type="hbars"
        data-labels='<?= e(json_encode($apps['labels'])) ?>'
        data-values='<?= e(json_encode(array_map(fn($s) => round($s / 60, 1), $apps['seconds']))) ?>'
        data-unit="min"></canvas>
    <?php else: ?><p class="muted">No application data.</p><?php endif; ?>
  </div>

  <div class="panel">
    <h3>Time spent per task</h3>
    <?php if ($task_times): ?>
      <table class="data">
        <thead><tr><th>Task</th><?php if (!empty($multi_user)): ?><th>Agent</th><?php endif; ?>
          <th>Client</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
        <tbody>
          <?php foreach ($task_times as $t): ?>
            <tr>
              <td><?= e($t['title']) ?></td>
              <?php if (!empty($multi_user)): ?><td><?= e($t['owner_name']) ?></td><?php endif; ?>
              <td><?= $t['client_name'] ? e($t['client_name']) : '<span class="muted">—</span>' ?></td>
              <td><span class="status <?= $t['status'] === 'done' ? 'approved' : 'pending' ?>"><?= e($t['status']) ?></span></td>
              <td><?= (int) $t['cnt'] ?></td>
              <td><b><?= e(fmt_hms((int) $t['secs'])) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">No task time recorded in this period.</p>
    <?php endif; ?>
  </div>
</div>
