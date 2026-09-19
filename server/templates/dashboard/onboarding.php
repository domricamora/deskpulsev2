<?php
$labels = ['org' => 'Organization', 'clients' => 'Clients', 'teams' => 'Teams',
           'agents' => 'Agents', 'tasks' => 'Tasks', 'done' => 'Done'];
$roles = ['member' => 'Employee', 'manager' => 'Team manager', 'hr_manager' => 'HR admin',
          'it_admin' => 'IT admin'];
$idx = array_search($step, $steps, true);
$next = $steps[$idx + 1] ?? 'done';
$prev = $idx > 0 ? $steps[$idx - 1] : null;
$go = fn($s) => e(url('/app/onboarding?step=' . $s));
// Tasks are for the people who do the work: agents + team managers.
$assignees = array_values(array_filter($members, fn($m) => in_array($m['role'], ['member', 'manager'], true)));
$nav = function () use ($go, $prev, $next) { ?>
  <div class="onb-nav">
    <?php if ($prev): ?><a class="btn ghost" href="<?= $go($prev) ?>">← Back</a><?php endif; ?>
    <a class="btn" href="<?= $go($next) ?>">Continue →</a>
  </div>
<?php };
?>
<div class="onb-intro">
  <h2>Set up DeskPulse</h2>
  <p class="muted">A quick guided setup — your organization, clients, teams, agents and starter tasks.
     You can change everything later.</p>
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
    <button class="btn ghost sm" name="action" value="finish">Skip setup for now →</button>
  </form>
<?php endif; ?>

<?php if ($step === 'org'): ?>
  <div class="panel">
    <h3>Your organization</h3>
    <div class="tutorial">
      <b>Set the monitoring policy.</b> These defaults apply to every desktop agent in your
      org — your IT admin can fine-tune them later in <b>Settings</b>, and you can upload a
      company logo there too. Nothing is tracked until a worker signs in and starts their timer.
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="stack"><?= csrf_field() ?>
      <input type="hidden" name="step" value="org">
      <label>Organization name
        <input type="text" name="name" value="<?= e($org['name']) ?>" maxlength="160" required></label>
      <h4 class="muted">Monitoring policy</h4>
      <label>Screenshot interval (minutes)
        <input type="number" name="screenshot_interval_min" min="1" max="120" style="width:90px"
               value="<?= (int) $policy['screenshot_interval_min'] ?>"></label>
      <label>Idle threshold (minutes)
        <input type="number" name="idle_threshold_min" min="1" max="120" style="width:90px"
               value="<?= (int) $policy['idle_threshold_min'] ?>"></label>
      <label class="check"><input type="checkbox" name="screenshot_blur" <?= $policy['screenshot_blur'] ? 'checked' : '' ?>> Blur screenshots (privacy)</label>
      <label class="check"><input type="checkbox" name="track_screenshots" <?= $policy['track_screenshots'] ? 'checked' : '' ?>> Capture screenshots</label>
      <label class="check"><input type="checkbox" name="track_windows" <?= $policy['track_windows'] ? 'checked' : '' ?>> Track active windows</label>
      <label class="check"><input type="checkbox" name="track_processes" <?= $policy['track_processes'] ? 'checked' : '' ?>> Track running apps</label>
      <div><button class="btn" name="action" value="save_org">Save &amp; continue →</button></div>
    </form>
    <?php $nav(); ?>
  </div>

<?php elseif ($step === 'clients'): ?>
  <div class="panel">
    <h3>Clients &amp; companies</h3>
    <div class="tutorial">
      <b>What are clients?</b> These are the companies your team does work for. Adding them lets you:
      <ul>
        <li>Tag tracked time to a client, so you can report <b>hours &amp; cost per client</b></li>
        <li>Bill each customer with their own rate (Billing page)</li>
        <li>Assign tasks to a client so agents know who the work is for</li>
      </ul>
      Solo or internal team? You can skip this — work is then tracked without a client.
    </div>
    <p class="muted small">Add a contact email and we'll create a <b>read-only client portal
       login</b> with a temporary password (they set their own on first sign-in).</p>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
      <input type="hidden" name="step" value="clients">
      <input type="text" name="name" placeholder="Client / company name" required>
      <input type="email" name="contact_email" placeholder="Contact email (for portal login)">
      <button class="btn" name="action" value="add_client">Add client</button>
    </form>
    <ul class="onb-list">
      <?php foreach ($clients as $c): ?>
        <li><b><?= e($c['name']) ?></b><?= $c['contact_email'] ? ' <span class="muted">· ' . e($c['contact_email']) . '</span>' : '' ?>
          <?php if (!empty($c['user_id'])): ?> <span class="status approved">portal login</span><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if (!$clients): ?><li class="muted">No clients yet — add one above (optional).</li><?php endif; ?>
    </ul>
    <?php $nav(); ?>
  </div>

<?php elseif ($step === 'teams'): ?>
  <div class="panel">
    <h3>Teams</h3>
    <p class="muted">Group employees into teams. Managers see their team; you can scope reports by team.</p>
    <div class="tutorial">
      Teams decide what a <b>team manager</b> can see. After setup, open the <b>Team</b> page to set
      each person's <b>work schedule</b> (standard hours &amp; days) and <b>pay/bill rates</b> — schedules
      are what let DeskPulse flag <b>overtime</b> for approval.
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
      <input type="hidden" name="step" value="teams">
      <input type="text" name="team_name" placeholder="Team name" required>
      <button class="btn" name="action" value="create_team">Create team</button>
    </form>
    <?php if ($teams && $members): ?>
      <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
        <input type="hidden" name="step" value="teams">
        <select name="team_id"><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <select name="user_id"><?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select>
        <button class="btn ghost" name="action" value="add_team_member">Assign to team</button>
      </form>
    <?php elseif ($teams): ?>
      <p class="muted small">Add agents in the next step, then come back to assign them.</p>
    <?php endif; ?>
    <ul class="onb-list">
      <?php foreach ($teams as $t): ?>
        <li><b><?= e($t['name']) ?></b> <span class="muted">· <?= !empty($team_members[$t['id']]) ? e(implode(', ', $team_members[$t['id']])) : 'no members yet' ?></span></li>
      <?php endforeach; ?>
      <?php if (!$teams): ?><li class="muted">No teams yet — add one above (optional).</li><?php endif; ?>
    </ul>
    <?php $nav(); ?>
  </div>

