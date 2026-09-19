<?php /** Org-wide contract list + member-roster assignment (company admin / HR admin).
 *  Contract terms/rate/status/dates are still edited on the Clients page — this
 *  page is specifically for assigning/removing who works under each contract. */ ?>
<div class="panel">
  <h3>Contracts</h3>
  <p class="muted">Contract terms, billing rate and status are managed from the
    <a class="lnk" href="<?= e(url('/app/clients')) ?>">Clients</a> page. Here you can see every
    contract at a glance and control its member roster.</p>
</div>

<?php if (!$contracts): ?>
  <div class="panel"><div class="empty-state">No contracts yet — add one from the Clients page.</div></div>
<?php endif; ?>

<?php foreach ($contracts as $ct):
    $assigned = $members_by_contract[$ct['id']] ?? [];
    $assignedIds = array_map('intval', array_column($assigned, 'user_id'));
    $available = array_filter($users_by_id, fn($m) => !in_array((int) $m['id'], $assignedIds, true)); ?>
<details class="org-panel">
  <summary>
    <span class="op-name"><b><?= e($ct['title']) ?></b>
      <span class="tag"><?= e($ct['client_name']) ?></span>
      <span class="status <?= $ct['status'] === 'ended' ? 'rejected' : 'approved' ?>"><?= e($ct['status']) ?></span></span>
    <span class="muted small"><?= e($ct['start_date'] ?: '—') ?> – <?= e($ct['end_date'] ?: '—') ?>
      · <?= count($assigned) ?> member<?= count($assigned) === 1 ? '' : 's' ?></span>
  </summary>
  <div class="op-body">
    <h4>Assigned members</h4>
    <ul class="chips">
      <?php foreach ($assigned as $m): ?>
        <li><?= e($m['name']) ?>
          <form method="post" action="<?= e(url('/app/contracts')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unassign_member">
            <input type="hidden" name="contract_id" value="<?= (int) $ct['id'] ?>">
            <input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
            <button class="lnk danger" title="Remove">✕</button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if (!$assigned): ?><li class="muted">No members assigned yet</li><?php endif; ?>
    </ul>

    <?php if ($available): ?>
    <form method="post" action="<?= e(url('/app/contracts')) ?>" class="row-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_member">
      <input type="hidden" name="contract_id" value="<?= (int) $ct['id'] ?>">
      <label class="grow">Assign member <select name="user_id">
        <?php foreach ($available as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> (<?= e(role_label($m['role'])) ?>)</option>
        <?php endforeach; ?>
      </select></label>
      <button class="btn sm" type="submit">Assign</button>
    </form>
    <?php else: ?>
      <p class="muted small">Every org member is already assigned to this contract.</p>
    <?php endif; ?>

    <h4>Terms</h4>
    <p class="muted small">Bill rate <?= e(money($ct['bill_rate'], $ct['currency'])) ?>/hr.
      Edit the rate, dates and status from the <a class="lnk" href="<?= e(url('/app/clients')) ?>">Clients</a> page.</p>
  </div>
</details>
<?php endforeach; ?>
