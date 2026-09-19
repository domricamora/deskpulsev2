<?php
/** First-run role guide. Expects $user (+ nav_context). Content is keyed by role so
 *  every role — including admins/managers who reopen it — gets a tailored tour. */
$role = $user['role'] ?? 'member';

// Each guide: badge emoji, headline, intro, and a list of cards
// [title, body, href (optional), link label (optional)].
$guides = [
    'member' => [
        'badge' => '🙋',
        'title' => 'Welcome to DeskPulse',
        'intro' => 'DeskPulse tracks your work automatically so your hours, activity and pay stay accurate. Here\'s how to get going.',
        'cards' => [
            ['Install the desktop app',
             'Download and install the DeskPulse agent, sign in with this account, and press the timer to start. A "● Monitoring" badge shows while it\'s tracking. You go idle automatically after the inactivity threshold your admin sets (15 min by default).',
             '/app/download', 'Get the app'],
            ['Pick what you\'re working on',
             'Add tasks and select your current one — in the app or here on the web. Time you track rolls up per task so you (and your manager) can see where it went.',
             '/app/tasks', 'My tasks'],
            ['See your activity',
             'Your Overview shows active vs inactive time, your top apps, recent screenshots and an activity timeline. Use the day / week / month switch to change the period.',
             '/app/overview', 'My overview'],
            ['Timesheets & manual time',
             'Forgot to track? Add a manual time entry on Timesheets — it goes to your manager for approval. Time worked beyond your set schedule is flagged as overtime and needs HR approval before it counts toward pay.',
             '/app/timesheets', 'My timesheets'],
            ['Your pay & schedule',
             'Your pay rate and work schedule are set by your admin/HR and shown on your Overview. View your Payslip for a period breakdown of paid hours.',
             '/app/payslip', 'My payslip'],
            ['Profile & settings',
             'Update your details, change your password, and grab a public share link from Settings and Profile.',
             '/app/profile', 'My profile'],
        ],
    ],
    'hr_manager' => [
        'badge' => '👥',
        'title' => 'Welcome, HR manager',
        'intro' => 'You manage people, schedules, time approvals and pay across the whole organization. (HR doesn\'t see screenshots or client billing.)',
        'cards' => [
            ['People & profiles',
             'Manage everyone\'s profile, job title and contact details. Set each person\'s work schedule (standard hours and work days) and pay rate from the Team page.',
             '/app/team', 'Open Team'],
            ['Approve manual time',
             'Manual time entries employees add start as pending. Review and approve or reject them on the Approvals page.',
             '/app/approvals', 'Review approvals'],
            ['Approve overtime',
             'New: time worked beyond an employee\'s schedule is split out as overtime and held for your approval before it counts toward payroll. Approve or reject it on the Overtime page.',
             '/app/overtime', 'Review overtime'],
            ['Pay breakdown',
             'See hours, pay rates and labor cost per person for any period on the Pay breakdown page — the basis for payroll.',
             '/app/payroll', 'Open Pay breakdown'],
            ['Organization activity',
             'Your Overview aggregates activity across all teams — active time, productivity and headcount.',
             '/app/overview', 'Open Overview'],
        ],
    ],
    'it_admin' => [
        'badge' => '🛠️',
        'title' => 'Welcome, IT admin',
        'intro' => 'You keep the deployment healthy: devices, monitoring policy and the audit trail. (IT doesn\'t see screenshots, billing or pay.)',
        'cards' => [
            ['Devices',
             'Every installed agent shows here with its owner and last-seen time. You can rename or remove (deauthorize) a device — handy when someone gets a new machine or leaves.',
             '/app/devices', 'Open Devices'],
            ['Monitoring policy & settings',
             'Set the org-wide monitoring policy from Settings: screenshot interval, idle threshold, screenshot blur and which signals are captured (screenshots, windows, apps). You can also upload the company logo.',
             '/app/settings', 'Open Settings'],
            ['Audit log',
             'Review key account and security actions across the organization on the Audit log.',
             '/app/audit', 'Open Audit log'],
            ['Deploy the agent',
             'Install the desktop agent on workers\' machines from the Download page. It\'s built sign-ready; without a code-signing certificate users see the standard SmartScreen "More info → Run anyway" prompt once.',
             '/app/download', 'Get the app'],
        ],
    ],
    'client_viewer' => [
        'badge' => '🔎',
        'title' => 'Welcome to your client portal',
        'intro' => 'This is a read-only window into the work being done for you. You can\'t change anything here — and you\'ll never see the team\'s internal costs, only what you\'re charged.',
        'cards' => [
            ['Your agents',
             'The team members working on your engagement. Open any agent for their activity, tasks and screenshots.',
             '/app/agents', 'Open Agents'],
            ['Timesheets',
             'The hours logged against your work, by day and period.',
             '/app/timesheets', 'Open Timesheets'],
            ['Tasks',
             'What\'s being worked on, with time spent per task.',
             '/app/tasks', 'Open Tasks'],
            ['Screenshots',
             'Periodic screenshots captured during tracked work, as proof of progress.',
             '/app/screenshots', 'Open Screenshots'],
            ['Billing',
             'A breakdown of what you\'re being charged for the period — never the provider\'s internal pay or cost.',
             '/app/billing', 'Open Billing'],
        ],
    ],
    'manager' => [
        'badge' => '🧭',
        'title' => 'Team manager guide',
        'intro' => 'You oversee a roster of agents. Here are the tools you\'ll use most. (Reopened from the sidebar — your first-run setup is the roster wizard.)',
        'cards' => [
            ['Your team',
             'Add and manage the agents you oversee on the Agents page; their time and activity roll up to you.',
             '/app/agents', 'Open Agents'],
            ['Live view',
             'See who\'s tracking right now and what they\'re working on in real time.',
             '/app/live', 'Open Live'],
            ['Approvals',
             'Approve or reject the manual time entries your team submits.',
             '/app/approvals', 'Review approvals'],
            ['Screenshots & activity',
             'Browse periodic screenshots and per-person activity for your team.',
             '/app/screenshots', 'Open Screenshots'],
        ],
    ],
    'client_admin' => [
        'badge' => '🏢',
        'title' => 'Company admin guide',
        'intro' => 'You have full control of the organization. Here\'s a refresher on the main areas. (Reopened from the sidebar — your first-run setup is the setup wizard.)',
        'cards' => [
            ['Re-run setup',
             'Revisit the guided setup any time to add clients, teams, accounts and tasks.',
             '/app/onboarding', 'Open setup'],
            ['People, schedules & pay',
             'Manage accounts and roles, set work schedules and pay/bill rates from the Team page.',
             '/app/team', 'Open Team'],
            ['Approvals & overtime',
             'Approve manual time on Approvals; overtime beyond a worker\'s schedule is held for approval on the Overtime page before it reaches payroll.',
             '/app/overtime', 'Open Overtime'],
            ['Billing & payroll',
             'Bill customers per agent or per month on Billing; see internal labor cost on Pay breakdown.',
             '/app/billing', 'Open Billing'],
            ['Devices, audit & settings',
             'Track installed agents on Devices, review the Audit log, and set the monitoring policy and company logo in Settings.',
             '/app/settings', 'Open Settings'],
        ],
    ],
];

