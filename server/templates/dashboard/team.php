<?php
$periods = period_options(['week', 'pay', 'month']);
$activeColor = '#3b82f6'; $idleColor = '#fbbf24';   // blue active · amber inactive (matches overview.php)
// Standard work-schedule fields (HR/admin) — reused in the edit forms below.
$scheduleFields = function (array $m) {
    $wd = explode(',', $m['work_days'] ?? '1,2,3,4,5');
    ob_start(); ?>
    <div class="inline">
      <label>Work start <input type="time" name="work_start" value="<?= e(substr($m['work_start'] ?? '', 0, 5)) ?>"></label>
      <label>Work end <input type="time" name="work_end" value="<?= e(substr($m['work_end'] ?? '', 0, 5)) ?>"></label>
    </div>
    <div class="sched-days"><span class="muted small">Work days</span>
      <?php foreach (['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'] as $n => $lbl): ?>
        <label class="check"><input type="checkbox" name="work_days[]" value="<?= $n ?>" <?= in_array((string) $n, $wd, true) ? 'checked' : '' ?>> <?= $lbl ?></label>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
};
// Employment type + contractual hour caps. Caps are advisory: hours over a cap are
// still tracked and still show on the timesheet, but the member is flagged on the
// Payroll page so someone reviews them before the run. 0 = no cap.
$employmentFields = function (array $m) {
    ob_start(); ?>
    <div class="inline">
      <label>Employment type
        <select name="employment_type">
          <?php foreach (employment_types() as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= (($m['employment_type'] ?? 'full_time') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Hired on <input type="date" name="hired_on" value="<?= e((string) ($m['hired_on'] ?? '')) ?>"></label>
    </div>
    <div class="inline">
      <label>Daily cap <input type="number" step="0.25" min="0" name="daily_hours_cap"
             value="<?= e(rtrim(rtrim(number_format((float) ($m['daily_hours_cap'] ?? 0), 2, '.', ''), '0'), '.')) ?>">
        <small class="muted">hours · 0 = none</small></label>
      <label>Weekly cap <input type="number" step="0.25" min="0" name="weekly_hours_cap"
             value="<?= e(rtrim(rtrim(number_format((float) ($m['weekly_hours_cap'] ?? 0), 2, '.', ''), '0'), '.')) ?>">
        <small class="muted">hours · 0 = none</small></label>
      <label>Pay-period cap <input type="number" step="0.25" min="0" name="period_hours_cap"
             value="<?= e(rtrim(rtrim(number_format((float) ($m['period_hours_cap'] ?? 0), 2, '.', ''), '0'), '.')) ?>">
        <small class="muted">hours · 0 = none</small></label>
    </div>
    <?php return ob_get_clean();
};
?>
<?= period_switch_html($periods, $period, $period_date, '/app/team') ?>

<?php if (array_sum($daily['active']) || array_sum($daily['inactive'])): ?>
<div class="panel">
  <h3>Team hours by day</h3>
  <canvas class="dp-chart" height="240" data-type="bars"
    data-labels='<?= e(json_encode($daily['labels'])) ?>'
    data-series='<?= e(json_encode([
        ['name' => 'Active', 'data' => $daily['active'], 'color' => '#3b82f6'],
        ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => '#475569'],
    ])) ?>'></canvas>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Members &amp; public links</h3>
  <p class="muted">Each member has an automatic public page (day summary, no salary info).</p>
  <table class="data">
    <thead><tr><th>Name</th><th>Role</th>
      <?php if ($can_rates || $can_set_pay): ?><th>Pay (cost)</th><?php endif; ?>
      <?php if ($can_rates): ?><th>Bill (client)</th><?php endif; ?>
      <th>Active</th>
      <th>Activity</th>
      <?php if ($can_rates): ?><th>Labor cost</th><?php endif; ?>
      <th>Public page</th><?php if ($can_manage || $can_profiles): ?><th>Manage</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($users_by_id as $id => $m): $pu = $per_user[$id] ?? ['active_s'=>0,'inactive_s'=>0];
        $cost = $pu['active_s'] / 3600.0 * user_hourly_rate($m);
        $puTotal = $pu['active_s'] + $pu['inactive_s']; ?>
      <tr>
        <td><?= e($m['name']) ?><br><small class="muted"><?= e($m['email']) ?>
          <?php if (!empty($m['job_title'])): ?>· <?= e($m['job_title']) ?><?php endif; ?></small></td>
        <td><span class="tag"><?= e(role_label($m['role'])) ?></span></td>
        <?php if ($can_rates || $can_set_pay): ?>
          <td><?= e(money($m['pay_rate'], $m['currency'])) ?><small class="muted">/<?= e($m['pay_type'] === 'monthly' ? 'mo' : 'hr') ?></small></td>
        <?php endif; ?>
        <?php if ($can_rates): ?>
          <td><?= e(money($m['bill_rate'] ?? 0, $m['currency'])) ?><small class="muted">/<?= e(($m['bill_type'] ?? 'hourly') === 'monthly' ? 'mo' : 'hr') ?></small></td>
        <?php endif; ?>
        <td><?= e(fmt_hms($pu['active_s'])) ?></td>
        <td>
          <?php if ($puTotal > 0): ?>
            <canvas class="dp-chart" data-type="donut" data-mini height="46"
              data-values='<?= e(json_encode([round($pu['active_s'] / 3600, 2), round($pu['inactive_s'] / 3600, 2)])) ?>'
              data-labels='<?= e(json_encode(['Active', 'Inactive'])) ?>'
              data-colors='<?= e(json_encode([$activeColor, $idleColor])) ?>'
              title="<?= e(round(100 * $pu['active_s'] / max(1, $puTotal))) ?>% active"></canvas>
          <?php else: ?><span class="muted small">—</span><?php endif; ?>
        </td>
        <?php if ($can_rates): ?><td><?= e(money($cost, $m['currency'])) ?></td><?php endif; ?>
        <td>
          <?php if (!empty($personal_links[$id])): $purl = $public_base . '/share/' . $personal_links[$id]; ?>
            <a class="lnk" href="<?= e($purl) ?>" target="_blank">open</a>
            <button type="button" class="lnk copy-link" data-link="<?= e($purl) ?>" title="Copy link">copy</button>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php if ($can_manage || $can_profiles): ?>
        <td>
          <details>
            <summary class="lnk">edit</summary>
            <?php if ($can_manage): ?>
            <form method="post" action="<?= e(url('/app/team')) ?>" class="stack" style="margin-top:.5rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_user">
              <input type="hidden" name="user_id" value="<?= (int) $id ?>">
              <div class="inline">
                <label>Pay type <select name="pay_type">
                  <option value="hourly" <?= $m['pay_type']==='hourly'?'selected':'' ?>>hourly</option>
                  <option value="monthly" <?= $m['pay_type']==='monthly'?'selected':'' ?>>monthly</option></select></label>
                <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="<?= e($m['pay_rate']) ?>"></label>
              </div>
              <div class="inline">
                <label>Bill type <select name="bill_type">
                  <option value="hourly" <?= ($m['bill_type']??'hourly')==='hourly'?'selected':'' ?>>hourly</option>
                  <option value="monthly" <?= ($m['bill_type']??'hourly')==='monthly'?'selected':'' ?>>monthly service</option></select></label>
                <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="<?= e($m['bill_rate'] ?? 0) ?>"></label>
              </div>
              <div class="inline">
                <label>Currency <input type="text" name="currency" value="<?= e($m['currency']) ?>" maxlength="8"></label>
                <label>Role <select name="role">
                  <?php foreach ($assignable as $r): ?>
                    <option value="<?= $r ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                  <?php endforeach; ?>
                  <?php if ($m['role'] === 'super_admin'): ?><option value="super_admin" selected>Super admin</option><?php endif; ?>
                </select></label>
              </div>
              <h5 class="sched-h">Employment</h5>
              <?= $employmentFields($m) ?>
              <h5 class="sched-h">Work schedule</h5>
              <?= $scheduleFields($m) ?>
              <button class="btn" type="submit">Save</button>
            </form>
            <?php else: /* HR: profile (+ pay rate if allowed) */ ?>
            <form method="post" action="<?= e(url('/app/team')) ?>" class="stack" style="margin-top:.5rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="user_id" value="<?= (int) $id ?>">
              <label>Name <input type="text" name="name" value="<?= e($m['name']) ?>"></label>
              <label>Email <input type="email" name="email" value="<?= e($m['email']) ?>"></label>
              <div class="inline">
                <label>Phone <input type="text" name="phone" value="<?= e($m['phone'] ?? '') ?>"></label>
                <label>Job title <input type="text" name="job_title" value="<?= e($m['job_title'] ?? '') ?>"></label>
              </div>
              <?php if ($can_set_pay): ?>
              <div class="inline">
                <label>Pay type <select name="pay_type">
                  <option value="hourly" <?= $m['pay_type']==='hourly'?'selected':'' ?>>hourly</option>
                  <option value="monthly" <?= $m['pay_type']==='monthly'?'selected':'' ?>>monthly</option></select></label>
                <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="<?= e($m['pay_rate']) ?>"></label>
                <label>Currency <input type="text" name="currency" value="<?= e($m['currency']) ?>" maxlength="8"></label>
              </div>
              <?php endif; ?>
              <h5 class="sched-h">Employment</h5>
              <?= $employmentFields($m) ?>
              <h5 class="sched-h">Work schedule</h5>
              <?= $scheduleFields($m) ?>
              <button class="btn" type="submit">Save profile</button>
            </form>
            <?php endif; ?>
          </details>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($can_manage): ?>
