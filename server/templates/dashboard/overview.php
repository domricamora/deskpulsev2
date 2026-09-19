<?php
$periods = period_options(['day', 'week', 'pay', 'month']);
$activeColor = '#3b82f6'; $idleColor = '#fbbf24';   // blue active · amber inactive
?>
<?= period_switch_html($periods, $period, $period_date, '/app/overview', [],
  '<a class="ghost right" href="' . e(url('/app/export.csv?' . period_qs($period, $period_date))) . '">Export CSV</a>') ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">Active time</span><b><?= e(fmt_hms($summary['active_s'])) ?></b>
    <span class="sub">avg <?= e(fmt_hms($stats['avg_daily_s'])) ?>/day</span></div>
  <div class="stat"><span class="lbl">Inactive time</span><b><?= e(fmt_hms($summary['inactive_s'])) ?></b></div>
  <div class="stat"><span class="lbl">Activity</span><b><?= (int) $summary['activity_pct'] ?>%</b></div>
  <div class="stat"><span class="lbl">Sessions</span><b><?= (int) $summary['count'] ?></b>
    <span class="sub"><?= (int) $stats['days_tracked'] ?> day<?= $stats['days_tracked'] == 1 ? '' : 's' ?> tracked</span></div>
  <div class="stat"><span class="lbl">Avg session</span><b><?= e(fmt_hms($stats['avg_session_s'])) ?></b>
    <span class="sub">longest <?= e(fmt_hms($stats['longest_session_s'])) ?></span></div>
  <div class="stat"><span class="lbl">Idle time</span><b><?= e(fmt_hms($stats['idle_total_s'])) ?></b>
    <span class="sub"><?= (int) $stats['idle_count'] ?> idle break<?= $stats['idle_count'] == 1 ? '' : 's' ?></span></div>
  <?php if ($can_rates): ?>
    <div class="stat"><span class="lbl">Labor cost</span><b><?= e(money($cost['amount'], $cost['currency'])) ?></b></div>
  <?php endif; ?>
  <div class="stat"><span class="lbl">Screenshots</span><b><?= (int) $stats['screenshots'] ?></b></div>
  <div class="stat"><span class="lbl">Open tasks</span><b><?= (int) $stats['open_tasks'] ?></b></div>
  <?php if ($stats['team_view']): ?>
    <div class="stat"><span class="lbl">Active people</span><b><?= (int) $stats['people_active'] ?></b>
      <span class="sub">of <?= (int) $stats['headcount'] ?> · <?= (int) $stats['tracking_now'] ?> live now</span></div>
  <?php endif; ?>
</div>

<?php if (!empty($agent)): ?>
<div class="panel agent-workspace">
  <div class="panel-head">
    <h3>Your information</h3>
    <a class="btn sm ghost" href="<?= e(url('/app/profile')) ?>">Edit profile</a>
  </div>
  <div class="ws-grid">
    <div class="ws-item"><span class="lbl">Name</span><b><?= e($agent['name']) ?></b></div>
    <div class="ws-item"><span class="lbl">Role</span><b><?= e(role_label($agent['role'])) ?></b></div>
    <div class="ws-item"><span class="lbl">Email</span><b><?= e($agent['email']) ?></b></div>
    <div class="ws-item"><span class="lbl">Phone</span><b><?= e($agent['phone'] ?: '—') ?></b></div>
    <div class="ws-item"><span class="lbl">Job title</span><b><?= e($agent['job_title'] ?: '—') ?></b></div>
    <div class="ws-item"><span class="lbl">Pay rate</span><b><?= e($agent['pay']) ?></b>
      <span class="muted small">set by your admin/HR</span></div>
    <div class="ws-item"><span class="lbl">Work schedule</span><b><?= e($agent['schedule']) ?></b>
      <span class="muted small">set by your admin/HR</span></div>
  </div>
  <?php if ($agent['live'] && $agent['in_schedule'] === false): ?>
    <p class="flash" style="margin:.8rem 0 0">⏰ <b>Heads up:</b> you're tracking
       <b>outside your scheduled hours</b> (<?= e($agent['schedule']) ?>). Monitoring continues and
       this time is still recorded.</p>
  <?php endif; ?>
</div>

<div class="panel agent-workspace">
  <div class="panel-head">
    <h3>Your workspace</h3>
    <span class="pill <?= $agent['live'] ? 'on' : 'off' ?>"><?= $agent['live'] ? '● Tracking now' : 'Not tracking' ?></span>
  </div>
  <div class="ws-grid">
    <div class="ws-item"><span class="lbl">Current task</span>
      <b><?= $agent['current_task'] ? e($agent['current_task']) : '—' ?></b>
      <a class="muted small" href="<?= e(url('/app/tasks')) ?>">manage tasks</a></div>
    <div class="ws-item"><span class="lbl">Tracking device</span>
      <?php if ($agent['device']): ?>
        <b><?= e($agent['device']['name']) ?></b>
        <span class="muted small"><?php if ($agent['device']['last_seen']): ?>last seen
          <time class="dp-time" data-utc="<?= e($agent['device']['last_seen']) ?>" data-fmt="full"></time>
          <?php else: ?>never connected<?php endif; ?></span>
      <?php else: ?>
        <b>No device</b><a class="muted small" href="<?= e(url('/app/download')) ?>">download the app</a>
      <?php endif; ?>
    </div>
    <div class="ws-item ws-links"><span class="lbl">Quick links</span>
      <div class="ws-linkrow">
        <a class="btn sm ghost" href="<?= e(url('/app/timesheets')) ?>">Timesheets</a>
        <a class="btn sm ghost" href="<?= e(url('/app/tasks')) ?>">Tasks</a>
        <a class="btn sm ghost" href="<?= e(url('/app/settings')) ?>">Settings</a>
        <a class="btn sm ghost" href="<?= e(url('/app/download')) ?>">Get the app</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($roster)): ?>