<?php elseif ($step === 'agents'): ?>
  <div class="panel">
    <h3>Accounts &amp; roles</h3>
    <p class="muted">Create accounts for your people. They sign in with this email/password; employees
       also install the desktop agent from the <b>Download</b> page to start tracking.</p>
    <div class="tutorial">
      <b>Pick the right role — access is matched to the job:</b>
      <ul>
        <li><b>Employee</b> — tracks their own time, tasks and screenshots; sees only their own data.</li>
        <li><b>Team manager</b> — oversees an assigned team: live view, screenshots, and approves their manual time.</li>
        <li><b>HR admin</b> — org-wide profiles, work schedules, pay rates, time &amp; <b>overtime approvals</b> and payroll. No screenshots or billing.</li>
        <li><b>IT admin</b> — devices, monitoring policy/settings and the audit log. No screenshots, pay or billing.</li>
      </ul>
      Client portal logins (read-only, for the companies you serve) are created on the <b>Clients</b> page, not here.
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
      <input type="hidden" name="step" value="agents">
      <input type="text" name="name" placeholder="Full name" required>
      <input type="email" name="email" placeholder="email@company.com" required>
      <select name="role"><?php foreach ($roles as $val => $label): ?><option value="<?= $val ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      <input type="text" name="password" placeholder="Temp password (optional)">
      <button class="btn" name="action" value="add_account">Add account</button>
    </form>
    <table class="data" style="margin-top:.6rem">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
      <tbody>
        <?php if (!$members): ?><tr><td colspan="3" class="muted">No agents yet — add your first above.</td></tr><?php endif; ?>
        <?php foreach ($members as $m): ?>
          <tr><td><?= e($m['name']) ?></td><td><?= e($m['email']) ?></td><td><span class="tag"><?= e(role_label($m['role'])) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php $nav(); ?>
  </div>

<?php elseif ($step === 'tasks'): ?>
  <div class="panel">
    <h3>Tasks</h3>
    <p class="muted">Optional starter tasks for your <b>team managers and agents</b>. Employees can also add
       their own from the desktop agent.</p>
    <?php if ($assignees): ?>
      <form method="post" action="<?= e(url('/app/onboarding')) ?>" class="onb-row"><?= csrf_field() ?>
        <input type="hidden" name="step" value="tasks">
        <input type="text" name="title" placeholder="Task description" required>
        <select name="user_id"><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> (<?= e(role_label($m['role'])) ?>)</option><?php endforeach; ?></select>
        <select name="client_id"><option value="">— No client —</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <button class="btn" name="action" value="add_task">Add task</button>
      </form>
    <?php else: ?>
      <p class="muted">Add team managers or agents first, then assign tasks to them.</p>
    <?php endif; ?>
    <ul class="onb-list">
      <?php foreach ($tasks as $t): ?>
        <li><b><?= e($t['title']) ?></b> <span class="muted">· <?= e($t['owner']) ?><?= $t['client'] ? ' · ' . e($t['client']) : '' ?></span></li>
      <?php endforeach; ?>
      <?php if (!$tasks): ?><li class="muted">No tasks yet (optional).</li><?php endif; ?>
    </ul>
    <div class="onb-nav">
      <a class="btn ghost" href="<?= $go('agents') ?>">← Back</a>
      <a class="btn" href="<?= $go('done') ?>">Continue →</a>
    </div>
  </div>

<?php else: /* done */ ?>
  <div class="panel onb-done">
    <h3>🎉 You're ready</h3>
    <p class="muted">Your workspace is set up:</p>
    <div class="stat-row">
      <div class="stat"><span class="lbl">Clients</span><b><?= count($clients) ?></b></div>
      <div class="stat"><span class="lbl">Teams</span><b><?= count($teams) ?></b></div>
      <div class="stat"><span class="lbl">Agents</span><b><?= count($members) ?></b></div>
      <div class="stat"><span class="lbl">Tasks</span><b><?= count($tasks) ?></b></div>
    </div>
    <div class="tutorial">
      <b>What's next:</b>
      <ul>
        <li>Have your team sign in and install the desktop agent (<b>Download</b>) to start tracking.</li>
        <li>Set <b>work schedules</b> &amp; <b>pay rates</b> on the <b>Team</b> page.</li>
        <li><b>Approvals</b> and <b>Overtime</b> are where you sign off manual time and out-of-schedule hours before payroll.</li>
        <li><b>Billing</b> charges customers; <b>Pay breakdown</b> shows your internal labor cost.</li>
        <li><b>Devices</b> and the <b>Audit log</b> keep the deployment healthy.</li>
      </ul>
    </div>
    <form method="post" action="<?= e(url('/app/onboarding')) ?>"><?= csrf_field() ?>
      <input type="hidden" name="step" value="done">
      <button class="btn lg" name="action" value="finish">Go to dashboard →</button>
    </form>
  </div>
<?php endif; ?>
