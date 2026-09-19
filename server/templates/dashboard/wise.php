<?php
/** Wise payout details: import from Excel/CSV, or maintain by hand.
 *  Expects: $members, $accounts, $preview, $spec, $spec_key, $pay_currency */
$fmtCur = fn ($v) => e(strtoupper((string) ($v ?: $pay_currency)));
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= e(url('/app/salary-run')) ?>">Go to salary run →</a>
</div>

<div class="panel">
  <h3>Import Wise account details</h3>
  <p class="muted">Upload the payout sheet you already use with Wise (.xlsx or .csv). DeskPulse
     matches each row to an employee and stores the payout details — it never creates new people,
     so anything it can't match is reported back to you instead of guessing.</p>
  <form method="post" action="<?= e(url('/app/wise')) ?>" class="row-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <label class="grow">Wise account file
      <input type="file" name="file" accept=".xlsx,.csv,.tsv" required></label>
    <button class="btn" type="submit">Preview import</button>
  </form>
</div>

<?php $guide_open = empty($preview); include __DIR__ . '/_import_guide.php'; ?>

<?php if ($preview): ?>
  <?php $s = $preview['summary']; ?>
  <div class="panel">
    <h3>Preview — nothing has been saved yet</h3>
    <div class="stat-row">
      <div class="stat"><div class="lbl">Rows read</div><div><?= (int) $preview['total'] ?></div></div>
      <div class="stat"><div class="lbl">Matched</div><div><?= (int) $s['matched'] ?></div></div>
      <div class="stat"><div class="lbl">New payout records</div><div><?= (int) $s['created'] ?></div></div>
      <div class="stat"><div class="lbl">Updated</div><div><?= (int) $s['updated'] ?></div></div>
      <div class="stat <?= $s['unmatched'] ? 'alert' : '' ?>">
        <div class="lbl">Unmatched</div><div><?= (int) $s['unmatched'] ?></div></div>
    </div>
    <p class="muted small">
      Read from <?= $preview['sheet'] ? '<b>' . e($preview['sheet']) . '</b>, ' : '' ?>
      header found on row <?= (int) $preview['header'] ?>.
      <?php if ($preview['skipped']['count']): ?>
        <?= (int) $preview['skipped']['count'] ?> row(s) skipped:
        <?= e(implode('; ', array_map(fn ($k, $v) => "$k ($v)",
              array_keys($preview['skipped']['reasons']), $preview['skipped']['reasons']))) ?>.
      <?php endif; ?>
    </p>

    <?php if (!empty($s['unmatched_names'])): ?>
      <div class="tutorial"><b>Not matched to an employee:</b>
        <?= e(implode(', ', $s['unmatched_names'])) ?><?= $s['unmatched'] > count($s['unmatched_names']) ? ' …' : '' ?>.
        <br><small>Add these people on the Team page first, or put their <b>VT ID</b> in the sheet so
        they can be matched.</small></div>
    <?php endif; ?>

    <?php if (!empty($s['matches'])): ?>
      <h4>How rows were matched</h4>
      <table class="data">
        <thead><tr><th>Employee</th><th>Account holder in file</th><th>Matched on</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($s['matches'] as $mm): ?>
          <tr><td><?= e($mm['name']) ?></td><td><?= e($mm['holder']) ?></td>
              <td><span class="tag"><?= e($mm['how']) ?></span></td>
              <td><span class="status <?= $mm['new'] ? 'approved' : 'pending' ?>">
                  <?= $mm['new'] ? 'new' : 'update' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h4>First rows in the file</h4>
    <table class="data">
      <thead><tr><th>Row</th><th>Wise Recipient ID</th><th>Wise Name</th><th>Email</th>
        <th>Account</th><th>From</th><th>To</th><th>Type</th></tr></thead>
      <tbody>
      <?php foreach ($preview['sample'] as $r): ?>
        <tr><td><?= (int) $r['row'] ?></td>
            <td class="small trunc"><?= e($r['recipient_id']) ?: '—' ?></td>
            <td><?= e($r['account_holder']) ?></td>
            <td class="small"><?= e($r['email']) ?: '—' ?></td>
            <td class="small"><?= e($r['account_summary']) ?: '—' ?></td>
            <td><?= $fmtCur($r['source_currency']) ?></td>
            <td><?= $fmtCur($r['target_currency']) ?></td>
            <td><span class="tag"><?= e($r['recipient_type']) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form method="post" action="<?= e(url('/app/wise')) ?>" style="margin-top:1rem"
          onsubmit="return confirm('Save these Wise payout details?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="commit">
      <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
      <button class="btn" type="submit">Confirm and save <?= (int) $s['matched'] ?> record(s)</button>
      <a class="lnk" href="<?= e(url('/app/wise')) ?>" style="margin-left:.7rem">cancel</a>
    </form>
  </div>
