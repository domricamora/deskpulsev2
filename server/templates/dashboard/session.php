<?php
$actLabels = array_map(fn($a) => gmdate('H:i', strtotime($a['ts'] . ' UTC')), $samples);
$actData = array_map(fn($a) => (int) $a['activity_pct'], $samples);
// For each activity point, the nearest screenshot URL (so clicking opens it).
$shotTimes = array_map(fn($sc) => strtotime($sc['ts'] . ' UTC'), $shots);
$actHrefs = [];
foreach ($samples as $a) {
    $t = strtotime($a['ts'] . ' UTC');
    $bestUrl = '';
    $bestDiff = PHP_INT_MAX;
    foreach ($shots as $i => $sc) {
        $d = abs($shotTimes[$i] - $t);
        if ($d < $bestDiff) {
            $bestDiff = $d;
            $bestUrl = url('/uploads/' . $sc['file_path']);
        }
    }
    $actHrefs[] = $bestUrl;
}
?>
<p><a class="lnk" href="<?= e(url('/app/timesheets')) ?>">← Timesheets</a></p>

<div class="stat-row">
  <div class="stat"><span class="lbl">Worker</span><b><?= e($owner['name'] ?? '') ?></b></div>
  <div class="stat"><span class="lbl">Client</span><b><?= e(client_name($sess['client_id'] ?? null)) ?></b></div>
  <div class="stat"><span class="lbl">Task</span><b><?= e(task_name($sess['task_id'] ?? null)) ?></b></div>
  <div class="stat"><span class="lbl">Started</span><b><?= tlocal($sess['started_at'], 'full') ?></b></div>
  <div class="stat"><span class="lbl">Active</span><b><?= e(fmt_hms($sess['active_s'])) ?></b></div>
  <div class="stat"><span class="lbl">Inactive</span><b><?= e(fmt_hms($sess['inactive_s'])) ?></b></div>
  <div class="stat"><span class="lbl">Activity</span><b><?= session_activity_pct($sess) ?>%</b></div>
</div>

<div class="panel">
  <h3>Activity over time</h3>
  <?php if ($samples): ?>
    <canvas class="dp-chart" height="200" data-type="bars"
      data-labels='<?= e(json_encode($actLabels)) ?>'
      data-values='<?= e(json_encode($actData)) ?>' data-unit="%"
      data-hrefs='<?= e(json_encode($actHrefs)) ?>' data-href-target="blank"></canvas>
    <p class="muted">Tip: click a bar on the graph to open the nearest screenshot.</p>
  <?php else: ?><p class="muted">No activity samples recorded.</p><?php endif; ?>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Windows — summary</h3>
    <table class="data"><thead><tr><th>App</th><th>Window</th><th>Focus</th></tr></thead><tbody>
      <?php foreach ($windows_agg as $w): ?>
        <tr><td><?= e($w['app_name']) ?></td><td class="trunc"><?= e($w['window_title']) ?></td>
          <td><?= e(fmt_hms($w['secs'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$windows_agg): ?><tr><td colspan="3" class="muted">No window data.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="panel">
    <h3>Running programs</h3>
    <ul class="chips">
      <?php foreach ($procs as $pr): ?><li><?= e($pr['app_name']) ?></li><?php endforeach; ?>
      <?php if (!$procs): ?><li class="muted">None recorded.</li><?php endif; ?>
    </ul>
    <h3>Idle periods (≥ threshold)</h3>
    <ul class="plain">
      <?php foreach ($idles as $i): ?>
        <li><?= tlocal($i['start_ts'], 'time') ?>–<?= tlocal($i['end_ts'], 'time') ?>
          (<?= e(fmt_hms($i['duration_s'])) ?>)</li>
      <?php endforeach; ?>
      <?php if (!$idles): ?><li class="muted">No idle periods.</li><?php endif; ?>
    </ul>
  </div>
</div>

<div class="panel">
  <h3>Windows — detail timeline</h3>
  <table class="data"><thead><tr><th>Time</th><th>App</th><th>Window title</th></tr></thead><tbody>
    <?php foreach ($window_timeline as $w): ?>
      <tr><td><?= tlocal($w['ts'], 'sec') ?></td><td><?= e($w['app_name']) ?></td>
        <td class="trunc"><?= e($w['window_title']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$window_timeline): ?><tr><td colspan="3" class="muted">No timeline.</td></tr><?php endif; ?>
  </tbody></table>
</div>

<div class="panel">
  <h3>Screenshots</h3>
  <?php if ($shots): ?>
    <div class="shot-grid">
      <?php foreach ($shots as $sc): ?>
        <a href="<?= e(url('/uploads/' . $sc['file_path'])) ?>" target="_blank">
          <img src="<?= e(url('/uploads/' . $sc['file_path'])) ?>" loading="lazy" alt="screenshot">
          <span><?= tlocal($sc['ts'], 'time') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="muted">No screenshots for this session.</p><?php endif; ?>
</div>
