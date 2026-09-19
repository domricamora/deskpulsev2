<div class="panel">
  <h3>Audit log</h3>
  <p class="muted">Recent activity across the organization — sessions, time-entry reviews,
     device registrations and share links.</p>
  <table class="data">
    <thead><tr><th>When</th><th>Actor</th><th>Event</th><th>Detail</th></tr></thead>
    <tbody>
      <?php if (!$events): ?><tr><td colspan="4" class="muted">No activity yet.</td></tr><?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <tr>
          <td><?= tlocal($ev['ts'], 'full') ?></td>
          <td><?= e($ev['who']) ?></td>
          <td><span class="tag"><?= e($ev['event']) ?></span></td>
          <td class="trunc"><?= e($ev['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
