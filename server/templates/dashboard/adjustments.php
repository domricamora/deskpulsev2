<?php
/** Manual pay adjustments for a pay period.
 *  Expects: $rows, $members, $period, $period_date, $ctx, $currency */
$periods = period_options(['day', 'week', 'pay', 'month']);
$kinds = adjustment_kinds();
$earnTotal = $dedTotal = 0.0;
foreach ($rows as $r) {
    ((int) $r['sign'] < 0) ? $dedTotal += (float) $r['amount'] : $earnTotal += (float) $r['amount'];
}
?>
<?= period_switch_html($periods, $period, $period_date, '/app/adjustments') ?>

<div class="stat-row">
  <div class="stat"><div class="lbl">Extra earnings</div><div><?= e(money($earnTotal, $currency)) ?></div>
    <div class="sub">bonuses, commissions, reimbursements</div></div>
  <div class="stat"><div class="lbl">Deductions</div><div><?= e(money($dedTotal, $currency)) ?></div></div>
  <div class="stat"><div class="lbl">Net effect on payroll</div>
    <div><?= e(money($earnTotal - $dedTotal, $currency)) ?></div>
    <div class="sub"><?= e($ctx['label']) ?></div></div>
  <div class="stat"><div class="lbl">Entries</div><div><?= count($rows) ?></div></div>
</div>

<div class="panel">
  <h3>Add an adjustment</h3>
  <p class="muted">Anything that isn't hours &times; rate: a bonus, sales commission, expense
     reimbursement, allowance — or a deduction. The <b>effective date</b> decides which pay period
     it lands in, and it flows straight through to the payslip and the salary run.</p>
  <form method="post" action="<?= e(url('/app/adjustments')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="inline">
      <label>Employee <select name="user_id" required>
        <option value="">Choose…</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Type <select name="kind">
        <?php foreach ($kinds as $k => [$lbl, $sign]): ?>
          <option value="<?= e($k) ?>"><?= e($lbl) ?><?= $sign < 0 ? ' (subtracts)' : '' ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Amount <input type="number" name="amount" step="0.01" min="0" required></label>
      <label>Currency <input type="text" name="currency" maxlength="8" value="<?= e($currency) ?>"></label>
    </div>
    <div class="inline">
      <label class="grow">Description
        <input type="text" name="label" maxlength="160" placeholder="e.g. Q3 performance bonus"></label>
      <label>Effective date
        <input type="date" name="effective_date" value="<?= e($ctx['end_date']) ?>" required></label>
    </div>
    <label class="check"><input type="checkbox" name="taxable" checked> Taxable</label>
    <label>Note <textarea name="note" rows="2" placeholder="Optional — why this was awarded"></textarea></label>
    <button class="btn" type="submit">Add adjustment</button>
  </form>
</div>

<div class="panel">
  <h3>Adjustments in <?= e($ctx['label']) ?></h3>
  <?php if (!$rows): ?>
    <div class="empty-state">No adjustments recorded for this period.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>Date</th><th>Employee</th><th>Type</th><th>Description</th>
      <th>Amount</th><th>Taxable</th><th>Added by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $neg = (int) $r['sign'] < 0; ?>
      <tr>
        <td data-sort="<?= e($r['effective_date']) ?>"><?= e(date('j M Y', strtotime($r['effective_date']))) ?></td>
        <td><?= e($r['user_name']) ?></td>
        <td><span class="tag"><?= e(adjustment_label($r['kind'])) ?></span></td>
        <td><?= e($r['label']) ?>
          <?php if (!empty($r['note'])): ?><br><small class="muted"><?= e($r['note']) ?></small><?php endif; ?></td>
        <td data-sort="<?= e(($neg ? '-' : '') . $r['amount']) ?>"
            style="color:<?= $neg ? 'var(--bad)' : 'var(--good)' ?>">
          <?= $neg ? '-' : '+' ?><?= e(money($r['amount'], $r['currency'])) ?></td>
        <td><?= $r['taxable'] ? 'Yes' : 'No' ?></td>
        <td class="small muted"><?= e($r['created_by'] ?? '—') ?></td>
        <td>
          <form method="post" action="<?= e(url('/app/adjustments')) ?>" class="inline-form"
                onsubmit="return confirm('Remove this adjustment?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="adjustment_id" value="<?= (int) $r['id'] ?>">
            <button class="lnk danger" type="submit">remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
