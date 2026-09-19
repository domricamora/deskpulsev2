<div class="panel">
  <h3>Pending manual entries</h3>
  <p class="muted">Members' manual time entries and adjustments wait here for your review.</p>
  <?php if (!$pending): ?>
    <p class="muted">Nothing pending. 🎉</p>
  <?php endif; ?>
  <?php foreach ($pending as $s): $owner = $users_by_id[$s['user_id']] ?? null; ?>
    <div class="approval">
      <div class="ap-info">
        <b><?= e($owner['name'] ?? '') ?></b>
        <span class="muted"><?= tlocal($s['started_at'], 'full') ?> – <?= tlocal($s['ended_at'], 'time') ?></span>
        <div>Active time: <b><?= e(fmt_hms($s['active_s'])) ?></b>
          <?= $s['client_id'] ? '· ' . e(client_name($s['client_id'])) : '' ?></div>
        <?php if ($s['note']): ?><div class="note">“<?= e($s['note']) ?>”</div><?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('/app/approvals/' . $s['id'])) ?>" class="ap-actions">
        <?= csrf_field() ?>
        <input type="text" name="review_note" placeholder="Optional note">
        <button class="btn" name="decision" value="approve">Approve</button>
        <button class="btn danger" name="decision" value="reject">Reject</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
