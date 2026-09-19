<?php $periods = period_options(['day', 'week', 'pay', 'month']); ?>
<?= period_switch_html($periods, $period, $period_date, '/app/payroll', [],
      (!empty($can_adjust) ? '<a class="ghost right" href="' . e(url('/app/adjustments?' . period_qs($period, $period_date))) . '">Pay adjustments →</a>' : '')) ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">Base labor cost</span><b><?= e(money($total_cost, $currency)) ?></b>
    <span class="sub"><?= e($ctx['label'] ?? ($periods[$period] ?? '')) ?></span></div>
  <?php if (($total_extras ?? 0) > 0 || ($total_deductions ?? 0) > 0): ?>
  <div class="stat"><span class="lbl">Extras</span><b>+<?= e(money($total_extras ?? 0, $currency)) ?></b>
    <span class="sub">bonuses, commissions, paid leave</span></div>
  <div class="stat"><span class="lbl">Deductions</span><b>-<?= e(money($total_deductions ?? 0, $currency)) ?></b></div>
  <?php endif; ?>
  <div class="stat"><span class="lbl">Total net pay</span><b><?= e(money($total_net ?? $total_cost, $currency)) ?></b></div>
  <div class="stat"><span class="lbl">Members</span><b><?= count($rows) ?></b></div>
  <div class="stat"><span class="lbl">Credited hours</span><b><?= e(fmt_hms($total_active_s)) ?></b></div>
  <?php if (($total_overtime_pending_s ?? 0) > 0): ?>
  <div class="stat alert"><span class="lbl">Overtime awaiting HR</span><b><?= e(fmt_hms($total_overtime_pending_s)) ?></b>
    <span class="sub">not yet paid</span></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Salary &amp; pay breakdown</h3>
  <p class="muted">Per-member pay from tracked active time. Hourly: credited hours × rate.
     Monthly salaries are prorated across the pay period by calendar days, so the two halves of a
     semi-monthly month add back up to exactly one month's salary.
     Overtime is excluded until HR approves it on the <a href="<?= e(url('/app/overtime')) ?>">Overtime</a> page,
     and paid leave is only added for hourly staff (a salary already covers the day off).</p>
  <table class="data">
    <thead><tr><th>Member</th><th>Employment</th><th>Pay type</th><th>Pay rate</th>
      <th>Credited</th><th>Leave</th><th>OT awaiting HR</th><th>Base</th>
      <th>Extras</th><th>Deductions</th><th>Net pay</th><th>Flags</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="12" class="muted">No members to report.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?= e($r['name']) ?></b><br><small class="muted"><?= e(role_label($r['role'])) ?></small></td>
          <td><span class="tag"><?= e(employment_label($r['employment_type'] ?? 'full_time')) ?></span></td>
          <td><?= e($r['pay_type']) ?></td>
          <td data-sort="<?= e(number_format((float) $r['pay_rate'], 2, '.', '')) ?>">
            <?= e(money($r['pay_rate'], $r['currency'])) ?><small class="muted">/<?= $r['pay_type'] === 'monthly' ? 'mo' : 'hr' ?></small></td>
          <td data-sort="<?= (int) $r['active_s'] ?>"><?= e(fmt_hms($r['active_s'])) ?></td>
          <td data-sort="<?= e(number_format((float) ($r['leave_days'] ?? 0), 4, '.', '')) ?>">
            <?= ($r['leave_days'] ?? 0) > 0 ? e(rtrim(rtrim(number_format($r['leave_days'], 2), '0'), '.')) . ' d' : '—' ?></td>
          <td data-sort="<?= (int) ($r['overtime_pending_s'] ?? 0) ?>">
            <?= ($r['overtime_pending_s'] ?? 0) > 0 ? '<span class="muted">' . e(fmt_hms($r['overtime_pending_s'])) . '</span>' : '—' ?></td>
          <td data-sort="<?= e(number_format((float) $r['cost'], 2, '.', '')) ?>"><?= e(money($r['cost'], $r['currency'])) ?></td>
          <td data-sort="<?= e(number_format((float) ($r['extras'] ?? 0), 2, '.', '')) ?>">
            <?= ($r['extras'] ?? 0) > 0 ? '+' . e(money($r['extras'], $r['currency'])) : '—' ?></td>
          <td data-sort="<?= e(number_format((float) ($r['deductions'] ?? 0), 2, '.', '')) ?>"
              style="color:<?= ($r['deductions'] ?? 0) > 0 ? 'var(--bad)' : 'inherit' ?>">
            <?= ($r['deductions'] ?? 0) > 0 ? '-' . e(money($r['deductions'], $r['currency'])) : '—' ?></td>
          <td data-sort="<?= e(number_format((float) ($r['net'] ?? $r['cost']), 2, '.', '')) ?>">
            <b><?= e(money($r['net'] ?? $r['cost'], $r['currency'])) ?></b></td>
          <td class="small">
            <?php if (!empty($r['flags'])): foreach ($r['flags'] as $f): ?>
              <span class="status pending"><?= e($f) ?></span><br>
            <?php endforeach; else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot>
      <tr><td colspan="4"><b>Totals</b></td><td><b><?= e(fmt_hms($total_active_s)) ?></b></td>
        <td></td>
        <td><?= ($total_overtime_pending_s ?? 0) > 0 ? '<span class="muted">' . e(fmt_hms($total_overtime_pending_s)) . '</span>' : '' ?></td>
        <td><b><?= e(money($total_cost, $currency)) ?></b></td>
        <td><?= ($total_extras ?? 0) > 0 ? '+' . e(money($total_extras, $currency)) : '' ?></td>
        <td><?= ($total_deductions ?? 0) > 0 ? '-' . e(money($total_deductions, $currency)) : '' ?></td>
        <td><b><?= e(money($total_net ?? $total_cost, $currency)) ?></b></td>
        <td></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
