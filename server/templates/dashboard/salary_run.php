<?php
/** Salary run: net pay per employee for a period, exportable as a Wise batch file.
 *  Expects: $run, $period, $period_date, $ctx */
$periods = period_options(['day', 'week', 'pay', 'month']);
$rows = $run['rows'];
$cur = $run['currency'];
$payable = array_values(array_filter($rows, fn ($r) => $r['payable'] && $r['net'] > 0));
$blocked = array_values(array_filter($rows, fn ($r) => !$r['payable'] && ($r['net'] > 0 || $r['hours'] > 0)));
$payTotal = array_sum(array_map(fn ($r) => $r['net'], $payable));
?>
<?= period_switch_html($periods, $period, $period_date, '/app/salary-run', [],
      '<a class="btn sm right" href="' . e(url('/app/salary-run.csv?' . period_qs($period, $period_date)))
      . '">Download Wise batch CSV</a>') ?>

<div class="stat-row">
  <div class="stat"><div class="lbl">Ready to pay</div><div><?= count($payable) ?></div>
    <div class="sub">of <?= count($rows) ?> people</div></div>
  <div class="stat"><div class="lbl">Batch total</div><div><?= e(money($payTotal, $cur)) ?></div>
    <div class="sub"><?= e($ctx['label']) ?></div></div>
  <div class="stat <?= $blocked ? 'alert' : '' ?>"><div class="lbl">Missing payout details</div>
    <div><?= count($blocked) ?></div>
    <div class="sub"><?= $blocked ? 'excluded from the export' : 'everyone is set up' ?></div></div>
  <div class="stat"><div class="lbl">Total hours</div>
    <div><?= e(number_format($run['totals']['hours'] ?? 0, 2)) ?> h</div></div>
</div>

<?php if ($blocked): ?>
<div class="panel">
  <div class="tutorial"><b>These people are not in the export.</b>
    They have pay due but no usable Wise payout details:
    <?= e(implode(', ', array_map(fn ($r) => $r['name'], array_slice($blocked, 0, 12)))) ?><?= count($blocked) > 12 ? ' …' : '' ?>.
    <br><a class="lnk" href="<?= e(url('/app/wise')) ?>">Add their payout details →</a></div>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Salary run — <?= e($ctx['label']) ?></h3>
  <p class="muted">Net pay is worked hours (or prorated salary) plus paid leave and extra earnings,
     minus deductions. The CSV matches the Wise batch-payment layout, with <b>Amount</b> filled in.</p>
  <?php if (!$rows): ?>
    <div class="empty-state">No payable people in this period.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>Employee</th><th>Type</th><th>Hours</th><th>Leave</th><th>Base</th>
      <th>Extras</th><th>Deductions</th><th>Net pay</th><th>Wise</th><th>Flags</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?><br><small class="muted"><?= e(role_label($r['role'])) ?></small></td>
        <td><span class="tag"><?= e(employment_label($r['employment_type'])) ?></span><br>
          <small class="muted"><?= e($r['pay_type']) ?></small></td>
        <td data-sort="<?= e(number_format($r['hours'], 4, '.', '')) ?>"><?= e(number_format($r['hours'], 2)) ?> h</td>
        <td data-sort="<?= e(number_format($r['leave_days'], 4, '.', '')) ?>">
          <?= $r['leave_days'] > 0 ? e(rtrim(rtrim(number_format($r['leave_days'], 2), '0'), '.')) . ' d' : '—' ?></td>
        <td data-sort="<?= e(number_format($r['base'], 2, '.', '')) ?>"><?= e(money($r['base'], $r['currency'])) ?></td>
        <td data-sort="<?= e(number_format($r['earnings'] + $r['leave_pay'], 2, '.', '')) ?>">
          <?= ($r['earnings'] + $r['leave_pay']) > 0 ? '+' . e(money($r['earnings'] + $r['leave_pay'], $r['currency'])) : '—' ?></td>
        <td data-sort="<?= e(number_format($r['deductions'], 2, '.', '')) ?>"
            style="color:<?= $r['deductions'] > 0 ? 'var(--bad)' : 'inherit' ?>">
          <?= $r['deductions'] > 0 ? '-' . e(money($r['deductions'], $r['currency'])) : '—' ?></td>
        <td data-sort="<?= e(number_format($r['net'], 2, '.', '')) ?>"><b><?= e(money($r['net'], $r['currency'])) ?></b></td>
        <td data-sort="<?= $r['payable'] ? '1' : '0' ?>">
          <span class="status <?= $r['payable'] ? 'approved' : 'rejected' ?>">
            <?= $r['payable'] ? 'ready' : 'missing' ?></span></td>
        <td class="small">
          <?php if ($r['flags']): ?>
            <?php foreach ($r['flags'] as $f): ?>
              <span class="status pending"><?= e($f) ?></span><br>
            <?php endforeach; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th>Batch total (ready only)</th><th></th>
      <th><?= e(number_format($run['totals']['hours'] ?? 0, 2)) ?> h</th>
      <th></th><th></th><th></th><th></th>
      <th><?= e(money($payTotal, $cur)) ?></th><th></th><th></th>
    </tr></tfoot>
  </table>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>What the export contains</h3>
  <p class="muted">One row per payable employee, in the same column order as your Wise payout sheet:</p>
  <table class="data" data-nofilter>
    <thead><tr><th>Column</th><th>Filled with</th></tr></thead>
    <tbody>
      <tr><td><code>Wise Recipient ID</code></td><td>The recipient UUID stored on the employee</td></tr>
      <tr><td><code>Wise Name</code></td><td>Account holder name</td></tr>
      <tr><td><code>EMAIL</code></td><td>Recipient email (blank for imported placeholder addresses)</td></tr>
      <tr><td><code>Wise account</code></td><td>Account description, defaulting to "Wise account"</td></tr>
      <tr><td><code>from Currency</code> / <code>to Currency</code></td><td>Source and target currency</td></tr>
      <tr><td><code>Source</code></td><td>Your Wise source-account label</td></tr>
      <tr><td><code>Amount</code></td><td><b>Net pay for <?= e($ctx['label']) ?></b></td></tr>
      <tr><td><code>Type</code></td><td>PERSON or BUSINESS</td></tr>
    </tbody>
  </table>
</div>
