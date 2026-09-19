<?php
$periods = period_options(['day', 'week', 'pay', 'month']);
$aid = (int) $agent_user['id'];
$cur = $agent_user['currency'] ?: 'USD';
?>
<p><a class="lnk" href="<?= e(url('/app/agents')) ?>">&larr; Back to Agents</a></p>

<div class="detail-head">
  <div>
    <h2><?= $stats['live'] ? '<span class="dot live"></span> ' : '' ?><?= e($agent_user['name']) ?></h2>
    <p class="muted"><?= e(role_label($agent_user['role'])) ?><?php if ($agent_user['job_title']): ?> · <?= e($agent_user['job_title']) ?><?php endif; ?>
      <?php if ($stats['live']): ?> · <span class="pill on">tracking now</span><?php endif; ?></p>
  </div>
  <?= period_switch_html($periods, $period, $period_date, '/app/agents/' . $aid) ?>
</div>

<div class="stat-row">
  <div class="stat"><span class="lbl">Active time</span><b><?= e(fmt_hms($summary['active_s'])) ?></b></div>
  <div class="stat"><span class="lbl">Inactive time</span><b><?= e(fmt_hms($summary['inactive_s'])) ?></b></div>
  <div class="stat"><span class="lbl">Activity</span><b><?= (int) $summary['activity_pct'] ?>%</b></div>
  <div class="stat"><span class="lbl">Sessions</span><b><?= (int) $summary['count'] ?></b></div>
  <div class="stat"><span class="lbl">Avg session</span><b><?= e(fmt_hms($stats['avg_session_s'])) ?></b>
    <span class="sub">longest <?= e(fmt_hms($stats['longest_session_s'])) ?></span></div>
  <div class="stat"><span class="lbl">Idle time</span><b><?= e(fmt_hms($stats['idle_total_s'])) ?></b>
    <span class="sub"><?= (int) $stats['idle_count'] ?> idle break<?= $stats['idle_count'] == 1 ? '' : 's' ?></span></div>
  <div class="stat"><span class="lbl">Screenshots</span><b><?= (int) $stats['screenshots'] ?></b></div>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Profile</h3>
    <dl class="kv">
      <dt>Email</dt><dd><?= e($agent_user['email']) ?></dd>
      <dt>Phone</dt><dd><?= e($agent_user['phone'] ?: '—') ?></dd>
      <dt>Job title</dt><dd><?= e($agent_user['job_title'] ?: '—') ?></dd>
      <?php if ($can_manage): ?>
        <dt>Teams</dt><dd><?= $teams ? e(implode(', ', $teams)) : '—' ?></dd>
        <dt>Assigned clients</dt><dd><?= $assigned_clients ? e(implode(', ', $assigned_clients)) : '—' ?></dd>
      <?php endif; ?>
      <dt>Total tracked</dt><dd><?= e(fmt_hms($stats['total_active_s'])) ?> (all time)</dd>
    </dl>
  </div>
  <div class="panel">
    <h3>Rates <?php if (!$can_rates): ?><span class="muted small">— hidden for your role</span><?php endif; ?></h3>
    <?php if ($can_rates): ?>
      <dl class="kv">
        <dt>Pay rate <span class="muted small">(internal cost)</span></dt>
        <dd><?= e(money((float) $agent_user['pay_rate'], $cur)) ?> / <?= e($agent_user['pay_type'] ?: 'hourly') ?></dd>
        <dt>Bill rate <span class="muted small">(client charge)</span></dt>
        <dd><?= e(money((float) $agent_user['bill_rate'], $cur)) ?> / <?= e($agent_user['bill_type'] ?: 'hourly') ?></dd>
        <dt>Currency</dt><dd><?= e($cur) ?></dd>
      </dl>
    <?php else: ?>
      <p class="muted">Pay and bill rates are visible only to roles with rate access.</p>
    <?php endif; ?>
    <h3 style="margin-top:1rem">Devices</h3>
    <?php if ($devices): ?>
      <ul class="plain">
        <?php foreach ($devices as $d): ?>
          <li><?= e($d['name']) ?>
            <span class="muted small"><?php if ($d['last_seen']): ?>· last seen
              <time class="dp-time" data-utc="<?= e($d['last_seen']) ?>" data-fmt="full"></time>
              <?php else: ?>· never connected<?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="muted">No devices registered.</p><?php endif; ?>
  </div>
</div>

