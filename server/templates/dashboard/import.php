<?php
/** @var array|null $preview */
$p = $preview ?? null;
$s = $p['summary'] ?? null;
?>

<div class="panel">
  <h3>Import payroll from Excel <span class="tag">admin · HR</span></h3>
  <p class="muted">Upload a payroll spreadsheet (<code>.xlsx</code>) to load a whole team
     into <b>this organization</b> at once. It creates a <b>client</b> for each distinct
     Client, an <b>employee</b> for each VT&nbsp;ID (with the sheet's hourly pay rate), and
     one approved <b>time entry</b> per employee, per day, per client. Re-importing the same
     file updates the same records instead of duplicating them — so it's safe to run again
     after corrections.</p>
  <form method="post" action="<?= e(url('/app/import')) ?>" class="row-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <label class="grow">Payroll file <input type="file" name="file" accept=".xlsx" required></label>
    <button class="btn" type="submit">Preview import</button>
  </form>
</div>

<?php
// Shared column-naming guide, generated from import_specs() in helpers.php so the
// documented headers and the ones actually matched can never drift apart.
$spec = import_spec('payroll');
$spec_key = 'payroll';
$guide_open = empty($p);
include __DIR__ . '/_import_guide.php';
?>

<?php if ($p): ?>
<div class="panel">
  <h3>Preview <span class="muted" style="font-weight:400">— nothing has been saved yet</span></h3>
  <p class="muted">Read from sheet <b><?= e($p['sheet']) ?></b> · <?= (int) $p['total'] ?> valid data
     row(s)<?php if (($p['skipped']['count'] ?? 0) > 0): ?>, <?= (int) $p['skipped']['count'] ?> skipped<?php endif; ?>.</p>

  <div class="stat-row">
    <div class="stat"><span class="lbl">Clients</span><b><?= (int) $s['clients_new'] ?> new</b>
      <span class="sub"><?= (int) $s['clients_existing'] ?> already exist</span></div>
    <div class="stat"><span class="lbl">Employees</span><b><?= (int) $s['emps_new'] ?> new</b>
      <span class="sub"><?= (int) $s['emps_updated'] ?> updated</span></div>
    <div class="stat"><span class="lbl">Time entries</span><b><?= (int) $s['sessions_new'] ?> added</b>
      <span class="sub"><?= (int) $s['sessions_updated'] ?> updated</span></div>
  </div>

  <?php if (!empty($s['rate_changes'])): ?>
    <p class="muted"><b>Pay-rate changes</b> (the sheet is authoritative):
      <?php foreach (array_slice($s['rate_changes'], 0, 12) as $rc): ?>
        <br><?= e($rc['name']) ?>: <?= e(money($rc['from'], 'USD')) ?> → <?= e(money($rc['to'], 'USD')) ?>
      <?php endforeach; ?>
      <?php if (count($s['rate_changes']) > 12): ?><br>… and <?= count($s['rate_changes']) - 12 ?> more<?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($p['skipped']['reasons'])): ?>
    <p class="muted"><b>Skipped rows:</b>
      <?php foreach ($p['skipped']['reasons'] as $why => $n): ?>
        <span class="tag"><?= (int) $n ?> · <?= e($why) ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($s['errors'])): ?>
    <p class="danger"><b>Row errors:</b></p>
    <ul class="plain"><?php foreach (array_slice($s['errors'], 0, 10) as $er): ?><li class="danger"><?= e($er) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <h4>First rows</h4>
  <table class="data">
    <thead><tr><th>VT ID</th><th>Employee</th><th>Client</th><th>Date</th><th>Hours</th><th>Rate</th></tr></thead>
    <tbody>
      <?php foreach ($p['sample'] as $r): ?>
        <tr>
          <td><code><?= e($r['vt_id']) ?></code></td>
          <td><?= e($r['name']) ?></td>
          <td><?= $r['client_name'] !== '' ? e($r['client_name']) : '<span class="muted">—</span>' ?></td>
          <td><?= e($r['date']) ?></td>
          <td><?= e(fmt_hms($r['active_s'])) ?></td>
          <td><?= e(money($r['pay_rate'], 'USD')) ?><small class="muted">/hr</small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="<?= e(url('/app/import')) ?>" style="margin-top:1rem"
        onsubmit="return confirm('Import this data into your organization now?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">
    <input type="hidden" name="token" value="<?= e($p['token']) ?>">
    <button class="btn" type="submit">Confirm import</button>
    <a class="lnk" href="<?= e(url('/app/import')) ?>" style="margin-left:.75rem">Cancel</a>
  </form>
</div>
<?php endif; ?>
