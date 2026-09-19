<div class="panel">
  <h3>Registered devices</h3>
  <p class="muted">Every desktop-agent install across the organization. Revoking a device
     stops it from syncing until the user signs in again.</p>
  <table class="data">
    <thead><tr><th>Device</th><th>User</th><th>Registered</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
      <?php if (!$devices): ?><tr><td colspan="5" class="muted">No devices registered yet.</td></tr><?php endif; ?>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td><?= e($d['name']) ?></td>
          <td><?= e($d['user_name']) ?><br><small class="muted"><?= e($d['email']) ?></small></td>
          <td><?= tlocal($d['created_at'], 'datetime') ?></td>
          <td><?= $d['last_seen'] ? tlocal($d['last_seen'], 'datetime') : '<span class="muted">never</span>' ?></td>
          <td class="row-actions">
            <?php if (!empty($can_remote)): ?>
              <a class="lnk" href="<?= e(url('/app/remote/' . (int) $d['id'])) ?>">remote control</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/app/devices')) ?>" onsubmit="return confirm('Revoke this device?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
              <button class="lnk danger" type="submit">revoke</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
