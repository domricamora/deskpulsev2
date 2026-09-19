<div class="two-col">
  <div class="panel">
    <h3>Profile information</h3>
    <form method="post" action="<?= e(url('/app/profile')) ?>" class="stack">
      <?= csrf_field() ?>
      <label>Full name <input type="text" name="name" value="<?= e($me['name']) ?>" required></label>
      <label>Email <input type="email" name="email" value="<?= e($me['email']) ?>" required></label>
      <div class="inline">
        <label>Phone <input type="text" name="phone" value="<?= e($me['phone'] ?? '') ?>"></label>
        <label>Job title <input type="text" name="job_title" value="<?= e($me['job_title'] ?? '') ?>"></label>
      </div>
      <label>New password <input type="password" name="password" minlength="8" placeholder="leave blank to keep current">
        <small class="muted">At least 8 characters.</small></label>
      <button class="btn" type="submit">Save profile</button>
    </form>
  </div>
  <div class="panel">
    <h3>Account</h3>
    <ul class="plain">
      <li>Role <span class="tag"><?= e(role_label($me['role'])) ?></span></li>
      <li>Organization <b><?= e($org['name'] ?? '') ?></b></li>
      <?php if ($me['role'] !== 'super_admin' && $me['role'] !== 'client_viewer'): ?>
        <li>Work schedule <b><?= e(work_schedule_label($me)) ?></b>
          <small class="muted">set by your admin/HR</small></li>
        <li>Pay rate <b><?= e(money($me['pay_rate'], $me['currency'])) ?></b>
          <small class="muted">/<?= e($me['pay_type'] === 'monthly' ? 'mo' : 'hr') ?> · set by your admin/HR</small></li>
        <?php if (!empty($can_rates)): ?>
          <li>Bill (client) <?= e(money($me['bill_rate'] ?? 0, $me['currency'])) ?>
            <small class="muted">/<?= e(($me['bill_type'] ?? 'hourly') === 'monthly' ? 'mo' : 'hr') ?></small></li>
        <?php endif; ?>
      <?php endif; ?>
    </ul>
  </div>
</div>