<div class="panel">
  <div class="panel-head">
    <h3>Agents under you</h3>
    <span class="muted small"><?= count($roster) ?> <?= count($roster) === 1 ? 'person' : 'people' ?> · <?= e($periods[$period] ?? '') ?> · click for details</span>
  </div>
  <div class="agent-rows">
    <?php foreach ($roster as $a): ?>
      <button type="button" class="agent-link" data-user-id="<?= (int) $a['id'] ?>">
        <?php if ($a['live']): ?><span class="dot live"></span><?php endif; ?>
        <?= e($a['name']) ?>
        <span class="muted">· <?= e(role_label($a['role'])) ?> · <?= e(fmt_hms($a['active_s'])) ?>
          · <?= (int) $a['activity_pct'] ?>%</span>
      </button>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Activity timeline</h3>
  <canvas class="dp-chart" height="240"
    data-type="timeline"
    data-points='<?= e(json_encode($timeline['points'])) ?>'
    data-markers='<?= e(json_encode($timeline['markers'])) ?>'
    data-start='<?= (int) $timeline['start'] ?>'
    data-end='<?= (int) $timeline['end'] ?>'></canvas>
  <p class="muted">Activity % over time. The <b>●</b> markers are screenshots — hover to
     see the running app/window and a preview, or click to open the full screenshot.</p>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Active vs inactive — daily hours</h3>
    <?php if (array_sum($daily['active']) || array_sum($daily['inactive'])): ?>
      <canvas class="dp-chart" height="240" data-type="bars"
        data-labels='<?= e(json_encode($daily['labels'])) ?>'
        data-series='<?= e(json_encode([
            ['name' => 'Active', 'data' => $daily['active'], 'color' => $activeColor],
            ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => $idleColor],
        ])) ?>'></canvas>
      <p class="muted small">Hours tracked each day, split into <b>active</b> (genuine
         mouse/keyboard input) and <b>inactive</b> time. Bars are labelled in hours.</p>
    <?php else: ?>
      <p class="muted">No tracked time in this period yet.</p>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h3>Activity breakdown</h3>
    <?php if ($summary['active_s'] || $summary['inactive_s']): ?>
      <canvas class="dp-chart" height="160" data-type="donut" data-unit="h"
        data-values='<?= e(json_encode([round($summary['active_s'] / 3600, 2), round($summary['inactive_s'] / 3600, 2)])) ?>'
        data-labels='<?= e(json_encode(['Active', 'Inactive'])) ?>'
        data-colors='<?= e(json_encode([$activeColor, $idleColor])) ?>'></canvas>
      <p class="muted small">Hours active vs inactive — overall activity was
         <b><?= (int) $summary['activity_pct'] ?>%</b>.</p>
    <?php else: ?>
      <p class="muted">No tracked time in this period yet.</p>
    <?php endif; ?>
    <ul class="plain mini-stats">
      <li>Active time <b><?= e(fmt_hms($summary['active_s'])) ?></b></li>
      <li>Inactive time <b><?= e(fmt_hms($summary['inactive_s'])) ?></b></li>
      <li>Idle breaks <b><?= (int) $stats['idle_count'] ?> · <?= e(fmt_hms($stats['idle_total_s'])) ?></b></li>
      <li>Avg session <b><?= e(fmt_hms($stats['avg_session_s'])) ?></b></li>
      <li>Longest session <b><?= e(fmt_hms($stats['longest_session_s'])) ?></b></li>
    </ul>
  </div>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Top applications</h3>
    <?php if ($apps['labels']): ?>
      <canvas class="dp-chart" height="240" data-type="bars"
        data-labels='<?= e(json_encode($apps['labels'])) ?>'
        data-series='<?= e(json_encode([
            ['name' => 'Minutes focused', 'data' => array_map(fn($s) => round($s / 60, 1), $apps['seconds']),
             'color' => '#3b82f6'],
        ])) ?>'></canvas>
      <p class="muted small">Most-used applications by focused time (minutes). Hover the
         timeline above for moment-by-moment detail.</p>
    <?php else: ?>
      <p class="muted">No application data yet. Track a session with the desktop agent.</p>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h3>Recent screenshots</h3>
    <?php if ($recent_shots): ?>
      <div class="shot-grid sm">
        <?php foreach ($recent_shots as $sc): ?>
          <a href="<?= e(url('/uploads/' . $sc['file_path'])) ?>" target="_blank">
            <img src="<?= e(url('/uploads/' . $sc['file_path'])) ?>" alt="screenshot" loading="lazy">
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">No screenshots yet.</p>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($roster)): ?>
<div id="user-modal" class="modal-backdrop" hidden data-base="<?= e(url('/app/agent/')) ?>">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="um-title">
    <div class="modal-head"><h3 id="um-title">Agent</h3>
      <button type="button" class="modal-close" id="um-close" aria-label="Close">&times;</button></div>
    <div class="modal-body" id="um-body"></div>
  </div>
</div>
<script src="<?= e(url('/assets/js/platform.js')) ?>"></script>
<?php endif; ?>
