<?php if ($can_manage): ?>
<div class="panel">
  <h3>Add a task</h3>
  <p class="muted">You manage your own tasks here or in the desktop app, and pick the one
     you're working on. Time tracked while a task is selected rolls up below.</p>
  <form method="post" action="<?= e(url('/app/tasks')) ?>" class="row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label class="grow">Task <input type="text" name="title" placeholder="e.g. Build login page" required></label>
    <label>Client / company
      <select name="client_id">
        <option value="">— none —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Add task</button>
  </form>
</div>
<?php else: ?>
<p class="muted">Tasks are created and managed by each agent. This is a read-only view with
   <b>time spent per task</b>.</p>
<?php endif; ?>

<div class="panel">
  <h3>Tasks &amp; time spent</h3>

  <?php if ($can_manage): ?>
    <?php /* Agent (owner) view: each task is editable inline. */ ?>
    <div class="task-list">
      <?php if (!$tasks): ?><p class="muted small">No tasks yet — add one above.</p><?php endif; ?>
      <?php foreach ($tasks as $t): $tt = $time_by_task[$t['id']] ?? ['secs' => 0, 'cnt' => 0]; ?>
        <div class="task-row">
          <form method="post" action="<?= e(url('/app/tasks')) ?>" class="task-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
            <input type="text" name="title" value="<?= e($t['title']) ?>" aria-label="Task title">
            <select name="client_id" aria-label="Client">
              <option value="">— no client —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $t['client_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="status" aria-label="Status">
              <option value="open" <?= $t['status'] !== 'done' ? 'selected' : '' ?>>open</option>
              <option value="done" <?= $t['status'] === 'done' ? 'selected' : '' ?>>done</option>
            </select>
            <span class="muted small nowrap"><?= e(fmt_hms($tt['secs'])) ?> · <?= (int) $tt['cnt'] ?> sess</span>
            <button class="btn sm" type="submit">Save</button>
          </form>
          <form method="post" action="<?= e(url('/app/tasks')) ?>" class="task-del"
                onsubmit="return confirm('Remove this task?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
            <button class="lnk danger" type="submit">delete</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php /* Manager / admin / client-viewer: read-only stats. */ ?>
    <table class="data">
      <thead><tr><th>Task</th><th>Owner</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
      <tbody>
        <?php if (!$tasks): ?>
          <tr><td colspan="5" class="muted">No tasks yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($tasks as $t): $tt = $time_by_task[$t['id']] ?? ['secs' => 0, 'cnt' => 0]; ?>
          <tr>
            <td><?= e($t['title']) ?>
              <?php if ($t['client_id']): ?><br><small class="muted"><?= e(client_name($t['client_id'])) ?></small><?php endif; ?>
            </td>
            <td><?= e($t['owner_name']) ?></td>
            <td><span class="status <?= $t['status'] === 'done' ? 'approved' : 'pending' ?>"><?= e($t['status']) ?></span></td>
            <td><?= (int) $tt['cnt'] ?></td>
            <td><b><?= e(fmt_hms($tt['secs'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
