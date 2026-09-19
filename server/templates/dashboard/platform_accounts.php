<?php include __DIR__ . '/_platform_tabs.php'; ?>

<div class="panel">
  <h3>All accounts</h3>
  <p class="muted">User management &amp; role overrides across every organization.</p>
  <table class="data">
    <thead><tr><th>Name</th><th>Email</th><th>Organization</th><th>Role</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $usr): ?>
      <tr>
        <td><?= e($usr['name']) ?></td>
        <td><?= e($usr['email']) ?></td>
        <td><?= e($usr['org_name']) ?></td>
        <td>
          <form method="post" action="<?= e(url('/app/platform')) ?>" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="return" value="accounts">
            <input type="hidden" name="user_id" value="<?= (int) $usr['id'] ?>">
            <select name="role" onchange="this.form.submit()">
              <?php foreach (['member', 'manager', 'hr_manager', 'it_admin', 'client_admin', 'client_viewer', 'super_admin'] as $r): ?>
                <option value="<?= $r ?>" <?= $usr['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="set_role">
          </form>
        </td>
        <td>
          <?php if ((int) $usr['id'] !== (int) $user['id']): ?>
          <form method="post" action="<?= e(url('/app/platform')) ?>" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?>
            <input type="hidden" name="return" value="accounts">
            <input type="hidden" name="user_id" value="<?= (int) $usr['id'] ?>">
            <button class="lnk danger" name="action" value="delete_user">delete</button></form>
          <?php else: ?><span class="muted">you</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