<div class="two-col">
  <div class="panel">
    <h3>Add a team member</h3>
    <form method="post" action="<?= e(url('/app/team')) ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_user">
      <label>Name <input type="text" name="name" required></label>
      <label>Email <input type="email" name="email" required></label>
      <label>Temp password <input type="text" name="password" placeholder="leave blank to auto-generate"></label>
      <div class="inline">
        <label>Role <select name="role">
          <?php foreach ($creatable as $r): ?><option value="<?= $r ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?>
        </select></label>
        <label>Pay type <select name="pay_type"><option value="hourly">hourly</option><option value="monthly">monthly</option></select></label>
      </div>
      <div class="inline">
        <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="0"></label>
        <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="0"></label>
        <label>Currency <input type="text" name="currency" value="USD" maxlength="8"></label>
      </div>
      <button class="btn" type="submit">Create member</button>
    </form>
  </div>
  <div class="panel">
    <h3>Teams &amp; membership</h3>
    <p class="muted">Assign managers and client viewers to teams — they can only see members
       of teams they belong to.</p>
    <?php foreach ($teams as $t): ?>
      <div class="team-block">
        <b><?= e($t['name']) ?></b>
        <ul class="chips">
          <?php foreach (($team_members[$t['id']] ?? []) as $tm): ?>
            <li><?= e($tm['name']) ?>
              <form method="post" action="<?= e(url('/app/team')) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="remove_team_member">
                <input type="hidden" name="tm_id" value="<?= (int) $tm['tm_id'] ?>">
                <button class="lnk danger" title="Remove">✕</button>
              </form>
            </li>
          <?php endforeach; ?>
          <?php if (empty($team_members[$t['id']])): ?><li class="muted">No members</li><?php endif; ?>
        </ul>
        <form method="post" action="<?= e(url('/app/team')) ?>" class="row-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_team_member">
          <input type="hidden" name="team_id" value="<?= (int) $t['id'] ?>">
          <label>Add <select name="user_id">
            <?php foreach ($users_by_id as $uid => $m): ?>
              <option value="<?= (int) $uid ?>"><?= e($m['name']) ?> (<?= e(role_label($m['role'])) ?>)</option>
            <?php endforeach; ?>
          </select></label>
          <button class="btn sm" type="submit">Add</button>
        </form>
      </div>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('/app/team')) ?>" class="row-form" style="margin-top:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_team">
      <label class="grow">New team <input type="text" name="team_name" required></label>
      <button class="btn" type="submit">Add team</button>
    </form>
  </div>
</div>
<?php endif; ?>
