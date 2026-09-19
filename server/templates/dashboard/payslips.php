<?php
/** Generate + email PDF payslips for a pay period.
 *  Expects: $run, $slips, $period, $period_date, $ctx, $mail_ready */
$periods = period_options(['day', 'week', 'pay', 'month']);
$rows = $run['rows'];
$emailed = count(array_filter($slips, fn ($s) => !empty($s['emailed_at'])));
$hidden = '<input type="hidden" name="period" value="' . e($period) . '">'
        . '<input type="hidden" name="date" value="' . e($period_date) . '">'
        . '<input type="hidden" name="from" value="' . e((string) ($ctx['from'] ?? '')) . '">'
        . '<input type="hidden" name="to" value="' . e((string) ($ctx['to'] ?? '')) . '">';
?>
<?= period_switch_html($periods, $period, $period_date, '/app/payslips') ?>

<div class="stat-row">
  <div class="stat"><div class="lbl">Employees in period</div><div><?= count($rows) ?></div>
    <div class="sub"><?= e($ctx['label']) ?></div></div>
  <div class="stat"><div class="lbl">Payslips generated</div><div><?= count($slips) ?></div></div>
  <div class="stat"><div class="lbl">Emailed</div><div><?= $emailed ?></div></div>
  <div class="stat"><div class="lbl">Total net</div>
    <div><?= e(money($run['totals']['net'] ?? 0, $run['currency'])) ?></div></div>
</div>

<?php if (!$mail_ready): ?>
  <div class="tutorial"><b>Email is not configured yet.</b> Payslips will still be generated and
     queued, but nothing leaves the server until <code>smtp_host</code> and
     <code>mail_enabled</code> are set in <code>server/config.php</code>. You can check the queue on
     the <a class="lnk" href="<?= e(url('/app/messages')) ?>">Messages</a> page.</div>
<?php endif; ?>

<div class="panel">
  <h3>Generate payslips for <?= e($ctx['label']) ?></h3>
  <p class="muted">Each payslip is a PDF showing worked hours, approved overtime, paid time off,
     bonuses and deductions, and the resulting net pay. Files are stored privately on the server —
     they are never served from a public URL.</p>
  <div class="actions-row">
    <form method="post" action="<?= e(url('/app/payslips')) ?>">
      <?= csrf_field() ?><?= $hidden ?>
      <input type="hidden" name="action" value="generate">
      <button class="btn ghost" type="submit">Generate all (no email)</button>
    </form>
    <form method="post" action="<?= e(url('/app/payslips')) ?>"
          onsubmit="return confirm('Generate and email payslips to every employee in this period?')">
      <?= csrf_field() ?><?= $hidden ?>
      <input type="hidden" name="action" value="generate_email">
      <button class="btn" type="submit">Generate &amp; email all</button>
      <label class="check" style="display:inline-flex;margin-left:.6rem">
        <input type="checkbox" name="resend"> re-send to people already emailed</label>
    </form>
  </div>
</div>

<div class="panel">
  <h3>Per employee</h3>
  <?php if (!$rows): ?>
    <div class="empty-state">Nobody has payable activity in this period.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>Employee</th><th>Hours</th><th>Gross</th><th>Deductions</th><th>Net</th>
      <th>Payslip</th><th>Emailed</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $s = $slips[(int) $r['user_id']] ?? null; ?>
      <tr>
        <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['user']['email'] ?? '') ?></small></td>
        <td data-sort="<?= e(number_format($r['hours'], 4, '.', '')) ?>"><?= e(number_format($r['hours'], 2)) ?> h</td>
        <td data-sort="<?= e(number_format($r['gross'], 2, '.', '')) ?>"><?= e(money($r['gross'], $r['currency'])) ?></td>
        <td data-sort="<?= e(number_format($r['deductions'], 2, '.', '')) ?>">
          <?= $r['deductions'] > 0 ? '-' . e(money($r['deductions'], $r['currency'])) : '—' ?></td>
        <td data-sort="<?= e(number_format($r['net'], 2, '.', '')) ?>"><b><?= e(money($r['net'], $r['currency'])) ?></b></td>
        <td data-sort="<?= $s ? '1' : '0' ?>">
          <?php if ($s): ?><span class="status approved">generated</span>
          <?php else: ?><span class="tag">not yet</span><?php endif; ?></td>
        <td data-sort="<?= !empty($s['emailed_at']) ? '1' : '0' ?>">
          <?= !empty($s['emailed_at']) ? tlocal($s['emailed_at'], 'date') : '—' ?></td>
        <td class="actions-row">
          <a class="lnk" target="_blank" href="<?= e(url('/app/payslip.pdf?user_id=' . (int) $r['user_id']
              . '&refresh=1&' . period_qs($period, $period_date))) ?>">view PDF</a>
          <form method="post" action="<?= e(url('/app/payslips')) ?>" class="inline-form">
            <?= csrf_field() ?><?= $hidden ?>
            <input type="hidden" name="action" value="generate_email">
            <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
            <input type="hidden" name="resend" value="1">
            <button class="lnk" type="submit">email</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