$g = $guides[$role] ?? $guides['member'];
$isFirstRun = is_welcome_role($role) && empty($user['welcomed_at']);

// Stepped wizard: each card is a step, plus a final "Done" step. Navigated with
// ?step=N (no server state needed); the final step stamps welcomed_at.
$cards   = $g['cards'];
$nCards  = count($cards);
$step    = isset($_GET['step']) ? max(0, min((int) $_GET['step'], $nCards)) : 0;
$isDone  = $step >= $nCards;
$go      = fn($i) => e(url('/app/welcome?step=' . $i));
?>
<div class="welcome-hero">
  <div class="wh-badge"><?= $g['badge'] ?></div>
  <div>
    <h2><?= e($g['title']) ?></h2>
    <p class="muted" style="margin:.25rem 0 0;max-width:62ch"><?= e($g['intro']) ?></p>
  </div>
</div>

<ol class="onb-steps">
  <?php foreach ($cards as $i => $c): ?>
    <li class="<?= $i === $step ? 'on' : ($i < $step ? 'done' : '') ?>">
      <a href="<?= $go($i) ?>"><span class="n"><?= $i < $step ? '✓' : $i + 1 ?></span><?= e($c[0]) ?></a>
    </li>
  <?php endforeach; ?>
  <li class="<?= $isDone ? 'on' : '' ?>">
    <a href="<?= $go($nCards) ?>"><span class="n"><?= $isDone ? '★' : $nCards + 1 ?></span>Done</a>
  </li>
</ol>

<?php if (!$isDone): $c = $cards[$step]; ?>
  <?php if ($step === 0): ?>
    <div class="tutorial">
      <b>Your role: <?= e(role_label($role)) ?>.</b> The sidebar only shows the pages you have
      access to. Step through the cards below for a quick tour of what each one does.
    </div>
  <?php endif; ?>
  <div class="panel">
    <div class="guide-card" style="border:0;padding:0">
      <span class="gc-step"><?= $step + 1 ?></span>
      <h3 style="margin:.1rem 0 .4rem"><?= e($c[0]) ?></h3>
      <p style="font-size:.95rem"><?= e($c[1]) ?></p>
      <?php if (!empty($c[2])): ?>
        <a class="btn sm ghost" style="margin-top:.7rem" href="<?= e(url($c[2])) ?>"><?= e($c[3] ?? 'Open') ?> →</a>
      <?php endif; ?>
    </div>
    <div class="onb-nav">
      <?php if ($step > 0): ?><a class="btn ghost" href="<?= $go($step - 1) ?>">← Back</a><?php endif; ?>
      <a class="btn ghost" href="<?= $go($nCards) ?>">Skip tour</a>
      <a class="btn" href="<?= $go($step + 1) ?>">Continue →</a>
    </div>
  </div>
<?php else: /* done */ ?>
  <div class="panel onb-done">
    <h3>🎉 You're all set</h3>
    <p class="muted">That's the quick tour for your role. You can reopen this guide any time from
       <b>Getting started</b> in the sidebar.</p>
    <form method="post" action="<?= e(url('/app/welcome')) ?>" class="guide-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="dismiss">
      <?php if ($isFirstRun): ?>
        <button class="btn lg" type="submit" name="to" value="/app/overview">Go to my dashboard →</button>
        <span class="muted small">You won't see this automatically again.</span>
      <?php else: ?>
        <a class="btn lg" href="<?= e(url('/app/overview')) ?>">Back to dashboard →</a>
      <?php endif; ?>
    </form>
  </div>
<?php endif; ?>
