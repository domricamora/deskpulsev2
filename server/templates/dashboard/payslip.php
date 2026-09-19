<?php $periods = period_options(['day', 'week', 'pay', 'month']); ?>
<?= period_switch_html($periods, $period, $period_date, '/app/payslip', [],
      '<a class="btn sm right" target="_blank" href="'
      . e(url('/app/payslip.pdf?' . period_qs($period, $period_date))) . '">Download PDF payslip</a>') ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">Estimated pay</span><b><?= e(money($total_pay, $currency)) ?></b>
    <span class="sub"><?= e($periods[$period] ?? '') ?></span></div>
  <div class="stat"><span class="lbl">Credited hours</span><b><?= e(fmt_hms($total_active_s)) ?></b></div>
  <?php if (($overtime_pending_s ?? 0) > 0): ?>
  <div class="stat"><span class="lbl">Overtime awaiting HR</span><b><?= e(fmt_hms($overtime_pending_s)) ?></b>
    <span class="sub">not yet paid</span></div>
  <?php endif; ?>
  <div class="stat"><span class="lbl">Pay rate</span><b><?= e(money($pay_rate, $currency)) ?></b>
    <span class="sub">/<?= e($pay_type === 'monthly' ? 'mo' : 'hr') ?><?= $pay_type === 'monthly' ? ' · ≈' . e(money($hourly, $currency)) . '/hr' : '' ?></span></div>
</div>

<div class="panel">
  <h3>Payslip — <?= e($me['name']) ?></h3>
  <p class="muted">Your pay per day = active hours × rate.
    <?php if ($pay_type === 'monthly'): ?>Your monthly salary is normalized to an hourly
      equivalent (÷ 173.33 h/mo) for the daily figure.<?php endif; ?>
    Active hours are genuine tracked time; this is an estimate, not a final statement.
    <?php if (($overtime_pending_s ?? 0) > 0): ?><br><strong>Note:</strong> <?= e(fmt_hms($overtime_pending_s)) ?>
      of overtime is awaiting HR approval and is <em>not</em> included above; it will be credited once approved.<?php endif; ?>
    <?php if (($overtime_approved_s ?? 0) > 0): ?><br>Includes <?= e(fmt_hms($overtime_approved_s)) ?> of HR-approved overtime.<?php endif; ?></p>
  <table class="data">
    <thead><tr><th>Date</th><th>Hours worked</th><th>Rate</th><th>Pay</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="muted">No worked days in this period.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><time class="dp-time" data-utc="<?= e($r['date']) ?>T00:00:00Z" data-fmt="date"><?= e($r['date']) ?></time></td>
          <td><?= number_format($r['hours'], 2) ?> h</td>
          <td><?= e(money($hourly, $currency)) ?><small class="muted">/hr</small></td>
          <td><b><?= e(money($r['pay'], $currency)) ?></b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot>
      <tr><td><b>Total</b></td><td><b><?= e(fmt_hms($total_active_s)) ?></b></td><td></td>
        <td><b><?= e(money($total_pay, $currency)) ?></b></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