<div class="panel">
  <h3>Activity timeline</h3>
  <canvas class="dp-chart" height="220" data-type="timeline"
    data-points='<?= e(json_encode($timeline['points'])) ?>'
    data-markers='<?= e(json_encode($timeline['markers'])) ?>'
    data-start='<?= (int) $timeline['start'] ?>'
    data-end='<?= (int) $timeline['end'] ?>'></canvas>
  <p class="muted">Activity % over time; <b>●</b> markers are screenshots — hover to preview.</p>
</div>

<?php $activeColor = '#3b82f6'; $idleColor = '#fbbf24'; ?>
<div class="two-col">
  <div class="panel">
    <h3>Active vs inactive — daily hours</h3>
    <?php if (array_sum($daily['active']) || array_sum($daily['inactive'])): ?>
      <canvas class="dp-chart" height="240" data-type="bars"
        data-labels='<?= e(json_encode($daily['labels'])) ?>'
        data-series='<?= e(json_encode([
            ['name' => 'Active', 'data' => $daily['active'], 'color' => '#3b82f6'],
            ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => '#475569'],
        ])) ?>'></canvas>
    <?php else: ?><p class="muted">No tracked time in this period.</p><?php endif; ?>
  </div>
  <div class="panel">
    <h3>Performance</h3>
    <?php if ($summary['active_s'] || $summary['inactive_s']): ?>
      <canvas class="dp-chart" height="160" data-type="donut" data-unit="h"
        data-values='<?= e(json_encode([round($summary['active_s'] / 3600, 2), round($summary['inactive_s'] / 3600, 2)])) ?>'
        data-labels='<?= e(json_encode(['Active', 'Inactive'])) ?>'
        data-colors='<?= e(json_encode([$activeColor, $idleColor])) ?>'></canvas>
      <p class="muted small">Overall activity was <b><?= (int) $summary['activity_pct'] ?>%</b> this period.</p>
    <?php else: ?>
      <p class="muted">No tracked time in this period yet.</p>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h3>Top applications</h3>
  <?php if ($apps['labels']): ?>
    <canvas class="dp-chart" height="240" data-type="hbars" data-fmt="hm"
      data-labels='<?= e(json_encode($apps['labels'])) ?>'
      data-values='<?= e(json_encode(array_map(fn($s) => round($s / 60, 1), $apps['seconds']))) ?>'></canvas>
  <?php else: ?><p class="muted">No application data in this period.</p><?php endif; ?>
</div>

<div class="panel">
  <h3>Tasks — status &amp; time spent</h3>
  <table class="data">
    <thead><tr><th>Task</th><th>Client</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
    <tbody>
      <?php if (!$tasks): ?><tr><td colspan="5" class="muted">No tasks.</td></tr><?php endif; ?>
      <?php foreach ($tasks as $t): $tt = $task_time[$t['id']] ?? ['secs' => 0, 'cnt' => 0]; ?>
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
</div>

<?php if ($can_shots): ?>
<div class="panel">
  <h3>Recent screenshots</h3>
  <?php if ($shots): ?>
    <div class="shot-grid">
      <?php foreach ($shots as $sc): ?>
        <a href="<?= e(url('/uploads/' . $sc['file_path'])) ?>" target="_blank">
          <img src="<?= e(url('/uploads/' . $sc['file_path'])) ?>" alt="screenshot" loading="lazy">
          <span><time class="dp-time" data-utc="<?= e($sc['ts']) ?>" data-fmt="time"></time></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="muted">No screenshots in this period.</p><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Activity times — recent sessions</h3>
  <table class="data">
    <thead><tr><th>Started</th><th>Ended</th><th>Client</th><th>Active</th><th>Inactive</th><th>Source</th></tr></thead>
    <tbody>
      <?php if (!$recent_sessions): ?><tr><td colspan="6" class="muted">No sessions in this period.</td></tr><?php endif; ?>
      <?php foreach ($recent_sessions as $s): ?>
        <tr>
          <td><time class="dp-time" data-utc="<?= e($s['started_at']) ?>" data-fmt="full"></time></td>
          <td><?php if ($s['ended_at']): ?><time class="dp-time" data-utc="<?= e($s['ended_at']) ?>" data-fmt="time"></time>
              <?php else: ?><span class="pill on">live</span><?php endif; ?></td>
          <td><?= $s['client_name'] ? e($s['client_name']) : '<span class="muted">—</span>' ?></td>
          <td><?= e(fmt_hms((int) $s['active_s'])) ?></td>
          <td><?= e(fmt_hms((int) $s['inactive_s'])) ?></td>
          <td><span class="tag"><?= e($s['source']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
