<div class="panel">
  <h3>Overtime awaiting approval</h3>
  <p class="muted">Activity worked beyond a member's schedule (outside their hours/days or past
    their daily hours) is held here. Approve it to credit those hours to payroll; reject it to
    leave them unpaid. The regular portion of each session is already credited.</p>
  <?php if (!$pending): ?>
    <p class="muted">No overtime pending review. 🎉</p>
  <?php endif; ?>
  <?php foreach ($pending as $s): $owner = $users_by_id[$s['user_id']] ?? null; ?>
    <div class="approval">
      <div class="ap-info">
        <b><?= e($owner['name'] ?? '') ?></b>
        <span class="muted"><?= tlocal($s['started_at'], 'full') ?> – <?= tlocal($s['ended_at'], 'time') ?></span>
        <div>Overtime: <b><?= e(fmt_hms($s['overtime_s'])) ?></b>
          <small class="muted">of <?= e(fmt_hms($s['active_s'])) ?> active</small>
          <?= $s['client_id'] ? '· ' . e(client_name($s['client_id'])) : '' ?>
          · <span class="tag"><?= e($s['source']) ?></span></div>
        <?php if ($s['note']): ?><div class="note">“<?= e($s['note']) ?>”</div><?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('/app/overtime/' . $s['id'])) ?>" class="ap-actions">
        <?= csrf_field() ?>
        <input type="text" name="review_note" placeholder="Optional note">
        <button class="btn" name="decision" value="approve">Approve</button>
        <button class="btn danger" name="decision" value="reject">Reject</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
