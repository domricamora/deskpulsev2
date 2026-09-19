<?php
// Group contracts under their client for in-context editing.
$contractsByClient = [];
foreach ($contracts as $ct) {
    $contractsByClient[(int) $ct['client_id']][] = $ct;
}
$statusOpts = function (string $cur) {
    return '<option value="active"' . ($cur !== 'ended' ? ' selected' : '') . '>active</option>'
         . '<option value="ended"' . ($cur === 'ended' ? ' selected' : '') . '>ended</option>';
};
?>
<div class="panel">
  <h3>Add a client</h3>
  <p class="muted">Clients are the companies your remote workers are placed with.
    Add a contact email and we'll create a read-only portal login for them with a
    temporary password — they'll be asked to set their own on first sign-in.</p>
  <form method="post" action="<?= e(url('/app/clients')) ?>" class="row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_client">
    <label class="grow">Company name <input type="text" name="name" required></label>
    <label>Contact email <input type="email" name="contact_email"></label>
    <label class="grow">Notes <input type="text" name="notes"></label>
    <label>Bill rate
      <input type="number" name="bill_rate" step="0.01" min="0" value="0"></label>
    <label>Currency <input type="text" name="currency" value="USD" maxlength="8"></label>
    <button class="btn" type="submit">Add client</button>
  </form>
</div>

<?php if (!$clients): ?>
  <div class="panel"><div class="empty-state">No clients yet — add one above.</div></div>
<?php endif; ?>

<?php foreach ($clients as $c): $rt = $rollup[$c['id']] ?? ['secs' => 0, 'amount' => 0];
      $cContracts = $contractsByClient[(int) $c['id']] ?? []; ?>
<details class="org-panel">
  <summary>
    <span class="op-name"><b><?= e($c['name']) ?></b>
      <span class="status <?= $c['archived'] ? 'rejected' : 'approved' ?>"><?= $c['archived'] ? 'archived' : 'active' ?></span>
      <?php if (!empty($client_logins[(int) $c['id']])): ?><span class="status approved">portal login</span><?php endif; ?></span>
    <span class="muted small"><?= e(fmt_hms($rt['secs'])) ?> tracked · <?= e(money($rt['amount'])) ?> billable
      · <?= count($cContracts) ?> contract<?= count($cContracts) === 1 ? '' : 's' ?></span>
  </summary>
  <div class="op-body">
    <h4>Client details</h4>
    <form method="post" action="<?= e(url('/app/clients')) ?>" class="row-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_client">
      <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
      <label class="grow">Company name <input type="text" name="name" value="<?= e($c['name']) ?>" required></label>
      <label>Contact email <input type="email" name="contact_email" value="<?= e($c['contact_email']) ?>"></label>
      <label class="grow">Notes <input type="text" name="notes" value="<?= e($c['notes']) ?>"></label>
      <label>Bill rate
        <input type="number" name="bill_rate" step="0.01" min="0" value="<?= e($c['bill_rate'] ?? 0) ?>"></label>
      <label>Currency <input type="text" name="currency" value="<?= e($c['currency'] ?? 'USD') ?>" maxlength="8"></label>
      <button class="btn sm" type="submit">Save</button>
    </form>
    <div class="actions-row">
      <?php if ($c['archived']): ?>
        <form method="post" action="<?= e(url('/app/clients')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="action" value="unarchive_client">
          <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
          <button class="btn sm ghost" type="submit">Restore</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/app/clients')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="action" value="archive_client">
          <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
          <button class="btn sm ghost" type="submit">Archive</button></form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/app/clients')) ?>"
            onsubmit="return confirm('Delete this client and its contracts? Tracked time is kept but un-tagged.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_client">
        <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
        <button class="lnk danger" type="submit">delete client</button></form>
    </div>

    <h4>Portal login</h4>
    <?php $login = $client_logins[(int) $c['id']] ?? null; ?>
    <?php if ($login): ?>
      <p class="muted small">Read-only portal access for <b><?= e($login) ?></b>.
        They sign in at the normal login page and are prompted to set a password on first use.</p>
      <form method="post" action="<?= e(url('/app/clients')) ?>"
            onsubmit="return confirm('Reset this client\'s password? A new temporary password will be shown for you to share.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_client_password">
        <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
        <button class="btn sm ghost" type="submit">Reset password</button>
      </form>
    <?php else: ?>
      <p class="muted small">No portal login yet for this client.</p>
      <form method="post" action="<?= e(url('/app/clients')) ?>" class="row-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_client_login">
        <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
        <label class="grow">Login email
          <input type="email" name="contact_email" value="<?= e($c['contact_email']) ?>" required></label>
        <button class="btn sm" type="submit">Create login</button>
      </form>
    <?php endif; ?>

    <h4>Contracts</h4>
    <div class="task-list">
      <?php if (!$cContracts): ?><p class="muted small">No contracts for this client.</p><?php endif; ?>
      <?php foreach ($cContracts as $ct): ?>
        <div class="task-row">
          <form method="post" action="<?= e(url('/app/clients')) ?>" class="task-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_contract">
            <input type="hidden" name="contract_id" value="<?= (int) $ct['id'] ?>">
            <input type="text" name="title" value="<?= e($ct['title']) ?>" aria-label="Contract title">
            <select name="status" aria-label="Status"><?= $statusOpts($ct['status']) ?></select>
            <label class="dlabel">Start <input type="date" name="start_date" value="<?= e($ct['start_date']) ?>"></label>
            <label class="dlabel">End <input type="date" name="end_date" value="<?= e($ct['end_date']) ?>"></label>
            <label>Bill rate <?= e($ct['currency']) ?>
              <input type="number" name="bill_rate" step="0.01" min="0" value="<?= e($ct['bill_rate'] ?? 0) ?>" style="width:6.5em"></label>
            <button class="btn sm" type="submit">Save</button>
          </form>
          <form method="post" action="<?= e(url('/app/clients')) ?>" class="task-del"
                onsubmit="return confirm('Delete this contract?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_contract">
            <input type="hidden" name="contract_id" value="<?= (int) $ct['id'] ?>">
            <button class="lnk danger" type="submit">delete</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="<?= e(url('/app/clients')) ?>" class="row-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_contract">
      <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
      <label class="grow">New contract <input type="text" name="title" placeholder="e.g. Q3 Support Retainer" required></label>
      <label>Status <select name="status"><option value="active">active</option><option value="ended">ended</option></select></label>
      <label class="dlabel">Start <input type="date" name="start_date"></label>
      <label class="dlabel">End <input type="date" name="end_date"></label>
      <label>Bill rate
        <input type="number" name="bill_rate" step="0.01" min="0" placeholder="<?= e($c['bill_rate'] ?? 0) ?>" style="width:6.5em"></label>
      <button class="btn" type="submit">Add contract</button>
    </form>
  </div>
</details>
<?php endforeach; ?>
