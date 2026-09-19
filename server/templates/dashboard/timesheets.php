<?php $periods = period_options(['day', 'week', 'pay', 'month']); ?>
<?= period_switch_html($periods, $period, $period_date, '/app/timesheets', [],
  '<a class="ghost right" href="' . e(url('/app/export.csv?' . period_qs($period, $period_date))) . '">Export CSV</a>') ?>

<?php if (!empty($can_log)): ?>
<div class="panel">
  <h3>Add manual entries / adjustments</h3>
  <p class="muted">Forgot to track time? Enter start and end in <b>your local time</b>
     (stored in UTC). Use <b>Add more</b> to log several entries for a day at once.
     Entries you submit go to a manager for approval.</p>
  <form method="post" action="<?= e(url('/app/timesheets')) ?>" id="manual-entry">
    <?= csrf_field() ?>
    <div id="adj-rows">
      <div class="adj-row row-form">
        <input type="hidden" name="started_at_utc[]"><input type="hidden" name="ended_at_utc[]">
        <label>Start <input type="datetime-local" name="started_at[]"></label>
        <label>End <input type="datetime-local" name="ended_at[]"></label>
        <label>Client / company
          <select name="client_id[]">
            <option value="">— none —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="grow">Note <input type="text" name="note[]" placeholder="e.g. client call"></label>
        <button type="button" class="lnk danger remove-row" title="Remove this row">✕</button>
      </div>
    </div>
    <div class="row-form" style="margin-top:.6rem">
      <button type="button" class="btn ghost" id="add-row">+ Add more</button>
      <button class="btn" type="submit">Submit all</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Sessions</h3>
  <table class="data">
    <thead><tr><th>Date</th><th>Who</th><th>Client</th><th>Task</th><th>Active</th><th>Inactive</th>
      <th>Activity</th><th>Source</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$sessions): ?>
      <tr><td colspan="10" class="muted">No sessions in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($sessions as $s): $owner = $users_by_id[$s['user_id']] ?? null; ?>
      <tr>
        <td><?= tlocal($s['started_at'], 'full') ?></td>
        <td><?= e($owner['name'] ?? '') ?></td>
        <td><?= e($s['client_id'] ? client_name($s['client_id']) : '—') ?></td>
        <td><?= e(task_name($s['task_id'] ?? null)) ?></td>
        <td><?= e(fmt_hms($s['active_s'])) ?></td>
        <td><?= e(fmt_hms($s['inactive_s'])) ?></td>
        <td><?= session_activity_pct($s) ?>%</td>
        <td><span class="tag"><?= e($s['source']) ?></span></td>
        <td><span class="status <?= e($s['approval_status']) ?>"><?= e($s['approval_status']) ?></span></td>
        <td><a class="lnk" href="<?= e(url('/app/session/' . $s['id'])) ?>">details</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
