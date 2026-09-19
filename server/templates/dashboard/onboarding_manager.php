<?php
$labels = ['roster' => 'Roster', 'tasks' => 'Tasks', 'done' => 'Done'];
$idx = array_search($step, $steps, true);
$go = fn($s) => e(url('/app/onboarding?step=' . $s));
?>
<div class="onb-intro">
  <h2>Build your team</h2>
  <p class="muted">As a team manager, add the agents you oversee to your roster and give them starter tasks.
     You can manage all of this later from your dashboard.</p>
</div>

<ol class="onb-steps">
  <?php foreach ($steps as $i => $s): ?>
    <li class="<?= $s === $step ? 'on' : ($i < $idx ? 'done' : '') ?>">
      <a href="<?= $go($s) ?>"><span class="n"><?= $i < $idx ? '✓' : $i + 1 ?></span><?= e($labels[$s]) ?></a>
    </li>
  <?php endforeach; ?>
</ol>

<?php if ($step !== 'done'): ?>
  <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-skip"><?= csrf_field() ?>
    <input type="hidden" name="step" value="done">
    <button class="btn ghost sm" name="action" value="finish">Skip for now →</button>
  </form>
<?php endif; ?>

<?php if ($step === 'roster'): ?>
  <div class="panel">
    <h3>Your roster</h3>
    <div class="tutorial">
      <b>What's a roster?</b> It's the agents you manage. Each agent you add here gets a DeskPulse
      account and joins your team, so their time, activity and screenshots roll up to you. Once
      they're tracking you can watch them <b>Live</b>, review <b>Screenshots</b>, and sign off
      their manual time on <b>Approvals</b>.
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
      <input type="hidden" name="step" value="roster">
      <input type="text" name="name" placeholder="Full name" required>
      <input type="email" name="email" placeholder="email@company.com" required>
      <input type="text" name="password" placeholder="Temp password (optional)">
      <button class="btn" name="action" value="add_agent">Add agent</button>
    </form>
    <table class="data" style="margin-top:.6rem">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
      <tbody>
        <?php if (!$roster): ?><tr><td colspan="3" class="muted">No agents yet — add your first above.</td></tr><?php endif; ?>
        <?php foreach ($roster as $m): ?>
          <tr><td><?= e($m['name']) ?></td><td><?= e($m['email']) ?></td><td><span class="tag"><?= e(role_label($m['role'])) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="onb-nav"><span></span><a class="btn" href="<?= $go('tasks') ?>">Continue →</a></div>
  </div>

<?php elseif ($step === 'tasks'): ?>
  <div class="panel">
    <h3>Tasks</h3>
    <div class="tutorial">
      Assign starter tasks to yourself or any agent in your roster. Agents pick their current task
      in the desktop app, and you'll see time reported per task.
    </div>
    <?php if ($roster): ?>
      <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
        <input type="hidden" name="step" value="tasks">
        <input type="text" name="title" placeholder="Task description" required>
        <select name="user_id">
          <option value="<?= (int) $user['id'] ?>">You (<?= e(role_label($user['role'])) ?>)</option>
          <?php foreach ($roster as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> (<?= e(role_label($m['role'])) ?>)</option><?php endforeach; ?>
        </select>
        <select name="client_id"><option value="">— No client —</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <button class="btn" name="action" value="add_task">Add task</button>
      </form>
    <?php else: ?>
      <p class="muted">Add agents to your roster first, then assign tasks to them.</p>
    <?php endif; ?>
    <ul class="onb-list">
      <?php foreach ($tasks as $t): ?>
        <li><b><?= e($t['title']) ?></b> <span class="muted">· <?= e($t['owner']) ?><?= $t['client'] ? ' · ' . e($t['client']) : '' ?></span></li>
      <?php endforeach; ?>
      <?php if (!$tasks): ?><li class="muted">No tasks yet (optional).</li><?php endif; ?>
    </ul>
    <div class="onb-nav">
      <a class="btn ghost" href="<?= $go('roster') ?>">← Back</a>
      <a class="btn" href="<?= $go('done') ?>">Continue →</a>
    </div>
  </div>

<?php else: /* done */ ?>
  <div class="panel onb-done">
    <h3>🎉 Roster ready</h3>
    <div class="stat-row">
      <div class="stat"><span class="lbl">Agents</span><b><?= count($roster) ?></b></div>
      <div class="stat"><span class="lbl">Tasks</span><b><?= count($tasks) ?></b></div>
    </div>
    <div class="tutorial">
      <b>What's next:</b>
      <ul>
        <li>Have your agents sign in and install the desktop agent to start tracking.</li>
        <li>Watch your team in real time on <b>Live</b> and browse <b>Screenshots</b>.</li>
        <li>Sign off submitted manual time on <b>Approvals</b>.</li>
      </ul>
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>"><?= csrf_field() ?>
      <input type="hidden" name="step" value="done">
      <button class="btn lg" name="action" value="finish">Go to dashboard →</button>
    </form>
  </div>
<?php endif; ?>
