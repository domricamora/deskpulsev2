<?php
/** Paid time off: request, approve, balances and (for approvers) leave types.
 *  Expects: $types, $requests, $balances, $members, $can_approve, $me_id, $year */
$activeTypes = array_values(array_filter($types, fn ($t) => (int) $t['active'] === 1));
$pending = array_values(array_filter($requests, fn ($r) => $r['status'] === 'pending'));
?>
<?php if ($balances): ?>
<div class="stat-row">
  <?php foreach ($balances as $b): ?>
    <div class="stat <?= $b['left'] < 0 ? 'alert' : '' ?>">
      <div class="lbl"><?= e($b['type']['name']) ?><?= $b['type']['paid'] ? '' : ' (unpaid)' ?></div>
      <div><?= e(rtrim(rtrim(number_format($b['left'], 2), '0'), '.')) ?></div>
      <div class="sub">days left of <?= e(rtrim(rtrim(number_format($b['entitled'], 2), '0'), '.')) ?>
        · <?= e(rtrim(rtrim(number_format($b['used'], 2), '0'), '.')) ?> used in <?= (int) $year ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h3><?= $can_approve ? 'Record or request time off' : 'Request time off' ?></h3>
  <p class="muted">Days are counted against the member's own work schedule, so weekends and
     non-working days are never deducted.
     <?= $can_approve
        ? 'Anything you file here is approved immediately; requests from employees wait for your review.'
        : 'Your request goes to your manager or HR for approval.' ?></p>
  <form method="post" action="<?= e(url('/app/leave')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="request">
    <div class="inline">
      <?php if ($can_approve): ?>
        <label>Employee <select name="user_id">
          <?php foreach ($members as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $me_id ? 'selected' : '' ?>>
              <?= e($m['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <label>Leave type <select name="leave_type_id" required>
        <?php foreach ($activeTypes as $t): ?>
          <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?><?= $t['paid'] ? '' : ' — unpaid' ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>From <input type="date" name="start_date" required></label>
      <label>To <input type="date" name="end_date" required></label>
      <label>Hours per day <input type="number" name="hours_per_day" step="0.5" min="0.5" max="24" value="8"></label>
    </div>
    <label class="check"><input type="checkbox" name="half_day"> Half day (single date only)</label>
    <label>Reason <textarea name="reason" rows="2" placeholder="Optional"></textarea></label>
    <button class="btn" type="submit"><?= $can_approve ? 'Record time off' : 'Submit request' ?></button>
  </form>
</div>

<?php if ($can_approve && $pending): ?>
<div class="panel">
  <h3>Awaiting your review <span class="badge"><?= count($pending) ?></span></h3>
  <?php foreach ($pending as $r): ?>
    <div class="approval">
      <div>
        <b><?= e($r['user_name']) ?></b> — <?= e($r['type_name']) ?>
        <span class="tag"><?= $r['paid'] ? 'paid' : 'unpaid' ?></span><br>
        <?= e(range_label($r['start_date'], $r['end_date'])) ?>
        · <?= e(rtrim(rtrim(number_format((float) $r['total_days'], 2), '0'), '.')) ?> day(s)
        · <?= e(rtrim(rtrim(number_format((float) $r['total_hours'], 2), '0'), '.')) ?> h
        <?php if (!empty($r['reason'])): ?><br><small class="muted"><?= e($r['reason']) ?></small><?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('/app/leave')) ?>" class="actions-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="review">
        <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
        <input type="text" name="review_note" placeholder="Note (optional)" style="width:190px">
        <button class="btn sm" type="submit" name="decision" value="approve">Approve</button>
        <button class="btn sm danger" type="submit" name="decision" value="reject">Reject</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h3><?= $can_approve ? 'All leave' : 'My leave' ?></h3>
  <?php if (!$requests): ?>
    <div class="empty-state">No time off recorded yet.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><?php if ($can_approve): ?><th>Employee</th><?php endif; ?>
      <th>Type</th><th>From</th><th>To</th><th>Days</th><th>Hours</th>
      <th>Paid</th><th>Status</th><th>Reviewed by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <?php if ($can_approve): ?><td><?= e($r['user_name']) ?></td><?php endif; ?>
        <td><?= e($r['type_name']) ?></td>
        <td data-sort="<?= e($r['start_date']) ?>"><?= e(date('j M Y', strtotime($r['start_date']))) ?></td>
        <td data-sort="<?= e($r['end_date']) ?>"><?= e(date('j M Y', strtotime($r['end_date']))) ?></td>
        <td><?= e(rtrim(rtrim(number_format((float) $r['total_days'], 2), '0'), '.')) ?></td>
        <td><?= e(rtrim(rtrim(number_format((float) $r['total_hours'], 2), '0'), '.')) ?></td>
        <td><?= $r['paid'] ? 'Yes' : 'No' ?></td>
        <td><span class="status <?= e($r['status'] === 'cancelled' ? 'rejected' : $r['status']) ?>">
            <?= e($r['status']) ?></span></td>
        <td class="small muted"><?= e($r['reviewer'] ?? '—') ?>
          <?php if (!empty($r['review_note'])): ?><br><small><?= e($r['review_note']) ?></small><?php endif; ?></td>
        <td>
          <?php if ($r['status'] === 'pending' && ((int) $r['user_id'] === $me_id || $can_approve)): ?>
            <form method="post" action="<?= e(url('/app/leave')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
              <button class="lnk danger" type="submit">cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($can_approve): ?>
<div class="two-col">
  <div class="panel">
    <h3>Leave types</h3>
    <p class="muted">A <b>paid</b> type turns approved days into paid hours for hourly staff.
       Salaried staff are already paid for the day, so their leave is recorded but not paid twice.</p>
    <table class="data">
      <thead><tr><th>Name</th><th>Paid</th><th>Default days/yr</th><th>Active</th></tr></thead>
      <tbody>
      <?php foreach ($types as $t): ?>
        <tr>
          <td><?= e($t['name']) ?><br><small class="muted"><code><?= e($t['code']) ?></code></small></td>
          <td><?= $t['paid'] ? 'Yes' : 'No' ?></td>
          <td><?= e(rtrim(rtrim(number_format((float) $t['days_per_year'], 2), '0'), '.')) ?></td>
          <td>
            <details>
              <summary class="lnk"><?= $t['active'] ? 'active' : 'inactive' ?></summary>
              <form method="post" action="<?= e(url('/app/leave')) ?>" class="stack" style="margin-top:.4rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_type">
                <input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="code" value="<?= e($t['code']) ?>">
                <label>Name <input type="text" name="name" value="<?= e($t['name']) ?>"></label>
                <label>Default days per year
                  <input type="number" name="days_per_year" step="0.5" min="0" value="<?= e($t['days_per_year']) ?>"></label>
                <label class="check"><input type="checkbox" name="paid" <?= $t['paid'] ? 'checked' : '' ?>> Paid</label>
                <label class="check"><input type="checkbox" name="active" <?= $t['active'] ? 'checked' : '' ?>> Active</label>
                <button class="btn sm" type="submit">Save</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="<?= e(url('/app/leave')) ?>" class="row-form" style="margin-top:.8rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_type">
      <label class="grow">New type <input type="text" name="name" placeholder="e.g. Bereavement" required></label>
      <label>Code <input type="text" name="code" placeholder="bereavement" required></label>
      <label>Days/yr <input type="number" name="days_per_year" step="0.5" min="0" value="0"></label>
      <label class="check"><input type="checkbox" name="paid" checked> Paid</label>
      <button class="btn" type="submit">Add</button>
    </form>
  </div>

  <div class="panel">
    <h3>Set an entitlement</h3>
    <p class="muted">Overrides the type's default for one person and year — use it for part-timers
       or pro-rated first-year allowances.</p>
    <form method="post" action="<?= e(url('/app/leave')) ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_entitlement">
      <label>Employee <select name="user_id" required>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Leave type <select name="leave_type_id" required>
        <?php foreach ($activeTypes as $t): ?>
          <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
      <div class="inline">
        <label>Year <input type="number" name="year" min="2000" max="2100" value="<?= (int) $year ?>"></label>
        <label>Days <input type="number" name="days" step="0.5" min="0" value="0"></label>
        <label>Carried over <input type="number" name="carried_days" step="0.5" min="0" value="0"></label>
      </div>
      <button class="btn" type="submit">Save entitlement</button>
    </form>
  </div>
</div>
<?php endif; ?>
