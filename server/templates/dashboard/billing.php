<?php $periods = period_options(['day', 'week', 'pay', 'month']); ?>
<?php if (empty($is_super)): ?>
  <div class="tutorial"><b>Read-only.</b> Customer billing is set by your DeskPulse platform
     administrator (super&nbsp;admin). You can view and export it here, but the billing
     arrangement is managed centrally and can&rsquo;t be changed from this account.</div>
<?php endif; ?>
<?= period_switch_html($periods, $period, $period_date, '/app/billing', [],
  '<a class="ghost right" href="' . e(url('/app/billing.csv?' . period_qs($period, $period_date))) . '">Export CSV</a>') ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">Total billable</span><b><?= e(money($total)) ?></b></div>
  <div class="stat"><span class="lbl">Agents billed</span><b><?= count($by_agent) ?></b></div>
  <div class="stat"><span class="lbl">Customers</span><b><?= count($by_client) ?></b></div>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Billing per agent</h3>
    <p class="muted">Hourly agents: active hours × rate. Monthly agents: flat service charge (prorated).</p>
    <table class="data">
      <thead><tr><th>Agent</th><th>Type</th><th>Active</th><th>Rate</th><th>Amount</th></tr></thead>
      <tbody>
        <?php if (!$by_agent): ?><tr><td colspan="5" class="muted">Nothing billable in this period.</td></tr><?php endif; ?>
        <?php foreach ($by_agent as $a): ?>
          <tr>
            <td><?= e($a['name']) ?></td>
            <td><span class="tag"><?= e($a['bill_type']) ?></span></td>
            <td><?= e(fmt_hms($a['secs'])) ?></td>
            <td><?= e(money($a['rate'], $a['currency'])) ?><small class="muted">/<?= $a['bill_type'] === 'monthly' ? 'mo' : 'hr' ?></small></td>
            <td><b><?= e(money($a['amount'], $a['currency'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h3>Billing per customer</h3>
    <table class="data">
      <thead><tr><th>Customer</th><th>Active</th><th>Amount</th></tr></thead>
      <tbody>
        <?php if (!$by_client): ?><tr><td colspan="3" class="muted">No customer billing yet.</td></tr><?php endif; ?>
        <?php foreach ($by_client as $c): ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td><?= e(fmt_hms($c['secs'])) ?></td>
            <td><b><?= e(money($c['amount'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($contract_billing)): ?>
<div class="panel">
  <h3>Billing by contract</h3>
  <table class="data">
    <thead><tr><th>Contract</th><th>Term</th><th>Status</th><th>Active</th><th>Amount</th></tr></thead>
    <tbody>
      <?php foreach ($contract_billing as $ct): ?>
        <tr>
          <td><?= e($ct['title']) ?></td>
          <td class="muted small"><?= e($ct['start_date'] ?: '—') ?> – <?= e($ct['end_date'] ?: '—') ?></td>
          <td><span class="status <?= $ct['status'] === 'ended' ? 'rejected' : 'approved' ?>"><?= e($ct['status']) ?></span></td>
          <td><?= e(fmt_hms($ct['secs'])) ?></td>
          <td><b><?= e(money($ct['amount'], $ct['currency'])) ?></b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