<?php endif; ?>

<div class="panel">
  <h3>Payout details by employee</h3>
  <p class="muted">Everyone who needs paying should have an account holder name plus either a Wise
     recipient ID or an email address — that is what the salary-run export needs.</p>
  <table class="data">
    <thead><tr><th>Employee</th><th>Account holder</th><th>Wise recipient ID</th><th>Email</th>
      <th>Account</th><th>Currency</th><th>Type</th><th>Status</th><th>Edit</th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): $a = $accounts[(int) $m['id']] ?? null;
        $ready = $a && $a['active'] && ($a['recipient_id'] || $a['email']); ?>
      <tr>
        <td><?= e($m['name']) ?><br><small class="muted"><?= e(role_label($m['role'])) ?></small></td>
        <td><?= e($a['account_holder'] ?? '') ?: '—' ?></td>
        <td class="small trunc"><?= e($a['recipient_id'] ?? '') ?: '—' ?></td>
        <td class="small"><?= e($a['email'] ?? '') ?: '—' ?></td>
        <td class="small"><?= e($a['account_summary'] ?? '') ?: '—' ?></td>
        <td><?= $a ? $fmtCur($a['source_currency']) . ' → ' . $fmtCur($a['target_currency']) : '—' ?></td>
        <td><span class="tag"><?= e($a['recipient_type'] ?? 'PERSON') ?></span></td>
        <td data-sort="<?= $ready ? '1' : '0' ?>">
          <span class="status <?= $ready ? 'approved' : 'rejected' ?>">
            <?= $ready ? 'ready' : 'incomplete' ?></span></td>
        <td>
          <details>
            <summary class="lnk">edit</summary>
            <form method="post" action="<?= e(url('/app/wise')) ?>" class="stack" style="margin-top:.5rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
              <div class="inline">
                <label>Account holder (Wise Name)
                  <input type="text" name="account_holder" maxlength="160"
                         value="<?= e($a['account_holder'] ?? $m['name']) ?>"></label>
                <label>Wise recipient ID
                  <input type="text" name="recipient_id" maxlength="64"
                         value="<?= e($a['recipient_id'] ?? ($m['wise_id'] ?? '')) ?>"></label>
              </div>
              <div class="inline">
                <label>Email <input type="email" name="email" value="<?= e($a['email'] ?? '') ?>"></label>
                <label>Account description
                  <input type="text" name="account_summary" maxlength="160"
                         placeholder="Wise account" value="<?= e($a['account_summary'] ?? '') ?>"></label>
              </div>
              <div class="inline">
                <label>From currency <input type="text" name="source_currency" maxlength="8"
                       value="<?= e($a['source_currency'] ?? $pay_currency) ?>"></label>
                <label>To currency <input type="text" name="target_currency" maxlength="8"
                       value="<?= e($a['target_currency'] ?? $pay_currency) ?>"></label>
                <label>Recipient type <select name="recipient_type">
                  <option value="PERSON" <?= ($a['recipient_type'] ?? 'PERSON') === 'PERSON' ? 'selected' : '' ?>>PERSON</option>
                  <option value="BUSINESS" <?= ($a['recipient_type'] ?? '') === 'BUSINESS' ? 'selected' : '' ?>>BUSINESS</option>
                </select></label>
              </div>
              <div class="inline">
                <label>Source label <input type="text" name="source_label" maxlength="40"
                       value="<?= e($a['source_label'] ?? 'source') ?>"></label>
                <label>Payment reference <input type="text" name="reference" maxlength="80"
                       value="<?= e($a['reference'] ?? '') ?>"></label>
              </div>
              <label class="check"><input type="checkbox" name="active"
                     <?= (!$a || $a['active']) ? 'checked' : '' ?>> Include in salary runs</label>
              <label>Note <textarea name="note" rows="2"><?= e($a['note'] ?? '') ?></textarea></label>
              <button class="btn" type="submit">Save payout details</button>
            </form>
            <?php if ($a): ?>
              <form method="post" action="<?= e(url('/app/wise')) ?>" style="margin-top:.4rem"
                    onsubmit="return confirm('Remove the Wise payout details for <?= e($m['name']) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="wise_id" value="<?= (int) $a['id'] ?>">
                <button class="lnk danger" type="submit">remove payout details</button>
              </form>
            <?php endif; ?>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
